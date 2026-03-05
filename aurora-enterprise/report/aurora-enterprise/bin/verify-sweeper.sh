#!/usr/bin/env bash
set -euo pipefail

WP_BIN=${WP_BIN:-wp}
CHANNEL=${CHANNEL:-price}
COUNT=${COUNT:-60}
SIMULATE_AFTER=${SIMULATE_AFTER:-20}
BATCH=${BATCH:-50}
ALLOW_DEAD=${ALLOW_DEAD_JOBS:-0}

run() {
  echo "+ $WP_BIN $*"
  $WP_BIN "$@"
}

status_count() {
  local status=$1
  $WP_BIN eval "global \$wpdb; \$table = \$wpdb->prefix . 'product_index_queue'; echo (int) \$wpdb->get_var( \$wpdb->prepare( \"SELECT COUNT(*) FROM {\$table} WHERE queue = %s AND status = %s\", '$CHANNEL', '$status' ) );"
}

expired_processing() {
  $WP_BIN eval "global \$wpdb; \$table = \$wpdb->prefix . 'product_index_queue'; echo (int) \$wpdb->get_var( \$wpdb->prepare( \"SELECT COUNT(*) FROM {\$table} WHERE queue = %s AND status = 'processing' AND lease_expires_at IS NOT NULL AND lease_expires_at <= UTC_TIMESTAMP()\", '$CHANNEL' ) );"
}

run aurora test seed-queue --channel="$CHANNEL" --count="$COUNT" --reset

set +e
$WP_BIN aurora worker --indexer="$CHANNEL" --batch="$BATCH" --simulate-crash-after="$SIMULATE_AFTER"
WORKER_EXIT=$?
set -e
if [[ $WORKER_EXIT -eq 0 ]]; then
  echo "Worker completed successfully but a simulated crash was expected." >&2
  exit 1
fi

PENDING_BEFORE=$(status_count 'pending')
PROCESSING_BEFORE=$(status_count 'processing')
EXPIRED_PROCESSING=$(expired_processing)
DEAD_BEFORE=$(status_count 'dead')

printf 'Before sweep => pending=%s processing=%s expired_processing=%s dead=%s\n' "$PENDING_BEFORE" "$PROCESSING_BEFORE" "$EXPIRED_PROCESSING" "$DEAD_BEFORE"

run aurora queue sweep-leases --channel="$CHANNEL" --older-than=0

PENDING_AFTER=$(status_count 'pending')
PROCESSING_AFTER=$(status_count 'processing')
DEAD_AFTER=$(status_count 'dead')

printf 'After sweep => pending=%s processing=%s dead=%s\n' "$PENDING_AFTER" "$PROCESSING_AFTER" "$DEAD_AFTER"

if [[ $PROCESSING_AFTER -ne 0 ]]; then
  echo "Zombies detected after sweep (processing=${PROCESSING_AFTER})." >&2
  exit 2
fi

if [[ $DEAD_AFTER -ne 0 && $ALLOW_DEAD -ne 1 ]]; then
  echo "Dead jobs detected after sweep (dead=${DEAD_AFTER})." >&2
  exit 3
fi

echo "Sweeper E2E verification passed."
