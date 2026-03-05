# Sweeper E2E – Lease Recovery Test

This playbook validates that the lease sweeper recovers crashed workers without leaving jobs in `processing`.

## Prerequisites
- WordPress + Aurora Enterprise installed (DB queue driver is required).
- WP-CLI available as `wp` (or override via `WP_BIN` env for the helper script).

## Manual steps
```
wp aurora test seed-queue --count=60 --channel=price --reset
wp aurora worker --indexer=price --batch=50 --simulate-crash-after=20
wp aurora queue sweep-leases --channel=price --older-than=0
```
Expected observations:
1. After the worker crash you should see 40 pending / 20 processing jobs and all 20 processing rows have `lease_expires_at <= NOW()`.
2. After the sweep you should see 60 pending, 0 processing, 0 dead.
3. The sweeper CLI reports `Requeued 20 leases, marked 0 jobs dead.`

Run the SQL spot-checks (replace `wp_` with your prefix):
```
wp db query "SELECT status, COUNT(*) FROM wp_product_index_queue GROUP BY status;"
wp db query "SELECT COUNT(*) AS zombies FROM wp_product_index_queue WHERE status='processing';"
wp db query "SELECT COUNT(*) AS expired_processing FROM wp_product_index_queue WHERE status='processing' AND lease_expires_at <= UTC_TIMESTAMP();"
```

## Automated check
Use `bin/verify-sweeper.sh` to execute the flow end-to-end.
```
cd wp-content/plugins/aurora-enterprise
./bin/verify-sweeper.sh              # defaults: price, count=60, simulate=20
CHANNEL=stock COUNT=30 ./bin/verify-sweeper.sh
```
The script exits non-zero if:
- the crash simulation does not abort as expected,
- zombies (`status=processing`) remain after the sweep,
- dead jobs (`status=dead`) appear (unless `ALLOW_DEAD_JOBS=1`).

Artifacts:
- Command output is echoed to stdout.
- SQL counts are printed before/after `queue sweep-leases` for traceability.
