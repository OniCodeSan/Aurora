<?php
declare(strict_types=1);

namespace Aurora\Enterprise\Feed;

class FeedLockManager {
    private const OPTION = 'aurora_feed_lock';
    private const DEFAULT_TTL = 900; // 15 minutes

    private int $ttl;

    public function __construct(int $ttlSeconds = self::DEFAULT_TTL) {
        $this->ttl = $ttlSeconds;
    }

    private string $optionName;

    public function __construct(int $ttlSeconds = self::DEFAULT_TTL, string $optionName = 'aurora_feed_lock') {
        $this->ttl = $ttlSeconds;
        $this->optionName = $optionName;
    }

    public function acquire_lock(int $runId, string $owner, ?int $ttlSeconds = null): bool {
        $ttl = $ttlSeconds ?? $this->ttl;
        $expires = time() + $ttl;
        $payload = json_encode([
            'run_id'     => $runId,
            'owner'      => $owner,
            'expires_at' => $expires,
        ]);
        if (false === $payload) {
            return false;
        }

        $added = add_option($this->optionName, $payload, false, 'no');
        if ($added) {
            return true;
        }

        global $wpdb;
        $sql = $wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND (option_value = '' OR (JSON_EXTRACT(option_value, '$.expires_at')) < %d OR JSON_EXTRACT(option_value, '$.owner') = %s)",
            $payload,
            $this->optionName,
            time(),
            $owner
        );
        $updated = $wpdb->query($sql);
        return $updated > 0;
    }

    public function release_lock(int $runId): bool {
        $lock = $this->get_lock();
        if (!$lock || (int)$lock['run_id'] !== $runId) {
            return false;
        }
        return delete_option(self::OPTION);
    }

    public function is_locked(): bool {
        $lock = $this->get_lock();
        return (bool)$lock && $lock['expires_at'] > time();
    }

    public function get_lock_info(): ?array {
        $lock = $this->get_lock();
        if (!$lock) {
            return null;
        }
        return [
            'run_id'     => (int)$lock['run_id'],
            'expires_at' => (int)$lock['expires_at'],
        ];
    }

    private function refresh_lock(int $runId, int $ttl): bool {
        $expiresAt = time() + $ttl;
        return $this->write_lock($runId, $expiresAt);
    }

    private function write_lock(int $runId, int $expiresAt): bool {
        $payload = json_encode([
            'run_id'     => $runId,
            'expires_at' => $expiresAt,
        ]);
        if (false === $payload) {
            return false;
        }
        return update_option(self::OPTION, $payload, false);
    }

    private function get_lock(): ?array {
        $value = get_option(self::OPTION);
        if (!$value) {
            return null;
        }
        $decoded = json_decode((string)$value, true);
        if (!is_array($decoded)) {
            return null;
        }
        return $decoded;
    }
}
