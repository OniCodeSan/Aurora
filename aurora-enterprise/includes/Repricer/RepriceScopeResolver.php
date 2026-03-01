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

        $rawType = (string) ( $scope['scope_type'] ?? ( $scope['type'] ?? '' ) );
        $type = strtolower( trim( $rawType ) );
        if ( $type === 'product' ) {
            $type = 'products';
        } elseif ( $type === 'category' || $type === 'categories' ) {
            $type = 'product_cat';
        }
        switch ( $type ) {
            case 'products':
                return $this->by_products_list( $scope, $filters, $limit, $after_id );
            case 'brand':
            case 'product_brand':
                return $this->by_taxonomy_scope( $scope, $filters, $limit, $after_id, $this->brand_taxonomy() );
            case 'product_cat':
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
        $ids = $this->apply_excludes_array( $ids, $filters, $scope );
        if ( empty( $ids ) ) {
            return [];
        }
        $ids = $this->filter_require_price( $ids, $filters );
        $ids = $this->filter_require_cost( $ids, $filters );
        $ids = $this->apply_price_bounds( $ids, $filters );
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

        $scopeType = (string) ( $scope['scope_type'] ?? ( $scope['type'] ?? '' ) );
        $termKey   = str_starts_with( $scopeType, 'brand' ) ? 'brand_term_id' : 'category_term_id';
        $termId    = (int) ( $scope[ $termKey ] ?? 0 );
        if ( $termId <= 0 && ! empty( $scope['categories'] ) && is_array( $scope['categories'] ) ) {
            $termId = (int) reset( $scope['categories'] );
        }
        if ( $termId <= 0 ) {
            return [];
        }

        $termIds = [ $termId ];
        $includeChildren = ( ! empty( $filters['include_children'] ) || ! empty( $scope['include_children'] ) ) && $taxonomy === 'product_cat';
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

        $excludeSql = $this->build_exclusions_sql( $filters, 'p.ID', $scope );
        if ( '' !== $excludeSql ) {
            $where[] = $excludeSql;
        }

        $sql[] = 'WHERE ' . implode( ' AND ', $where );
        $sql[] = 'ORDER BY p.ID ASC';
        $sql[] = $this->limitSql( $limit );

        $prepared = $this->db->prepare( implode( ' ', $sql ), $params );
        $ids = $this->db->get_col( $prepared );
        $ids = $ids ?: [];
        $ids = array_map( 'intval', $ids );
        $ids = $this->apply_excludes_array( $ids, $filters, $scope );
        if ( empty( $ids ) ) {
            return [];
        }
        $ids = $this->filter_require_price( $ids, $filters );
        $ids = $this->filter_require_cost( $ids, $filters );
        $ids = $this->apply_price_bounds( $ids, $filters );
        return $ids;
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
    private function build_exclusions_sql( array $filters, string $field = 'p.ID', array $scope = [] ) : string {
        $clauses = [];

        $excludeProducts = [];
        if ( ! empty( $filters['exclude_products'] ) ) {
            $excludeProducts = array_merge( $excludeProducts, (array) $filters['exclude_products'] );
        }
        if ( ! empty( $scope['exclude_products'] ) ) {
            $excludeProducts = array_merge( $excludeProducts, (array) $scope['exclude_products'] );
        }
        $excludeProducts = array_values( array_filter( array_map( 'intval', $excludeProducts ), static fn( $v ) => $v > 0 ) );
        if ( ! empty( $excludeProducts ) ) {
            $clauses[] = $field . ' NOT IN (' . implode( ',', $excludeProducts ) . ')';
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
    private function apply_excludes_array( array $ids, array $filters, array $scope = [] ) : array {
        $exclude = [];
        if ( ! empty( $filters['exclude_products'] ) ) {
            $exclude = array_merge( $exclude, (array) $filters['exclude_products'] );
        }
        if ( ! empty( $scope['exclude_products'] ) ) {
            $exclude = array_merge( $exclude, (array) $scope['exclude_products'] );
        }
        $exclude = array_values( array_filter( array_map( 'intval', $exclude ), static fn( $v ) => $v > 0 ) );
        if ( empty( $exclude ) ) {
            return $ids;
        }
        return array_values( array_diff( $ids, $exclude ) );
    }

    /**
     * @param array<int> $ids
     * @return array<int>
     */
    private function filter_require_price( array $ids, array $filters ) : array {
        if ( empty( $filters['require_price'] ) || empty( $ids ) ) {
            return $ids;
        }
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $sql = $this->db->prepare(
            "SELECT post_id FROM {$this->db->postmeta} WHERE meta_key='_price' AND post_id IN ({$placeholders}) AND meta_value IS NOT NULL AND meta_value <> '' AND CAST(meta_value AS DECIMAL(18,4)) > 0",
            $ids
        );
        $rows = $this->db->get_col( $sql );
        return array_values( array_map( 'intval', $rows ?: [] ) );
    }

    /**
     * @param array<int> $ids
     * @return array<int>
     */
    private function filter_require_cost( array $ids, array $filters ) : array {
        if ( empty( $filters['require_cost'] ) || empty( $ids ) ) {
            return $ids;
        }
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $sql = $this->db->prepare(
            "SELECT DISTINCT post_id FROM {$this->db->postmeta} WHERE post_id IN ({$placeholders}) AND meta_key IN ('_aurora_cost','_cost') AND meta_value IS NOT NULL AND meta_value <> '' AND CAST(meta_value AS DECIMAL(18,4)) > 0",
            $ids
        );
        $rows = $this->db->get_col( $sql );
        return array_values( array_map( 'intval', $rows ?: [] ) );
    }

    /**
     * @param array<int> $ids
     * @param array<string,mixed> $filters
     * @return array<int>
     */
    private function apply_price_bounds( array $ids, array $filters ) : array {
        if ( empty( $ids ) ) {
            return $ids;
        }
        $min = isset( $filters['min_price'] ) && $filters['min_price'] !== '' ? (float) $filters['min_price'] : null;
        $max = isset( $filters['max_price'] ) && $filters['max_price'] !== '' ? (float) $filters['max_price'] : null;
        if ( null === $min && null === $max ) {
            return $ids;
        }

        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $bounds = [];
        $params = $ids;
        if ( null !== $min ) {
            $bounds[] = 'CAST(meta_value AS DECIMAL(18,4)) >= %f';
            $params[] = $min;
        }
        if ( null !== $max ) {
            $bounds[] = 'CAST(meta_value AS DECIMAL(18,4)) <= %f';
            $params[] = $max;
        }
        $where = implode( ' AND ', $bounds );
        $sql = $this->db->prepare(
            "SELECT post_id FROM {$this->db->postmeta} WHERE meta_key='_price' AND post_id IN ({$placeholders}) AND {$where}",
            $params
        );
        $rows = $this->db->get_col( $sql );
        return array_values( array_map( 'intval', $rows ?: [] ) );
    }

    private function limitSql( int $limit ) : string {
        return $this->db->prepare( 'LIMIT %d', max( 1, $limit ) );
    }
}
