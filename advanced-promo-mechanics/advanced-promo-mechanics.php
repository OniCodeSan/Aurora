<?php
/**
 * Plugin Name:       Advanced Promo Mechanics
 * Plugin URI:        https://example.com/plugins/advanced-promo-mechanics
 * Description:       Gestione avanzata delle promozioni WooCommerce (omaggi, BOGO, 3x2, sconti quantità) senza rompere i totali.
 * Version:           0.1.0
 * Author:            Mariano + Mark
 * Text Domain:       advanced-promo-mechanics
 * Domain Path:       /languages
 * Requires PHP:      8.1
 * Requires at least: 6.4
 * WC tested up to:   9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'APM_VERSION', '0.1.0' );
define( 'APM_PLUGIN_FILE', __FILE__ );
define( 'APM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'APM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

autoload_apm();

function apm_get_feed_paths() : array {
    $upload = wp_upload_dir();
    $dir    = trailingslashit( $upload['basedir'] ) . 'apm-feeds/';
    $url    = trailingslashit( $upload['baseurl'] ) . 'apm-feeds/';
    return [ 'dir' => $dir, 'url' => $url ];
}

function apm_ensure_feed_dir() : void {
    $paths = apm_get_feed_paths();
    if ( ! file_exists( $paths['dir'] ) ) {
        wp_mkdir_p( $paths['dir'] );
    }
    if ( ! file_exists( $paths['dir'] . 'logs' ) ) {
        wp_mkdir_p( $paths['dir'] . 'logs' );
    }
}

apm_ensure_feed_dir();

register_activation_hook( __FILE__, static function () {
    if ( ! get_option( 'apm_install_date' ) ) {
        update_option( 'apm_install_date', time(), false );
    }
    apm_install();
} );

/**
 * Lightweight autoloader for plugin classes (APM\ namespace).
 */
function autoload_apm() : void {
    spl_autoload_register(
        static function ( $class ) {
            if ( str_starts_with( $class, 'APM\\' ) ) {
                $relative   = str_replace( 'APM\\', '', $class );
                $parts      = explode( '\\', $relative );
                $class_name = array_pop( $parts );
                $sub_path   = $parts ? strtolower( implode( '/', $parts ) ) . '/' : '';
                $file       = 'class-' . strtolower( str_replace( '_', '-', $class_name ) ) . '.php';
                $path       = APM_PLUGIN_DIR . 'includes/' . $sub_path . $file;
                if ( file_exists( $path ) ) {
                    require_once $path;
                }
            }
        }
    );
}

function apm_install() : void {
    global $wpdb;

    $charset_collate   = $wpdb->get_charset_collate();
    $snapshot_table    = $wpdb->prefix . 'apm_pricing_snapshot';
    $actions_table     = $wpdb->prefix . 'apm_price_actions';
    $accounts_table    = $wpdb->prefix . 'apm_marketplace_accounts';
    $sku_map_table     = $wpdb->prefix . 'apm_sku_links';
    $feed_profiles     = $wpdb->prefix . 'apm_feed_profiles';
    $feed_logs         = $wpdb->prefix . 'apm_feed_logs';
    $activity_log      = $wpdb->prefix . 'apm_activity_log';

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $snapshot_sql = "CREATE TABLE {$snapshot_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        tenant_id VARCHAR(64) NOT NULL DEFAULT 'default',
        product_id BIGINT UNSIGNED NOT NULL,
        variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        cost DECIMAL(12,4) DEFAULT NULL,
        min_margin DECIMAL(6,4) DEFAULT NULL,
        competitor_min DECIMAL(12,4) DEFAULT NULL,
        competitor_avg DECIMAL(12,4) DEFAULT NULL,
        stock INT DEFAULT NULL,
        strategy VARCHAR(50) DEFAULT 'margin_guard',
        currency CHAR(3) DEFAULT NULL,
        snapshot_source VARCHAR(64) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY tenant_product (tenant_id, product_id, variation_id),
        KEY created_at (created_at)
    ) {$charset_collate};";

    $actions_sql = "CREATE TABLE {$actions_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        tenant_id VARCHAR(64) NOT NULL DEFAULT 'default',
        product_id BIGINT UNSIGNED NOT NULL,
        variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        strategy VARCHAR(100) NOT NULL,
        old_price DECIMAL(12,4) DEFAULT NULL,
        new_price DECIMAL(12,4) DEFAULT NULL,
        computed_margin DECIMAL(12,4) DEFAULT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        notes TEXT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY tenant_product_created (tenant_id, product_id, created_at)
    ) {$charset_collate};";

    $accounts_sql = "CREATE TABLE {$accounts_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        tenant_id VARCHAR(64) NOT NULL DEFAULT 'default',
        marketplace VARCHAR(20) NOT NULL,
        label VARCHAR(100) NOT NULL,
        data LONGTEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY tenant_marketplace_label (tenant_id, marketplace, label)
    ) {$charset_collate};";

    $sku_map_sql = "CREATE TABLE {$sku_map_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        tenant_id VARCHAR(64) NOT NULL DEFAULT 'default',
        product_id BIGINT UNSIGNED NOT NULL,
        variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        marketplace VARCHAR(20) NOT NULL,
        marketplace_sku VARCHAR(191) NOT NULL,
        listing_id VARCHAR(191) DEFAULT NULL,
        status VARCHAR(20) DEFAULT 'active',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY tenant_product_marketplace (tenant_id, product_id, variation_id, marketplace)
    ) {$charset_collate};";

    $feed_profiles_sql = "CREATE TABLE {$feed_profiles} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        tenant_id VARCHAR(64) NOT NULL DEFAULT 'default',
        name VARCHAR(150) NOT NULL,
        merchant VARCHAR(50) NOT NULL,
        format VARCHAR(20) NOT NULL DEFAULT 'xml',
        destination VARCHAR(255) DEFAULT NULL,
        schedule VARCHAR(20) DEFAULT 'manual',
        last_run DATETIME DEFAULT NULL,
        notes TEXT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) {$charset_collate};";

    $feed_logs_sql = "CREATE TABLE {$feed_logs} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        tenant_id VARCHAR(64) NOT NULL DEFAULT 'default',
        profile_id BIGINT UNSIGNED NOT NULL,
        status VARCHAR(20) NOT NULL,
        message TEXT DEFAULT NULL,
        file_path VARCHAR(255) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY profile_idx (profile_id, created_at)
    ) {$charset_collate};";

    $activity_log_sql = "CREATE TABLE {$activity_log} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        tenant_id VARCHAR(64) NOT NULL DEFAULT 'default',
        event VARCHAR(100) NOT NULL,
        message TEXT NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        meta LONGTEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY tenant_event_created (tenant_id, event, created_at)
    ) {$charset_collate};";

    dbDelta( $snapshot_sql );
    dbDelta( $actions_sql );
    dbDelta( $accounts_sql );
    dbDelta( $sku_map_sql );
    dbDelta( $feed_profiles_sql );
    dbDelta( $feed_logs_sql );
    dbDelta( $activity_log_sql );
}

