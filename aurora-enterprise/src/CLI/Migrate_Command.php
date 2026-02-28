<?php
namespace Aurora\Enterprise\CLI;

use WP_CLI_Command;
use WP_CLI;
use wpdb;
use Aurora\Enterprise\Support\Config;

use function array_keys;
use function current_time;
use function gmdate;
use function sprintf;
use function strtoupper;
use function wp_json_encode;

class Migrate_Command extends WP_CLI_Command {
    /**
     * Apply snapshot-v2 schema and bootstrap state.
     *
     * ## OPTIONS
     * [--dry-run]
     * [--no-rebuild]
     */
    public function snapshot_v2( array $args, array $assoc_args ) : void {
        $dryRun = isset( $assoc_args['dry-run'] );
        $skipRebuild = isset( $assoc_args['no-rebuild'] );
        $runner = new SnapshotV2Migrator( $dryRun, $skipRebuild );
        $runner->run();
    }

    /**
     * Add shard column + indexes to the queue table.
     *
     * ## OPTIONS
     * [--dry-run]
     */
    public function queue_shards( array $args, array $assoc_args ) : void {
        $dryRun = isset( $assoc_args['dry-run'] );
        $migrator = new QueueShardMigrator( $dryRun );
        $migrator->run();
    }
}

class SnapshotV2Migrator {
    private bool $dryRun;
    private bool $skipRebuild;
    private wpdb $db;
    private string $prefix;

    public function __construct( bool $dryRun, bool $skipRebuild ) {
        global $wpdb;
        $this->dryRun     = $dryRun;
        $this->skipRebuild = $skipRebuild;
        $this->db         = $wpdb;
        $this->prefix     = $wpdb->prefix;
    }

    public function run() : void {
        WP_CLI::log( 'Running snapshot-v2 migration' . ( $this->dryRun ? ' (dry-run)' : '' ) );
        $this->ensureTables();
        $this->alterQueueTable();
        $this->bootstrapVersions();
        if ( $this->dryRun ) {
            WP_CLI::log( 'Dry-run completed. No changes applied.' );
            return;
        }
        update_option( 'aurora_snapshot_v2_enabled', true );
        WP_CLI::log( 'V2 enabled' );

        if ( $this->skipRebuild ) {
            WP_CLI::log( 'Rebuild skipped (--no-rebuild).' );
        } else {
            WP_CLI::log( 'Rebuild triggered' );
            WP_CLI::log( 'Triggering full rebuild (soft cutover)...' );
            WP_CLI::runcommand( 'aurora rebuild --indexer=all' );
            WP_CLI::warning( 'Legacy tables were not migrated. If needed, run `wp aurora rebuild --indexer=all` again after verifying data.' );
        }

        $ttl = Config::leaseTtlSeconds();
        WP_CLI::log( sprintf( 'Next commands: wp aurora queue sweep-leases --all --older-than=%d && wp aurora worker --indexer=all', $ttl ) );
        WP_CLI::success( 'Snapshot-v2 migration completed.' );
    }

