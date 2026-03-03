<?php
require "/var/www/html/wp-load.php";

global $wpdb;

$SKU = "AURORA-TEST-REPRICE-001";
$ops_table = $wpdb->prefix . "aurora_ops_runs";
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

$assignment_id = (int) $wpdb->get_var(
    $wpdb->prepare("SELECT id FROM {$assignments_table} WHERE name=%s AND is_enabled=1 LIMIT 1", "e2e_live_assignment")
);
if ($assignment_id <= 0) {
    echo "missing_assignment\n";
    exit(1);
}

wp_set_current_user(1);
if (!defined('REST_REQUEST')) {
    define('REST_REQUEST', true);
}
$_SERVER["HTTP_X_WP_NONCE"] = wp_create_nonce("wp_rest");

$payload = [
    "assignment_id" => $assignment_id,
    "max_products" => 1,
    "chunk_size" => 1,
    "timebox_seconds" => 60,
    "min_margin_percent" => 20.0,
    "min_margin_abs" => 2.0,
    "dry_run" => false,
    "mode" => "apply",
];

$req = new WP_REST_Request("POST", "/aurora/v1/repricer/run");
$req->set_header("content-type", "application/json");
$req->set_body(wp_json_encode($payload));

$res = rest_do_request($req);
if ($res instanceof WP_Error) {
    echo "repricer_run_error=" . $res->get_error_message() . "\n";
    echo "repricer_run_error_data=" . wp_json_encode($res->get_error_data()) . "\n";
} else {
    $data = $res instanceof WP_REST_Response ? $res->get_data() : $res;
    echo "repricer_run_response=" . wp_json_encode($data) . "\n";
}

$last_run = $wpdb->get_row(
    "SELECT id, status, error, meta_json FROM {$ops_table} WHERE op_key='repricer_run' ORDER BY id DESC LIMIT 1",
    ARRAY_A
);
echo "sql_last_run=SELECT id, status, error, meta_json FROM {$ops_table} WHERE op_key='repricer_run' ORDER BY id DESC LIMIT 1\n";
if ($last_run) {
    update_option("aurora_e2e_live_last_run_id", (int) $last_run["id"], false);
    echo "last_run=" . wp_json_encode($last_run) . "\n";
}

$counts = $wpdb->get_row(
    "SELECT SUM(status='requested') AS requested, SUM(status='running') AS running FROM {$ops_table} WHERE op_key='repricer_run'",
    ARRAY_A
);
if ($counts) {
    echo "requested=" . (int) $counts["requested"] . " running=" . (int) $counts["running"] . "\n";
}

$price_before = get_post_meta($product_id, "_price", true);
$cost = get_post_meta($product_id, "_aurora_cost", true);

echo "product_id=" . $product_id . " price_before=" . $price_before . " cost=" . $cost . "\n";
