# Aurora Enterprise — Architecture One Pager

## Diagramma flusso (testuale)

1. Evento/trigger REST o UI (`/aurora/v1/trigger*`, repricer routes)
2. `Ops_Run_Manager` crea riga in `aurora_ops_runs`
3. Action Scheduler esegue hook `aurora_ops_dispatch`
4. `Ops_Dispatcher` instrada a executor (feed/rebuild/sweep/repricer)
5. Executor aggiorna progress/log/tabelle snapshot o decisioni
6. UI legge stato da provider (`system-status`, `ops-ui-status`, `dashboard/*`)

## Tabelle DB principali

- `aurora_ops_runs`: run-log canonico operazioni async
- `product_index_queue`: coda DB job price/stock/visibility/feed
- `aurora_feed_progress`: stato avanzamento feed generation
- `aurora_reprice_assignments`: configurazioni scheduler repricer
- `aurora_reprice_rules`: regole pricing strutturate
- `aurora_reprice_decisions`: audit trail decisioni repricer
- `aurora_reprice_progress`: progress run repricer
- `aurora_*_snapshot` + `aurora_snapshot_versions`: snapshot v2 e versioning
- `aurora_runtime_stats`: contatori runtime/dedup

## Entry points

- REST: namespace `aurora/v1` (status, trigger, repricer, dashboard, queue/metrics)
- Action Scheduler: `aurora_ops_dispatch`, `aurora_repricer_tick`
- Admin UI: Dashboard, System Status, Ops, Repricer, Feed Hub

## Idempotence / Dedup (5 righe)

- Ogni operazione ha record run-log (`aurora_ops_runs`) con stato lifecycle.
- Dispatch payload include `run_id` per correlazione deterministica.
- Repricer scheduler evita enqueue duplicato se run pending per assignment.
- Queue usa `aurora_idempotence_cache` + lease token/expiration.
- Stress harness valida `ops_errors=0` e assenza loop retry nei profili finali.
