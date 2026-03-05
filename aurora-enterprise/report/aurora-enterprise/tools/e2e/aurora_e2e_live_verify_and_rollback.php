<?php
require "/var/www/html/wp-load.php";

global $wpdb;

$SKU = "AURORA-TEST-REPRICE-001";

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

$current_price = get_post_meta($product_id, "_price", true);
$orig_price = get_post_meta($product_id, "_aurora_e2e_live_orig_price", true);
$orig_cost = get_post_meta($product_id, "_aurora_e2e_live_orig_cost", true);

if ($orig_price === '' || $orig_price === null) {
    $orig_price = $current_price;
}

if ($orig_cost === '' || $orig_cost === null) {
    $orig_cost = get_post_meta($product_id, "_aurora_cost", true);
}

$changed = ((string) $current_price !== (string) $orig_price) ? 1 : 0;

echo "product_id=" . $product_id . " current_price=" . $current_price . " orig_price=" . $orig_price . " changed=" . $changed . "\n";

// Evaluate run result deterministically.
$ops_table = $wpdb->prefix . "aurora_ops_runs";
$decisions_table = $wpdb->prefix . "aurora_reprice_decisions";
$progress_table = $wpdb->prefix . "aurora_reprice_progress";

$run_id = (int) get_option("aurora_e2e_live_last_run_id", 0);
if ($run_id <= 0) {
    $run_id = (int) $wpdb->get_var("SELECT id FROM {$ops_table} WHERE op_key='repricer_run' ORDER BY id DESC LIMIT 1");
}

$run_row = null;
if ($run_id > 0) {
    $run_row = $wpdb->get_row(
        $wpdb->prepare("SELECT id, status, message, error FROM {$ops_table} WHERE id=%d", $run_id),
        ARRAY_A
    );
}

$decisions_count = 0;
$applied_count = 0;
$rule_rows = [];
if ($run_id > 0) {
    $decisions_count = (int) $wpdb->get_var(
        $wpdb->prepare("SELECT COUNT(*) FROM {$decisions_table} WHERE run_id=%d", $run_id)
    );
    $applied_count = (int) $wpdb->get_var(
        $wpdb->prepare("SELECT COUNT(*) FROM {$decisions_table} WHERE run_id=%d AND applied=1", $run_id)
    );
    $rule_rows = $wpdb->get_results(
        $wpdb->prepare("SELECT rule_applied, COUNT(*) AS total FROM {$decisions_table} WHERE run_id=%d GROUP BY rule_applied", $run_id),
        ARRAY_A
    );
}

$progress = null;
if ($run_id > 0) {
    $progress = $wpdb->get_row(
        $wpdb->prepare("SELECT status, processed_count, updated_count, selected_count, decisions_written FROM {$progress_table} WHERE run_id=%d", $run_id),
        ARRAY_A
    );
}

$status = $run_row["status"] ?? "";
$progress_status = $progress["status"] ?? "";

$pass = true;
$reasons = [];
if ($status !== "success") {
    $pass = false;
    $reasons[] = "run_status_not_success";
}
if ($decisions_count < 1) {
    $pass = false;
    $reasons[] = "decisions_count_lt_1";
}
if ($progress_status !== "completed") {
    $pass = false;
    $reasons[] = "progress_not_completed";
}
if ($applied_count > 0 && $changed !== 1) {
    $pass = false;
    $reasons[] = "applied_but_price_not_changed";
}

echo "run_row=" . wp_json_encode($run_row) . "\n";
echo "decision_rules=" . wp_json_encode($rule_rows) . "\n";
echo "decisions_count=" . $decisions_count . " applied_count=" . $applied_count . "\n";
echo "progress_row=" . wp_json_encode($progress) . "\n";
echo "RESULT=" . ($pass ? "PASS" : "FAIL") . ($pass ? "" : " reasons=" . implode(",", $reasons)) . "\n";

// Rollback to original price/cost for idempotence.
update_post_meta($product_id, "_price", $orig_price);
update_post_meta($product_id, "_regular_price", $orig_price);
update_post_meta($product_id, "_aurora_cost", $orig_cost);

$after_price = get_post_meta($product_id, "_price", true);

echo "rollback_price=" . $after_price . " rollback_cost=" . $orig_cost . "\n";
