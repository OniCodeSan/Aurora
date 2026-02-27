#!/usr/bin/env bash
set -euo pipefail

WP_BIN=${WP_BIN:-wp}
LABEL=${LABEL:-}
MIN_SEEDED=${MIN_SEEDED:-1}

if [[ -z "${LABEL}" ]]; then
  echo "LABEL is required. Example: LABEL=test-52k $0" >&2
  exit 1
fi

run() {
  echo "+ ${WP_BIN} --allow-root $*"
  ${WP_BIN} --allow-root "$@"
}

query_scalar() {
  local sql="$1"
  ${WP_BIN} --allow-root db query "${sql}" --skip-column-names | tr -d '[:space:]'
}

run aurora queue status

SEEDED=$(query_scalar "SELECT COUNT(*) FROM wp_postmeta WHERE meta_key = '_aurora_seed_batch' AND meta_value = '${LABEL}';")
PRODUCTS=$(query_scalar "SELECT COUNT(*) FROM wp_posts WHERE post_type = 'product' AND post_status = 'publish';")
LOOKUP=$(query_scalar "SELECT COUNT(*) FROM wp_wc_product_meta_lookup;")
SIMPLE_REL=$(query_scalar "SELECT COUNT(*) FROM wp_term_relationships tr INNER JOIN wp_term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id INNER JOIN wp_terms t ON t.term_id = tt.term_id WHERE tt.taxonomy = 'product_type' AND t.slug = 'simple';")
SIMPLE_COUNT=$(query_scalar "SELECT tt.count FROM wp_term_taxonomy tt INNER JOIN wp_terms t ON t.term_id = tt.term_id WHERE tt.taxonomy = 'product_type' AND t.slug = 'simple' LIMIT 1;")

printf 'seeded(label=%s)=%s products=%s lookup_rows=%s simple_rel=%s simple_count=%s\n' "${LABEL}" "${SEEDED}" "${PRODUCTS}" "${LOOKUP}" "${SIMPLE_REL}" "${SIMPLE_COUNT}"

if [[ "${SEEDED}" -lt "${MIN_SEEDED}" ]]; then
  echo "Seed verification failed: seeded=${SEEDED} is below MIN_SEEDED=${MIN_SEEDED} for label ${LABEL}" >&2
  exit 2
fi

if [[ "${SIMPLE_REL}" != "${SIMPLE_COUNT}" ]]; then
  echo "Seed verification failed: product_type simple count mismatch (relationships=${SIMPLE_REL}, term_taxonomy.count=${SIMPLE_COUNT})" >&2
  exit 3
fi

echo "Seed smoke verification passed."
