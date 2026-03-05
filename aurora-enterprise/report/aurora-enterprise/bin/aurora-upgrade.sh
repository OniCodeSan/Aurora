#!/usr/bin/env bash
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
WP_PATH="${PROJECT_ROOT}/../wordpress"

info() { printf "[upgrade] %s\n" "$1"; }
run_wp() { (cd "$WP_PATH" && docker compose exec worker wp --allow-root "$@" ); }

info "Running Aurora Enterprise upgrade command"
run_wp aurora upgrade
info "Upgrade finished"
