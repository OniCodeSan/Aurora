<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

$deleteData = (bool) get_option( 'aurora_delete_data_on_uninstall', false );

// Always remove Aurora options/transients; keep custom tables by default.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'aurora_%'" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_aurora_%'" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_timeout\\_aurora_%'" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_site\\_transient\\_aurora_%'" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_site\\_transient\\_timeout\\_aurora_%'" );

if ( ! $deleteData ) {
    return;
}

$tables = [
    'product_price_index',
    'product_price_index_staging',
    'product_stock_index',
    'product_stock_index_staging',
    'product_visibility_index',
    'product_visibility_index_staging',
    'product_index_queue',
    'product_index_logs',
    'aurora_reprice_decisions',
    'aurora_reprice_progress',
    'aurora_reprice_assignments',
    'aurora_reprice_rules',
    'aurora_reprice_rules_audit',
    'aurora_snapshot_versions',
    'aurora_price_snapshot',
    'aurora_stock_snapshot',
    'aurora_visibility_snapshot',
    'aurora_runtime_stats',
    'aurora_queue_checkpoints',
    'aurora_idempotence_cache',
    'aurora_ops_runs',
    'aurora_feed_progress',
];

foreach ( $tables as $suffix ) {
    $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$suffix}" );
}
