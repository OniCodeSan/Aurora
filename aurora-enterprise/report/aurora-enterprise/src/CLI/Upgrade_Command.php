<?php
namespace Aurora\Enterprise\CLI;

use WP_CLI_Command;
use WP_CLI;
use wpdb;
use Aurora\Enterprise\Queue\Queue_Manager;
use function current_time;

class Upgrade_Command extends WP_CLI_Command {
    private SnapshotShardDoctor $doctor;

    public function __invoke( array $args, array $assoc_args ) : void {
        $this->doctor = new SnapshotShardDoctor();
        WP_CLI::log( 'Aurora upgrade starting…' );
        $schema = new Upgrade_Schema();
        $schema->run();
        $options = new Upgrade_Options();
        $options->apply();
        $this->doctor->checkShards();
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
        $this->ensureRepriceDecisions();
        $this->ensureRepriceAssignments();
        $this->ensureRepriceRules();
        $this->ensureRepriceProgress();
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
                PRIMARY KEY (product_id, variation_id, scope_region, scope_channel, version),
                KEY idx_version (version),
                KEY idx_version_product (version, product_id),
                KEY idx_price_product (product_id)
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
                PRIMARY KEY (product_id, variation_id, scope_region, scope_channel, version),
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
                PRIMARY KEY (product_id, variation_id, scope_region, scope_channel, version),
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
            ) {$charset};",
        ];
        foreach ( $tables as $name => $sql ) {
            dbDelta( $sql );
            $this->ensurePrimaryKey( $name );
            WP_CLI::log( sprintf( 'Ensured table %s', $name ) );
        }
    }

    private function ensurePrimaryKey( string $tableKey ) : void {
        $watch = [ 'aurora_price_snapshot', 'aurora_stock_snapshot', 'aurora_visibility_snapshot' ];
        if ( ! in_array( $tableKey, $watch, true ) ) {
            return;
        }
        $table = $this->prefix . $tableKey;
        $primaryColumns = $this->db->get_col( "SHOW KEYS FROM {$table} WHERE Key_name = 'PRIMARY'", 4 );
        $expected = [ 'product_id', 'variation_id', 'scope_region', 'scope_channel', 'version' ];
        if ( $primaryColumns === $expected ) {
            return;
        }
        $this->db->query( "ALTER TABLE {$table} DROP PRIMARY KEY" );
        $this->db->query( "ALTER TABLE {$table} ADD PRIMARY KEY (product_id, variation_id, scope_region, scope_channel, version)" );
        WP_CLI::log( sprintf( 'Adjusted primary key for %s', $tableKey ) );
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

    private function ensureRepriceDecisions() : void {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = $this->prefix . 'aurora_reprice_decisions';
        $charset = $this->db->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            run_id BIGINT UNSIGNED NOT NULL,
            product_id BIGINT UNSIGNED NOT NULL,
            variation_id BIGINT UNSIGNED NULL,
            sku VARCHAR(64) NULL,
            currency VARCHAR(8) NOT NULL DEFAULT 'EUR',
            old_price DECIMAL(12,4) NULL,
            candidate_price DECIMAL(12,4) NULL,
            clamped_price DECIMAL(12,4) NULL,
            rounded_price DECIMAL(12,4) NULL,
            new_price DECIMAL(12,4) NULL,
            delta_pct DECIMAL(9,4) NULL,
            cost DECIMAL(12,4) NULL,
            competitor_price DECIMAL(12,4) NULL,
            min_price DECIMAL(12,4) NULL,
            max_price DECIMAL(12,4) NULL,
            map_price DECIMAL(12,4) NULL,
            margin_before DECIMAL(8,4) NULL,
            margin_after DECIMAL(8,4) NULL,
            rule_applied VARCHAR(64) NOT NULL,
            strategy_key VARCHAR(64) NULL,
            strategy_rule_id VARCHAR(64) NULL,
            reason_code VARCHAR(64) NULL,
            reason_codes_json LONGTEXT NULL,
            reason TEXT NULL,
            audit_json LONGTEXT NULL,
            applied TINYINT(1) NOT NULL DEFAULT 0,
            applied_at_utc DATETIME NULL,
            old_price_applied_from DECIMAL(18,4) NULL,
            new_price_applied_to DECIMAL(18,4) NULL,
            rollback_status VARCHAR(32) NULL,
            rolled_back_at_utc DATETIME NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_run_id (run_id),
            KEY idx_product (product_id),
            KEY run_id_applied (run_id, applied),
            UNIQUE KEY uniq_run_product (run_id, product_id, variation_id)
        ) {$charset};";
        dbDelta( $sql );
        WP_CLI::log( 'Ensured repricer decisions schema' );
    }

    private function ensureRepriceAssignments() : void {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = $this->prefix . 'aurora_reprice_assignments';
        $charset = $this->db->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(190) NOT NULL,
            is_enabled TINYINT(1) NOT NULL DEFAULT 1,
            priority INT NOT NULL DEFAULT 100,
            scope_type VARCHAR(50) NOT NULL,
            scope_json LONGTEXT NOT NULL,
            filters_json LONGTEXT NOT NULL,
            rule_json LONGTEXT NOT NULL,
            schedule_json LONGTEXT NULL,
            last_run_id BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY is_enabled (is_enabled),
            KEY scope_type (scope_type),
            KEY last_run_id (last_run_id),
            KEY priority (priority)
        ) {$charset};";
        dbDelta( $sql );
        WP_CLI::log( 'Ensured repricer assignments schema' );
    }

    private function ensureRepriceRules() : void {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $rulesTable = $this->prefix . 'aurora_reprice_rules';
        $auditTable = $this->prefix . 'aurora_reprice_rules_audit';
        $charset = $this->db->get_charset_collate();

        $rulesSql = "CREATE TABLE {$rulesTable} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(190) NOT NULL,
            priority INT NOT NULL DEFAULT 100,
            is_enabled TINYINT(1) NOT NULL DEFAULT 1,
            is_exclusive TINYINT(1) NOT NULL DEFAULT 0,
            rule_json LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY priority_enabled (priority, is_enabled),
            KEY updated_at (updated_at)
        ) {$charset};";
        dbDelta( $rulesSql );

        $auditSql = "CREATE TABLE {$auditTable} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            rule_id BIGINT UNSIGNED NOT NULL,
            action VARCHAR(32) NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            before_json LONGTEXT NULL,
            after_json LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY rule_id_created (rule_id, created_at)
        ) {$charset};";
        dbDelta( $auditSql );
        WP_CLI::log( 'Ensured repricer rules schema' );
    }

    private function ensureRepriceProgress() : void {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = $this->prefix . 'aurora_reprice_progress';
        $charset = $this->db->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            run_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(16) NOT NULL,
            last_product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            processed_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
            updated_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
            selected_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
            decisions_written BIGINT UNSIGNED NOT NULL DEFAULT 0,
            started_at DATETIME NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY (run_id),
            KEY status_updated (status, updated_at),
            KEY last_product (last_product_id)
        ) {$charset};";
        dbDelta( $sql );
        WP_CLI::log( 'Ensured repricer progress schema' );
    }
}


