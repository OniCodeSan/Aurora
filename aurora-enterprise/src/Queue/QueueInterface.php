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
     * @return array<int,Payload>
     */
    public function reserveBatch( string $channel, int $batchSize = 500 ) : array;

    public function ack( Payload $job ) : void;

    public function fail( Payload $job, string $error, bool $retryable = true ) : void;

    /**
     * @return array<string,int|string>
     */
    public function stats() : array;
}
