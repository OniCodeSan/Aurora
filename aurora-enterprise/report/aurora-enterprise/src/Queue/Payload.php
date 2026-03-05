<?php
namespace Aurora\Enterprise\Queue;

class Payload {
    public function __construct(
        public readonly string $id,
        public readonly string $channel,
        public readonly array $data,
        public int $attempts = 0,
        public ?string $leaseToken = null,
        public ?string $payloadHash = null,
        public ?int $shard = null
    ) {}
}
