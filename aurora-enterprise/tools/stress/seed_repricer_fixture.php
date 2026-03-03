<?php
require "/var/www/html/wp-load.php";
global $wpdb;

$sku = 'AURORA-TEST-REPRICE-001';
$title = 'Aurora Stress Reprice Fixture';
$slug = 'aurora-stress-reprice-fixture';

$product_id = 0;
if ( function_exists( 'wc_get_product_id_by_sku' ) ) {
    $product_id = (int) wc_get_product_id_by_sku( $sku );
}
if ( $product_id <= 0 ) {
    $product_id = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_sku' AND meta_value = %s LIMIT 1",
            $sku
        )
    );
}

if ( $product_id > 0 ) {
    $post_update = [
        'ID'         => $product_id,
        'post_title' => $title,
        'post_name'  => $slug,
        'post_status'=> 'publish',
    ];
    wp_update_post( $post_update );
} else {
    $product_id = (int) wp_insert_post( [
        'post_type'   => 'product',
        'post_status' => 'publish',
        'post_title'  => $title,
        'post_name'   => $slug,
    ] );
}

if ( $product_id <= 0 ) {
    echo "assignment_id=0 product_id=0 error=product_create_failed\n";
    exit(0);
}

update_post_meta( $product_id, '_sku', $sku );
update_post_meta( $product_id, '_price', '11.00' );
update_post_meta( $product_id, '_regular_price', '11.00' );
update_post_meta( $product_id, '_aurora_cost', '10.00' );
update_post_meta( $product_id, '_stock_status', 'instock' );
update_post_meta( $product_id, '_manage_stock', 'no' );
update_post_meta( $product_id, '_virtual', 'no' );

$assign_table = $wpdb->prefix . 'aurora_reprice_assignments';
$assignment_name = 'stress_reprice_assignment';
$existing = $wpdb->get_row(
    $wpdb->prepare(
        "SELECT id FROM {$assign_table} WHERE name = %s LIMIT 1",
        $assignment_name
    ),
    ARRAY_A
);

$scope = [
    'scope_type' => 'products',
    'products' => [ $product_id ],
    'exclude_products' => [],
];
$filters = [
    'require_cost' => true,
    'require_price' => true,
];
$schedule = [
    'type' => 'windows',
    'timezone' => 'Europe/Rome',
    'windows' => [
        [
            'from' => '00:00',
            'to' => '23:59',
        ],
    ],
];

$now = current_time( 'mysql', true );
$assignment_id = 0;
if ( $existing && isset( $existing['id'] ) ) {
    $assignment_id = (int) $existing['id'];
    $wpdb->update(
        $assign_table,
        [
            'is_enabled' => 1,
            'priority' => 999,
            'scope_type' => 'products',
            'scope_json' => wp_json_encode( $scope ),
            'filters_json' => wp_json_encode( $filters ),
            'rule_json' => wp_json_encode( [] ),
            'schedule_json' => wp_json_encode( $schedule ),
            'updated_at' => $now,
        ],
        [ 'id' => $assignment_id ]
    );
} else {
    $inserted = $wpdb->insert(
        $assign_table,
        [
            'name' => $assignment_name,
            'is_enabled' => 1,
            'priority' => 999,
            'scope_type' => 'products',
            'scope_json' => wp_json_encode( $scope ),
            'filters_json' => wp_json_encode( $filters ),
            'rule_json' => wp_json_encode( [] ),
            'schedule_json' => wp_json_encode( $schedule ),
            'last_run_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]
    );
    if ( false !== $inserted ) {
        $assignment_id = (int) $wpdb->insert_id;
    }
}

echo "assignment_id={$assignment_id} product_id={$product_id}\n";