class Upgrade_Options {
    public function apply() : void {
        update_option( 'aurora_snapshot_v2_enabled', 0, false );
        update_option( 'aurora_idempotence_ttl', 900, false );
        update_option( 'aurora_queue_lease_ttl', 60, false );
        if ( ! get_option( 'aurora_total_shards', false ) ) {
            update_option( 'aurora_total_shards', 2, false );
        }
        update_option( 'aurora_lease_sweep_cron_enabled', 1, false );
        WP_CLI::log( 'Option defaults applied.' );
    }
}

class SnapshotShardDoctor {
    private wpdb $db;

    public function __construct() {
        global $wpdb;
        $this->db = $wpdb;
    }

    public function checkShards() : void {
        $total = (int) get_option( 'aurora_total_shards', 2 );
        $max = (int) $this->db->get_var( "SELECT IFNULL(MAX(shard),0) FROM {$this->db->prefix}product_index_queue" );
        $outOfRange = (int) $this->db->get_var( $this->db->prepare( "SELECT COUNT(*) FROM {$this->db->prefix}product_index_queue WHERE status='pending' AND shard >= %d", $total ) );
        if ( $outOfRange > 0 || $max >= $total ) {
            WP_CLI::warning( sprintf( 'Shard mismatch: total_shards=%d, max_shard_seen=%d, pending_out_of_range=%d', $total, $max, $outOfRange ) );
        } else {
            WP_CLI::log( sprintf( 'Shard check OK (total=%d, max_seen=%d)', $total, $max ) );
        }
    }

    private function ensureRepriceDecisions() : void {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = $this->prefix . 'aurora_reprice_decisions';
        $charset = $this->db->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            run_id BIGINT UNSIGNED NOT NULL,
            product_id BIGINT UNSIGNED NOT NULL,
            variation_id BIGINT UNSIGNED NULL,
            sku VARCHAR(64) NULL,
            currency VARCHAR(8) NOT NULL DEFAULT 'EUR',
            old_price DECIMAL(12,4) NULL,
            candidate_price DECIMAL(12,4) NULL,
            clamped_price DECIMAL(12,4) NULL,
            rounded_price DECIMAL(12,4) NULL,
            new_price DECIMAL(12,4) NULL,
            delta_pct DECIMAL(9,4) NULL,
            cost DECIMAL(12,4) NULL,
            competitor_price DECIMAL(12,4) NULL,
            min_price DECIMAL(12,4) NULL,
            max_price DECIMAL(12,4) NULL,
            map_price DECIMAL(12,4) NULL,
            margin_before DECIMAL(8,4) NULL,
            margin_after DECIMAL(8,4) NULL,
            rule_applied VARCHAR(64) NOT NULL,
            strategy_key VARCHAR(64) NULL,
            strategy_rule_id VARCHAR(64) NULL,
            reason_code VARCHAR(64) NULL,
            reason_codes_json LONGTEXT NULL,
            reason TEXT NULL,
            audit_json LONGTEXT NULL,
            applied TINYINT(1) NOT NULL DEFAULT 0,
            applied_at_utc DATETIME NULL,
            old_price_applied_from DECIMAL(18,4) NULL,
            new_price_applied_to DECIMAL(18,4) NULL,
            rollback_status VARCHAR(32) NULL,
            rolled_back_at_utc DATETIME NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_run_id (run_id),
            KEY idx_product (product_id),
            KEY run_id_applied (run_id, applied),
            UNIQUE KEY uniq_run_product (run_id, product_id, variation_id)
        ) {$charset};";
        dbDelta( $sql );
        WP_CLI::log( 'Ensured repricer decisions schema' );
    }
}
