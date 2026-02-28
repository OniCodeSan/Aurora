<?php
declare(strict_types=1);

namespace Aurora\Enterprise\Feed;

use wpdb;

/**
 * Deterministic chunk reader for feed generation.
 * Conservative: only published products; snapshot-first, index fallback.
 */
class FeedChunkProcessor {
    private wpdb $db;
    private int $batchSize;

    public function __construct(int $batchSize = 500) {
        global $wpdb;
        $this->db = $wpdb;
        $this->batchSize = $batchSize;
    }

    /**
     * @return array{rows:array<int,array<string,mixed>>,last_product_id:int,count:int}
     */
    public function fetchChunk(int $snapshotVersion, int $lastProductId, int $limit = 500): array {
        $productIds = $this->fetchProductIds($lastProductId, $limit);
        if (empty($productIds)) {
            return [ 'rows' => [], 'last_product_id' => $lastProductId, 'count' => 0 ];
        }

        $rows = $this->fetchFromSnapshots($snapshotVersion, $productIds);
        if (empty($rows)) {
            $rows = $this->fetchFromIndexes($productIds);
        }

        $last = $lastProductId;
        foreach ($productIds as $id) {
            if ($id > $last) {
                $last = $id;
            }
        }

        return [
            'rows' => $rows,
            'last_product_id' => $last,
            'count' => count($rows),
        ];
    }

    public function getBatchSize(): int {
        return $this->batchSize;
    }

    private function fetchProductIds(int $lastProductId, int $limit): array {
        $prepared = $this->db->prepare(
            "SELECT ID FROM {$this->db->posts}
             WHERE post_type = 'product' AND post_status = 'publish' AND ID > %d
             ORDER BY ID ASC LIMIT %d",
            $lastProductId,
            $limit
        );
        $ids = $this->db->get_col($prepared);
        return array_map('intval', $ids ?: []);
    }

    private function fetchFromSnapshots(int $version, array $productIds): array {
        if ($version <= 0 || empty($productIds)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($productIds), '%d'));
        $price = $this->db->prefix . 'aurora_price_snapshot';
        $stock = $this->db->prefix . 'aurora_stock_snapshot';
        $vis   = $this->db->prefix . 'aurora_visibility_snapshot';

        $sql = "SELECT p.product_id, p.sku, p.effective_price, p.updated_at,
                       s.stock_qty, s.stock_status,
                       v.visibility
                FROM {$price} p
                LEFT JOIN {$stock} s ON s.product_id = p.product_id AND s.version = p.version AND s.scope_region = p.scope_region AND s.scope_channel = p.scope_channel
                LEFT JOIN {$vis} v ON v.product_id = p.product_id AND v.version = p.version AND v.scope_region = p.scope_region AND v.scope_channel = p.scope_channel
                WHERE p.version = %d AND p.product_id IN ({$placeholders})
                ORDER BY p.product_id ASC";

        $params = array_merge([ $version ], $productIds);
        $prepared = $this->db->prepare($sql, ...$params);
        return $this->db->get_results($prepared, ARRAY_A) ?: [];
    }

    private function fetchFromIndexes(array $productIds): array {
        if (empty($productIds)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($productIds), '%d'));
        $price = $this->db->prefix . 'product_price_index';
        $stock = $this->db->prefix . 'product_stock_index';
        $vis   = $this->db->prefix . 'product_visibility_index';

        $sql = "SELECT p.product_id, p.sku, p.effective_price, p.updated_at,
                       s.stock_qty, s.stock_status,
                       v.visibility
                FROM {$price} p
                LEFT JOIN {$stock} s ON s.product_id = p.product_id AND s.variation_id = 0
                LEFT JOIN {$vis} v ON v.product_id = p.product_id AND v.variation_id = 0
                WHERE p.product_id IN ({$placeholders}) AND p.variation_id = 0
                ORDER BY p.product_id ASC";

        $prepared = $this->db->prepare($sql, ...$productIds);
        return $this->db->get_results($prepared, ARRAY_A) ?: [];
    }
}
