<?php
namespace Aurora\Enterprise\Queue;

interface QueueInterface {
    /**
     * @param string $channel price|stock|visibility
     * @param array<string,mixed> $payload
     * @return string job id
     */
    public function enqueue( string $channel, array $payload ) : string;

    /**
     * @param string $channel
     * @param int $batchSize
     * @param int|null $shard
     * @return array<int,Payload>
     */
    public function reserveBatch( string $channel, int $batchSize = 500, ?int $shard = null ) : array;

    public function ack( Payload $job ) : void;

    public function fail( Payload $job, string $error, bool $retryable = true ) : void;

    /**
     * @return array<string,int|string>
     */
    public function stats() : array;

    /**
     * @return array<int,array<string,mixed>>
     */
    public function dead( ?string $queue = null, int $limit = 20 ) : array;

    public function retryDead( ?string $queue = null, int $limit = 100 ) : int;
}
