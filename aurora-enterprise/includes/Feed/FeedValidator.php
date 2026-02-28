<?php
declare(strict_types=1);

namespace Aurora\Enterprise\Feed;

use Aurora\Enterprise\Support\Logger;
use wpdb;

class FeedValidator {
    private Logger $logger;
    private int $tolerancePercent;
    private wpdb $db;

    public function __construct(int $tolerancePercent = 1) {
        $this->logger = new Logger();
        $this->tolerancePercent = $tolerancePercent;
        global $wpdb;
        $this->db = $wpdb;
    }

    public function expectedCount(int $snapshotVersion): int {
        if ($snapshotVersion > 0) {
            $table = $this->db->prefix . 'aurora_price_snapshot';
            $exists = $this->db->get_var($this->db->prepare('SHOW TABLES LIKE %s', $table));
            if ($exists) {
                return (int) $this->db->get_var(
                    $this->db->prepare(
                        "SELECT COUNT(DISTINCT product_id) FROM {$table} WHERE version = %d",
                        $snapshotVersion
                    )
                );
            }
        }
        return (int) $this->db->get_var(
            "SELECT COUNT(ID) FROM {$this->db->posts} WHERE post_type = 'product' AND post_status = 'publish'"
        );
    }

    public function validate(int $expected, int $actual): array {
        $ratio = $expected > 0 ? abs($expected - $actual) / $expected : ($actual === 0 ? 0.0 : 1.0);
        return [
            'ok' => $ratio <= ($this->tolerancePercent / 100),
            'expected' => $expected,
            'actual' => $actual,
            'ratio' => $ratio,
        ];
    }

    public function logResult(string $level, int $runId, int $snapshotVersion, array $result, array $files, int $bytes): void {
        $payload = [
            'run_id' => $runId,
            'snapshot_version' => $snapshotVersion,
            'expected' => $result['expected'] ?? 0,
            'actual' => $result['actual'] ?? 0,
            'ratio' => $result['ratio'] ?? 0,
            'files' => $files,
            'bytes' => $bytes,
        ];
        if ($result['ok'] ?? false) {
            $this->logger->info('feed', 'Feed validation ok', $payload);
        } else {
            $this->logger->error('feed', 'Feed validation failed', $payload);
        }
    }
}
