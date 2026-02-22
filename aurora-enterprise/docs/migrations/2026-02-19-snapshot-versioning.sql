-- Snapshot versioning & idempotence migration
START TRANSACTION;

/* 1. Ensure metadata table exists */
CREATE TABLE IF NOT EXISTS aurora_snapshot_versions (
    table_name VARCHAR(64) PRIMARY KEY,
    current_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
    pending_version BIGINT UNSIGNED NULL,
    previous_version BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

/* 2. Snapshot tables */
CREATE TABLE IF NOT EXISTS aurora_price_snapshot (
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
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (product_id, scope_region, scope_channel, version),
    KEY idx_version (version)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS aurora_stock_snapshot (
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
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (product_id, scope_region, scope_channel, version),
    KEY idx_version (version)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS aurora_visibility_snapshot (
    product_id BIGINT UNSIGNED NOT NULL,
    scope_region VARCHAR(32) NOT NULL DEFAULT 'default',
    scope_channel VARCHAR(32) NOT NULL DEFAULT 'default',
    version BIGINT UNSIGNED NOT NULL,
    variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    sku VARCHAR(190) NOT NULL,
    visibility VARCHAR(32) NOT NULL,
    catalog_flags JSON NULL,
    channel_mask BIGINT UNSIGNED NOT NULL DEFAULT 0,
    hash BINARY(16) NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (product_id, scope_region, scope_channel, version),
    KEY idx_version (version)
) ENGINE=InnoDB;

/* 5. Idempotence cache fallback */
CREATE TABLE IF NOT EXISTS aurora_idempotence_cache (
    dedup_hash BINARY(16) PRIMARY KEY,
    job_uuid BINARY(16) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

COMMIT;
