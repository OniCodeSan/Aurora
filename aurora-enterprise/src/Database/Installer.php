<?php
namespace Aurora\Enterprise\Database;

class Installer {
    public function install() : void {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        $schema = [];
        $prefix = $wpdb->prefix;

        $schema[] = "CREATE TABLE {$prefix}product_price_index (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id BIGINT UNSIGNED NOT NULL,
            variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            sku VARCHAR(191) NOT NULL DEFAULT '',
            currency CHAR(3) NOT NULL DEFAULT 'EUR',
            regular_price DECIMAL(12,4) NULL,
            sale_price DECIMAL(12,4) NULL,
            effective_price DECIMAL(12,4) NULL,
            margin_percent DECIMAL(6,3) NULL,
            version CHAR(36) NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY product_variation (product_id, variation_id),
            KEY sku (sku),
            KEY updated_at (updated_at)
        ) {$charset};";

        $schema[] = "CREATE TABLE {$prefix}product_price_index_staging (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            batch_id CHAR(36) NOT NULL,
            product_id BIGINT UNSIGNED NOT NULL,
            variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            sku VARCHAR(191) NOT NULL DEFAULT '',
            currency CHAR(3) NOT NULL DEFAULT 'EUR',
            regular_price DECIMAL(12,4) NULL,
            sale_price DECIMAL(12,4) NULL,
            effective_price DECIMAL(12,4) NULL,
            margin_percent DECIMAL(6,3) NULL,
            version CHAR(36) NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY batch_product (batch_id, product_id)
        ) {$charset};";

        $schema[] = "CREATE TABLE {$prefix}product_stock_index (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id BIGINT UNSIGNED NOT NULL,
            variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            sku VARCHAR(191) NOT NULL DEFAULT '',
            stock_qty INT NULL,
            stock_status VARCHAR(20) NOT NULL DEFAULT 'instock',
            warehouse VARCHAR(50) NULL,
            version CHAR(36) NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY product_variation (product_id, variation_id),
            KEY sku (sku)
        ) {$charset};";

        $schema[] = "CREATE TABLE {$prefix}product_stock_index_staging (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            batch_id CHAR(36) NOT NULL,
            product_id BIGINT UNSIGNED NOT NULL,
            variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            sku VARCHAR(191) NOT NULL DEFAULT '',
            stock_qty INT NULL,
            stock_status VARCHAR(20) NOT NULL DEFAULT 'instock',
            warehouse VARCHAR(50) NULL,
            version CHAR(36) NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY batch_product (batch_id, product_id)
        ) {$charset};";

        $schema[] = "CREATE TABLE {$prefix}product_visibility_index (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id BIGINT UNSIGNED NOT NULL,
            variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            sku VARCHAR(191) NOT NULL DEFAULT '',
            visibility VARCHAR(20) NOT NULL DEFAULT 'catalog',
            catalog_flags VARCHAR(191) NULL,
            channel_mask INT NOT NULL DEFAULT 0,
            version CHAR(36) NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY product_variation (product_id, variation_id),
            KEY sku (sku)
        ) {$charset};";

        $schema[] = "CREATE TABLE {$prefix}product_visibility_index_staging (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            batch_id CHAR(36) NOT NULL,
            product_id BIGINT UNSIGNED NOT NULL,
            variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            sku VARCHAR(191) NOT NULL DEFAULT '',
            visibility VARCHAR(20) NOT NULL DEFAULT 'catalog',
            catalog_flags VARCHAR(191) NULL,
            channel_mask INT NOT NULL DEFAULT 0,
            version CHAR(36) NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY batch_product (batch_id, product_id)
        ) {$charset};";

        $schema[] = "CREATE TABLE {$prefix}product_index_queue (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            job_uuid CHAR(36) NOT NULL,
            queue VARCHAR(50) NOT NULL,
            shard TINYINT UNSIGNED NOT NULL DEFAULT 0,
            payload LONGTEXT NOT NULL,
            payload_hash BINARY(16) NULL,
            priority TINYINT NOT NULL DEFAULT 0,
            attempts TINYINT NOT NULL DEFAULT 0,
            available_at DATETIME NOT NULL,
            reserved_at DATETIME NULL,
            lease_token CHAR(36) NULL,
            lease_expires_at DATETIME NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            error TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY job_uuid (job_uuid),
            KEY queue_shard_status (queue, shard, status, available_at, id),
            KEY shard_status (shard, status, available_at, id),
            KEY payload_hash (payload_hash)
        ) {$charset};";

        $schema[] = "CREATE TABLE {$prefix}product_index_logs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            job_id CHAR(36) NULL,
            indexer VARCHAR(20) NOT NULL,
            level VARCHAR(20) NOT NULL DEFAULT 'info',
            message TEXT NOT NULL,
            context LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY indexer_level (indexer, level)
        ) {$charset};";

        $schema[] = "CREATE TABLE {$prefix}aurora_reprice_decisions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            run_id BIGINT UNSIGNED NOT NULL,
            product_id BIGINT UNSIGNED NOT NULL,
            variation_id BIGINT UNSIGNED NULL,
            sku VARCHAR(64) NULL,
            currency VARCHAR(8) NOT NULL DEFAULT 'EUR',
            old_price DECIMAL(12,4) NULL,
            new_price DECIMAL(12,4) NULL,
            cost DECIMAL(12,4) NULL,
            margin_before DECIMAL(8,4) NULL,
            margin_after DECIMAL(8,4) NULL,
            rule_applied VARCHAR(64) NOT NULL,
            reason TEXT NULL,
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

        $schema[] = "CREATE TABLE {$prefix}aurora_reprice_progress (
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

        $schema[] = "CREATE TABLE {$prefix}aurora_reprice_assignments (
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

        $schema[] = "CREATE TABLE {$prefix}aurora_snapshot_versions (
            table_name VARCHAR(64) NOT NULL,
            current_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
            pending_version BIGINT UNSIGNED NULL,
            previous_version BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (table_name)
        ) {$charset};";

        $schema[] = "CREATE TABLE {$prefix}aurora_price_snapshot (
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
            KEY idx_price_product (product_id),
            KEY idx_price_updated (updated_at)
        ) {$charset};";

        $schema[] = "CREATE TABLE {$prefix}aurora_stock_snapshot (
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
            KEY idx_version (version),
            KEY idx_version_product (version, product_id),
            KEY idx_stock_product (product_id),
            KEY idx_stock_updated (updated_at)
        ) {$charset};";

        $schema[] = "CREATE TABLE {$prefix}aurora_visibility_snapshot (
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
            KEY idx_version (version),
            KEY idx_version_product (version, product_id),
            KEY idx_vis_product (product_id),
            KEY idx_visibility_updated (updated_at)
        ) {$charset};";

        $schema[] = "CREATE TABLE {$prefix}aurora_runtime_stats (
            stat_key VARCHAR(64) NOT NULL,
            stat_value BIGINT NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (stat_key)
        ) {$charset};";

        $schema[] = "CREATE TABLE {$prefix}aurora_queue_checkpoints (
            channel VARCHAR(32) NOT NULL,
            shard TINYINT UNSIGNED NOT NULL,
            last_job_uuid CHAR(36) NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (channel, shard)
        ) {$charset};";

        $schema[] = "CREATE TABLE {$prefix}aurora_idempotence_cache (
            dedup_hash BINARY(16) NOT NULL,
            job_uuid CHAR(36) NOT NULL,
            expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (dedup_hash),
            KEY idx_expires (expires_at)
        ) {$charset};";

        $schema[] = "CREATE TABLE {$prefix}aurora_ops_runs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            op_key VARCHAR(100) NOT NULL,
            action_type VARCHAR(50) NOT NULL,
            indexer VARCHAR(20) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'requested',
            requested_at DATETIME NOT NULL,
            started_at DATETIME NULL,
            finished_at DATETIME NULL,
            message VARCHAR(255) NULL,
            error TEXT NULL,
            meta_json LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY status_requested (status, requested_at),
            KEY action_created (action_type, created_at),
            KEY op_key_created (op_key, created_at)
        ) {$charset};";

        $schema[] = "CREATE TABLE {$prefix}aurora_feed_progress (
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
        ) {$charset};";

        foreach ( $schema as $sql ) {
            dbDelta( $sql );
        }
    }
}
