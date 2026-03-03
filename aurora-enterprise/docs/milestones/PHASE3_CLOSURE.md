# Phase 3 Closure — Async Repricer Engine

## Objective
Ship a deterministic, async repricer pipeline with a verified live mini E2E and a stable repricer-only stress profile.

## What’s included
- Repricer scheduler sets `payload.run_id` at enqueue time (prevents “Missing run_id”).
- Repricer scheduler tick supports `only_assignment_id` for deterministic runs.
- Deterministic stress fixture (`AURORA-TEST-REPRICE-001` + `stress_reprice_assignment`).
- Stress report timings normalized to milliseconds with explicit unit flags.

## Evidence commands (expected key outputs)
E2E live mini:
```
cd /Users/mariano/.openclaw/workspace/wordpress
docker compose exec worker php /var/www/html/wp-content/plugins/aurora-enterprise/tools/e2e/aurora_e2e_live_reset.php
docker compose exec worker php /var/www/html/wp-content/plugins/aurora-enterprise/tools/e2e/aurora_e2e_live_create_test_product.php
docker compose exec worker php /var/www/html/wp-content/plugins/aurora-enterprise/tools/e2e/aurora_e2e_live_seed_assignment.php
docker compose exec worker php /var/www/html/wp-content/plugins/aurora-enterprise/tools/e2e/aurora_e2e_live_tick_enqueue.php
docker compose exec worker php /var/www/html/wp-content/plugins/aurora-enterprise/tools/e2e/aurora_e2e_live_tick_dedup.php
docker compose exec worker wp action-scheduler run --hooks=aurora_ops_dispatch --batches=5 --batch-size=100
docker compose exec worker php /var/www/html/wp-content/plugins/aurora-enterprise/tools/e2e/aurora_e2e_live_as_run_due.php
docker compose exec worker php /var/www/html/wp-content/plugins/aurora-enterprise/tools/e2e/aurora_e2e_live_verify_and_rollback.php
```
Expected:
- `repricer_run_response` includes `run_id`.
- `run_row` status `success`.
- `decisions_count >= 1` and `rule_applied` present.
- `RESULT=PASS`.

Repricer-only stress:
```
cd /Users/mariano/.openclaw/workspace/aurora-enterprise
export OPS_PROFILE=repricer DURATION=2m RATE=10
./tools/stress/run.sh
```
Expected in `tools/stress/out/<timestamp>/report.txt`:
- `ops_profile=repricer`
- `ops_errors_filtered=0`
- `timing_unit=ms` (and `timing_converted_from=s` when conversion applied)

Expected in `tools/stress/out/<timestamp>/ops.log`:
- `[fixture] assignment_id=...`
- tick payload includes `only_assignment_id`.

## Out of scope / next phase
- Frontend performance under load (Phase 4).
