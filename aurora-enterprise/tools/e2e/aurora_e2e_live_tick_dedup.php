<?php
require "/var/www/html/wp-load.php";

global $wpdb;

$ops_table = $wpdb->prefix . "aurora_ops_runs";

$before = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$ops_table} WHERE op_key='repricer_run' AND status IN ('requested','running','partial')"
);

$controller = new \Aurora\Enterprise\Ops\Rest\Ops_Controller();
$req = new \WP_REST_Request("POST", "/aurora/v1/repricer/scheduler/tick");
$res = $controller->repricer_scheduler_tick($req);
$data = ($res instanceof \WP_REST_Response) ? $res->get_data() : $res;

$after = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$ops_table} WHERE op_key='repricer_run' AND status IN ('requested','running','partial')"
);

$runs_delta = $after - $before;
$enqueued = isset($data["enqueued"]) ? $data["enqueued"] : null;
$skipped = isset($data["skipped"]) ? $data["skipped"] : null;
$mode = isset($data["mode"]) ? $data["mode"] : null;
$in_window = isset($data["in_window"]) ? $data["in_window"] : null;

print_r($data);

echo "mode=" . $mode . " in_window=" . $in_window . " enqueued=" . $enqueued . " skipped=" . $skipped . " runs_delta=" . $runs_delta . "\n";
