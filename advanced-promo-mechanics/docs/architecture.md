# Aurora Enterprise Indexer – Architecture Blueprint

## 1. Visione generale
Obiettivo: portare il plugin "Aurora Project" a livello enterprise per gestire 50.000+ prodotti con aggiornamenti massivi su prezzi, stock e visibilità senza affidarsi a `wp_postmeta` per i dati ad alta frequenza. Tutta la logica pesante viene spostata fuori da WordPress core, usando worker CLI persistenti e una coda reale.

### Flow di alto livello (stile Magento)
```
Evento WooCommerce (save_post_product / webhook / import)
        ↓ enqueue (QueueInterface)
Coda (Redis / Rabbit / SQS + fallback DB)
        ↓ worker CLI (wp aurora worker)
Indexer Service (Price / Stock / Visibility / Search stub)
        ↓ staging table write (bulk SQL)
        ↓ swap atomico (RENAME TABLE o flag version)
        ↓ cache purge (object cache + transients)
        ↓ log & metrics (wp_product_index_logs)
```

### Vincoli principali
- WP-Cron disattivato: scheduling esclusivamente via cron Linux o supervisore (systemd).
- WordPress fornisce solo UI admin, configurazioni, comandi WP-CLI e trigger eventi.
- Nessun uso di `update_post_meta` o CRUD WooCommerce per operazioni massive.
- Compatibilità minima: PHP 8.2+, MariaDB/MySQL 10.5+, Redis opzionale ma consigliato.
- Il sistema deve funzionare anche senza Redis (prestazioni inferiori ma funziona) grazie a una coda DB.

## 2. Componenti principali

| Componente | Descrizione |
|------------|-------------|
| `QueueInterface` | API per enqueue/reserve/release/fail con backoff e dead-letter. Implementazioni: RedisQueue, DatabaseQueue (fallback). |
| `IndexerService` | Servizi separati per prezzi, stock, visibilità, ricerca futura. Gestiscono staging table, swap atomico e purge cache. |
| `WorkerRunner` | CLI persistente (WP-CLI command + script standalone) che consuma la coda in batch da 500-1000 record. |
| `EventDispatcher` | Hook WordPress (product save, status change, import) che serializza il payload e lo invia alla coda. |
| `Admin Dashboard` | UI per stato code, job falliti, tempo medio batch, ultimo rebuild, pulsante rebuild manuale (che chiama WP-CLI o REST protetta). |
| `Logger` | Log sia su tabella `wp_product_index_logs` sia su file rotanti (es. `wp-content/uploads/aurora-logs/indexer-YYYYMMDD.log`). |

## 3. Struttura cartelle del plugin
```
advanced-promo-mechanics-enterprise/
├─ advanced-promo-mechanics-enterprise.php   (bootstrap)
├─ src/
│  ├─ Admin/
│  │   ├─ Dashboard.php
│  │   └─ Controllers/QueueStatusController.php
│  ├─ CLI/
│  │   ├─ Enqueue_Command.php
│  │   ├─ Worker_Command.php
│  │   ├─ Rebuild_Command.php
│  │   └─ Status_Command.php
│  ├─ Database/
│  │   ├─ Installer.php
│  │   └─ Schema/*.sql.php
│  ├─ Events/
│  │   └─ Product_Event_Subscriber.php
│  ├─ Indexer/
│  │   ├─ AbstractIndexer.php
│  │   ├─ PriceIndexer.php
│  │   ├─ StockIndexer.php
│  │   ├─ VisibilityIndexer.php
│  │   └─ SearchIndexer.php (stub)
│  ├─ Queue/
│  │   ├─ QueueInterface.php
│  │   ├─ RedisQueue.php
│  │   ├─ DatabaseQueue.php
│  │   └─ Payload.php
│  ├─ Worker/
│  │   └─ WorkerRunner.php
│  ├─ Support/
│  │   ├─ Config.php
│  │   ├─ Logger.php
│  │   └─ CachePurger.php
│  └─ Http/
│      └─ Controllers/RebuildController.php (per pulsante UI)
├─ assets/
│  └─ admin/js/dashboard.js (React/Vue lite) + css
├─ docs/
│  ├─ architecture.md (questo file)
│  ├─ cli.md
│  └─ benchmarks.md
└─ bin/
   └─ aurora-worker.php (entrypoint CLI standalone)
```

