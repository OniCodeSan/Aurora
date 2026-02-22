<?php
namespace Aurora\Enterprise\Support;

use wpdb;

class SnapshotVersionGuard {
    private wpdb $db;

    public function __construct() {
        global $wpdb;
        $this->db = $wpdb;
    }

    /**
     * Returns detailed snapshot state (alignment + shard mismatches).
     *
     * @param string ...$tables
     * @return array<string,mixed>
     */
    public function report( string ...$tables ) : array {
        $tables = $this->resolveTables( $tables );
        $versions = [];
        $pending = false;
        $missing = [];
        foreach ( $tables as $table ) {
            $row = $this->db->get_row(
                $this->db->prepare(
                    "SELECT current_version, pending_version FROM {$this->db->prefix}aurora_snapshot_versions WHERE table_name = %s",
                    $table
                )
            );
            if ( ! $row ) {
                $missing[] = $table;
                continue;
            }
            if ( ! empty( $row->pending_version ) ) {
                $pending = true;
            }
            $versions[ $table ] = (int) $row->current_version;
        }
        $aligned = ! $pending && count( array_unique( $versions ) ) === 1 && empty( $missing );
        return [
            'aligned'             => $aligned,
            'pending_out_of_range'=> $this->pendingOutOfRange(),
            'tables'              => $versions,
            'missing'             => $missing,
        ];
    }

    public function isAligned( string ...$tables ) : bool {
        return $this->report( ...$tables )['aligned'];
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

    private function pendingOutOfRange() : int {
        $total = (int) get_option( 'aurora_total_shards', 2 );
        return (int) $this->db->get_var(
            $this->db->prepare(
                "SELECT COUNT(*) FROM {$this->db->prefix}product_index_queue WHERE status='pending' AND shard >= %d",
                $total
            )
        );
    }

    private function resolveTables( array $tables ) : array {
        if ( empty( $tables ) ) {
            return [
                $this->tableName( 'aurora_price_snapshot' ),
                $this->tableName( 'aurora_stock_snapshot' ),
                $this->tableName( 'aurora_visibility_snapshot' ),
            ];
        }
        return $tables;
    }

    private function tableName( string $base ) : string {
        return $this->db->prefix . $base;
    }
}
