#!/usr/bin/env bash
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
WP_PATH="${PROJECT_ROOT}/../wordpress"

info() { printf "[upgrade] %s\n" "$1"; }
run_wp() { (cd "$WP_PATH" && docker compose exec worker wp --allow-root "$@" ); }

info "Running Aurora Enterprise upgrade" 
run_wp aurora migrate snapshot-v2 || true
run_wp option update aurora_snapshot_v2_enabled 0
run_wp option update aurora_idempotence_ttl 900
run_wp option update aurora_queue_lease_ttl 60
run_wp option update aurora_total_shards 2
run_wp option update aurora_lease_sweep_cron_enabled 1
info "Sanity: queue stats"
run_wp aurora queue status
info "Sanity: metrics"
run_wp eval '$request = new WP_REST_Request("GET", "/aurora/v1/metrics");
$controller = new Aurora\Enterprise\Http\Controllers\Metrics_Controller();
$controller->register_routes();
$response = $controller->metrics($request);
print_r($response->get_data());'
