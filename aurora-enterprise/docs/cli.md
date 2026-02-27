# Aurora Enterprise – Comandi CLI

Tutti i comandi sono sotto il namespace `wp aurora` e richiedono PHP 8.2+, WP-CLI installato e WP-Cron disabilitato.

## 1. Enqueue manuale
```
wp aurora enqueue --type=price --product=123
wp aurora enqueue --type=stock --product=123
wp aurora enqueue --type=visibility --product=123
```
Enqueue immediato del prodotto indicato sul canale desiderato.

## 2. Worker
```
wp aurora worker --indexer=price --batch=750 --max-loops=5
wp aurora worker --indexer=all --batch=500 --max-loops=1
```
- `--indexer` può essere `price`, `stock`, `visibility` oppure `all`.
- `--batch` indica quanti job per ciclo.
- `--max-loops` quanti cicli eseguire (utile per cron periodico). Con un supervisore persistente usare valore alto o `INF`.
- `--simulate-crash-after=N` (solo test) processa N job e termina senza ACK, utile per provare lo sweeper.

### Script standalone (cron Linux)
```
*/1 * * * * php /path/to/wp-content/plugins/aurora-enterprise/bin/aurora-worker.php price 750 1 >> /var/log/aurora-price.log 2>&1
```
Parametri script: `indexer`, `batch`, `loops`.

## 3. Rebuild indici
```
wp aurora rebuild --indexer=price
wp aurora rebuild --indexer=all
```
Esegue il rebuild completo (price/stock/visibility). Target: 50k prodotti < 60s.

## 4. Stato coda
```
wp aurora queue status
```
Mostra la profondità delle code (Redis o DB).

## 5. Feed channel (MVP)
```
wp aurora feed enqueue --chunk-size=1000
wp aurora worker --indexer=feed --batch=10 --max-loops=20
```
- `enqueue` crea job feed suddividendo il catalogo in chunk (default 1000 SKU).
- I file vengono generati in `wp-content/uploads/aurora-feeds/<feed_id>.jsonl`.
- Prima di usare il worker assicurarsi che la cartella `wp-content/uploads/aurora-feeds/` sia scrivibile da `www-data` (nel dev stack: `docker compose exec wordpress chmod -R 777 wp-content/uploads`).

## 6. Test helpers
```
wp aurora test seed-queue --count=60 --channel=price --reset
```
- Preferisci sempre la forma con trattino: `wp aurora test seed-queue` (alias WP-CLI `seed_queue`).
- `--reset` tronca `product_index_queue` e `aurora_idempotence_cache` per run ripetibili (es. CI).
- I payload sono deterministici: stesso `channel` + `count` => stessi hash/payload.

## 7. Seed prodotti WooCommerce
```
wp aurora seed products --count=52000 --progress=2000 --label=test-52k
```
- Crea in batch prodotti WooCommerce completi (titolo, contenuto, SKU, _price, _stock, _stock_status) pronti per pipeline di indicizzazione/feed.
- Opzioni: `--count` (default 50000), `--start-index` per offset numerico, `--progress` per frequenza log, `--batch` per dimensione commit transazionale (default 1000), `--label` per taggare il batch (salvato nel meta `_aurora_seed_batch`).
- Il comando stampa log periodici con tempo/throughput; al termine mostra label batch, primo/ultimo ID e timing totale.
- Prerequisito DB: tabelle InnoDB (il seed usa transazioni esplicite con rollback su errore).

Verifica rapida post-seed:
```
wp aurora queue status
wp db query "SELECT COUNT(*) AS seeded FROM wp_postmeta WHERE meta_key = '_aurora_seed_batch' AND meta_value = 'test-52k';"
wp db query "SELECT COUNT(*) AS products FROM wp_posts WHERE post_type = 'product' AND post_status = 'publish';"
```
Script helper: `LABEL=test-52k ./bin/verify-seed-smoke.sh`
Nota: il helper standalone `wordpress/seed-products-direct.php` vive fuori dal plugin `aurora-enterprise`; non viene versionato in questo modulo.

## 8. Queue shard utilities
```
wp aurora queue backfill-shards --batch=2000 --total=8
```
- Usa questo comando dopo la migrazione schema per popolare la colonna `shard` esistente.
- `--batch` controlla quante righe vengono elaborate per iterazione.
- `--total` deve combaciare con il numero di shard configurati (default 8).
- `--force` ricalcola TUTTE le righe; senza flag si toccano solo quelle con shard=0 (sicuro per prod).

## 9. Suggerimenti operativi
- Eseguire più worker in parallelo per price e stock (es. 2 process per ciascuno) per raggiungere il target import 10k < 5 minuti.
- Monitorare la tabella `wp_product_index_logs` o i log file per errori; usare `wp aurora queue status` per identificare code bloccate.
- Per riprovare job falliti in DB queue, cambiare `status` da `dead` a `pending` manualmente oppure implementare un comando `wp aurora queue retry --queue=price` (todo).
