<?php

declare(strict_types=1);

namespace App\Infrastructure\Command;

use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\VectorDocument;
use Symfony\AI\Store\StoreInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(name: 'artifihair:rag:ingest-json', description: 'Ingest JSON data for RAG system')]
class RagIngestJsonCommand extends Command
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly StoreInterface $store,
        private readonly string $ollamaUrl,
        private readonly string $embedModel
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'Chemin du fichier JSON (dans le conteneur)')
            ->addOption('chunk-size', null, InputOption::VALUE_REQUIRED, 'Taille de chunk (caractères)', '1200')
            ->addOption('overlap', null, InputOption::VALUE_REQUIRED, 'Chevauchement (caractères)', '200')
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Taille de batch Qdrant', '10');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $file = (string) $input->getArgument('file');
        $chunkSize = (int) $input->getOption('chunk-size');
        $overlap = (int) $input->getOption('overlap');
        $batchSize = (int) $input->getOption('batch');

        if (!is_file($file)) {
            $io->error("Fichier introuvable: $file");

            return Command::FAILURE;
        }

        $raw = json_decode((string) file_get_contents($file), true);
        if ($raw === null) {
            $io->error("JSON invalide: $file");

            return Command::FAILURE;
        }

        $raw = $raw['data'];

        // 1) Détection GeoJSON + normalisation des enregistrements
        $isGeo = is_array($raw) && ($raw['type'] ?? null) === 'FeatureCollection' && isset($raw['features']);

        if ($isGeo) {
            $records = [];
            foreach ((array) $raw['features'] as $feat) {
                if (!is_array($feat) || ($feat['type'] ?? null) !== 'Feature') continue;

                $props = (array) ($feat['properties'] ?? []);
                $coords = (array) ($feat['geometry']['coordinates'] ?? []); // [lng, lat] en GeoJSON
                if (count($coords) >= 2) {
                    $props['lng'] = $props['lng'] ?? $coords[0];
                    $props['lat'] = $props['lat'] ?? $coords[1];
                }
                $records[] = $props;
            }
        } else {
            $records = is_array($raw) ? $raw : [$raw];
        }

        if (!$records) {
            $io->warning('Aucun enregistrement détecté.');

            return Command::SUCCESS;
        }

        // 2) Aplatisseur -> "path => string"
        $flatten = static function (array $row, string $prefix = '') use (&$flatten): array {
            $out = [];
            foreach ($row as $k => $v) {
                $path = $prefix === '' ? (string) $k : $prefix . '.' . $k;
                if (is_array($v)) {
                    $out += $flatten($v, $path);
                } elseif (is_scalar($v)) {
                    $s = trim((string) $v);
                    if ($s !== '') {
                        $out[$path] = $s;
                    }
                }
            }

            return $out;
        };

        // 3) Construit un texte unique "clé: valeur" pour TOUT vectoriser
        $toFullText = static function (array $row) use ($flatten): string {
            $pairs = $flatten($row);
            if (!$pairs) {
                return json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $lines = [];
            foreach ($pairs as $path => $val) {
                $lines[] = $path . ': ' . $val;
            }

            return implode("\n", $lines);
        };

        // 4) Splitter (chunking) simple
        $split = function (string $t) use ($chunkSize, $overlap): array {
            $t = trim($t);
            if ($t === '') return [];
            $out = [];
            $len = mb_strlen($t);
            $step = max(1, $chunkSize - $overlap);
            for ($i = 0; $i < $len; $i += $step) {
                $out[] = mb_substr($t, $i, $chunkSize);
                if ($i + $chunkSize >= $len) break;
            }

            return $out;
        };

        // 5) Probe dimension via embeddings
        $dim = count($this->embed('probe'));
        if ($dim <= 0) {
            $io->error('Embeddings indisponibles (Ollama)');

            return Command::FAILURE;
        }

        // 6) Ingestion
        $batch = [];
        $count = 0;

        foreach ($records as $i => $row) {
            if (!is_array($row)) continue;

            // Texte = tout l'objet
            $txt = $toFullText($row);

            foreach ($split($txt) as $j => $chunkText) {
                $embedding = $this->embed($chunkText);
                if (count($embedding) !== $dim) {
                    continue;
                }

                $batch[] = new VectorDocument(
                    id: Uuid::v7(),
                    vector: new Vector($embedding),
                    metadata: new Metadata([
                        'text' => $chunkText,
                        'source_id' => $i + 1,
                        'chunk_index' => $j + 1,
                        'nom' => $row['nom'] ?? null,
                        'ville' => $row['ville'] ?? null,
                        'codepostal' => $row['codepostal'] ?? null,
                        'lat' => $row['lat'] ?? null,
                        'lng' => $row['lng'] ?? null,
                    ]),
                );

                if (count($batch) >= $batchSize) {
                    $this->store->add(...$batch);
                    $batch = [];
                }
                $count++;
            }
        }

        if ($batch) {
            $this->store->add(...$batch);
        }

        $io->success("OK : $count chunk(s) indexé(s) dans Qdrant via Symfony AI Store (vectorisation complète).");

        return Command::SUCCESS;
    }

    /**
     * Génère un embedding en privilégiant /v1/embeddings (OpenAI-compat)
     * puis fallback /api/embed et /api/embeddings d’Ollama.
     */
    private function embed(string $text): array
    {
        // 1) /v1/embeddings (si exposé par ton Ollama)
        try {
            $r = $this->httpClient->request(
                'POST',
                $this->ollamaUrl . '/v1/embeddings',
                [
                    'json' => ['model' => $this->embedModel, 'input' => $text],
                    'timeout' => 60,
                ]
            )->toArray(false);

            if (isset($r['data'][0]['embedding']) && is_array($r['data'][0]['embedding'])) {
                return $r['data'][0]['embedding'];
            }
        } catch (\Throwable) {
            // fallback
        }

        // 2) /api/embed
        try {
            $r = $this->httpClient->request(
                'POST',
                $this->ollamaUrl . '/api/embed',
                [
                    'json' => ['model' => $this->embedModel, 'input' => $text],
                    'timeout' => 60,
                ]
            )->toArray(false);

            if (isset($r['embeddings'][0]) && is_array($r['embeddings'][0])) {
                return $r['embeddings'][0];
            }
            if (isset($r['embedding']) && is_array($r['embedding'])) {
                return $r['embedding'];
            }
        } catch (\Throwable) {
            // try legacy
        }

        // 3) /api/embeddings (legacy)
        $r = $this->httpClient->request(
            'POST',
            $this->ollamaUrl . '/api/embeddings',
            [
                'json' => ['model' => $this->embedModel, 'prompt' => $text],
                'timeout' => 60,
            ]
        )->toArray(false);

        return $r['embedding'] ?? ($r['embeddings'][0] ?? []);
    }
}
