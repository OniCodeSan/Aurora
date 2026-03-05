<?php
/**
 * Plugin Name:       Aurora Enterprise Indexer
 * Description:       Enterprise-grade indexing pipeline for WooCommerce (async price/stock/visibility updates via external workers).
 * Version:           0.1.0
 * Author:            Mariano + Mark
 * Requires PHP:      8.2
 * Requires at least: 6.4
 * Text Domain:       aurora-enterprise
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

const AURORA_ENTERPRISE_VERSION = '0.1.0';
const AURORA_ENTERPRISE_PLUGIN_FILE = __FILE__;
const AURORA_ENTERPRISE_PLUGIN_DIR = __DIR__ . '/';

spl_autoload_register( static function ( string $class ) : void {
    $prefix = 'Aurora\\Enterprise\\';
    if ( ! str_starts_with( $class, $prefix ) ) {
        return;
    }
    $relative = substr( $class, strlen( $prefix ) );
    $relative = str_replace( '\\', '/', $relative );
    $candidates = [
        AURORA_ENTERPRISE_PLUGIN_DIR . 'src/' . $relative . '.php',
        AURORA_ENTERPRISE_PLUGIN_DIR . 'includes/' . $relative . '.php',
    ];
    foreach ( $candidates as $file ) {
        if ( file_exists( $file ) ) {
            require_once $file;
            return;
        }
    }
} );

register_activation_hook( __FILE__, static function () {
    ( new Aurora\Enterprise\Database\Installer() )->install();
    add_option( 'aurora_snapshot_v2_enabled', 1, '', 'no' );
    add_option( 'aurora_idempotence_ttl', 900, '', 'no' );
    add_option( 'aurora_queue_lease_ttl', 60, '', 'no' );
    add_option( 'aurora_total_shards', 2, '', 'no' );
    add_option( 'aurora_lease_sweep_cron_enabled', 1, '', 'no' );
} );

register_deactivation_hook( __FILE__, static function () {
    if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
        wp_clear_scheduled_hook( 'aurora_queue_sweeper_run' );
    }
    if ( function_exists( 'as_unschedule_all_actions' ) ) {
        as_unschedule_all_actions( 'aurora_repricer_tick', [], 'aurora' );
    }
} );

add_action( 'plugins_loaded', static function () {
    $bootstrap = new Aurora\Enterprise\Support\Bootstrap();
    $bootstrap->init();
} );
