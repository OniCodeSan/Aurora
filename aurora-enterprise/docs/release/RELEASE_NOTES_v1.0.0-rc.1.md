# Aurora Enterprise — Release Notes v1.0.0-rc.1

## Cosa include la RC

- Core async engine con run-log e dispatcher ops
- Queue DB + lease sweep + idempotence cache
- Snapshot v2 (price/stock/visibility)
- Feed engine (enqueue/run/progress/metadata)
- Repricer async (scheduler tick, assignments, rules, decisions, rollback)
- Admin UI: Dashboard, System Status, Ops, Repricer, Feed Hub
- Stress harness deterministico con diagnostica report (`tools/stress`)

## Requirements

- WordPress: **>= 6.4**
- PHP: **>= 8.2**
- WooCommerce: richiesto (plugin attivo)
- Action Scheduler: richiesto (fornito da WooCommerce)

## Known issues

- Nessun blocker funzionale noto nel perimetro engine.
- Performance variabile in ambienti Docker locali saturi può alterare p99; usare sempre run multipli e host stabile.

## Upgrade notes

- Upgrade non distruttivo: tabelle/option vengono mantenute.
- Alla activation viene eseguito l'installer schema.
- Alla deactivation non vengono rimossi dati (solo unschedule hook Aurora).
- Uninstall distruttivo solo se `aurora_delete_data_on_uninstall=true`.
