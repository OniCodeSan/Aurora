# Aurora E2E LIVE Mini (Async Repricer)

End-to-end LIVE repricer test (single product) using Action Scheduler, with guardrail (floor margin), decision logging, dedup verification, and rollback.

## Prerequisites
- WordPress docker compose running at `/Users/mariano/.openclaw/workspace/wordpress`.
- Plugin mounted in container: `/var/www/html/wp-content/plugins/aurora-enterprise`.
- Action Scheduler available.

## Scripts
- `aurora_e2e_live_reset.php`
- `aurora_e2e_live_create_test_product.php`
- `aurora_e2e_live_seed_assignment.php`
- `aurora_e2e_live_tick_enqueue.php`
- `aurora_e2e_live_tick_dedup.php`
- `aurora_e2e_live_as_run_due.php`
- `aurora_e2e_live_verify_and_rollback.php`

## Full sequence (copy/paste)
```bash
cd /Users/mariano/.openclaw/workspace/wordpress

# 1) Reset repricer-only state

docker compose exec worker php /var/www/html/wp-content/plugins/aurora-enterprise/tools/e2e/aurora_e2e_live_reset.php

# 2) Create or reset test product (SKU AURORA-TEST-REPRICE-001)

docker compose exec worker php /var/www/html/wp-content/plugins/aurora-enterprise/tools/e2e/aurora_e2e_live_create_test_product.php

# 3) Seed assignment restricted to the test product

docker compose exec worker php /var/www/html/wp-content/plugins/aurora-enterprise/tools/e2e/aurora_e2e_live_seed_assignment.php

# 4) Enqueue LIVE repricer run (dry_run=false, apply mode)

docker compose exec worker php /var/www/html/wp-content/plugins/aurora-enterprise/tools/e2e/aurora_e2e_live_tick_enqueue.php

# 5) Dedup check (tick while run pending should not enqueue another run)

docker compose exec worker php /var/www/html/wp-content/plugins/aurora-enterprise/tools/e2e/aurora_e2e_live_tick_dedup.php

# 6) Run Action Scheduler (async execution)

docker compose exec worker wp action-scheduler run --hooks=aurora_ops_dispatch --batches=1 --batch-size=100

# 7) Verify run status, decisions, progress

docker compose exec worker php /var/www/html/wp-content/plugins/aurora-enterprise/tools/e2e/aurora_e2e_live_as_run_due.php

# 8) Verify price change + rollback to original (idempotent)

docker compose exec worker php /var/www/html/wp-content/plugins/aurora-enterprise/tools/e2e/aurora_e2e_live_verify_and_rollback.php
```

## Notes / Assumptions
- Cost meta key used: `_aurora_cost`.
- Guardrail is enforced by `min_margin_percent` and `min_margin_abs`; apply only occurs for `floor_margin` rule.
- Initial test product price is 11.00, cost 10.00, min margins are 20% and 2.0, so LIVE run should raise price to the floor (12.00) and log `floor_margin`.
- `aurora_e2e_live_as_run_due.php` polls up to 60s for completion (success/error) before dumping rows.
