<?php
declare(strict_types=1);

namespace Aurora\Enterprise\Repricer;

use wpdb;

class RepriceScopeResolver {
    private wpdb $db;

    public function __construct() {
        global $wpdb;
        $this->db = $wpdb;
    }

    /**
     * Resolve product IDs for an assignment scope + filters.
     *
     * @param array<string,mixed> $scope
     * @param array<string,mixed> $filters
     * @return array<int>
     */
    public function resolve_product_ids( array $scope, array $filters, int $limit, int $after_id = 0 ) : array {
        $limit = max( 1, $limit );

        // Safety cap from filters.
        $filtersLimit = isset( $filters['limit_products'] ) ? (int) $filters['limit_products'] : 0;
        if ( $filtersLimit > 0 ) {
            $limit = min( $limit, $filtersLimit );
        }

        $type = $scope['type'] ?? '';
        switch ( $type ) {
            case 'products':
                return $this->by_products_list( $scope, $filters, $limit, $after_id );
            case 'brand':
                return $this->by_taxonomy_scope( $scope, $filters, $limit, $after_id, $this->brand_taxonomy() );
            case 'category':
                return $this->by_taxonomy_scope( $scope, $filters, $limit, $after_id, 'product_cat' );
            default:
                return [];
        }
    }

    /**
     * @param array<string,mixed> $scope
     * @return array<int>
     */
    private function by_products_list( array $scope, array $filters, int $limit, int $after_id ) : array {
        $ids = array_map( 'intval', $scope['products'] ?? [] );
        $ids = array_filter( $ids, static fn( $v ) => $v > $after_id );
        sort( $ids, SORT_NUMERIC );
        $ids = $this->apply_excludes_array( $ids, $filters );
        return array_slice( $ids, 0, $limit );
    }

    /**
     * @param array<string,mixed> $scope
     * @param array<string,mixed> $filters
     */
    private function by_taxonomy_scope( array $scope, array $filters, int $limit, int $after_id, ?string $taxonomy ) : array {
        if ( ! $taxonomy ) {
            return [];
        }

        $termKey = 'brand' === $scope['type'] ? 'brand_term_id' : 'category_term_id';
        $termId = (int) ( $scope[ $termKey ] ?? 0 );
        if ( $termId <= 0 ) {
            return [];
        }

        $termIds = [ $termId ];
        $includeChildren = ! empty( $filters['include_children'] ) && $taxonomy === 'product_cat';
        if ( $includeChildren ) {
            $children = get_term_children( $termId, $taxonomy );
            if ( is_array( $children ) && ! empty( $children ) ) {
                $termIds = array_map( 'intval', array_merge( $termIds, $children ) );
                $termIds = array_values( array_unique( array_filter( $termIds, static fn( $v ) => $v > 0 ) ) );
            }
        }

        $idsIn = implode( ',', array_map( 'intval', $termIds ) );
        if ( '' === $idsIn ) {
            return [];
        }

        $sql = [];
        $sql[] = 'SELECT DISTINCT p.ID';
        $sql[] = "FROM {$this->db->posts} p";
        $sql[] = "INNER JOIN {$this->db->term_relationships} tr ON tr.object_id = p.ID";
        $sql[] = "INNER JOIN {$this->db->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id";

        $joinsFilters = $this->build_filter_joins( $filters );
        $sql = array_merge( $sql, $joinsFilters['joins'] );

        $where = [
            "p.post_type='product'",
            "p.post_status='publish'",
            "p.ID > %d",
            "tt.taxonomy = %s",
            "tt.term_id IN ({$idsIn})",
        ];

        $params = [ $after_id, $taxonomy ];

        $where = array_merge( $where, $joinsFilters['where'] );

        $excludeSql = $this->build_exclusions_sql( $filters, 'p.ID' );
        if ( '' !== $excludeSql ) {
            $where[] = $excludeSql;
        }

        $sql[] = 'WHERE ' . implode( ' AND ', $where );
        $sql[] = 'ORDER BY p.ID ASC';
        $sql[] = $this->limitSql( $limit );

        $prepared = $this->db->prepare( implode( ' ', $sql ), $params );
        $ids = $this->db->get_col( $prepared );
        return array_map( 'intval', $ids ?: [] );
    }

