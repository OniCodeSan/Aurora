# Aurora Enterprise — Project Closure (2026-03-05)

## Scopo

Consolidare e congelare il **Performance & Automation Layer** di Aurora Enterprise su WordPress/WooCommerce:
- esecuzione async (queue + run-log)
- snapshot pipeline
- feed engine
- repricer engine
- UI operativa admin + dashboard
- harness stress deterministico

Esclusioni da questo closure: go-to-market, contenuti commerciali, hardening infrastrutturale esterno al plugin.

## Stato finale

- Engine: **CHIUSO** (runtime stabile, gate stress finale superato)
- Packaging release candidate: **PRONTO** (scripts/docs inclusi in questa chiusura)

## Componenti chiave chiusi

- Async executor + Ops dispatcher + run-log (`aurora_ops_runs`)
- Queue DB + lease/idempotence + sweep
- Snapshot v2 (price/stock/visibility)
- Feed engine (enqueue/run/progress)
- Repricer (scheduler tick, rules, guardrail, decision audit trail)
- Admin UI (Dashboard, System Status, Ops, Repricer, Feed Hub)
- Stress harness (`none/repricer/full`) con report deterministico

## Gate di accettazione (stress deterministico finale)

Comando di riferimento:

```bash
OPS_PROFILE=full DURATION=60s WINDOW_MINUTES=5 BASE_URL='http://localhost' \
HOME_PATH='/' CATEGORY_PATH='/?product_cat=senza-categoria' \
PRODUCT_PATH='/?product=aurora-stress-reprice-fixture' \
SEARCH_PATH='/?s=test' ./tools/stress/run.sh
```

Evidenze finali 3/3 PASS:
- `tools/stress/out/20260305-180111/report.txt`
- `tools/stress/out/20260305-180221/report.txt`
- `tools/stress/out/20260305-180330/report.txt`

Risultato richiesto e ottenuto su tutti i 3 run:
- `RESULT=PASS`
- `k6_exit_code=0`
- `ops_errors=0`

## Commit/Tag chiave

- `d029fd3` — stabilize full deterministic gate timing
- `8c01809` — final 3x full deterministic evidence in docs
- Tag storico rilevante: `phase6_done_2026-03-04`
- Tag closure tecnica: `engine_closed_2026-03-05`

## Inventario tecnico (handoff)

### Tabelle custom Aurora

`product_price_index`, `product_price_index_staging`, `product_stock_index`, `product_stock_index_staging`, `product_visibility_index`, `product_visibility_index_staging`, `product_index_queue`, `product_index_logs`, `aurora_ops_runs`, `aurora_feed_progress`, `aurora_reprice_assignments`, `aurora_reprice_rules`, `aurora_reprice_rules_audit`, `aurora_reprice_decisions`, `aurora_reprice_progress`, `aurora_snapshot_versions`, `aurora_price_snapshot`, `aurora_stock_snapshot`, `aurora_visibility_snapshot`, `aurora_runtime_stats`, `aurora_queue_checkpoints`, `aurora_idempotence_cache`.

### Option keys principali `aurora_*`

`aurora_feed_lock`, `aurora_reprice_lock`, `aurora_last_feed_meta`, `aurora_last_rebuild_price`, `aurora_last_rebuild_stock`, `aurora_last_rebuild_visibility`, `aurora_repricer_tick_last`, `aurora_repricer_tick_cursor`, `aurora_cron_profiles`, `aurora_feed_marketplace_integrations`, `aurora_snapshot_v2_enabled`, `aurora_idempotence_ttl`, `aurora_queue_lease_ttl`, `aurora_total_shards`, `aurora_lease_sweep_cron_enabled`.

### REST routes principali (`aurora/v1`)

`/system-status`, `/ops-ui-status`, `/trigger`, `/trigger/rebuild`, `/trigger/sweep-leases`, `/trigger/feed-enqueue`, `/trigger/feed-run`, `/feed/run`, `/feed/integrations`, `/repricer/run`, `/repricer/apply`, `/repricer/run-all`, `/repricer/preview`, `/repricer/scheduler/tick`, `/repricer/assignments`, `/repricer/rules`, `/repricer/rules/(?P<id>\\d+)/preview-scope`, `/repricer/rules/options`, `/dashboard/summary`, `/dashboard/runs`, `/dashboard/events`, `/dashboard/action`, `/queue/dead`, `/queue/retry`, `/metrics`, `/cron`.

## Rischi residuali

- Nessun blocker funzionale noto lato engine.
- Rischio operativo residuo: variabilità ambientale locale (Docker host contention) mitigata da warmup/start-delay nel runner.

## Next step ufficiale

Procedere con **Release Candidate packaging** (`1.0.0-rc.1`): build zip, install test, activation/deactivation/uninstall verification, poi tag RC pubblico.
