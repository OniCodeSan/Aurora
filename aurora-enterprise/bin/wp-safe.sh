#!/usr/bin/env bash
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="${SCRIPT_DIR}/.."
WP_PATH="${REPO_ROOT}/../wordpress"

# Usage: ./bin/wp-safe.sh plugin list
# Automatically runs `wp --allow-root --skip-plugins=woocommerce` inside the worker container.

if [[ ! -d "${WP_PATH}" ]]; then
  echo "wordpress/ directory not found relative to aurora-enterprise" >&2
  exit 1
fi

pushd "${WP_PATH}" >/dev/null
command docker compose exec worker wp --allow-root --skip-plugins=woocommerce "$@"
popd >/dev/null
