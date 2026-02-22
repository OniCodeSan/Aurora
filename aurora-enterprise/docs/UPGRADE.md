# Aurora Enterprise Upgrade Guide

This script standardizes upgrades across environments. Run from repo root:

```bash
./aurora-enterprise/bin/aurora-upgrade.sh
```

What it does:
1. Runs snapshot v2 migration (safe if already applied).
2. Ensures snapshot flag is disabled by default.
3. Sets default options for idempotence TTL, queue lease TTL, shards, and sweeper cron.
4. Outputs queue stats and metrics after upgrade to verify health.

Prereqs:
- Docker stack (`wordpress` folder) running.
- WP-CLI accessible via `docker compose exec worker wp`.
- Apply any custom env overrides after running the script.

When NOT to use `wp-safe.sh`:
- Commands that require WooCommerce to load (e.g., `wp aurora worker`, rebuilds, feed enqueue). Use raw `docker compose exec worker wp --allow-root ...` instead.
- `wp-safe.sh` is only for quick admin/read-only commands that fail when WooCommerce loads under WP < 6.8.

### Snapshot V2 rollout checklist
1. Keep `aurora_snapshot_v2_enabled=0` by default after upgrade.
2. Enable flag only in staging/pilot, run `wp aurora rebuild --indexer=all` to populate snapshot tables.
3. Verify dashboard alert shows “Snapshot cut aligned” before running feeds.
4. To rollback: `wp option update aurora_snapshot_v2_enabled 0` and continue using legacy indexes (snapshot tables stay intact for future cutover).
