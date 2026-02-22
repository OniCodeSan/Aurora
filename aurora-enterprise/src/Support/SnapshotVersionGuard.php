<?php
namespace Aurora\Enterprise\Support;

use wpdb;

class SnapshotVersionGuard {
    private wpdb $db;

    public function __construct() {
        global $wpdb;
        $this->db = $wpdb;
    }

    public function isAligned( string ...$tables ) : bool {
        if ( empty( $tables ) ) {
            $tables = [
                $this->tableName( 'aurora_price_snapshot' ),
                $this->tableName( 'aurora_stock_snapshot' ),
                $this->tableName( 'aurora_visibility_snapshot' ),
            ];
        }
        $versions = [];
        foreach ( $tables as $table ) {
            $row = $this->db->get_row(
                $this->db->prepare(
                    "SELECT current_version, pending_version FROM {$this->db->prefix}aurora_snapshot_versions WHERE table_name = %s",
                    $table
                )
            );
            if ( ! $row ) {
                return false;
            }
            if ( ! empty( $row->pending_version ) ) {
                return false;
            }
            $versions[] = (int) $row->current_version;
        }
        return count( array_unique( $versions ) ) === 1;
    }

    public function currentVersion( string $table ) : int {
        $row = $this->db->get_row(
            $this->db->prepare(
                "SELECT current_version FROM {$this->db->prefix}aurora_snapshot_versions WHERE table_name = %s",
                $table
            )
        );
        return $row ? (int) $row->current_version : 1;
    }

    private function tableName( string $base ) : string {
        return $this->db->prefix . $base;
    }
}
