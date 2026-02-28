<?php
declare(strict_types=1);

namespace Aurora\Enterprise\Feed;

/**
 * Atomic lock backed by wp_options with TTL and owner token.
 * Conservative behaviour: never overwrites a valid lock owned by another process.
 */
class FeedLockManager {
    private const OPTION_NAME = 'aurora_feed_lock';
    private const DEFAULT_TTL = 900; // 15 minutes

    private int $ttl;

    public function __construct(int $ttlSeconds = self::DEFAULT_TTL) {
        $this->ttl = $ttlSeconds;
    }

    public function acquire(string $owner, ?int $ttlSeconds = null): bool {
        $ttl = $ttlSeconds ?? $this->ttl;
        $expires = time() + $ttl;
        $payload = wp_json_encode([
            'owner'      => $owner,
            'expires_at' => $expires,
        ]);
        if (false === $payload) {
            return false;
        }

        if (add_option(self::OPTION_NAME, $payload, false, 'no')) {
            return true;
        }

        global $wpdb;
        $sql = $wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND (
                option_value = '' OR
                JSON_EXTRACT(option_value, '$.expires_at') IS NULL OR
                JSON_EXTRACT(option_value, '$.expires_at') < %d OR
                JSON_EXTRACT(option_value, '$.owner') = %s
            )",
            $payload,
            self::OPTION_NAME,
            time(),
            $owner
        );
        return (int) $wpdb->query( $sql ) > 0;
    }

    public function refresh(string $owner, ?int $ttlSeconds = null): bool {
        $ttl = $ttlSeconds ?? $this->ttl;
        $expires = time() + $ttl;
        $payload = wp_json_encode([
            'owner'      => $owner,
            'expires_at' => $expires,
        ]);
        if (false === $payload) {
            return false;
        }
        global $wpdb;
        $sql = $wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND JSON_EXTRACT(option_value, '$.owner') = %s",
            $payload,
            self::OPTION_NAME,
            $owner
        );
        return (int) $wpdb->query( $sql ) > 0;
    }

    public function release(string $owner): bool {
        $lock = $this->get();
        if ( ! $lock || ($lock['owner'] ?? '') !== $owner ) {
            return false;
        }
        return delete_option( self::OPTION_NAME );
    }

    public function get(): ?array {
        $value = get_option( self::OPTION_NAME );
        if ( ! $value ) {
            return null;
        }
        $decoded = json_decode( (string) $value, true );
        if ( ! is_array( $decoded ) ) {
            return null;
        }
        return $decoded;
    }
}
