<?php
namespace Aurora\Enterprise\Queue;

use Aurora\Enterprise\Support\Config;

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
        if ( extension_loaded( 'redis' ) ) {
            return new RedisQueue( Config::redisConfig() );
        }
        return new DatabaseQueue();
    }

    public function enqueue( string $channel, array $payload ) : string {
        return $this->driver->enqueue( $channel, $payload );
    }

    public function reserveBatch( string $channel, int $batchSize = 500 ) : array {
        return $this->driver->reserveBatch( $channel, $batchSize );
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
}
