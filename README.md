# Intelligence Artifi'Hair

## Lancer le projet

```bash
docker compose up -d
docker exec -it $(docker ps -qf name=ollama) ollama pull deepseek-r1:7b
docker exec -it $(docker ps -qf name=ollama) ollama pull bge-m3
```

## Initialiser la collection dans Qdrant

### Créer la collection

```bash
curl -X PUT "http://localhost:6333/collections/hairsalons" \
-H "Content-Type: application/json" \
-H "api-key: dev-secret-123" \
-d '{ "vectors": { "size": 1024, "distance": "Cosine" } }'
```

### Lancer la commande Symfony pour insérer les données

Commande de plusieurs heures selon votre machine

```bash
php -d memory_limit=-1 bin/console artifihair:rag:ingest-json coiffeurs.json
````

### Tester les données dans Qdrant

```bash
curl -s -X POST http://localhost:6333/collections/hairsalons/points/scroll \
  -H "Content-Type: application/json" \
  -H "api-key: dev-secret-123" \
  -d '{
        "limit": 5,
        "with_payload": true,
        "filter": {
          "must": [
            { "key": "codepostal", "match": { "value": "75010" } }
          ]
        }
      }' 
```

### Indexation

```bash
curl -X PUT http://localhost:6333/collections/hairsalons/index \
  -H "Content-Type: application/json" \
  -H "api-key: dev-secret-123" \
  -d '{"field_name":"ville","field_schema":"keyword"}'
  
  
curl -X PUT http://localhost:6333/collections/hairsalons/index \
  -H "Content-Type: application/json" \
  -H "api-key: dev-secret-123" \
  -d '{"field_name":"nom","field_schema":"keyword"}'
```

## Comment poser une question

```bash
docker compose exec app bash
bin/console artifihair:request-ai "ma question"
```


## Au kazou

### Supprimer la collection

```bash
curl -X DELETE "http://localhost:6333/collections/hairsalons" \
  -H "api-key: dev-secret-123" 
```

### Test filtre

```bash
curl -s -X POST http://localhost:6333/collections/hairsalons/points/scroll \
    -H "Content-Type: application/json" \
    -H "api-key: dev-secret-123" \
    -d '{
        "limit": 5,
        "with_payload": true,
        "filter": { "must": [ { "key": "ville", "match": { "any": ["Nantes","Rennes"] } } ] }
    }'
```