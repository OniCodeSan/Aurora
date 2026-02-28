<?php
declare(strict_types=1);

namespace Aurora\Enterprise\Feed;

use Aurora\Enterprise\Support\Logger;

class FeedValidator {
    private Logger $logger;
    private int $tolerancePercent;

    public function __construct(int $tolerancePercent = 1) {
        $this->logger = new Logger();
        $this->tolerancePercent = $tolerancePercent;
    }

    public function validate(int $generated, int $snapshotCount, string $feedId, array $details = []): bool {
        $diff = abs($generated - $snapshotCount);
        $threshold = (int)ceil($snapshotCount * ($this->tolerancePercent / 100));
        if ($diff <= max(1, $threshold)) {
            return true;
        }

        $this->logger->error('feed', 'Feed validation failed', array_merge($details, [
            'feed_id'        => $feedId,
            'generated'      => $generated,
            'snapshot_count' => $snapshotCount,
            'diff'           => $diff,
        ]));
        return false;
    }
}
