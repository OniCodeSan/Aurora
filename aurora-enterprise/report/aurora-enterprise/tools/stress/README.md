# Aurora Stress Harness

Operational stress test harness for Aurora (Feed Engine + async ops) with controlled frontend load.

## Prerequisites
- WordPress + Aurora running via docker compose in `/Users/mariano/.openclaw/workspace/wordpress/`.
- Aurora plugin mounted in container (default: `/var/www/html/wp-content/plugins/aurora-enterprise`).
  - Check with: `docker compose exec worker ls /var/www/html/wp-content/plugins`.
  - Override with `PLUGIN_PATH_IN_CONTAINER` if different.
- k6 installed locally: [https://k6.io/docs/get-started/installation/](https://k6.io/docs/get-started/installation/).
- Action Scheduler available (WooCommerce installs it by default).

## Files
- `tools/stress/load.js` -> k6 frontend load test.
- `tools/stress/ops.sh` -> triggers Aurora ops + Action Scheduler in parallel.
- `tools/stress/run.sh` -> orchestrator (reset optional, run load + ops, collect metrics, report).
- `tools/stress/rest_call.php` -> REST trigger helper (server-side via `rest_do_request`).
- `tools/stress/seed_repricer_fixture.php` -> creates test product + dedicated repricer assignment for deterministic repricer-only runs.
- `tools/stress/metrics.php` -> collects Aurora metrics from DB/runtime stats.
- `tools/stress/report.php` -> builds PASS/FAIL report from k6 + Aurora metrics.

## Quick start
```bash
cd /Users/mariano/.openclaw/workspace/aurora-enterprise

# Set real slugs/paths from your catalog
export BASE_URL="http://localhost"
export CATEGORY_PATH="/categoria/sample-category"
export PRODUCT_PATH="/prodotto/sample-product"
export SEARCH_PATH="/?s=sample"

# Optional: reset ops tables before run (uses `/var/www/html/aurora_e2e_reset.php`)
export RESET=1

# Run
./tools/stress/run.sh
```

Outputs are written to `tools/stress/out/<timestamp>/`:
- `k6.log`, `k6-summary.json`
- `k6-error-samples.json` (only when HTTP errors are sampled)
- `ops.log`
- `metrics.json`
- `report.txt`

## Configurable parameters
Frontend (k6):
- `BASE_URL` (default `http://localhost`)
- `DURATION` (default `2m`)
- `RATE` (requests/sec, default `10`)
- `VUS` (preallocated VUs, default `20`)
- `MAX_VUS` (cap, default `50`)
- `MAX_ERROR_SAMPLES` (default `20`, error samples captured to `k6-error-samples.json`)
- `HOME_PATH`, `CATEGORY_PATH`, `PRODUCT_PATH`, `SEARCH_PATH`

Backend ops:
- `WP_DIR` (default `/Users/mariano/.openclaw/workspace/wordpress`)
- `PLUGIN_PATH_IN_CONTAINER` (default `/var/www/html/wp-content/plugins/aurora-enterprise`)
- `OPS_PROFILE` (default `repricer`)
  - `repricer`: triggers only repricer scheduler tick + sweep-leases
  - `full`: original feed + rebuild + sweep-leases triggers
  - `none`: frontend-only (ops disabled)
- `ONLY_ASSIGNMENT_ID` (optional; when set in repricer profile, forces tick to use that assignment)
- `OPS_DURATION_SECONDS` (default inferred from `DURATION`)
- `OPS_TRIGGER_INTERVAL_SECONDS` (default `15`)
- `OPS_AS_INTERVAL_SECONDS` (default `10`)
- `FEED_CHUNK_SIZE` (default `1000`)
- `FEED_BATCH` (default `100`)
- `FEED_MAX_LOOPS` (default `1`)
- `SWEEP_CHANNEL` (default `all`)
- `REBUILD_INDEXER` (default `price`)

Pass/Fail thresholds (override in env):
- `MAX_HTTP_ERROR_RATE` (default `0.05`)
- `MAX_HOME_P95_MS` (default `800`)
- `MAX_HOME_P99_MS` (default `1500`)
- `MAX_CATEGORY_P95_MS` (default `1000`)
- `MAX_CATEGORY_P99_MS` (default `2000`)
- `MAX_PRODUCT_P95_MS` (default `1200`)
- `MAX_PRODUCT_P99_MS` (default `2500`)
- `MAX_SEARCH_P95_MS` (default `1500`)
- `MAX_SEARCH_P99_MS` (default `3000`)
- `MAX_OPS_ERRORS` (default `0`)
- `MAX_DEAD_QUEUE` (default `0`)

## REST endpoints used
Triggered from inside WP using `rest_do_request`:
- `POST /aurora/v1/trigger/feed-enqueue`
- `POST /aurora/v1/trigger/feed-run`
- `POST /aurora/v1/trigger/sweep-leases`
- `POST /aurora/v1/trigger/rebuild`
- `POST /aurora/v1/repricer/scheduler/tick`
  - supports optional payload: `{"only_assignment_id": <int>}`

## Profiles
Repricer-only (default):
```bash
export OPS_PROFILE=repricer
./tools/stress/run.sh
```

Repricer-only (deterministic fixture):
```bash
export OPS_PROFILE=repricer
./tools/stress/run.sh
grep -n "\\[fixture\\]" tools/stress/out/<timestamp>/ops.log
```

Full ops (feed + rebuild + sweep):
```bash
export OPS_PROFILE=full
./tools/stress/run.sh
```

Frontend-only (no ops):
```bash
export OPS_PROFILE=none
./tools/stress/run.sh
```

## Precheck behavior
Before the load starts, k6 performs a single request to each endpoint (home/category/product/search). If any returns `status >= 400` or `status == 0`, the run fails immediately with a clear `[precheck]` error in `k6.log`.

## RESULT meaning
`report.txt` is authoritative for PASS/FAIL. If k6 exits non-zero or precheck fails, the report is forced to `RESULT=FAIL` with:
- `k6_exit_code`
- `precheck_failed` and `precheck_message`

## SQL verification queries
Use in your DB client (adjust `wp_` prefix if different):

Ops runs (latest):
```sql
SELECT id, op_key, status, requested_at, started_at, finished_at, message, error
FROM wp_aurora_ops_runs
ORDER BY id DESC
LIMIT 20;
```

Ops run duration summary (last 60 min):
```sql
SELECT op_key, status,
       COUNT(*) AS total,
       SUM(CASE WHEN started_at IS NOT NULL AND finished_at IS NOT NULL
                THEN TIMESTAMPDIFF(SECOND, started_at, finished_at)
                ELSE 0 END) AS duration_seconds
FROM wp_aurora_ops_runs
WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 60 MINUTE)
GROUP BY op_key, status;
```

Feed progress:
```sql
SELECT run_id, status, snapshot_version, file_part, rows_written, bytes_written,
       last_product_id, started_at, updated_at, error
FROM wp_aurora_feed_progress
ORDER BY run_id DESC
LIMIT 5;
```

Queue stats:
```sql
SELECT queue, status, COUNT(*) AS total
FROM wp_product_index_queue
GROUP BY queue, status;
```
Note: there is no explicit "acked" count in the DB; successful acks remove rows. Use the delta of `pending/processing` plus `aurora_runtime_stats` (dedup/lease counters) as evidence.

Snapshot versions:
```sql
SELECT table_name, current_version, updated_at
FROM wp_aurora_snapshot_versions;
```

Last feed meta (option):
```sql
SELECT option_value
FROM wp_options
WHERE option_name = 'aurora_last_feed_meta';
```

Action Scheduler status (Aurora ops):
```sql
SELECT status, COUNT(*) AS total
FROM wp_actionscheduler_actions
WHERE hook = 'aurora_ops_dispatch'
GROUP BY status;
```

## Log patterns to confirm correctness
- Action Scheduler execution:
  - `Ops_Dispatcher start: run_id=... op_key=...`
  - `Ops_Dispatcher success:` / `Ops_Dispatcher error:`
- Dedup/queue:
  - `dedup_hits_total` in `aurora_runtime_stats`
  - `lease_sweep_recovered_total` and `lease_sweep_dead_total`
- No double execution:
  - repeated `run_id` should not show multiple `start` lines

## Notes on scaling to 50k/100k SKU
- Seed product fixtures before the test using your existing seeding scripts (e.g. `seed-products.php` or a dedicated fixture import).
- Increase `FEED_CHUNK_SIZE`, `FEED_BATCH`, and `RATE` gradually; monitor `queue_stats` and `feed_progress` for saturation.
- If snapshots are enabled, run a rebuild before the stress test to align versions and avoid snapshot mismatch errors.
- Consider splitting feed runs by shard using `total_shards` + `shard` in `trigger/feed-run` for controlled concurrency.
