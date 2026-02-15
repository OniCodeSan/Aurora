<?php
namespace Aurora\Enterprise\Queue;

use wpdb;

class DatabaseQueue implements QueueInterface {
    private wpdb $db;
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->db    = $wpdb;
        $this->table = $wpdb->prefix . 'product_index_queue';
    }

    public function enqueue( string $channel, array $payload ) : string {
        $job_id = wp_generate_uuid4();
        $this->db->insert(
            $this->table,
            [
                'job_uuid'     => $job_id,
                'queue'        => $channel,
                'payload'      => wp_json_encode( $payload ),
                'status'       => 'pending',
                'available_at' => current_time( 'mysql', true ),
                'created_at'   => current_time( 'mysql', true ),
                'updated_at'   => current_time( 'mysql', true ),
            ],
            [ '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );
        return $job_id;
    }

    public function reserveBatch( string $channel, int $batchSize = 500 ) : array {
        $now = current_time( 'mysql', true );
        $this->db->query( 'START TRANSACTION' );
        $rows = $this->db->get_results( $this->db->prepare(
            "SELECT * FROM {$this->table}
             WHERE queue = %s AND status = 'pending' AND available_at <= %s
             ORDER BY priority DESC, id ASC
             LIMIT %d FOR UPDATE SKIP LOCKED",
            $channel,
            $now,
            $batchSize
        ) );
        if ( empty( $rows ) ) {
            $this->db->query( 'COMMIT' );
            return [];
        }
        $jobs = [];
        foreach ( $rows as $row ) {
            $jobs[] = new Payload( $row->job_uuid, $row->queue, json_decode( $row->payload, true ) ?: [], (int) $row->attempts );
            $this->db->update(
                $this->table,
                [
                    'status'      => 'processing',
                    'reserved_at' => current_time( 'mysql', true ),
                    'updated_at'  => current_time( 'mysql', true ),
                ],
                [ 'id' => $row->id ],
                [ '%s', '%s', '%s' ],
                [ '%d' ]
            );
        }
        $this->db->query( 'COMMIT' );
        return $jobs;
    }

    public function ack( Payload $job ) : void {
        $this->db->delete( $this->table, [ 'job_uuid' => $job->id ], [ '%s' ] );
    }

    public function fail( Payload $job, string $error, bool $retryable = true ) : void {
        if ( $retryable && $job->attempts < 5 ) {
            $backoffSeconds = min( pow( 2, $job->attempts + 1 ), 300 );
            $available = gmdate( 'Y-m-d H:i:s', time() + $backoffSeconds );
            $this->db->update(
                $this->table,
                [
                    'status'       => 'pending',
                    'available_at' => $available,
                    'attempts'     => $job->attempts + 1,
                    'error'        => $error,
                ],
                [ 'job_uuid' => $job->id ],
                [ '%s', '%s', '%d', '%s' ],
                [ '%s' ]
            );
            return;
        }
        $this->db->update(
            $this->table,
            [
                'status'      => 'dead',
                'error'       => $error,
                'updated_at'  => current_time( 'mysql', true ),
            ],
            [ 'job_uuid' => $job->id ],
            [ '%s', '%s', '%s' ],
            [ '%s' ]
        );
    }

    public function stats() : array {
        $counts = $this->db->get_results( "SELECT queue, COUNT(*) as total FROM {$this->table} WHERE status = 'pending' GROUP BY queue" );
        $out = [];
        foreach ( $counts as $row ) {
            $out[ $row->queue ] = (int) $row->total;
        }
        return $out;
    }
}
