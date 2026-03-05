<?php
require "/var/www/html/wp-load.php";

global $wpdb;

$SKU = "AURORA-TEST-REPRICE-001";
$SLUG = "aurora-test-reprice-001";
$NAME = "Aurora Test Reprice 001";
$INITIAL_PRICE = 11.00;
$INITIAL_COST = 10.00;

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
    $product_id = (int) wp_insert_post([
        "post_title" => $NAME,
        "post_name" => $SLUG,
        "post_status" => "publish",
        "post_type" => "product",
    ]);
    if ($product_id <= 0) {
        echo "product_create_failed\n";
        exit(1);
    }
    wp_set_object_terms($product_id, "simple", "product_type", false);
}

update_post_meta($product_id, "_sku", $SKU);
update_post_meta($product_id, "_price", $INITIAL_PRICE);
update_post_meta($product_id, "_regular_price", $INITIAL_PRICE);
update_post_meta($product_id, "_sale_price", "");
update_post_meta($product_id, "_aurora_cost", $INITIAL_COST);
update_post_meta($product_id, "_stock_status", "instock");
update_post_meta($product_id, "_aurora_e2e_live_orig_price", $INITIAL_PRICE);
update_post_meta($product_id, "_aurora_e2e_live_orig_cost", $INITIAL_COST);
update_post_meta($product_id, "_aurora_e2e_live_test", "1");

$price = get_post_meta($product_id, "_price", true);
$cost = get_post_meta($product_id, "_aurora_cost", true);

echo "product_id=" . $product_id . " sku=" . $SKU . " price=" . $price . " cost=" . $cost . "\n";
