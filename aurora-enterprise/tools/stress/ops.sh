#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WP_DIR="${WP_DIR:-/Users/mariano/.openclaw/workspace/wordpress}"
PLUGIN_PATH_IN_CONTAINER="${PLUGIN_PATH_IN_CONTAINER:-/var/www/html/wp-content/plugins/aurora-enterprise}"

DURATION_SECONDS="${OPS_DURATION_SECONDS:-120}"
TRIGGER_INTERVAL_SECONDS="${OPS_TRIGGER_INTERVAL_SECONDS:-15}"
AS_INTERVAL_SECONDS="${OPS_AS_INTERVAL_SECONDS:-10}"
OPS_PROFILE="${OPS_PROFILE:-repricer}"

FEED_CHUNK_SIZE="${FEED_CHUNK_SIZE:-1000}"
FEED_BATCH="${FEED_BATCH:-100}"
FEED_MAX_LOOPS="${FEED_MAX_LOOPS:-1}"
SWEEP_CHANNEL="${SWEEP_CHANNEL:-all}"
REBUILD_INDEXER="${REBUILD_INDEXER:-price}"

LOG_FILE="${LOG_FILE:-$ROOT_DIR/out/ops.log}"
ONLY_ASSIGNMENT_ID="${ONLY_ASSIGNMENT_ID:-0}"

mkdir -p "$(dirname "$LOG_FILE")"

compose_exec() {
  (cd "$WP_DIR" && docker compose exec worker "$@")
}

rest_call() {
  local route="$1"
  local payload="$2"
  compose_exec php "$PLUGIN_PATH_IN_CONTAINER/tools/stress/rest_call.php" "$route" "$payload"
}

if [[ "$OPS_PROFILE" == "repricer" ]]; then
  fixture_out="$(compose_exec php "$PLUGIN_PATH_IN_CONTAINER/tools/stress/seed_repricer_fixture.php" 2>&1 | tee -a "$LOG_FILE")"
  ONLY_ASSIGNMENT_ID="$(echo "$fixture_out" | sed -n 's/.*assignment_id=\([0-9][0-9]*\).*/\1/p' | head -n 1)"
  if [[ -z "$ONLY_ASSIGNMENT_ID" ]]; then
    ONLY_ASSIGNMENT_ID=0
    echo "[fixture] parse_failed output=${fixture_out}" >> "$LOG_FILE"
  else
    echo "[fixture] assignment_id=${ONLY_ASSIGNMENT_ID}" >> "$LOG_FILE"
  fi
fi

trigger_loop() {
  local end_ts=$(( $(date +%s) + DURATION_SECONDS ))
  while [ $(date +%s) -lt $end_ts ]; do
    if [[ "$OPS_PROFILE" == "full" ]]; then
      echo "[trigger] feed-enqueue" >> "$LOG_FILE"
      rest_call "/aurora/v1/trigger/feed-enqueue" "{\"chunk_size\":${FEED_CHUNK_SIZE}}" >> "$LOG_FILE"

      echo "[trigger] feed-run" >> "$LOG_FILE"
      rest_call "/aurora/v1/trigger/feed-run" "{\"batch\":${FEED_BATCH},\"max_loops\":${FEED_MAX_LOOPS}}" >> "$LOG_FILE"

      echo "[trigger] sweep-leases" >> "$LOG_FILE"
      rest_call "/aurora/v1/trigger/sweep-leases" "{\"channel\":\"${SWEEP_CHANNEL}\"}" >> "$LOG_FILE"

      echo "[trigger] rebuild" >> "$LOG_FILE"
      rest_call "/aurora/v1/trigger/rebuild" "{\"indexer\":\"${REBUILD_INDEXER}\"}" >> "$LOG_FILE"
    else
      echo "[trigger] repricer-scheduler-tick" >> "$LOG_FILE"
      if [[ "$ONLY_ASSIGNMENT_ID" -gt 0 ]]; then
        echo "[trigger] repricer-scheduler-tick payload={\"only_assignment_id\":${ONLY_ASSIGNMENT_ID}}" >> "$LOG_FILE"
        rest_call "/aurora/v1/repricer/scheduler/tick" "{\"only_assignment_id\":${ONLY_ASSIGNMENT_ID}}" >> "$LOG_FILE"
      else
        echo "[trigger] repricer-scheduler-tick payload={}" >> "$LOG_FILE"
        rest_call "/aurora/v1/repricer/scheduler/tick" "{}" >> "$LOG_FILE"
      fi

      echo "[trigger] sweep-leases" >> "$LOG_FILE"
      rest_call "/aurora/v1/trigger/sweep-leases" "{\"channel\":\"${SWEEP_CHANNEL}\"}" >> "$LOG_FILE"
    fi

    sleep "$TRIGGER_INTERVAL_SECONDS"
  done
}

scheduler_loop() {
  local end_ts=$(( $(date +%s) + DURATION_SECONDS ))
  while [ $(date +%s) -lt $end_ts ]; do
    echo "[action-scheduler] run" >> "$LOG_FILE"
    compose_exec wp action-scheduler run --hooks=aurora_ops_dispatch --batches=1 --batch-size=100 >> "$LOG_FILE" 2>&1 || true
    sleep "$AS_INTERVAL_SECONDS"
  done
}

trigger_loop &
trigger_pid=$!

scheduler_loop &
scheduler_pid=$!

wait "$trigger_pid"
wait "$scheduler_pid"
