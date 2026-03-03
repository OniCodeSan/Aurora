<?php
require "/var/www/html/wp-load.php";

global $wpdb;

$ops_table = $wpdb->prefix . "aurora_ops_runs";
$progress_table = $wpdb->prefix . "aurora_reprice_progress";
$decisions_table = $wpdb->prefix . "aurora_reprice_decisions";

$run_id = (int) get_option("aurora_e2e_live_last_run_id", 0);
if ($run_id <= 0) {
    $run_id = (int) $wpdb->get_var("SELECT id FROM {$ops_table} WHERE op_key='repricer_run' ORDER BY id DESC LIMIT 1");
}

if ($run_id <= 0) {
    echo "no_runs_found\n";
    exit(1);
}

// Poll until completion (success/error) or timeout.
$max_wait_seconds = 60;
$poll_interval = 2;
$deadline = time() + $max_wait_seconds;
$run_row = null;
do {
    $run_row = $wpdb->get_row(
        $wpdb->prepare("SELECT id, status, message, error, meta_json FROM {$ops_table} WHERE id=%d", $run_id),
        ARRAY_A
    );
    if (!$run_row) {
        break;
    }
    $status = (string) ($run_row["status"] ?? "");
    if (in_array($status, ["success", "error"], true)) {
        break;
    }
    sleep($poll_interval);
} while (time() < $deadline);

echo "sql_run=SELECT id, status, message, error, meta_json FROM {$ops_table} WHERE id={$run_id}\n";
if ($run_row) {
    echo "run_row=" . wp_json_encode($run_row) . "\n";
}

echo "sql_decisions=SELECT COUNT(*) FROM {$decisions_table} WHERE run_id={$run_id}\n";
$decisions_count = (int) $wpdb->get_var(
    $wpdb->prepare("SELECT COUNT(*) FROM {$decisions_table} WHERE run_id=%d", $run_id)
);

echo "decisions_count=" . $decisions_count . "\n";

$decision_rules = $wpdb->get_results(
    $wpdb->prepare("SELECT rule_applied, COUNT(*) AS total FROM {$decisions_table} WHERE run_id=%d GROUP BY rule_applied", $run_id),
    ARRAY_A
);

echo "decision_rules=" . wp_json_encode($decision_rules) . "\n";

$progress_sql = "SELECT * FROM {$progress_table} WHERE run_id={$run_id}";
echo "sql_progress=" . $progress_sql . "\n";
$progress = $wpdb->get_row(
    $wpdb->prepare("SELECT * FROM {$progress_table} WHERE run_id=%d", $run_id),
    ARRAY_A
);
if ($progress) {
    echo "progress_row=" . wp_json_encode($progress) . "\n";
}

$runtime_stats = [];
$stats_table = $wpdb->prefix . 'aurora_runtime_stats';
$table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $stats_table));
if ($table_exists) {
    $runtime_stats = $wpdb->get_results(
        "SELECT stat_key, stat_value FROM {$stats_table} WHERE stat_key IN ('dedup_hits_total','lease_sweep_recovered_total','lease_sweep_dead_total')",
        ARRAY_A
    );
}
if (!empty($runtime_stats)) {
    echo "runtime_stats=" . wp_json_encode($runtime_stats) . "\n";
}
