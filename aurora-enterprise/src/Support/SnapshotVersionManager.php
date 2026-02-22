<?php
namespace Aurora\Enterprise\Support;

use RuntimeException;
use wpdb;

use function current_time;

class SnapshotVersionManager {
    private wpdb $db;
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->db    = $wpdb;
        $this->table = $wpdb->prefix . 'aurora_snapshot_versions';
    }

    public function allocatePendingVersion( string $tableName ) : int {
        $this->db->query( 'START TRANSACTION' );
        try {
            $this->ensureRow( $tableName );
            $row = $this->db->get_row(
                $this->db->prepare(
                    "SELECT current_version, pending_version FROM {$this->table} WHERE table_name = %s FOR UPDATE",
                    $tableName
                )
            );
            if ( ! $row ) {
                throw new RuntimeException( 'Unable to read snapshot version metadata.' );
            }
            if ( ! empty( $row->pending_version ) ) {
                $this->db->query( 'ROLLBACK' );
                throw new RuntimeException( 'Snapshot batch already pending for ' . $tableName );
            }
            $nextVersion = (int) $row->current_version + 1;
            $now         = current_time( 'mysql', true );
            $this->db->update(
                $this->table,
                [
                    'pending_version' => $nextVersion,
                    'updated_at'      => $now,
                ],
                [ 'table_name' => $tableName ],
                [ '%d', '%s' ],
                [ '%s' ]
            );
            $this->db->query( 'COMMIT' );
            return $nextVersion;
        } catch ( RuntimeException $exception ) {
            throw $exception;
        } catch ( \Throwable $exception ) {
            $this->db->query( 'ROLLBACK' );
            throw $exception;
        }
    }

    public function activatePendingVersion( string $tableName, int $version ) : void {
        $this->db->query( 'START TRANSACTION' );
        try {
            $row = $this->db->get_row(
                $this->db->prepare(
                    "SELECT current_version, pending_version FROM {$this->table} WHERE table_name = %s FOR UPDATE",
                    $tableName
                )
            );
            if ( ! $row ) {
                throw new RuntimeException( 'Snapshot metadata missing for ' . $tableName );
            }
            if ( (int) $row->pending_version !== $version ) {
                $this->db->query( 'ROLLBACK' );
                throw new RuntimeException( 'Pending version mismatch for ' . $tableName );
            }
            $now = current_time( 'mysql', true );
            $this->db->update(
                $this->table,
                [
                    'previous_version' => (int) $row->current_version,
                    'current_version'  => $version,
                    'pending_version'  => null,
                    'updated_at'       => $now,
                ],
                [ 'table_name' => $tableName ],
                [ '%d', '%d', '%s', '%s' ],
                [ '%s' ]
            );
            $this->db->query( 'COMMIT' );
        } catch ( RuntimeException $exception ) {
            throw $exception;
        } catch ( \Throwable $exception ) {
            $this->db->query( 'ROLLBACK' );
            throw $exception;
        }
    }

    public function currentVersion( string $tableName ) : int {
        $row = $this->db->get_row(
            $this->db->prepare(
                "SELECT current_version FROM {$this->table} WHERE table_name = %s",
                $tableName
            )
        );
        if ( ! $row ) {
            return 1;
        }
        return (int) $row->current_version;
    }

    public function clearPending( string $tableName ) : void {
        $this->db->update(
            $this->table,
            [
                'pending_version' => null,
                'updated_at'      => current_time( 'mysql', true ),
            ],
            [ 'table_name' => $tableName ],
            [ '%s', '%s' ],
            [ '%s' ]
        );
    }

    private function ensureRow( string $tableName ) : void {
        $this->db->query(
            $this->db->prepare(
                "INSERT INTO {$this->table} (table_name) VALUES (%s) ON DUPLICATE KEY UPDATE table_name = VALUES(table_name)",
                $tableName
            )
        );
    }
}
