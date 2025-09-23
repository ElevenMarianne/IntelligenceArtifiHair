<?php

declare(strict_types=1);

namespace App\Application\Handler;

use Symfony\AI\Platform\Bridge\Ollama\OllamaClient;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Document\VectorDocument;
use Symfony\AI\Store\StoreInterface;

class GetRequestAIHandler
{
    public function __construct(
        private readonly OllamaClient $ollamaClient,
        private readonly StoreInterface $store,
        private readonly string $ollamaModel
    ) {

    }

    public function handle(string $request): string
    {
        $filters = $this->buildFilters($request);

        //$embedding = $this->ollamaClient->request(new Model('bge-m3'), 'je cherche des salons de coiffure');
        $embedding = $this->ollamaClient->request(new Model('bge-m3'), $request, ['embedding' => true]);

//        $vector = new Vector(array_values($embedding->getData()['embeddings'][0]) ?? []);
        $vector = new Vector($embedding->getData()['embeddings'][0] ?? []);
        $hits = $this->store->query(
            $vector,
            [
                'limit' => 30,
                'with_payload' => true,
                'score_threshold' => 0.2,
                //...$filters
            ]
        );

        $context = $this->buildContext($hits);

        $message = [
            ["role" => "system", "content" => "Réponds UNIQUEMENT d’après le CONTEXTE. Si insuffisant, dis-le."],
            [
                "role" => "user",
                "content" => "CONTEXTE:\n" . $context . "\n\nQUESTION:\n$request",
            ],
        ];

        $response = $this->ollamaClient->request(new Model($this->ollamaModel), ['messages' => $message],
            [
                'model' => $this->ollamaModel,
                'stream' => false,
                'options' => [
                    'num_ctx' => 8192,
                    'num_predict' => 256,
                    'temperature' => 0.0,
                ],
            ]
        );

        $content = $response->getData()['message']['content'] ?? 'Error';

        $content = preg_replace('~\s*<think\b[^>]*>.*?</think>\s*~si', '', $content);
        $content = preg_replace("/\n{3,}/", "\n\n", $content);

        return $content;
    }

    /**
     * @param VectorDocument[] $hits
     */
    private function buildContext(array $hits): string
    {
        $parts = [];
        foreach ($hits as $i => $hit) {
            $p = $hit->metadata->offsetGet('text') ?? '';
            $p = $this->parseFlatPayloadText($p);
            $h1 = trim(
                sprintf(
                    '%s — %s %s  (lat:%s, lng:%s)',
                    $p['nom'] ?? 'Inconnu',
                    $p['ville'] ?? '',
                    $p['codepostal'] ?? '',
                    $p['lat'] ?? '?',
                    $p['lng'] ?? '?'
                )
            );

            $parts[] = "{$h1}";
        }

        return implode("\n", $parts);
    }

    private function parseFlatPayloadText(string $text): array
    {
        // normaliser les fins de lignes + enlever les guillemets superflus
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $lines = preg_split('/\n+/', $text);
        $out = [];

        foreach ($lines as $line) {
            $line = trim($line, " \t\n\r\0\x0B\""); // trim + retire les " isolés en fin/ début
            if ($line === '') continue;

            // match "clé: valeur" (tolérant)
            if (preg_match('/^([^:]+):\s*(.*)$/u', $line, $m)) {
                $key = strtolower(trim($m[1]));
                $val = trim($m[2]);

                // remplacer les '\n' littéraux par un espace
                $val = str_replace('\n', ' ', $val);

                // nettoyer HTML sur les champs qui en contiennent
                if (in_array($key, ['markerinnerhtml', 'liinnerhtml'], true)) {
                    $val = strip_tags($val); // PHP strip_tags
                }

                $out[$key] = $val;
            } else {
                // ligne orpheline -> on la stocke
                $out['_rest'][] = $line;
            }
        }

        return $out;
    }

    private function buildFilters(string $request): array
    {
        return [];
        $filterCities = $this->buildFiltersCities($request);

        return $filterCities;
    }

    private function buildFiltersCities(string $request): ?array
    {
        $message = [
            "model" => $this->ollamaModel,
            "messages" => [
                ["role" => "system", "content" => "Réponds UNIQUEMENT un string formaté \"ville1, ville2\" ou un string vide si aucune ville n'est détectée."],
                [
                    "role" => "user",
                    "content" => "QUESTION:\nQuelles sont les villes françaises demandées dans le message suivant.\nCONTEXT:\n$request",
                ],
            ],
        ];

        $response = $this->ollamaClient->request(new Model($this->ollamaModel), $message, ['temperature' => 0.0]);
        $filterCities = $response->getData()['message']['content'] ?? null;

        if (empty($filterCities)) {
            return null;
        }

        $cities = explode(',', $filterCities);
        $cities = array_map('trim', $cities);

        if (count($cities) === 0 || $cities[0] === '') {
            return null;
        }

        if (count($cities) > 1) {
            return [
                'filter' => [
                    'must' => [
                        'key' => 'ville',
                        'match' => ['any' => $cities],
                    ],
                ],
            ];
        }

        return [
            'filter' => [
                'must' => [
                    'key' => 'ville',
                    'match' => ['value' => $cities[0]],
                ],
            ],
        ];
    }
}
