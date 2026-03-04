# Phase 6 Closure - Aurora Admin UX/UI

Status: COMPLETE

Date: 2026-03-04

## Objective

Stabilizzare la UX admin di Aurora per `System Status` e `Repricer` con:

- stato operativo leggibile e non "debug dump"
- gestione robusta autenticazione/permessi (`401/403`) e rate-limit (`429`)
- polling con backoff
- compatibilita` con permalink WordPress plain (`?rest_route=...`)

## Scope Implementato

- Nuove pagine admin:
  - `Aurora -> System Status` (`src/Admin/System_Status_Page.php`)
  - `Aurora -> Ops` (`src/Admin/Ops_Page.php`)
  - `Aurora -> Repricer` (`src/Admin/Repricer_Page.php`)
- Nuovi asset dedicati:
  - `assets/admin/js/system-status.js`
  - `assets/admin/css/system-status.css`
  - `assets/admin/js/aurora-admin.js`
  - `assets/admin/css/aurora-admin.css`
  - `assets/admin/js/aurora-repricer.js`
  - `assets/admin/css/aurora-repricer.css`
- Routing/admin bootstrap aggiornato in `src/Support/Bootstrap.php`.
- Endpoint read-only UI arricchito in `src/Ops/Rest/Ops_Controller.php` (`GET /aurora/v1/ops-ui-status`).

## Fix Critici di Stabilizzazione

1. **Permalink plain support**
   - I client JS costruiscono URL REST in due modalita`:
     - pretty: `/wp-json/aurora/v1/...`
     - plain: `/index.php?rest_route=/aurora/v1/...`
   - Risolto blocco pagina su `Caricamento...` in siti senza permalink structure.

2. **JS runtime resiliency**
   - Aggiunta funzione mancante `setHealthBadge` su System Status.
   - Migliorata gestione auth failure su polling (lock UI solo dopo failure consecutive).

3. **Incidents readability**
   - Message sintetico in card/tabella (niente JSON esteso in chiaro).
   - Raw tecnico mantenuto in `<details>` collassabile.

4. **Session handling**
   - Overlay sessione scaduta con blocco UI in caso di `401/403`.
   - Retry/backoff/cooldown per risposte `429`.

## Gate Checklist

- [x] Admin pages render senza fatal PHP
- [x] Polling/backoff implementati
- [x] `401/403` gestiti con stato bloccato/overlay
- [x] `429` con countdown/cooldown azioni
- [x] Compatibilita` con permalink plain verificata
- [x] Endpoint status UI operativo

## Evidenze (comandi ripetibili)

```bash
cd /Users/mariano/.openclaw/workspace/aurora-enterprise
php -l src/Admin/System_Status_Page.php
php -l src/Admin/Repricer_Page.php
php -l src/Admin/Ops_Page.php
php -l src/Support/Bootstrap.php
php -l src/Ops/Rest/Ops_Controller.php
```

```bash
cd /Users/mariano/.openclaw/workspace/wordpress
docker compose exec worker wp plugin status aurora-enterprise
docker compose exec worker php /var/www/html/wp-content/plugins/aurora-enterprise/tools/stress/rest_call.php "/aurora/v1/ops-ui-status" "{}"
docker compose exec worker wp eval 'wp_set_current_user(1); ob_start(); (new \Aurora\Enterprise\Admin\System_Status_Page())->render_page(); ob_end_clean(); echo "system_status_render_ok\n";'
docker compose exec worker wp eval 'wp_set_current_user(1); ob_start(); (new \Aurora\Enterprise\Admin\Repricer_Page())->render_page(); ob_end_clean(); echo "repricer_render_ok\n";'
docker compose exec worker wp option get permalink_structure
docker compose exec worker wp eval 'echo rest_url("aurora/v1/") . PHP_EOL;'
```

Output chiave osservato:

- Plugin: `Status: Active`
- REST UI status: `{"ok":true,...}`
- Render smoke:
  - `system_status_render_ok`
  - `repricer_render_ok`
- Permalink mode plain:
  - `permalink_structure` vuoto
  - `rest_url("aurora/v1/") => http://localhost:8080/index.php?rest_route=/aurora/v1/`

Log tecnico locale (non versionato): `/tmp/phase6_smoke.txt`
