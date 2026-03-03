# Phase 5 Closure - Security Hardening (Ops REST Controller)

Date: 2026-03-03

## Scope

Hardening applied only to:

- `aurora-enterprise/src/Ops/Rest/Ops_Controller.php`

No functional changes were made to repricer/feed business logic or to success payload shape.

## Hardened Areas

1. **Permissions / authentication**
   - `permission_callback` now returns explicit `WP_Error` with:
     - `401` when unauthenticated.
     - `403` when user lacks capability.
     - `403` when `X-WP-Nonce` is provided but invalid.
   - Capability policy: `manage_woocommerce || manage_options`.

2. **Nonce policy (optional, non-breaking)**
   - If nonce header is missing, request can proceed (capability still required).
   - If nonce header is present, it is verified.

3. **Input validation + clamp**
   - Sanitization for all critical REST params (`absint`, numeric clamp, string sanitization, whitelist enums).
   - Safer trigger payload handling by op type with bounded ranges.

4. **Trigger rate-limit**
   - Lightweight per-user/per-op throttle (default 5s) on trigger endpoints.
   - Returns `429` with `retry_after`.
   - Bypass enabled for `WP_CLI` and `AURORA_DISABLE_RATE_LIMIT` (constant or env).

5. **Error path hygiene**
   - Safer error responses/logging in scheduler tick failure paths (no raw exception leak in response).

## Verification Commands and Observed Output

### 1) PHP lint

```bash
cd /Users/mariano/.openclaw/workspace/aurora-enterprise
php -l src/Ops/Rest/Ops_Controller.php
```

Observed:

```text
No syntax errors detected in src/Ops/Rest/Ops_Controller.php
```

### 2) REST smoke (authenticated, success path unchanged)

```bash
cd /Users/mariano/.openclaw/workspace/wordpress
docker compose exec worker php /var/www/html/wp-content/plugins/aurora-enterprise/tools/stress/rest_call.php "/aurora/v1/repricer/scheduler/tick" "{\"only_assignment_id\":0}"
```

Observed:

```json
{"ok":true,"route":"\/aurora\/v1\/repricer\/scheduler\/tick","response":{"ok":1,"mode":"windows","in_window":true,"enqueued":2,"skipped":0,"skipped_out_of_window":0,"cursor":70,"last_error":null}}
```

### 3) REST unauthorized (anonymous -> 401)

```bash
cd /Users/mariano/.openclaw/workspace/wordpress
docker compose exec worker sh -lc "curl -s -i -X POST 'http://wordpress/?rest_route=/aurora/v1/repricer/scheduler/tick' -H 'Content-Type: application/json' --data '{\"only_assignment_id\":0}'"
```

Observed key lines:

```text
HTTP/1.1 401 Unauthorized
{"code":"aurora_rest_unauthorized","message":"Authentication required.","data":{"status":401}}
```

### 4) REST no-capability (authenticated non-admin -> 403)

```bash
cd /Users/mariano/.openclaw/workspace/wordpress
TMP_UID=$(docker compose exec worker wp user create aurora_phase5_subscriber aurora_phase5_subscriber@example.com --role=subscriber --user_pass='TempP@ssw0rd!' --porcelain)
docker compose exec worker wp eval "wp_set_current_user((int) ${TMP_UID}); if (!defined('REST_REQUEST')) { define('REST_REQUEST', true); } \$req = new WP_REST_Request('POST', '/aurora/v1/repricer/scheduler/tick'); \$req->set_param('only_assignment_id', 0); \$res = rest_do_request(\$req); if (\$res instanceof WP_REST_Response) { \$data = \$res->get_data(); echo wp_json_encode(['status' => \$res->get_status(), 'data' => \$data]) . \"\n\"; }"
docker compose exec worker wp user delete "${TMP_UID}" --yes --reassign=1
```

Observed:

```json
{"status":403,"data":{"code":"aurora_rest_forbidden","message":"Insufficient permissions.","data":{"status":403}}}
```

### 5) Nonce invalid (header present -> 403)

```bash
cd /Users/mariano/.openclaw/workspace/wordpress
docker compose exec worker sh -lc "curl -s -i -X POST 'http://wordpress/?rest_route=/aurora/v1/repricer/scheduler/tick' -H 'Content-Type: application/json' -H 'X-WP-Nonce: invalid' --data '{\"only_assignment_id\":0}'"
```

Observed key lines:

```text
HTTP/1.1 403 Forbidden
{"code":"rest_cookie_invalid_nonce","message":"Controllo cookie fallito","data":{"status":403}}
```

### 6) Rate limit (second trigger call -> 429)

```bash
cd /Users/mariano/.openclaw/workspace/wordpress
docker compose exec worker php /var/www/html/wp-content/plugins/aurora-enterprise/tools/stress/rest_call.php "/aurora/v1/trigger/feed-enqueue" "{\"chunk_size\":1000}"
docker compose exec worker php /var/www/html/wp-content/plugins/aurora-enterprise/tools/stress/rest_call.php "/aurora/v1/trigger/feed-enqueue" "{\"chunk_size\":1000}"
```

Observed second response:

```json
{"ok":true,"route":"\/aurora\/v1\/trigger\/feed-enqueue","response":{"code":"aurora_ops_rate_limited","message":"Too many requests, retry shortly.","data":{"status":429,"retry_after":5}}}
```

### 7) Action Scheduler smoke

```bash
cd /Users/mariano/.openclaw/workspace/wordpress
docker compose exec worker wp action-scheduler run --hooks=aurora_ops_dispatch --batches=1 --batch-size=50
```

Observed:

```text
Found 4 scheduled tasks
...
1 batch executed.
```

### 8) Phase 4 Scenario B regression guard (must remain PASS)

```bash
cd /Users/mariano/.openclaw/workspace/aurora-enterprise
export OPS_PROFILE=none BASE_URL="http://localhost" HOME_PATH="/" CATEGORY_PATH="/?product_cat=senza-categoria" PRODUCT_PATH="/?product=aurora-stress-reprice-fixture" SEARCH_PATH="/?s=test"
export DURATION="60s" RATE="5" VUS="10" MAX_VUS="20" WINDOW_MINUTES="5"
./tools/stress/run.sh ; echo "exit=$?"
```

Evidence run:

- Out dir: `aurora-enterprise/tools/stress/out/20260303-174016`
- `exit=0`
- `report.txt` key lines:
  - `RESULT=PASS`
  - `k6_exit_code=0`
  - `precheck_failed=0`
  - `ops_errors=0`
  - `ops_errors_filtered=0`
  - `timing_unit=ms`

## Notes

- Rate-limit bypass for operational tools:
  - Automatic when running under `WP_CLI`.
  - Manual override with `AURORA_DISABLE_RATE_LIMIT=1` (env or constant).
- Success-path JSON fields were kept backward compatible; hardening affects permission/validation/error paths.
