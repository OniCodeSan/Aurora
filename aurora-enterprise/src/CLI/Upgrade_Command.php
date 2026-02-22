<?php
namespace Aurora\Enterprise\CLI;

use WP_CLI_Command;
use WP_CLI;
use wpdb;
use Aurora\Enterprise\Queue\Queue_Manager;
use function current_time;

class Upgrade_Command extends WP_CLI_Command {
    public function __invoke( array $args, array $assoc_args ) : void {
        WP_CLI::log( 'Aurora upgrade starting…' );
        $schema = new Upgrade_Schema();
        $schema->run();
        $options = new Upgrade_Options();
        $options->apply();
        $this->printQueueStats();
        $this->printMetrics();
        WP_CLI::success( 'Aurora upgrade completed.' );
    }

    private function printQueueStats() : void {
        $stats = Queue_Manager::instance()->stats();
        WP_CLI::log( 'Queue stats:' );
        foreach ( $stats as $key => $value ) {
            WP_CLI::log( sprintf( '  %s: %s', $key, $value ) );
        }
    }

    private function printMetrics() : void {
        $request = new \WP_REST_Request( 'GET', '/aurora/v1/metrics' );
        $controller = new \Aurora\Enterprise\Http\Controllers\Metrics_Controller();
        $controller->register_routes();
        $response = $controller->metrics( $request );
        $data = $response instanceof \WP_REST_Response ? $response->get_data() : $response;
        WP_CLI::log( 'Metrics snapshot:' );
        WP_CLI::log( wp_json_encode( $data, JSON_PRETTY_PRINT ) );
    }
}

class Upgrade_Schema {
    private wpdb $db;
    private string $prefix;

    public function __construct() {
        global $wpdb;
        $this->db     = $wpdb;
        $this->prefix = $wpdb->prefix;
    }

    public function run() : void {
        $this->ensureSnapshotTables();
        $this->alterQueueTable();
        $this->ensureCheckpointTable();
    }

    private function ensureSnapshotTables() : void {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $this->db->get_charset_collate();
        $tables = [
            'aurora_snapshot_versions' => "CREATE TABLE {$this->prefix}aurora_snapshot_versions (
                table_name VARCHAR(64) NOT NULL,
                current_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
                pending_version BIGINT UNSIGNED NULL,
                previous_version BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (table_name)
            ) {$charset};",
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
                KEY idx_version (version)
            ) {$charset};",
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
            ) {$charset};",
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
            ) {$charset};",
            'aurora_idempotence_cache' => "CREATE TABLE {$this->prefix}aurora_idempotence_cache (
                dedup_hash BINARY(16) NOT NULL,
                job_uuid CHAR(36) NOT NULL,
                expires_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (dedup_hash),
                KEY idx_expires (expires_at)
            ) {$charset};",
            'aurora_runtime_stats' => "CREATE TABLE {$this->prefix}aurora_runtime_stats (
                stat_key VARCHAR(64) NOT NULL,
                stat_value BIGINT NOT NULL DEFAULT 0,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (stat_key)
            ) {$charset};",
        ];
        foreach ( $tables as $name => $sql ) {
            dbDelta( $sql );
            WP_CLI::log( sprintf( 'Ensured table %s', $name ) );
        }
    }

    private function alterQueueTable() : void {
        $table = $this->prefix . 'product_index_queue';
        $columns = [
            'payload_hash' => "ALTER TABLE {$table} ADD COLUMN payload_hash BINARY(16) NULL AFTER payload",
            'lease_token' => "ALTER TABLE {$table} ADD COLUMN lease_token CHAR(36) NULL AFTER reserved_at",
            'lease_expires_at' => "ALTER TABLE {$table} ADD COLUMN lease_expires_at DATETIME NULL AFTER lease_token",
        ];
        foreach ( $columns as $column => $sql ) {
            if ( ! $this->columnExists( $table, $column ) ) {
                $this->db->query( $sql );
                WP_CLI::log( sprintf( 'Added column %s', $column ) );
            }
        }
        if ( ! $this->indexExists( $table, 'payload_hash' ) ) {
            $this->db->query( "ALTER TABLE {$table} ADD KEY payload_hash (payload_hash)" );
            WP_CLI::log( 'Added payload_hash index' );
        }
    }

    private function ensureCheckpointTable() : void {
        $table = $this->prefix . 'aurora_queue_checkpoints';
        if ( $this->tableExists( $table ) ) {
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
        $this->db->query( $sql );
        WP_CLI::log( 'Created aurora_queue_checkpoints table' );
    }

    private function columnExists( string $table, string $column ) : bool {
        $result = $this->db->get_var(
            $this->db->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column )
        );
        return ! empty( $result );
    }

    private function indexExists( string $table, string $index ) : bool {
        $result = $this->db->get_var(
            $this->db->prepare( "SHOW INDEX FROM {$table} WHERE Key_name = %s", $index )
        );
        return ! empty( $result );
    }

    private function tableExists( string $table ) : bool {
        $result = $this->db->get_var(
            $this->db->prepare( 'SHOW TABLES LIKE %s', $table )
        );
        return ! empty( $result );
    }
}


class Upgrade_Options {
    public function apply() : void {
        update_option( 'aurora_snapshot_v2_enabled', 0, false );
        update_option( 'aurora_idempotence_ttl', 900, false );
        update_option( 'aurora_queue_lease_ttl', 60, false );
        update_option( 'aurora_total_shards', 2, false );
        update_option( 'aurora_lease_sweep_cron_enabled', 1, false );
        WP_CLI::log( 'Option defaults applied.' );
    }
}
