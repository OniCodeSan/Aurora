# Phase Repricer Price Logic

Date: 2026-03-04

## Objective

Add deterministic repricer price logic with:
- strategy selection (`maintain_margin`, `match_competitor`, `beat_competitor`)
- guardrails (margin, min/max, MAP, max delta, rounding)
- richer decision audit trail persisted in `aurora_reprice_decisions`
- deterministic golden tests with fixed fixtures.

## Decision audit trail fields

Persisted fields (new/extended):
- `old_price`
- `candidate_price`
- `clamped_price`
- `rounded_price`
- `new_price`
- `delta_pct`
- `cost`
- `competitor_price`
- `min_price`
- `max_price`
- `map_price`
- `margin_before`
- `margin_after`
- `rule_applied`
- `strategy_key`
- `strategy_rule_id`
- `reason_code`
- `reason_codes_json`
- `reason`
- `audit_json`

Primary reason codes:
- `no_change`
- `strategy_maintain_margin`
- `strategy_match_competitor`
- `strategy_beat_competitor`
- `competitor_missing`
- `floor_margin_clamp`
- `floor_margin_block`
- `min_price_clamp`
- `max_price_clamp`
- `map_price_clamp`
- `max_raise_clamp`
- `max_drop_clamp`
- `rounded`

## Strategy and guardrail order

Deterministic pipeline:
1. Read inputs and normalized config.
2. Compute strategy candidate price.
3. Apply margin/min/max/MAP/delta guardrails.
4. Apply rounding.
5. Post-round min/max/MAP safety clamp.
6. Build reason code list and persisted audit payload.

Rule selection tie-break:
- by `priority` DESC
- then `rule_id` ASC.

## Evidence commands

```bash
cd /Users/mariano/.openclaw/workspace/aurora-enterprise
php tools/tests/repricer_price_logic_golden.php
```

Expected key output:
- `TOTAL=11 FAILED=0`

```bash
cd /Users/mariano/.openclaw/workspace/wordpress
docker compose exec worker php /var/www/html/wp-content/plugins/aurora-enterprise/tools/stress/seed_repricer_fixture.php
docker compose exec worker php /var/www/html/wp-content/plugins/aurora-enterprise/tools/stress/rest_call.php "/aurora/v1/repricer/run" "{\"assignment_id\":70,\"dry_run\":true,\"max_products\":1,\"strategy\":\"match_competitor\",\"competitor_price\":12.5,\"min_margin_percent\":0,\"min_margin_abs\":0}"
docker compose exec worker wp action-scheduler run --hooks=aurora_ops_dispatch --batches=2 --batch-size=100
docker compose exec worker php -r 'require "/var/www/html/wp-load.php"; global $wpdb; $runId=384; $row=$wpdb->get_row($wpdb->prepare("SELECT run_id,product_id,old_price,candidate_price,clamped_price,rounded_price,new_price,delta_pct,rule_applied,strategy_key,reason_code,reason_codes_json FROM {$wpdb->prefix}aurora_reprice_decisions WHERE run_id=%d ORDER BY id DESC LIMIT 1",$runId), ARRAY_A); print_r($row);'
```

Expected key output (example):
- `run_id=384`
- `candidate_price=12.5000`
- `new_price=12.5000`
- `strategy_key=match_competitor`
- `reason_code=strategy_match_competitor`

Block mode check:

```bash
cd /Users/mariano/.openclaw/workspace/wordpress
docker compose exec worker php /var/www/html/wp-content/plugins/aurora-enterprise/tools/stress/rest_call.php "/aurora/v1/repricer/run" "{\"assignment_id\":70,\"dry_run\":true,\"max_products\":1,\"strategy\":\"match_competitor\",\"competitor_price\":8,\"min_margin_percent\":20,\"margin_mode\":\"block\"}"
docker compose exec worker wp action-scheduler run --hooks=aurora_ops_dispatch --batches=2 --batch-size=100
```

Expected key output:
- decision `reason_code=floor_margin_block`
- `new_price` equals previous `old_price`.

## Regression check

Frontend-only stress regression remains green:

```bash
cd /Users/mariano/.openclaw/workspace/aurora-enterprise
export OPS_PROFILE=none BASE_URL="http://localhost" HOME_PATH="/" CATEGORY_PATH="/?product_cat=senza-categoria" PRODUCT_PATH="/?product=aurora-stress-reprice-fixture" SEARCH_PATH="/?s=test" DURATION="60s" RATE="5" VUS="10" MAX_VUS="20" WINDOW_MINUTES="5"
./tools/stress/run.sh
```

Expected key output:
- `RESULT=PASS`
- `ops_profile=none`
- `k6_exit_code=0`.
