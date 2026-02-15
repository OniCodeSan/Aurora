<?php
namespace Aurora\Enterprise\Queue;

use Redis;
use RedisException;

class RedisQueue implements QueueInterface {
    private Redis $redis;
    private string $namespace = 'aurora_queue:';

    public function __construct( array $config ) {
        $this->redis = new Redis();
        $this->redis->connect( $config['host'], $config['port'] );
        if ( ! empty( $config['password'] ) ) {
            $this->redis->auth( $config['password'] );
        }
        if ( isset( $config['database'] ) ) {
            $this->redis->select( $config['database'] );
        }
    }

    public function enqueue( string $channel, array $payload ) : string {
        $job_id = wp_generate_uuid4();
        $data   = [
            'id'       => $job_id,
            'channel'  => $channel,
            'payload'  => $payload,
            'attempts' => 0,
            'ts'       => time(),
        ];
        $this->redis->rPush( $this->key( $channel ), wp_json_encode( $data ) );
        return $job_id;
    }

    public function reserveBatch( string $channel, int $batchSize = 500 ) : array {
        $this->drainDueRetries( $channel );
        $jobs = [];
        for ( $i = 0; $i < $batchSize; $i++ ) {
            $raw = $this->redis->lPop( $this->key( $channel ) );
            if ( ! $raw ) {
                break;
            }
            $decoded = json_decode( $raw, true );
            if ( ! is_array( $decoded ) ) {
                continue;
            }
            $jobs[] = new Payload( $decoded['id'], $decoded['channel'], $decoded['payload'], (int) ( $decoded['attempts'] ?? 0 ) );
        }
        return $jobs;
    }

    private function drainDueRetries( string $channel ) : void {
        $retryKey = $this->key( 'retry_' . $channel );
        $now = time();
        $due = $this->redis->zRangeByScore( $retryKey, 0, $now );
        if ( empty( $due ) ) {
            return;
        }
        foreach ( $due as $item ) {
            $this->redis->zRem( $retryKey, $item );
            $this->redis->lPush( $this->key( $channel ), $item );
        }
    }

    public function ack( Payload $job ) : void {
        // No-op: job already removed from queue.
    }

    public function fail( Payload $job, string $error, bool $retryable = true ) : void {
        if ( ! $retryable || $job->attempts >= 5 ) {
            $this->redis->rPush( $this->key( 'dead_' . $job->channel ), wp_json_encode( [
                'id' => $job->id,
                'error' => $error,
            ] ) );
            return;
        }
        $retryPayload = [
            'id'       => $job->id,
            'channel'  => $job->channel,
            'payload'  => $job->data,
            'attempts' => $job->attempts + 1,
            'ts'       => time(),
        ];
        $backoff = min( pow( 2, $job->attempts + 1 ), 300 );
        $this->redis->zAdd( $this->key( 'retry_' . $job->channel ), time() + $backoff, wp_json_encode( $retryPayload ) );
    }

    public function stats() : array {
        return [
            'price'      => $this->redis->lLen( $this->key( 'price' ) ),
            'stock'      => $this->redis->lLen( $this->key( 'stock' ) ),
            'visibility' => $this->redis->lLen( $this->key( 'visibility' ) ),
        ];
    }

    private function key( string $suffix ) : string {
        return $this->namespace . $suffix;
    }
}