## 4. Schema database richiesto
Tutte le tabelle usano `InnoDB`, charset utf8mb4, `PRIMARY KEY (id BIGINT AUTO_INCREMENT)`, indici su `product_id`, `sku`, e `updated_at`. Nessun join con `wp_postmeta` per i dati dinamici.

### 4.1 `wp_product_price_index`
| Campo | Tipo | Note |
|-------|------|------|
| id | BIGINT PK | |
| product_id | BIGINT | indice + UNIQUE(product_id, variation_id) |
| variation_id | BIGINT | 0 se semplice |
| sku | VARCHAR(191) | indicizzato |
| currency | CHAR(3) | |
| regular_price | DECIMAL(12,4) | |
| sale_price | DECIMAL(12,4) | |
| effective_price | DECIMAL(12,4) | precalcolato |
| margin_percent | DECIMAL(6,3) | opzionale |
| version | CHAR(36) | per swap atomico (flag attivo) |
| updated_at | DATETIME | indicizzato |

### 4.2 `wp_product_stock_index`
| Campo | Tipo |
| product_id/variation_id/sku | come sopra |
| stock_qty | INT |
| stock_status | ENUM('instock','outofstock','onbackorder') |
| warehouse | VARCHAR(50) opzionale |
| version | CHAR(36) |
| updated_at | DATETIME |

### 4.3 `wp_product_visibility_index`
| Campo | Tipo |
| visibility | ENUM('catalog','search','hidden') |
| catalog_flags | JSON/SET | es. featured, new |
| channel_mask | INT | bitmask per store view |
| version | CHAR(36) |
| updated_at | DATETIME |

### 4.4 `wp_product_index_queue`
| Campo | Tipo | Note |
| id | BIGINT PK |
| payload | JSON | {type: price|stock|visibility, product_id, data_snapshot} |
| priority | TINYINT | 0 default |
| attempts | TINYINT | retry counter |
| available_at | DATETIME | per backoff |
| reserved_at | DATETIME NULL | lock per worker |
| queue | VARCHAR(50) | es. price, stock |
| status | ENUM('pending','processing','failed','done','dead') |
| error | TEXT | last error |
| created_at/updated_at | DATETIME |

### 4.5 `wp_product_index_logs`
| Campo | Tipo |
| job_id | CHAR(36) | correlazione |
| indexer | VARCHAR(20) | price/stock/visibility |
| level | ENUM('info','warning','error') |
| message | TEXT |
| context | JSON |
| created_at | DATETIME |

### 4.6 Staging tables
Per ciascun indice esiste `*_staging` con stessa struttura + campo `batch_id`. L’indexer scrive lì, poi:
1. `ALTER TABLE ... RENAME TO ..._old`
2. `ALTER TABLE ..._staging RENAME TO ...`
3. Drop old oppure tenerla per rollback veloce.

