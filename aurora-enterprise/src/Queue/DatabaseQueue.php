<?php
namespace Aurora\Enterprise\Queue;

use Aurora\Enterprise\Support\Config;
use Aurora\Enterprise\Support\Runtime_Stats;
use Aurora\Enterprise\Support\CheckpointStore;
use function current_time;
use function gmdate;
use function md5;
use function pack;
use function time;
use function trim;
use function sprintf;
use function wp_generate_uuid4;
use function wp_json_encode;
use wpdb;

class DatabaseQueue implements QueueInterface {
    private wpdb $db;
    private string $table;
    private IdempotenceStore $idempotence;
    private int $leaseTtl;
    private int $idempotenceTtl;
    private int $maxAttempts = 5;
    private CheckpointStore $checkpoints;

    public function __construct() {
        global $wpdb;
        $this->db             = $wpdb;
        $this->table          = $wpdb->prefix . 'product_index_queue';
        $this->idempotence    = new IdempotenceStore();
        $this->checkpoints   = new CheckpointStore();
        $this->leaseTtl       = Config::leaseTtlSeconds();
        $this->idempotenceTtl = Config::idempotenceTtlSeconds();
        $this->idempotence->purgeExpired();
    }

    public function enqueue( string $channel, array $payload ) : string {
        $job_id      = wp_generate_uuid4();
        $payloadHash = $this->hashPayload( $channel, $payload );
        $claim       = $this->idempotence->claim( $payloadHash, $job_id, $this->idempotenceTtl );
        if ( ! $claim['inserted'] ) {
            Runtime_Stats::instance()->increment( 'dedup_hits_total' );
            return $claim['job_id'];
        }
        $now = current_time( 'mysql', true );
        $shard = $this->determineShard( $channel, $payload );
        $this->db->insert(
            $this->table,
            [
                'job_uuid'       => $job_id,
                'queue'          => $channel,
                'shard'          => $shard,
                'payload'        => wp_json_encode( $payload ),
                'payload_hash'   => $payloadHash,
                'status'         => 'pending',
                'available_at'   => $now,
                'created_at'     => $now,
                'updated_at'     => $now,
            ],
            [ '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );
        return $job_id;
    }

    public function reserveBatch( string $channel, int $batchSize = 500, ?int $shard = null ) : array {
        $now = current_time( 'mysql', true );
        $this->db->query( 'START TRANSACTION' );
        $reserveSql = "SELECT * FROM {$this->table}
             WHERE queue = %s
               AND status = 'pending'
               AND available_at <= %s";
        $params = [ $channel, $now ];
        if ( null !== $shard ) {
            $reserveSql .= ' AND shard = %d';
            $params[] = $shard;
        }
        $reserveSql .= "
             ORDER BY priority DESC, available_at ASC, id ASC
             LIMIT %d FOR UPDATE SKIP LOCKED";
        $params[] = $batchSize;
        $rows = $this->db->get_results( $this->db->prepare( $reserveSql, ...$params ) );
        if ( empty( $rows ) ) {
            $this->db->query( 'COMMIT' );
            return [];
        }
        $jobs = [];
        foreach ( $rows as $row ) {
            $leaseToken  = wp_generate_uuid4();
            $leaseExpiry = gmdate( 'Y-m-d H:i:s', time() + $this->leaseTtl );
            $this->db->update(
                $this->table,
                [
                    'status'          => 'processing',
                    'reserved_at'     => $now,
                    'updated_at'      => $now,
                    'lease_token'     => $leaseToken,
                    'lease_expires_at'=> $leaseExpiry,
                ],
                [ 'id' => $row->id ],
                [ '%s', '%s', '%s', '%s', '%s' ],
                [ '%d' ]
            );
            $jobs[] = new Payload(
                $row->job_uuid,
                $row->queue,
                json_decode( $row->payload, true ) ?: [],
                (int) $row->attempts,
                $leaseToken,
                $row->payload_hash ? (string) $row->payload_hash : null,
                isset( $row->shard ) ? (int) $row->shard : 0
            );
        }
        $this->db->query( 'COMMIT' );
        return $jobs;
    }

    public function ack( Payload $job ) : void {
        $this->db->delete(
            $this->table,
            [
                'job_uuid'   => $job->id,
                'lease_token'=> $job->leaseToken,
            ],
            [ '%s', '%s' ]
        );
        if ( $job->shard !== null ) {
            $this->checkpoints->update( $job->channel, $job->shard, $job->id );
        }
    }

    public function fail( Payload $job, string $error, bool $retryable = true ) : void {
        if ( $retryable && $job->attempts < $this->maxAttempts ) {
            $backoffSeconds = min( pow( 2, $job->attempts + 1 ), 300 );
            $available      = gmdate( 'Y-m-d H:i:s', time() + $backoffSeconds );
            $this->db->update(
                $this->table,
                [
                    'status'         => 'pending',
                    'available_at'   => $available,
                    'attempts'       => $job->attempts + 1,
                    'error'          => $error,
                    'lease_token'    => null,
                    'lease_expires_at'=> null,
                    'updated_at'     => current_time( 'mysql', true ),
                ],
                [
                    'job_uuid'    => $job->id,
                    'lease_token' => $job->leaseToken,
                ],
                [ '%s', '%s', '%d', '%s', '%s', '%s', '%s' ],
                [ '%s', '%s' ]
            );
            return;
        }
        $this->db->update(
            $this->table,
            [
                'status'         => 'dead',
                'error'          => $error,
                'lease_token'    => null,
                'lease_expires_at'=> null,
                'updated_at'     => current_time( 'mysql', true ),
            ],
            [
                'job_uuid'    => $job->id,
                'lease_token' => $job->leaseToken,
            ],
            [ '%s', '%s', '%s', '%s', '%s' ],
            [ '%s', '%s' ]
        );
    }

    public function stats() : array {
        $counts = $this->db->get_results( "SELECT queue, COUNT(*) as total FROM {$this->table} WHERE status = 'pending' GROUP BY queue" );
        $defaults = [ 'price' => 0, 'stock' => 0, 'visibility' => 0, 'feed' => 0 ];
        foreach ( $counts as $row ) {
            $defaults[ $row->queue ] = (int) $row->total;
        }
        $defaults['dead'] = (int) $this->db->get_var( "SELECT COUNT(*) FROM {$this->table} WHERE status = 'dead'" );
        return $defaults;
    }

    public function dead( ?string $queue = null, int $limit = 20 ) : array {
        $where = "status = 'dead'";
        $params = [];
        if ( $queue ) {
            $where .= ' AND queue = %s';
            $params[] = $queue;
        }
        $sql = "SELECT job_uuid, queue, error, updated_at FROM {$this->table} WHERE {$where} ORDER BY updated_at DESC LIMIT %d";
        $params[] = $limit;
        $prepared = $this->db->prepare( $sql, ...$params );
        $rows = $this->db->get_results( $prepared, ARRAY_A );
        return array_map( static function ( array $row ) {
            return [
                'id'        => $row['job_uuid'],
                'queue'     => $row['queue'],
                'error'     => $row['error'],
                'failed_at' => $row['updated_at'],
            ];
        }, $rows ?: [] );
    }

    public function retryDead( ?string $queue = null, int $limit = 100 ) : int {
        $where = [ "status = 'dead'" ];
        $params = [];
        if ( $queue ) {
            $where[] = 'queue = %s';
            $params[] = $queue;
        }
        $whereSql = implode( ' AND ', $where );
        $sql = "UPDATE {$this->table}
            SET status = 'pending', attempts = 0, available_at = %s, updated_at = %s, lease_token = NULL, lease_expires_at = NULL
            WHERE {$whereSql}
            ORDER BY updated_at DESC
            LIMIT %d";
        $params = array_merge( [ current_time( 'mysql', true ), current_time( 'mysql', true ) ], $params, [ $limit ] );
        $prepared = $this->db->prepare( $sql, ...$params );
        $this->db->query( $prepared );
        return (int) $this->db->rows_affected;
    }

    public function sweepExpiredLeases( ?string $channel, int $olderThanSeconds, ?int $shard = null ) : array {
        $requeued = 0;
        $dead     = 0;
        $threshold = gmdate( 'Y-m-d H:i:s', time() - $olderThanSeconds );
        $stats    = Runtime_Stats::instance();
        while ( true ) {
            $this->db->query( 'START TRANSACTION' );
            $params = [ $threshold ];
            $where  = "status = 'processing' AND lease_expires_at IS NOT NULL AND lease_expires_at <= %s";
            if ( $channel ) {
                $where   .= ' AND queue = %s';
                $params[] = $channel;
            }
            if ( null !== $shard ) {
                $where   .= ' AND shard = %d';
                $params[] = $shard;
            }
            $sql = "SELECT * FROM {$this->table} WHERE {$where} ORDER BY lease_expires_at ASC LIMIT 200 FOR UPDATE SKIP LOCKED";
            $rows = $this->db->get_results( $this->db->prepare( $sql, ...$params ) );
            if ( empty( $rows ) ) {
                $this->db->query( 'COMMIT' );
                break;
            }
            foreach ( $rows as $row ) {
                $message = trim( (string) ( $row->error ?? '' ) );
                $message = $message ? $message . "\n" : '';
                $message .= sprintf( 'Lease expired at %s (swept %s)', $row->lease_expires_at, current_time( 'mysql', true ) );
                if ( (int) $row->attempts < $this->maxAttempts ) {
                    $this->db->update(
                        $this->table,
                        [
                            'status'          => 'pending',
                            'available_at'    => current_time( 'mysql', true ),
                            'attempts'        => (int) $row->attempts + 1,
                            'error'           => $message,
                            'lease_token'     => null,
                            'lease_expires_at'=> null,
                            'updated_at'      => current_time( 'mysql', true ),
                        ],
                        [ 'id' => $row->id ],
                        [ '%s', '%s', '%d', '%s', '%s', '%s', '%s' ],
                        [ '%d' ]
                    );
                    $requeued++;
                    $stats->increment( 'lease_sweep_recovered_total' );
                } else {
                    $this->db->update(
                        $this->table,
                        [
                            'status'          => 'dead',
                            'error'           => $message,
                            'lease_token'     => null,
                            'lease_expires_at'=> null,
                            'updated_at'      => current_time( 'mysql', true ),
                        ],
                        [ 'id' => $row->id ],
                        [ '%s', '%s', '%s', '%s', '%s' ],
                        [ '%d' ]
                    );
                    $dead++;
                    $stats->increment( 'lease_sweep_dead_total' );
                }
            }
            $this->db->query( 'COMMIT' );
        }
        return [ 'requeued' => $requeued, 'dead' => $dead ];
    }

    private function hashPayload( string $channel, array $payload ) : string {
        $encoded = wp_json_encode( [ 'channel' => $channel, 'payload' => $payload ] ) ?: '';
        return pack( 'H*', md5( $encoded ) );
    }

    private function determineShard( string $channel, array $payload ) : int {
        $total = max( 1, Config::totalShards() );
        $productId   = (int) ( $payload['product_id'] ?? $payload['id'] ?? 0 );
        $variationId = (int) ( $payload['variation_id'] ?? 0 );
        if ( $productId <= 0 && isset( $payload['items'][0]['product_id'] ) ) {
            $productId   = (int) $payload['items'][0]['product_id'];
            $variationId = (int) ( $payload['items'][0]['variation_id'] ?? 0 );
        }
        if ( $productId <= 0 ) {
            $key = $channel . ':' . ( $payload['payload_hash'] ?? wp_json_encode( $payload ) );
        } else {
            $key = sprintf( '%s:%d:%d', $channel, $productId, $variationId );
        }
        return (int) ( crc32( $key ) % $total );
    }

}
