<?php
require "/var/www/html/wp-load.php";

global $wpdb;

$prefix = $wpdb->prefix;
$ops_table = $prefix . "aurora_ops_runs";
$progress_table = $prefix . "aurora_reprice_progress";
$decisions_table = $prefix . "aurora_reprice_decisions";
$assignments_table = $prefix . "aurora_reprice_assignments";

// Reset repricer-related state only.
delete_option("aurora_reprice_lock");
delete_option("aurora_repricer_tick_cursor");
delete_option("aurora_repricer_tick_last");

delete_option("aurora_repricer_scheduler_cursor");
delete_option("aurora_repricer_scheduler_stats");

delete_option("aurora_e2e_live_last_run_id");

$assignment_id = (int) $wpdb->get_var(
    $wpdb->prepare("SELECT id FROM {$assignments_table} WHERE name=%s LIMIT 1", "e2e_live_assignment")
);
$run_ids = [];

if ($assignment_id > 0) {
    $like = '%"assignment_id":' . $assignment_id . '%';
    $rows = $wpdb->get_col(
        $wpdb->prepare("SELECT id FROM {$ops_table} WHERE op_key='repricer_run' AND meta_json LIKE %s", $like)
    );
    if ($rows) {
        $run_ids = array_map('intval', $rows);
    }
}

$last_run_id = (int) get_option("aurora_e2e_live_last_run_id", 0);
if ($last_run_id > 0) {
    $run_ids[] = $last_run_id;
}

$run_ids = array_values(array_unique(array_filter($run_ids, static fn($v) => $v > 0)));

if (!empty($run_ids)) {
    $placeholders = implode(',', array_fill(0, count($run_ids), '%d'));
    $wpdb->query($wpdb->prepare("DELETE FROM {$decisions_table} WHERE run_id IN ({$placeholders})", $run_ids));
    $wpdb->query($wpdb->prepare("DELETE FROM {$progress_table} WHERE run_id IN ({$placeholders})", $run_ids));
    $wpdb->query($wpdb->prepare("DELETE FROM {$ops_table} WHERE id IN ({$placeholders})", $run_ids));
}

// Remove prior e2e assignment if present.
$wpdb->delete($assignments_table, ["name" => "e2e_live_assignment"]);

echo "reset_ok\n";
