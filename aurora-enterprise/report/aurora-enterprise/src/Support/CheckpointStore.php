<?php
namespace Aurora\Enterprise\Support;

use wpdb;

use function current_time;

class CheckpointStore {
    private wpdb $db;
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->db    = $wpdb;
        $this->table = $wpdb->prefix . 'aurora_queue_checkpoints';
    }

    public function update( string $channel, int $shard, string $jobId ) : void {
        $now = current_time( 'mysql', true );
        $this->db->query( $this->db->prepare(
            "INSERT INTO {$this->table} (channel, shard, last_job_uuid, updated_at)
             VALUES (%s, %d, %s, %s)
             ON DUPLICATE KEY UPDATE last_job_uuid = VALUES(last_job_uuid), updated_at = VALUES(updated_at)",
            $channel,
            $shard,
            $jobId,
            $now
        ) );
    }

    /**
     * @return array<string,array<int,array<string,string>>>
     */
    public function fetchForChannels( array $channels ) : array {
        if ( empty( $channels ) ) {
            return [];
        }
        $placeholders = implode( ',', array_fill( 0, count( $channels ), '%s' ) );
        $sql = "SELECT channel, shard, last_job_uuid, updated_at FROM {$this->table} WHERE channel IN ({$placeholders})";
        $rows = $this->db->get_results( $this->db->prepare( $sql, ...$channels ), ARRAY_A ) ?: [];
        $result = [];
        foreach ( $rows as $row ) {
            $channel = $row['channel'];
            $shard   = (int) $row['shard'];
            if ( ! isset( $result[ $channel ] ) ) {
                $result[ $channel ] = [];
            }
            $result[ $channel ][ $shard ] = [
                'last_job_uuid'      => $row['last_job_uuid'],
                'checkpoint_updated_at' => $row['updated_at'],
            ];
        }
        return $result;
    }
}
