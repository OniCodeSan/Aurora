<?php
declare(strict_types=1);

namespace Aurora\Enterprise\Repricer;

use wpdb;

class RepriceLockManager {
    private const OPTION_NAME = 'aurora_reprice_lock';
    private const DEFAULT_TTL = 900; // seconds

    private wpdb $db;

    public function __construct() {
        global $wpdb;
        $this->db = $wpdb;
    }

    public function acquire( string $owner, int $ttlSeconds = self::DEFAULT_TTL ) : bool {
        $ttlSeconds = max( 1, $ttlSeconds );
        $expiresAt  = time() + $ttlSeconds;
        $payload    = wp_json_encode( [
            'owner'      => $owner,
            'expires_at' => $expiresAt,
        ] );

        if ( add_option( self::OPTION_NAME, $payload, '', 'no' ) ) {
            return true;
        }

        $row = $this->db->get_var(
            $this->db->prepare(
                "SELECT option_value FROM {$this->db->options} WHERE option_name=%s LIMIT 1",
                self::OPTION_NAME
            )
        );
        $decoded = is_string( $row ) ? json_decode( $row, true ) : null;
        $currentOwner = $decoded['owner'] ?? '';
        $expires      = (int) ( $decoded['expires_at'] ?? 0 );

        if ( $expires < time() || $currentOwner === $owner ) {
            $updated = $this->db->update(
                $this->db->options,
                [ 'option_value' => $payload ],
                [
                    'option_name'  => self::OPTION_NAME,
                ]
            );
            return false !== $updated;
        }

        return false;
    }

    public function release( string $owner ) : void {
        $row = get_option( self::OPTION_NAME, null );
        $decoded = is_string( $row ) ? json_decode( $row, true ) : ( is_array( $row ) ? $row : [] );
        if ( ! is_array( $decoded ) ) {
            return;
        }
        if ( isset( $decoded['owner'] ) && $decoded['owner'] === $owner ) {
            delete_option( self::OPTION_NAME );
        }
    }
}
