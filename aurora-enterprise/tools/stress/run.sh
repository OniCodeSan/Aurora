#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WP_DIR="${WP_DIR:-/Users/mariano/.openclaw/workspace/wordpress}"
PLUGIN_PATH_IN_CONTAINER="${PLUGIN_PATH_IN_CONTAINER:-/var/www/html/wp-content/plugins/aurora-enterprise}"
TEST_START_UTC="$(date -u '+%Y-%m-%d %H:%M:%S')"
export TEST_START_UTC

BASE_URL="${BASE_URL:-http://localhost}"
DURATION="${DURATION:-2m}"
RATE="${RATE:-10}"
VUS="${VUS:-20}"
MAX_VUS="${MAX_VUS:-50}"
MAX_ERROR_SAMPLES="${MAX_ERROR_SAMPLES:-20}"
OPS_PROFILE="${OPS_PROFILE:-repricer}"

HOME_PATH="${HOME_PATH:-/}"
CATEGORY_PATH="${CATEGORY_PATH:-/categoria/sample-category}"
PRODUCT_PATH="${PRODUCT_PATH:-/prodotto/sample-product}"
SEARCH_PATH="${SEARCH_PATH:-/?s=sample}"

OUT_ROOT="$ROOT_DIR/out"
RUN_ID="$(date +%Y%m%d-%H%M%S)"
OUT_DIR="$OUT_ROOT/$RUN_ID"
mkdir -p "$OUT_DIR"

if ! command -v k6 >/dev/null 2>&1; then
  echo "k6 not found. Install: https://k6.io/docs/get-started/installation/" >&2
  exit 1
fi

parse_duration_seconds() {
  local d="$1"
  if [[ "$d" =~ ^([0-9]+)s$ ]]; then
    echo "${BASH_REMATCH[1]}"
    return
  fi
  if [[ "$d" =~ ^([0-9]+)m$ ]]; then
    echo $(( ${BASH_REMATCH[1]} * 60 ))
    return
  fi
  if [[ "$d" =~ ^([0-9]+)h$ ]]; then
    echo $(( ${BASH_REMATCH[1]} * 3600 ))
    return
  fi
  echo "120"
}

OPS_DURATION_SECONDS="${OPS_DURATION_SECONDS:-$(parse_duration_seconds "$DURATION")}"
if [[ "${RESET:-0}" == "1" ]]; then
  echo "[orchestrator] reset" | tee -a "$OUT_DIR/run.log"
  (cd "$WP_DIR" && docker compose exec worker php /var/www/html/aurora_e2e_reset.php) | tee -a "$OUT_DIR/run.log"
fi

OPS_PID=""
if [[ "$OPS_PROFILE" == "none" ]]; then
  echo "[orchestrator] ops disabled (OPS_PROFILE=none)" | tee -a "$OUT_DIR/run.log"
else
  echo "[orchestrator] start ops" | tee -a "$OUT_DIR/run.log"
  LOG_FILE="$OUT_DIR/ops.log" OPS_DURATION_SECONDS="$OPS_DURATION_SECONDS" \
    WP_DIR="$WP_DIR" PLUGIN_PATH_IN_CONTAINER="$PLUGIN_PATH_IN_CONTAINER" OPS_PROFILE="$OPS_PROFILE" \
    "$ROOT_DIR/ops.sh" &
  OPS_PID=$!
fi

sleep 1

echo "[orchestrator] start k6 load" | tee -a "$OUT_DIR/run.log"
set +e
OUT_DIR="$OUT_DIR" BASE_URL="$BASE_URL" DURATION="$DURATION" RATE="$RATE" VUS="$VUS" MAX_VUS="$MAX_VUS" \
  HOME_PATH="$HOME_PATH" CATEGORY_PATH="$CATEGORY_PATH" PRODUCT_PATH="$PRODUCT_PATH" SEARCH_PATH="$SEARCH_PATH" \
  MAX_ERROR_SAMPLES="$MAX_ERROR_SAMPLES" \
  k6 run "$ROOT_DIR/load.js" \
    --summary-export "$OUT_DIR/k6-summary.json" \
    2>&1 | tee "$OUT_DIR/k6.log"
K6_EXIT=${PIPESTATUS[0]}
set -e
echo "[orchestrator] k6_exit=$K6_EXIT" | tee -a "$OUT_DIR/run.log"
PRECHECK_LINE_RAW="$(grep -m1 "\\[precheck\\]" "$OUT_DIR/k6.log" || true)"
PRECHECK_LINE="$PRECHECK_LINE_RAW"
if [[ "$PRECHECK_LINE_RAW" =~ (\[precheck\].*) ]]; then
  PRECHECK_LINE="${BASH_REMATCH[1]}"
fi
PRECHECK_FAILED=0
if [[ -n "$PRECHECK_LINE" ]]; then
  PRECHECK_FAILED=1
fi
{
  echo "k6_exit_code=$K6_EXIT"
  echo "precheck_failed=$PRECHECK_FAILED"
  echo "precheck_message=$PRECHECK_LINE"
} > "$OUT_DIR/k6-meta.txt"

echo "[orchestrator] wait ops" | tee -a "$OUT_DIR/run.log"
if [[ -n "${OPS_PID:-}" ]]; then
  wait "$OPS_PID"
fi

if grep -q "__ERROR_SAMPLE__" "$OUT_DIR/k6.log"; then
  SAMPLES_FILE="$OUT_DIR/k6-error-samples.json"
  awk -v marker="__ERROR_SAMPLE__" '{
    pos = index($0, marker);
    if (pos > 0) {
      print substr($0, pos + length(marker));
    }
  }' "$OUT_DIR/k6.log" | awk 'BEGIN{print "["} {print (NR>1?",":"") $0} END{print "]"}' > "$SAMPLES_FILE"
fi

echo "[orchestrator] collect metrics" | tee -a "$OUT_DIR/run.log"
if [[ -n "${WINDOW_MINUTES:-}" ]]; then
  (cd "$WP_DIR" && docker compose exec -e WINDOW_MINUTES="$WINDOW_MINUTES" worker php "$PLUGIN_PATH_IN_CONTAINER/tools/stress/metrics.php" > "$OUT_DIR/metrics.json")
else
  (cd "$WP_DIR" && docker compose exec worker php "$PLUGIN_PATH_IN_CONTAINER/tools/stress/metrics.php" > "$OUT_DIR/metrics.json")
fi

echo "[orchestrator] build report" | tee -a "$OUT_DIR/run.log"
BASE_URL="$BASE_URL" HOME_PATH="$HOME_PATH" CATEGORY_PATH="$CATEGORY_PATH" PRODUCT_PATH="$PRODUCT_PATH" SEARCH_PATH="$SEARCH_PATH" \
  OPS_PROFILE="$OPS_PROFILE" php "$ROOT_DIR/report.php" "$OUT_DIR/k6-summary.json" "$OUT_DIR/metrics.json" "$OUT_DIR/report.txt" | tee -a "$OUT_DIR/run.log"

echo "[orchestrator] done: $OUT_DIR" | tee -a "$OUT_DIR/run.log"
if [[ "$K6_EXIT" -ne 0 ]]; then
  exit "$K6_EXIT"
fi