function apm_encrypt_data( array $data ) : string {
    $key = hash( 'sha256', wp_salt( 'auth' ), true );
    $iv  = random_bytes( 12 );
    $json = wp_json_encode( $data );
    $tag  = '';
    $cipher = openssl_encrypt( $json, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
    if ( false === $cipher ) {
        return '';
    }
    return base64_encode( $iv . $tag . $cipher );
}

function apm_decrypt_data( string $payload ) : array {
    $raw = base64_decode( $payload, true );
    if ( false === $raw || strlen( $raw ) < 28 ) {
        return [];
    }
    $iv    = substr( $raw, 0, 12 );
    $tag   = substr( $raw, 12, 16 );
    $cipher = substr( $raw, 28 );
    $key   = hash( 'sha256', wp_salt( 'auth' ), true );
    $json  = openssl_decrypt( $cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
    if ( false === $json ) {
        return [];
    }
    $data = json_decode( $json, true );
    return is_array( $data ) ? $data : [];
}

function apm_log_activity( string $event, string $message, array $meta = [] ) : void {
    if ( ! class_exists( '\APM\Activity_Log' ) ) {
        return;
    }
    static $activity_logger = null;
    if ( null === $activity_logger ) {
        $activity_logger = new APM\Activity_Log();
    }
    $activity_logger->record( $event, $message, $meta );
}

function apm_get_repricer_settings() : array {
    $defaults = [
        'default_min_margin'   => 20.0,
        'stock_push_threshold' => 100,
        'stock_push_discount'  => 10.0,
        'stock_push_min_margin'=> 5.0,
        'batch_size'           => 50,
        'schedule'             => '5min',
    ];

    $settings = get_option( 'apm_repricer_settings', [] );
    $settings = wp_parse_args( is_array( $settings ) ? $settings : [], $defaults );

    $settings['default_min_margin']    = max( 0, min( 100, (float) $settings['default_min_margin'] ) );
    $settings['stock_push_threshold']  = max( 0, (int) $settings['stock_push_threshold'] );
    $settings['stock_push_discount']   = max( 0, min( 90, (float) $settings['stock_push_discount'] ) );
    $settings['stock_push_min_margin'] = max( 0, min( 100, (float) $settings['stock_push_min_margin'] ) );
    $settings['batch_size']            = max( 1, (int) $settings['batch_size'] );
    $settings['schedule']              = in_array( $settings['schedule'], [ '5min', '15min' ], true ) ? $settings['schedule'] : '5min';

    return $settings;
}

function apm_register_wc_feature_flags() : void {
    if ( ! class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
        return;
    }
    \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', plugin_basename( __FILE__ ), true );
    \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', plugin_basename( __FILE__ ), true );
}

if ( did_action( 'before_woocommerce_init' ) ) {
    apm_register_wc_feature_flags();
} else {
    add_action( 'before_woocommerce_init', 'apm_register_wc_feature_flags' );
}

add_action( 'plugins_loaded', static function () {
    if ( ! class_exists( 'WooCommerce' ) ) {
        return;
    }

    load_plugin_textdomain( 'advanced-promo-mechanics', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

    $plugin = new APM\Plugin();
    $plugin->init();
} );
