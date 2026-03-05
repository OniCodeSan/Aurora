<?php
declare(strict_types=1);

namespace Aurora\Enterprise\Repricer;

use wpdb;

class RepriceChunkProcessor {
    private wpdb $db;

    public function __construct() {
        global $wpdb;
        $this->db = $wpdb;
    }

    /**
     * @return array<int>
     */
    public function fetch_next_ids( int $lastProductId, int $limit ) : array {
        $limit = max( 1, $limit );
        $prepared = $this->db->prepare(
            "SELECT ID FROM {$this->db->posts} WHERE post_type='product' AND post_status='publish' AND ID > %d ORDER BY ID ASC LIMIT %d",
            $lastProductId,
            $limit
        );
        $ids = $this->db->get_col( $prepared );
        return array_map( 'intval', $ids ?: [] );
    }
}