    private function ensureTables() : void {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $tables = [
            'aurora_snapshot_versions' => "CREATE TABLE {$this->prefix}aurora_snapshot_versions (
                table_name VARCHAR(64) NOT NULL,
                current_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
                pending_version BIGINT UNSIGNED NULL,
                previous_version BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (table_name)
            ) {$this->db->get_charset_collate()};",
            'aurora_price_snapshot' => "CREATE TABLE {$this->prefix}aurora_price_snapshot (
                product_id BIGINT UNSIGNED NOT NULL,
                scope_region VARCHAR(32) NOT NULL DEFAULT 'default',
                scope_channel VARCHAR(32) NOT NULL DEFAULT 'default',
                version BIGINT UNSIGNED NOT NULL,
                variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                sku VARCHAR(190) NOT NULL,
                currency CHAR(3) NOT NULL,
                regular_price DECIMAL(12,4) NULL,
                sale_price DECIMAL(12,4) NULL,
                effective_price DECIMAL(12,4) NULL,
                margin_percent DECIMAL(7,2) NULL,
                hash BINARY(16) NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (product_id, scope_region, scope_channel, version),
                KEY idx_version (version),
                KEY idx_version_product (version, product_id),
                KEY idx_price_product (product_id)
            ) {$this->db->get_charset_collate()};",
            'aurora_stock_snapshot' => "CREATE TABLE {$this->prefix}aurora_stock_snapshot (
                product_id BIGINT UNSIGNED NOT NULL,
                scope_region VARCHAR(32) NOT NULL DEFAULT 'default',
                scope_channel VARCHAR(32) NOT NULL DEFAULT 'default',
                version BIGINT UNSIGNED NOT NULL,
                variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                sku VARCHAR(190) NOT NULL,
                stock_qty INT NULL,
                stock_status VARCHAR(32) NOT NULL,
                warehouse VARCHAR(64) NULL,
                hash BINARY(16) NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (product_id, scope_region, scope_channel, version),
                KEY idx_version (version)
            ) {$this->db->get_charset_collate()};",
            'aurora_visibility_snapshot' => "CREATE TABLE {$this->prefix}aurora_visibility_snapshot (
                product_id BIGINT UNSIGNED NOT NULL,
                scope_region VARCHAR(32) NOT NULL DEFAULT 'default',
                scope_channel VARCHAR(32) NOT NULL DEFAULT 'default',
                version BIGINT UNSIGNED NOT NULL,
                variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                sku VARCHAR(190) NOT NULL,
                visibility VARCHAR(32) NOT NULL,
                catalog_flags LONGTEXT NULL,
                channel_mask BIGINT UNSIGNED NOT NULL DEFAULT 0,
                hash BINARY(16) NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (product_id, scope_region, scope_channel, version),
                KEY idx_version (version)
            ) {$this->db->get_charset_collate()};",
            'aurora_idempotence_cache' => "CREATE TABLE {$this->prefix}aurora_idempotence_cache (
                dedup_hash BINARY(16) NOT NULL,
                job_uuid CHAR(36) NOT NULL,
                expires_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (dedup_hash),
                KEY idx_expires (expires_at)
            ) {$this->db->get_charset_collate()};",
            'aurora_runtime_stats' => "CREATE TABLE {$this->prefix}aurora_runtime_stats (
                stat_key VARCHAR(64) NOT NULL,
                stat_value BIGINT NOT NULL DEFAULT 0,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (stat_key)
            ) {$this->db->get_charset_collate()};",
            'aurora_feed_progress' => "CREATE TABLE {$this->prefix}aurora_feed_progress (
                run_id BIGINT UNSIGNED NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                owner CHAR(36) NULL,
                snapshot_version BIGINT UNSIGNED NULL,
                file_part INT UNSIGNED NOT NULL DEFAULT 1,
                rows_written BIGINT UNSIGNED NOT NULL DEFAULT 0,
                bytes_written BIGINT UNSIGNED NOT NULL DEFAULT 0,
                last_product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                started_at DATETIME NULL,
                updated_at DATETIME NULL,
                error TEXT NULL,
                PRIMARY KEY (run_id),
                KEY status_updated (status, updated_at),
                KEY last_product (last_product_id)
            ) {$this->db->get_charset_collate()};",
            'aurora_queue_checkpoints' => "CREATE TABLE {$this->prefix}aurora_queue_checkpoints (
                channel VARCHAR(32) NOT NULL,
                shard TINYINT UNSIGNED NOT NULL,
                last_job_uuid CHAR(36) NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (channel, shard)
            ) {$this->db->get_charset_collate()};",
        ];
        foreach ( $tables as $name => $sql ) {
            if ( $this->dryRun ) {
                if ( ! $this->tableExists( $name ) ) {
                    WP_CLI::log( sprintf( '[dry-run] Would create table %s', $name ) );
                } else {
                    WP_CLI::log( sprintf( '[dry-run] Table %s already exists', $name ) );
                }
                continue;
            }
            $existed = $this->tableExists( $name );
            dbDelta( $sql );
            WP_CLI::log( sprintf( '%s table %s', $existed ? 'Verified' : 'Created', $name ) );
        }
    }

    private function alterQueueTable() : void {
        $table = $this->prefix . 'product_index_queue';
        $columns = [
            'payload_hash'   => "ALTER TABLE {$table} ADD COLUMN payload_hash BINARY(16) NULL AFTER payload",
            'lease_token'    => "ALTER TABLE {$table} ADD COLUMN lease_token CHAR(36) NULL AFTER reserved_at",
            'lease_expires_at' => "ALTER TABLE {$table} ADD COLUMN lease_expires_at DATETIME NULL AFTER lease_token",
        ];
        foreach ( $columns as $column => $sql ) {
            if ( $this->columnExists( $table, $column ) ) {
                WP_CLI::log( sprintf( 'Column %s already present on %s', $column, $table ) );
                continue;
            }
            $this->execute( $sql );
        }
        if ( ! $this->indexExists( $table, 'payload_hash' ) ) {
            $this->execute( "ALTER TABLE {$table} ADD KEY payload_hash (payload_hash)" );
        } else {
            WP_CLI::log( 'Index payload_hash already present on queue table' );
        }
    }

    private function bootstrapVersions() : void {
        $table = $this->prefix . 'aurora_snapshot_versions';
        $targets = [ 'price', 'stock', 'visibility' ];
        foreach ( $targets as $target ) {
            $sql = $this->db->prepare(
                "INSERT INTO {$table} (table_name, current_version, created_at, updated_at)
                 VALUES (%s, 1, %s, %s)
                 ON DUPLICATE KEY UPDATE table_name = table_name",
                $this->prefix . 'aurora_' . $target . '_snapshot',
                current_time( 'mysql', true ),
                current_time( 'mysql', true )
            );
            $this->execute( $sql, true );
        }
    }

    private function execute( string $sql, bool $prepared = false ) : void {
        if ( $this->dryRun ) {
            WP_CLI::log( '[dry-run] ' . $sql );
            return;
        }
        $this->db->query( $sql );
        WP_CLI::log( 'Executed: ' . $this->shorten( $sql ) );
    }

    private function shorten( string $sql ) : string {
        $trimmed = trim( preg_replace( '/\s+/', ' ', $sql ) ?? '' );
        return strlen( $trimmed ) > 120 ? substr( $trimmed, 0, 117 ) . '...' : $trimmed;
    }

    private function tableExists( string $name ) : bool {
        $table = $this->prefix . $name;
        $result = $this->db->get_var( $this->db->prepare( 'SHOW TABLES LIKE %s', $table ) );
        return ! empty( $result );
    }

    private function columnExists( string $table, string $column ) : bool {
        $result = $this->db->get_var(
            $this->db->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column )
        );
        return ! empty( $result );
    }

    private function recreateIndex( string $name, string $sql ) : void {
        if ( $this->indexExists( $name ) ) {
            $this->execute( "ALTER TABLE {$this->table} DROP INDEX {$name}" );
        }
        $this->execute( $sql );
    }

    private function indexExists( string $table, string $index ) : bool {
        $result = $this->db->get_var(
            $this->db->prepare( "SHOW INDEX FROM {$table} WHERE Key_name = %s", $index )
        );
        return ! empty( $result );
    }
}


