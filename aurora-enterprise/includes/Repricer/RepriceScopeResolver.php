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
     * @param array<string,mixed> $scope
     * @return array<int>
     */
    public function select_product_ids( array $scope, int $limit, int $after_id = 0 ) : array {
        $limit = max( 1, $limit );
        $type = $scope['type'] ?? '';
        switch ( $type ) {
            case 'products':
                return $this->by_products_list( $scope, $limit, $after_id );
            case 'brand':
                return $this->by_brand( $scope, $limit, $after_id );
            case 'category':
                return $this->by_category( $scope, $limit, $after_id );
            default:
                return [];
        }
    }

    /**
     * @param array<string,mixed> $scope
     * @return array<int>
     */
    private function by_products_list( array $scope, int $limit, int $after_id ) : array {
        $ids = array_map( 'intval', $scope['products'] ?? [] );
        $ids = array_filter( $ids, static fn( $v ) => $v > $after_id );
        sort( $ids, SORT_NUMERIC );
        return array_slice( $ids, 0, $limit );
    }

    /**
     * @param array<string,mixed> $scope
     * @return array<int>
     */
    private function by_brand( array $scope, int $limit, int $after_id ) : array {
        $term_id = (int) ( $scope['brand_term_id'] ?? 0 );
        if ( $term_id <= 0 ) {
            return [];
        }
        $tax = $this->brand_taxonomy();
        if ( ! $tax ) {
            return [];
        }
        $exclude = array_map( 'intval', $scope['exclude_products'] ?? [] );
        $exclude = array_filter( $exclude, static fn( $v ) => $v > 0 );

        $placeholders = [];
        $values       = [];
        $placeholders[] = '%d';
        $values[]       = $term_id;
        $placeholders[] = '%d';
        $values[]       = $after_id;

        $excludeSql = '';
        if ( ! empty( $exclude ) ) {
            $in = implode( ',', array_map( 'intval', $exclude ) );
            $excludeSql = "AND tr.object_id NOT IN ({$in})";
        }

        $sql = "
            SELECT DISTINCT p.ID
            FROM {$this->db->posts} p
            INNER JOIN {$this->db->term_relationships} tr ON tr.object_id = p.ID
            INNER JOIN {$this->db->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
            WHERE p.post_type='product'
              AND p.post_status='publish'
              AND tt.taxonomy = %s
              AND tt.term_id = %d
              AND p.ID > %d
              {$excludeSql}
            ORDER BY p.ID ASC
            LIMIT %d
        ";
        $prepared = $this->db->prepare( $sql, array_merge( [ $tax ], $values, [ $limit ] ) );
        $ids = $this->db->get_col( $prepared );
        return array_map( 'intval', $ids ?: [] );
    }

    /**
     * @param array<string,mixed> $scope
     * @return array<int>
     */
    private function by_category( array $scope, int $limit, int $after_id ) : array {
        $term_id = (int) ( $scope['category_term_id'] ?? 0 );
        if ( $term_id <= 0 ) {
            return [];
        }
        $exclude = array_map( 'intval', $scope['exclude_products'] ?? [] );
        $exclude = array_filter( $exclude, static fn( $v ) => $v > 0 );
        $excludeSql = '';
        if ( ! empty( $exclude ) ) {
            $excludeSql = 'AND tr.object_id NOT IN (' . implode( ',', array_map( 'intval', $exclude ) ) . ')';
        }
        $sql = "
            SELECT DISTINCT p.ID
            FROM {$this->db->posts} p
            INNER JOIN {$this->db->term_relationships} tr ON tr.object_id = p.ID
            INNER JOIN {$this->db->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
            WHERE p.post_type='product'
              AND p.post_status='publish'
              AND tt.taxonomy = 'product_cat'
              AND tt.term_id = %d
              AND p.ID > %d
              {$excludeSql}
            ORDER BY p.ID ASC
            LIMIT %d
        ";
        $prepared = $this->db->prepare( $sql, $term_id, $after_id, $limit );
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
}
