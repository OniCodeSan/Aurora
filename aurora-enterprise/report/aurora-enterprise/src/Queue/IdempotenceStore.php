<?php
namespace Aurora\Enterprise\Queue;

use wpdb;

use function current_time;
use function gmdate;
use function time;

class IdempotenceStore {
    private wpdb $db;
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->db    = $wpdb;
        $this->table = $wpdb->prefix . 'aurora_idempotence_cache';
    }

    /**
     * @return array{inserted:bool,job_id:string}
     */
    public function claim( string $hashBinary, string $jobId, int $ttlSeconds ) : array {
        $now     = current_time( 'mysql', true );
        $expires = gmdate( 'Y-m-d H:i:s', time() + $ttlSeconds );
        $insert  = $this->db->query(
            $this->db->prepare(
                "INSERT IGNORE INTO {$this->table} (dedup_hash, job_uuid, expires_at, created_at)
                 VALUES (%s, %s, %s, %s)",
                $hashBinary,
                $jobId,
                $expires,
                $now
            )
        );
        if ( $insert && $this->db->rows_affected > 0 ) {
            return [ 'inserted' => true, 'job_id' => $jobId ];
        }
        $existing = $this->db->get_var(
            $this->db->prepare(
                "SELECT job_uuid FROM {$this->table} WHERE dedup_hash = %s AND expires_at >= %s",
                $hashBinary,
                $now
            )
        );
        if ( $existing ) {
            return [ 'inserted' => false, 'job_id' => (string) $existing ];
        }
        $this->db->delete( $this->table, [ 'dedup_hash' => $hashBinary ], [ '%s' ] );
        $this->db->query(
            $this->db->prepare(
                "INSERT INTO {$this->table} (dedup_hash, job_uuid, expires_at, created_at)
                 VALUES (%s, %s, %s, %s)",
                $hashBinary,
                $jobId,
                $expires,
                $now
            )
        );
        return [ 'inserted' => true, 'job_id' => $jobId ];
    }

    public function release( ?string $hashBinary ) : void {
        if ( empty( $hashBinary ) ) {
            return;
        }
        $this->db->delete( $this->table, [ 'dedup_hash' => $hashBinary ], [ '%s' ] );
    }

    public function purgeExpired() : void {
        $this->db->query(
            $this->db->prepare(
                "DELETE FROM {$this->table} WHERE expires_at < %s",
                current_time( 'mysql', true )
            )
        );
    }
}
