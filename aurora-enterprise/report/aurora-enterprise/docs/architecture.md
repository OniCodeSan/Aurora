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
aurora-enterprise/
├─ aurora-enterprise.php
├─ src/
│  ├─ Admin/
│  │   └─ Dashboard.php
│  ├─ CLI/
│  │   ├─ Enqueue_Command.php
│  │   ├─ Worker_Command.php
│  │   ├─ Rebuild_Command.php
│  │   └─ Status_Command.php
│  ├─ Database/
│  │   └─ Installer.php
│  ├─ Events/
│  │   └─ Product_Event_Subscriber.php
│  ├─ Indexer/
│  │   ├─ AbstractIndexer.php
│  │   ├─ PriceIndexer.php
│  │   ├─ StockIndexer.php
│  │   └─ VisibilityIndexer.php
│  ├─ Queue/
│  │   ├─ QueueInterface.php
│  │   ├─ RedisQueue.php
│  │   ├─ DatabaseQueue.php
│  │   └─ Payload.php
│  ├─ Worker/
│  │   └─ WorkerRunner.php
│  ├─ Support/
│  │   ├─ Bootstrap.php
│  │   ├─ CachePurger.php
│  │   ├─ Config.php
│  │   └─ Logger.php
│  └─ Http/
│      └─ Controllers/
│          └─ Dashboard_Controller.php
├─ assets/
│  ├─ admin/js/dashboard.js
│  └─ admin/css/dashboard.css
├─ docs/
│  ├─ architecture.md
│  └─ cli.md
└─ bin/
   └─ aurora-worker.php
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
| catalog_flags | JSON/SET |
| channel_mask | INT |
| version | CHAR(36) |
| updated_at | DATETIME |

### 4.4 `wp_product_index_queue`
| Campo | Tipo | Note |
| job_uuid | CHAR(36) | chiave univoca per worker |
| queue | VARCHAR(50) | es. price/stock |
| payload | JSON |
| priority | TINYINT |
| attempts | TINYINT |
| available_at | DATETIME |
| reserved_at | DATETIME |
| status | ENUM |
| error | TEXT |
| timestamps | DATETIME |

### 4.5 `wp_product_index_logs`
| Campo | Tipo |
| job_id | CHAR(36) |
| indexer | VARCHAR(20) |
| level | ENUM('info','warning','error') |
| message | TEXT |
| context | JSON |
| created_at | DATETIME |

### 4.6 Staging tables
Indice duplicato `_staging` per prezzi/stock/visibilità + campo `batch_id`. Pipeline: insert in staging → swap con versione → drop/archivia vecchia.

## 5. Pipeline di indicizzazione
1. Hook WooCommerce crea payload `{product_id, trigger}` e `Queue_Manager::enqueue()` su ogni canale.
2. RedisQueue (o DatabaseQueue) salva job, gestisce retry/backoff e dead-letter.
3. Worker CLI (`wp aurora worker`) riserva batch (500-1000) e passa i payload a `PriceIndexer` / `StockIndexer` / `VisibilityIndexer`.
4. Indexer scrive su staging table via bulk SQL, esegue swap atomico, invalida cache e logga.
5. Manual rebuild tramite `wp aurora rebuild --indexer=price` per popolare da zero (target <60s / 50k).

## 6. WP-CLI & cron di sistema
- `wp aurora enqueue --type=price --product=123`
- `wp aurora worker --indexer=price --batch=750 --max-loops=5`
- `wp aurora rebuild --indexer=all`
- `wp aurora queue status`
- Cron: `*/1 * * * * php /path/to/wp-content/plugins/aurora-enterprise/bin/aurora-worker.php --indexer=price >> /var/log/aurora-price.log 2>&1`

## 7. Dashboard admin
- Card stato code (queue depth, throughput, dead-letter)
- Lista job falliti + pulsante retry
- KPI ultimo rebuild e durata
- Pulsante "Rebuild manuale" (chiama endpoint protetto → WP-CLI)
- Warning se WP-Cron non disabilitato

## 8. Sicurezza & qualità
- Namespace `Aurora\Enterprise`
- Prepared statements ovunque
- Capability check `manage_woocommerce`
- Escaping output
- Multisite opt-in via `AURORA_ALLOW_MULTISITE`
- Log rotanti + tabella `wp_product_index_logs`

## 9. Performance target
- Rebuild price+stock 50k prodotti < 60s
- Import massivo 10k prodotti < 5m
- Zero timeout HTTP (worker solo CLI)
- Nessun lock prolungato: transazioni corte, swap atomico

## 10. Backlog prossimo
1. Dead-letter UI (retry manuale da dashboard) e metriche avanzate (tempo medio batch).
2. Worker daemon supervisor + supporto multi-tenant (namespace per queue).
3. Script seed + benchmark (50k prodotti, 10k import) per documentare performance reali.
4. Alerting/telemetria (hook per push verso Slack/Webhook quando coda supera threshold).
5. Preparare SearchIndexer stub + API pubblica per feed esterni.
