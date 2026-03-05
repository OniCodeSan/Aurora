# Phase 6 Plan - Aurora Admin UX/UI

## Scope

- Admin page: `Aurora -> System Status`
- Admin page: `Aurora -> Ops`
- Read-only REST support for UI diagnostics:
  - `GET /aurora/v1/ops-ui-status`
- No change to existing trigger success payloads or backend trigger behavior.

## UI Features

1. **System Status page**
   - Summary cards:
     - queue dead count
     - ops errors filtered/total
     - health badge (`OK/WARN/FAIL`)
     - last error
   - Last run timestamps for:
     - repricer tick
     - repricer run
     - feed enqueue / feed run
     - rebuild / sweep leases
   - Refresh button.
   - Polling every 5s, exponential backoff to 60s on failures.
   - Clean handling for `401/403/429`.

2. **Ops page**
   - Safe trigger controls:
     - feed enqueue
     - feed run
     - rebuild (indexer selector)
     - sweep leases (channel selector)
     - repricer scheduler tick (`only_assignment_id`)
   - Pending-state disable while request is in-flight.
   - `429` handling with `retry_after` countdown and disabled controls.
   - Success/error notices with sanitized messages.
   - Recent activity table (last 20 ops runs).

3. **Client robustness**
   - Shared vanilla JS fetch wrapper.
   - Optional nonce header (`X-WP-Nonce` sent only if available).
   - Structured handling of WP REST errors (`401/403/429`).
   - No admin-page fatal if REST is unavailable.

## Verification Commands

```bash
cd /Users/mariano/.openclaw/workspace/aurora-enterprise
php -l src/Ops/Rest/Ops_Controller.php
php -l src/Admin/System_Status_Page.php
php -l src/Admin/Ops_Page.php
php -l src/Support/Bootstrap.php
```

```bash
cd /Users/mariano/.openclaw/workspace/wordpress
docker compose exec worker wp plugin status aurora-enterprise
docker compose exec worker php /var/www/html/wp-content/plugins/aurora-enterprise/tools/stress/rest_call.php "/aurora/v1/ops-ui-status" "{}"
```

```bash
cd /Users/mariano/.openclaw/workspace/aurora-enterprise
export OPS_PROFILE=none
export BASE_URL="http://localhost"
export HOME_PATH="/"
export CATEGORY_PATH="/?product_cat=senza-categoria"
export PRODUCT_PATH="/?product=aurora-stress-reprice-fixture"
export SEARCH_PATH="/?s=test"
export DURATION="60s"
export RATE="5"
export VUS="10"
export MAX_VUS="20"
export WINDOW_MINUTES="5"
./tools/stress/run.sh ; echo "exit=$?"
```

## Test Plan Dashboard

### Smoke test REST dashboard

```bash
cd /Users/mariano/.openclaw/workspace/wordpress
docker compose exec worker php /var/www/html/wp-content/plugins/aurora-enterprise/tools/smoke/aurora_dashboard_rest_smoke.php
```

Expected:
- `GET /dashboard/summary` -> `PASS` with keys `status,reasons,kpis,alerts`
- `GET /dashboard/runs` -> `PASS` with `runs[]`
- `GET /dashboard/events` -> `PASS` with `events[]`
- `POST /dashboard/action` second call -> `429` rate limit
- final line `RESULT=PASS`

### Edge-case checklist

| Case | Verify |
| --- | --- |
| Nonce/session scaduta | UI mostra notice sessione scaduta e blocca contenuti |
| Action Scheduler assente | KPI `past_due` mostrato come `—` + alert warning |
| WooCommerce non attivo | Summary `status=error` + alert `woocommerce_inactive` |
| Tabelle custom assenti | Nessun fatal SQL, fallback run/event attivo |
| Rate limit action | 2 POST uguali entro 5s -> seconda `429` |
| Transient invalidation | Dopo action valida, summary/runs ricalcolati |
