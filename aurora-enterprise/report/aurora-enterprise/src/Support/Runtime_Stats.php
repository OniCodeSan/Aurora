<?php
namespace Aurora\Enterprise\Support;

use wpdb;

use function array_fill;
use function current_time;
use function implode;

class Runtime_Stats {
    private static ?Runtime_Stats $instance = null;

    private wpdb $db;
    private string $table;

    private function __construct() {
        global $wpdb;
        $this->db    = $wpdb;
        $this->table = $wpdb->prefix . 'aurora_runtime_stats';
        $this->maybeInstall();
    }

    public static function instance() : Runtime_Stats {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function increment( string $key, int $delta = 1 ) : void {
        $delta = (int) $delta;
        if ( 0 === $delta ) {
            return;
        }
        $now = current_time( 'mysql', true );
        $this->db->query( $this->db->prepare(
            "INSERT INTO {$this->table} (stat_key, stat_value, updated_at) VALUES (%s, %d, %s)
             ON DUPLICATE KEY UPDATE stat_value = stat_value + VALUES(stat_value), updated_at = VALUES(updated_at)",
            $key,
            $delta,
            $now
        ) );
    }

    public function get( string $key ) : int {
        $value = $this->db->get_var(
            $this->db->prepare(
                "SELECT stat_value FROM {$this->table} WHERE stat_key = %s",
                $key
            )
        );
        return (int) ( $value ?? 0 );
    }

    /**
     * @param array<int,string> $keys
     * @return array<string,int>
     */
    public function getMany( array $keys ) : array {
        if ( empty( $keys ) ) {
            return [];
        }
        $placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
        $prepared     = $this->db->prepare(
            "SELECT stat_key, stat_value FROM {$this->table} WHERE stat_key IN ({$placeholders})",
            ...$keys
        );
        $rows   = $this->db->get_results( $prepared, ARRAY_A ) ?: [];
        $values = [];
        foreach ( $rows as $row ) {
            $values[ $row['stat_key'] ] = (int) $row['stat_value'];
        }
        foreach ( $keys as $key ) {
            if ( ! isset( $values[ $key ] ) ) {
                $values[ $key ] = 0;
            }
        }
        return $values;
    }

    private function maybeInstall() : void {
        $tableExists = $this->db->get_var(
            $this->db->prepare(
                'SHOW TABLES LIKE %s',
                $this->table
            )
        );
        if ( $tableExists ) {
            return;
        }
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $this->db->get_charset_collate();
        $sql     = "CREATE TABLE {$this->table} (
            stat_key VARCHAR(64) NOT NULL,
            stat_value BIGINT NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (stat_key)
        ) {$charset};";
        dbDelta( $sql );
    }
}
