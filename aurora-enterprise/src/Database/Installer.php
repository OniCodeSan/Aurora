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
            payload LONGTEXT NOT NULL,
            priority TINYINT NOT NULL DEFAULT 0,
            attempts TINYINT NOT NULL DEFAULT 0,
            available_at DATETIME NOT NULL,
            reserved_at DATETIME NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            error TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY job_uuid (job_uuid),
            KEY queue_status (queue, status, available_at)
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

        foreach ( $schema as $sql ) {
            dbDelta( $sql );
        }
    }
}
