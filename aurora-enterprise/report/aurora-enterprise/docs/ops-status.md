# Aurora System Status & Ops Triggers

## Overview
- Admin page: Aurora → System Status (cards: Health, Queues, Snapshot cut, Last Feed + runs table).
- Polling: GET `/wp-json/aurora/v1/system-status` every 5s.
- Triggers via POST `/wp-json/aurora/v1/trigger` with `op_key` and optional `payload`.
- Capability: `manage_woocommerce`; REST nonce required.

## Endpoints
- GET `/aurora/v1/system-status`
  - `health`: `{status, reasons[]}` (ERROR if dead>0; WARNING on backlog>10k or snapshot misaligned/coverage 0)
  - `queues`: `{price, stock, visibility, feed, dead}`
  - `snapshots`: `{versions:{channel:version}, aligned:bool, coverage:{channel:{version,count,distinct}}}`
  - `feed`: option `aurora_last_feed_meta`
  - `last_runs`: last 20 rows from `wp_aurora_ops_runs`
- POST `/aurora/v1/trigger`
  - body: `{ op_key: sweep_leases|rebuild|feed_enqueue|feed_run, payload: {} }`
  - creates run in `wp_aurora_ops_runs` (status=requested) and schedules `aurora_ops_dispatch` with args `[ { run_id, op_key, payload } ]` in group `aurora`.

## UI Buttons → op_key
- Sweep leases → `sweep_leases`
- Rebuild all/price/stock/visibility → `rebuild` with payload `indexer`
- Feed enqueue → `feed_enqueue` (payload `chunk_size` default 1000)
- Feed run → `feed_run` (payload `batch`, `max_loops`, optional `shard`, `total_shards`)

## Verification commands
1) Action Scheduler args (run_id > 0)
```
docker compose exec worker wp --allow-root db query "SELECT hook, args, status FROM wp_actionscheduler_actions WHERE hook='aurora_ops_dispatch' ORDER BY scheduled_date_gmt DESC LIMIT 10;"
```
2) Run-log state
```
docker compose exec worker wp --allow-root db query "SELECT id, op_key, status, started_at, finished_at, message, error FROM wp_aurora_ops_runs ORDER BY id DESC LIMIT 10;"
```
3) Queue status
```
docker compose exec worker wp --allow-root aurora queue status
```

## Troubleshooting
- 403/nonce: ensure admin login and correct REST nonce; capability `manage_woocommerce`.
- Actions stuck/requested: check Action Scheduler queue (as_pending); run `wp action-scheduler run`.
- Backlog/high dead: inspect queues (`aurora queue status`), run sweep, or investigate worker logs.
- Snapshot mismatch: ensure `wp_aurora_snapshot_versions` aligned; run rebuild.
- File permissions: feed output under `wp-content/uploads/aurora-feeds/` must be writable by web user.
