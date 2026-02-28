<?php
declare(strict_types=1);

namespace Aurora\Enterprise\Feed;

use wpdb;

class FeedChunkProcessor {
    private wpdb $db;
    private string $queueTable;
    private string $checkpointTable;
    private int $batchSize;

    public function __construct(int $batchSize = 500) {
        global $wpdb;
        $this->db = $wpdb;
        $this->queueTable = $wpdb->prefix . 'product_index_queue';
        $this->checkpointTable = $wpdb->prefix . 'aurora_queue_checkpoints';
        $this->batchSize = $batchSize;
    }

    public function read_products(int $snapshotVersion, int $lastProductId, int $limit = 500): array {
        $priceTable = $this->db->prefix . 'aurora_price_snapshot';
        $prepared = $this->db->prepare(
            "SELECT product_id, sku, variation_id, currency, regular_price, sale_price, effective_price FROM {$priceTable} WHERE version = %d AND product_id > %d ORDER BY product_id ASC LIMIT %d",
            $snapshotVersion,
            $lastProductId,
            $limit
        );

        return $this->db->get_results($prepared, ARRAY_A) ?: [];
    }

    public function update_checkpoint(string $channel, int $shard, int $lastProcessedId): void {
        $now = current_time('mysql', true);
        $this->db->query($this->db->prepare(
            "INSERT INTO {$this->checkpointTable} (channel, shard, last_job_uuid, updated_at)
             VALUES (%s, %d, %s, %s)
             ON DUPLICATE KEY UPDATE last_job_uuid = VALUES(last_job_uuid), updated_at = VALUES(updated_at)",
            $channel,
            $shard,
            '',
            $now
        ));
    }
}
