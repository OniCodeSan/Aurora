<?php
require "/var/www/html/wp-load.php";

global $wpdb;

$SKU = "AURORA-TEST-REPRICE-001";
$assignments_table = $wpdb->prefix . "aurora_reprice_assignments";

function aurora_e2e_get_product_id_by_sku(string $sku): int {
    if (function_exists('wc_get_product_id_by_sku')) {
        $id = (int) wc_get_product_id_by_sku($sku);
        if ($id > 0) {
            return $id;
        }
    }
    global $wpdb;
    $id = (int) $wpdb->get_var(
        $wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_sku' AND meta_value=%s LIMIT 1", $sku)
    );
    return $id > 0 ? $id : 0;
}

$product_id = aurora_e2e_get_product_id_by_sku($SKU);
if ($product_id <= 0) {
    echo "missing_product\n";
    exit(1);
}

$wpdb->delete($assignments_table, ["name" => "e2e_live_assignment"]);

$scope = [
    "scope_type" => "products",
    "type" => "products",
    "products" => [$product_id],
    "exclude_products" => [],
];
$filters = [
    "require_cost" => true,
    "require_price" => true,
];
$schedule = [
    "type" => "windows",
    "mode" => "windows",
    "timezone" => "Europe/Rome",
    "windows" => [
        ["from" => "00:00", "to" => "23:59", "start" => "00:00", "end" => "23:59"],
    ],
];

$data = [
    "name" => "e2e_live_assignment",
    "is_enabled" => 1,
    "priority" => 999,
    "scope_type" => "products",
    "scope_json" => wp_json_encode($scope),
    "filters_json" => wp_json_encode($filters),
    "rule_json" => wp_json_encode([]),
    "schedule_json" => wp_json_encode($schedule),
    "created_at" => current_time("mysql", true),
    "updated_at" => current_time("mysql", true),
];

$inserted = $wpdb->insert($assignments_table, $data);
if ($inserted === false) {
    echo "insert_error=" . $wpdb->last_error . "\n";
    exit(1);
}

$assignment_id = (int) $wpdb->insert_id;
$row = $wpdb->get_row(
    $wpdb->prepare("SELECT id, is_enabled, priority, scope_type, scope_json, filters_json, schedule_json FROM {$assignments_table} WHERE id=%d", $assignment_id),
    ARRAY_A
);

if ($row) {
    echo "assignment_row=" . wp_json_encode($row) . "\n";
}

echo "assignment_id=" . $assignment_id . " product_id=" . $product_id . "\n";
