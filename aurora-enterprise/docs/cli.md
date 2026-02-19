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

## 6. Suggerimenti operativi
- Eseguire più worker in parallelo per price e stock (es. 2 process per ciascuno) per raggiungere il target import 10k < 5 minuti.
- Monitorare la tabella `wp_product_index_logs` o i log file per errori; usare `wp aurora queue status` per identificare code bloccate.
- Per riprovare job falliti in DB queue, cambiare `status` da `dead` a `pending` manualmente oppure implementare un comando `wp aurora queue retry --queue=price` (todo).
