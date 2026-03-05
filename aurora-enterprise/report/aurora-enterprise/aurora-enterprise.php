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
} );

add_action( 'plugins_loaded', static function () {
    $bootstrap = new Aurora\Enterprise\Support\Bootstrap();
    $bootstrap->init();
} );