class QueueShardMigrator {
    private bool $dryRun;
    private wpdb $db;
    private string $table;

    public function __construct( bool $dryRun ) {
        global $wpdb;
        $this->dryRun = $dryRun;
        $this->db     = $wpdb;
        $this->table  = $wpdb->prefix . 'product_index_queue';
    }

    public function run() : void {
        WP_CLI::log( 'Migrating queue table for shard support' . ( $this->dryRun ? ' (dry-run)' : '' ) );
        $this->ensureShardColumn();
        $this->ensureIndexes();
        $this->ensureCheckpointTable();
        WP_CLI::success( 'Queue shard migration completed.' );
    }

    private function ensureShardColumn() : void {
        if ( $this->columnExists( 'shard' ) ) {
            WP_CLI::log( 'Column shard already present.' );
            return;
        }
        $sql = "ALTER TABLE {$this->table} ADD COLUMN shard TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER queue";
        $this->execute( $sql );
    }

    private function ensureIndexes() : void {

        $this->recreateIndex( 'queue_shard_status', "ALTER TABLE {$this->table} ADD KEY queue_shard_status (queue, shard, status, available_at, id)" );
        $this->recreateIndex( 'shard_status', "ALTER TABLE {$this->table} ADD KEY shard_status (shard, status, available_at, id)" );
        if ( $this->indexExists( 'queue_status' ) ) {
            $this->execute( "ALTER TABLE {$this->table} DROP INDEX queue_status" );
        }
    }

    private function ensureCheckpointTable() : void {
        $table = $this->db->prefix . 'aurora_queue_checkpoints';
        $exists = $this->db->get_var( $this->db->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( $exists ) {
            WP_CLI::log( 'Checkpoint table already present.' );
            return;
        }
        $charset = $this->db->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            channel VARCHAR(32) NOT NULL,
            shard TINYINT UNSIGNED NOT NULL,
            last_job_uuid CHAR(36) NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (channel, shard)
        ) {$charset};";
        $this->execute( $sql );
    }

    private function execute( string $sql ) : void {
        if ( $this->dryRun ) {
            WP_CLI::log( '[dry-run] ' . $sql );
            return;
        }
        $this->db->query( $sql );
        WP_CLI::log( 'Executed: ' . $sql );
    }

    private function columnExists( string $column ) : bool {
        $result = $this->db->get_var(
            $this->db->prepare( "SHOW COLUMNS FROM {$this->table} LIKE %s", $column )
        );
        return ! empty( $result );
    }

    private function recreateIndex( string $name, string $sql ) : void {
        if ( $this->indexExists( $name ) ) {
            $this->execute( "ALTER TABLE {$this->table} DROP INDEX {$name}" );
        }
        $this->execute( $sql );
    }

    private function indexExists( string $index ) : bool {
        $result = $this->db->get_var(
            $this->db->prepare( "SHOW INDEX FROM {$this->table} WHERE Key_name = %s", $index )
        );
        return ! empty( $result );
    }
}
