<?php
namespace Aurora\Enterprise\Queue;

use Aurora\Enterprise\Support\Config;

use function strtolower;

class Queue_Manager implements QueueInterface {
    private static ?Queue_Manager $instance = null;

    private QueueInterface $driver;

    private function __construct() {
        $this->driver = $this->bootstrapDriver();
    }

    public static function instance() : Queue_Manager {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function bootstrapDriver() : QueueInterface {
        if ( defined( 'AURORA_QUEUE_DRIVER' ) ) {
            $forced = strtolower( (string) AURORA_QUEUE_DRIVER );
            if ( 'database' === $forced ) {
                return new DatabaseQueue();
            }
            if ( 'redis' === $forced && extension_loaded( 'redis' ) ) {
                return new RedisQueue( Config::redisConfig() );
            }
        }
        if ( extension_loaded( 'redis' ) ) {
            return new RedisQueue( Config::redisConfig() );
        }
        return new DatabaseQueue();
    }

    public function driver() : QueueInterface {
        return $this->driver;
    }

    public function enqueue( string $channel, array $payload ) : string {
        return $this->driver->enqueue( $channel, $payload );
    }

    public function reserveBatch( string $channel, int $batchSize = 500, ?int $shard = null, bool $debug = false ) : array {
        return $this->driver->reserveBatch( $channel, $batchSize, $shard, $debug );
    }

    public function ack( Payload $job ) : void {
        $this->driver->ack( $job );
    }

    public function fail( Payload $job, string $error, bool $retryable = true ) : void {
        $this->driver->fail( $job, $error, $retryable );
    }

    public function stats() : array {
        return $this->driver->stats();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function dead( ?string $queue = null, int $limit = 20 ) : array {
        return $this->driver->dead( $queue, $limit );
    }

    public function retryDead( ?string $queue = null, int $limit = 100 ) : int {
        return $this->driver->retryDead( $queue, $limit );
    }

    /**
     * Sweep expired leases across queue shards.
     *
     * @return array<string,mixed>
     */
    public function sweep_leases( ?string $channel = null, ?int $olderThanSeconds = null, ?int $shard = null, ?int $totalShards = null ) : array {
        $driver = $this->driver();
        if ( ! $driver instanceof DatabaseQueue ) {
            return [
                'requeued' => 0,
                'dead'     => 0,
                'message'  => 'Sweep skipped: driver is not database.',
            ];
        }

        $ttl = null !== $olderThanSeconds ? max( 0, (int) $olderThanSeconds ) : Config::leaseTtlSeconds();
        $total = null !== $totalShards ? max( 1, (int) $totalShards ) : Config::totalShards();
        $targetShards = null === $shard ? range( 0, $total - 1 ) : [ max( 0, (int) $shard ) ];

        $requeued = 0;
        $dead     = 0;
        foreach ( $targetShards as $targetShard ) {
            $result = $driver->sweepExpiredLeases( $channel, $ttl, $targetShard );
            $requeued += (int) ( $result['requeued'] ?? 0 );
            $dead     += (int) ( $result['dead'] ?? 0 );
        }

        return [
            'requeued' => $requeued,
            'dead'     => $dead,
            'ttl'      => $ttl,
            'shards'   => $targetShards,
        ];
    }
}