## 5. Pipeline di indicizzazione
1. **Evento WooCommerce**: hook `save_post_product`, `woocommerce_product_import_inserted_product_object`, API custom, feed esterni. L’evento serializza un payload minimo `{ product_id, variation_ids[], cause, timestamp }` e chiama `QueueInterface::enqueue('price', payload)` (più entry per stock/visibility se necessario).
2. **Coda**: Redis (Stream/List) con fallback DB. Enqueue inserisce job, setta `available_at` e ritorna ID. Backoff = `min( attempts^2 * 5s, 5min )`. Dead-letter dopo `n` tentativi (default 5) → stato `dead` e log.
3. **Worker CLI**:
   - Avviato da cron di sistema ogni minuto o tramite supervisore (processo persistente).
   - Comando: `wp aurora worker --indexer=price --batch=750 --queue=price`
   - `reserveBatch()` blocca record (`SELECT ... FOR UPDATE SKIP LOCKED` via DB oppure `BLPOP` su Redis) e restituisce payload.
   - Processa chunk → chiama `IndexerService` corrispondente.
   - `IndexerService` prepara dataset (join leggere su `wp_posts` una tantum), scrive in staging via bulk insert prepared statements.
   - Swap atomico + purge cache (object cache + transients specifici + `do_action( 'aurora_index_updated', type, product_ids )`).
   - Logga su `wp_product_index_logs` + file.
4. **Manual rebuild**: `wp aurora rebuild --indexer=price` popola staging da zero (select su `wp_posts`, `wp_term_relationships`, ecc.). Target: < 60s per 50k prodotti.

## 6. WP-CLI & script
- `wp aurora enqueue --type=price --product=123`
- `wp aurora worker --indexer=price --batch=750 [--daemon]`
- `wp aurora rebuild --indexer=all`
- `wp aurora queue status`
- `bin/aurora-worker.php` script standalone (bootstrap WordPress + esegue `WorkerRunner`). Utile per cron: `*/1 * * * * php /path/bin/aurora-worker.php --indexer=price >> /var/log/aurora/price.log 2>&1`

## 7. Admin Dashboard
Funzioni principali:
- Grafico/stato code (profondità, throughput, tempo medio batch) usando REST endpoint `/wp-json/aurora/v1/queue-status`.
- Lista job falliti + pulsante retry (che re-enqueue in coda e resetta attempts).
- Card “Ultimo rebuild” con timestamp + durata.
- Pulsante “Rebuild manuale” → chiama endpoint protetto che lancia `wp aurora rebuild` asincrono (es. `proc_open` o queue dedicata `manual_rebuild`).
- Notifica se WP-Cron non è disattivato.

## 8. Sicurezza e qualità
- Tutte le classi sotto namespace `Aurora\Enterprise`.
- Prepared statements per ogni query (usare `$wpdb->prepare` o `wpdb::query` con placeholders).
- `current_user_can( 'manage_woocommerce' )` su tutte le azioni admin.
- Output escaping (`esc_html`, `wp_kses_post`).
- Multisite: plugin disabilita di default, abilitabile via costante `AURORA_ALLOW_MULTISITE`.
- File di log ruotati giornalmente (es. `Logger` wrapper su Monolog o `WC_Log_Handler_File` custom path).

## 9. Performance target e strategie
- **Rebuild prezzi+stock < 60s su 50k prodotti**: batch 1000, bulk insert, `SELECT ... LIMIT 1000` con `ORDER BY ID`, staging in memoria temporanea.
- **Import massivo 10k < 5 min**: worker in parallelo (2-4 process per indexer), queue priorità, riduzione lock grazie a `SKIP LOCKED`.
- **Zero timeout HTTP**: tutte le operazioni pesanti sono CLI/queue. UI usa async polling.
- **Zero lock prolungati**: transazioni corte (solo scrittura in staging + rename). Nessun `LOCK TABLE` globale.

## 10. Fallback senza Redis
- Plugin rileva `extension_loaded('redis')`. Se assente, `QueueManager` istanzia `DatabaseQueue` (usa `wp_product_index_queue` con polling e `SELECT ... FOR UPDATE SKIP LOCKED`).
- Performance inferiori ma stabili. Documentazione spiega come abilitare Redis per throughput massimo.

## 11. Prossimi passi
1. Implementare `src/Support/Config.php` e bootstrap plugin.
2. Sviluppare `Database\Installer` con script creazione tabelle (inclusi staging e logs).
3. Codificare layer coda (interface + Redis + DB).
4. Creare servizi indexer e worker CLI.
5. Admin dashboard + documentazione CLI/benchmark.