    private function brand_taxonomy() : ?string {
        if ( taxonomy_exists( 'product_brand' ) ) {
            return 'product_brand';
        }
        if ( taxonomy_exists( 'pa_brand' ) ) {
            return 'pa_brand';
        }
        return null;
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{joins:array<int,string>, where:array<int,string>}
     */
    private function build_filter_joins( array $filters ) : array {
        $joins = [];
        $where = [];

        $priceAlias = 'pm_price';
        $joins[] = "LEFT JOIN {$this->db->postmeta} {$priceAlias} ON {$priceAlias}.post_id = p.ID AND {$priceAlias}.meta_key = '_price'";

        $costAliasA = 'pm_cost_a';
        $costAliasB = 'pm_cost_b';
        $joins[] = "LEFT JOIN {$this->db->postmeta} {$costAliasA} ON {$costAliasA}.post_id = p.ID AND {$costAliasA}.meta_key = '_aurora_cost'";
        $joins[] = "LEFT JOIN {$this->db->postmeta} {$costAliasB} ON {$costAliasB}.post_id = p.ID AND {$costAliasB}.meta_key = '_cost'";

        $stockAlias = 'pm_stock';
        $joins[] = "LEFT JOIN {$this->db->postmeta} {$stockAlias} ON {$stockAlias}.post_id = p.ID AND {$stockAlias}.meta_key = '_stock_status'";

        $lookup = $this->db->prefix . 'wc_product_meta_lookup';
        $hasLookup = $this->db->get_var( $this->db->prepare( 'SHOW TABLES LIKE %s', $lookup ) );
        if ( $hasLookup ) {
            $joins[] = "LEFT JOIN {$lookup} wcpl ON wcpl.product_id = p.ID";
        }

        if ( ! empty( $filters['only_in_stock'] ) ) {
            if ( $hasLookup ) {
                $where[] = "(wcpl.stock_status = 'instock')";
            } else {
                $where[] = "({$stockAlias}.meta_value = 'instock')";
            }
        }

        if ( ! empty( $filters['only_visible'] ) ) {
            if ( $hasLookup ) {
                $where[] = "(wcpl.catalog_visibility <> 'hidden')";
            } else {
                $visAlias = 'pm_vis';
                $joins[] = "LEFT JOIN {$this->db->postmeta} {$visAlias} ON {$visAlias}.post_id = p.ID AND {$visAlias}.meta_key = '_visibility'";
                $where[] = "(COALESCE({$visAlias}.meta_value,'catalog') <> 'hidden')";
            }
        }

        $requireCost = ! empty( $filters['require_cost'] );
        if ( $requireCost ) {
            $where[] = "(COALESCE(NULLIF({$costAliasA}.meta_value,''), NULLIF({$costAliasB}.meta_value,'')) IS NOT NULL AND CAST(COALESCE(NULLIF({$costAliasA}.meta_value,''), NULLIF({$costAliasB}.meta_value,'')) AS DECIMAL(18,4)) > 0)";
        }

        $requirePrice = ! empty( $filters['require_price'] );
        if ( $requirePrice ) {
            $where[] = "({$priceAlias}.meta_value IS NOT NULL AND {$priceAlias}.meta_value <> '' AND CAST({$priceAlias}.meta_value AS DECIMAL(18,4)) > 0)";
        }

        if ( isset( $filters['min_price'] ) && $filters['min_price'] !== null && $filters['min_price'] !== '' ) {
            $where[] = $this->db->prepare( "CAST({$priceAlias}.meta_value AS DECIMAL(18,4)) >= %f", (float) $filters['min_price'] );
        }

        if ( isset( $filters['max_price'] ) && $filters['max_price'] !== null && $filters['max_price'] !== '' ) {
            $where[] = $this->db->prepare( "CAST({$priceAlias}.meta_value AS DECIMAL(18,4)) <= %f", (float) $filters['max_price'] );
        }

        return [ 'joins' => $joins, 'where' => $where ];
    }

    /**
     * @param array<string,mixed> $filters
     */
    private function build_exclusions_sql( array $filters, string $field = 'p.ID' ) : string {
        $clauses = [];

        if ( ! empty( $filters['exclude_products'] ) ) {
            $ids = array_values( array_filter( array_map( 'intval', (array) $filters['exclude_products'] ), static fn( $v ) => $v > 0 ) );
            if ( ! empty( $ids ) ) {
                $clauses[] = $field . ' NOT IN (' . implode( ',', $ids ) . ')';
            }
        }

        if ( ! empty( $filters['exclude_categories'] ) ) {
            $cats = array_values( array_filter( array_map( 'intval', (array) $filters['exclude_categories'] ), static fn( $v ) => $v > 0 ) );
            if ( ! empty( $cats ) ) {
                $in = implode( ',', $cats );
                $clauses[] = "NOT EXISTS (SELECT 1 FROM {$this->db->term_relationships} ex_tr JOIN {$this->db->term_taxonomy} ex_tt ON ex_tt.term_taxonomy_id = ex_tr.term_taxonomy_id WHERE ex_tr.object_id = p.ID AND ex_tt.taxonomy='product_cat' AND ex_tt.term_id IN ({$in}))";
            }
        }

        $brandTax = $this->brand_taxonomy();
        if ( $brandTax && ! empty( $filters['exclude_brands'] ) ) {
            $brands = array_values( array_filter( array_map( 'intval', (array) $filters['exclude_brands'] ), static fn( $v ) => $v > 0 ) );
            if ( ! empty( $brands ) ) {
                $in = implode( ',', $brands );
                $clauses[] = "NOT EXISTS (SELECT 1 FROM {$this->db->term_relationships} exb_tr JOIN {$this->db->term_taxonomy} exb_tt ON exb_tt.term_taxonomy_id = exb_tr.term_taxonomy_id WHERE exb_tr.object_id = p.ID AND exb_tt.taxonomy='{$brandTax}' AND exb_tt.term_id IN ({$in}))";
            }
        }

        return implode( ' AND ', $clauses );
    }

    /**
     * @param array<int> $ids
     * @param array<string,mixed> $filters
     * @return array<int>
     */
    private function apply_excludes_array( array $ids, array $filters ) : array {
        if ( empty( $filters['exclude_products'] ) ) {
            return $ids;
        }
        $exclude = array_map( 'intval', (array) $filters['exclude_products'] );
        return array_values( array_diff( $ids, $exclude ) );
    }

    private function limitSql( int $limit ) : string {
        return $this->db->prepare( 'LIMIT %d', max( 1, $limit ) );
    }
}
