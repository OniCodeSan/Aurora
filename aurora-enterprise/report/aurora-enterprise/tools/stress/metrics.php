<?php
require "/var/www/html/wp-load.php";
global $wpdb;

use Aurora\Enterprise\Queue\Queue_Manager;
use Aurora\Enterprise\Support\Runtime_Stats;

$windowMinutes = (int) (getenv('WINDOW_MINUTES') ?: 60);
$windowMinutes = max(1, $windowMinutes);
$testStartUtc = trim((string) (getenv('TEST_START_UTC') ?: ''));

$prefix = $wpdb->prefix;
$opsTable = $prefix . 'aurora_ops_runs';
$feedProgressTable = $prefix . 'aurora_feed_progress';
$actionTable = !empty($wpdb->actionscheduler_actions) ? $wpdb->actionscheduler_actions : $prefix . 'actionscheduler_actions';
$snapshotTable = $prefix . 'aurora_snapshot_versions';

$recentRuns = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT id, op_key, action_type, indexer, status, requested_at, started_at, finished_at, message, error, meta_json FROM {$opsTable} ORDER BY id DESC LIMIT %d",
        20
    ),
    ARRAY_A
) ?: [];

$summaryRows = $testStartUtc !== ''
    ? $wpdb->get_results(
        $wpdb->prepare(
            "SELECT op_key, status, COUNT(*) AS total, SUM(CASE WHEN started_at IS NOT NULL AND finished_at IS NOT NULL THEN TIMESTAMPDIFF(SECOND, started_at, finished_at) ELSE 0 END) AS duration_seconds\n         FROM {$opsTable}\n         WHERE created_at >= %s\n         GROUP BY op_key, status",
            $testStartUtc
        ),
        ARRAY_A
    )
    : $wpdb->get_results(
        $wpdb->prepare(
            "SELECT op_key, status, COUNT(*) AS total, SUM(CASE WHEN started_at IS NOT NULL AND finished_at IS NOT NULL THEN TIMESTAMPDIFF(SECOND, started_at, finished_at) ELSE 0 END) AS duration_seconds\n         FROM {$opsTable}\n         WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d MINUTE)\n         GROUP BY op_key, status",
            $windowMinutes
        ),
        ARRAY_A
    );
$summaryRows = $summaryRows ?: [];

$summaryRowsFiltered = $testStartUtc !== ''
    ? $wpdb->get_results(
        $wpdb->prepare(
            "SELECT op_key, status, COUNT(*) AS total, SUM(CASE WHEN started_at IS NOT NULL AND finished_at IS NOT NULL THEN TIMESTAMPDIFF(SECOND, started_at, finished_at) ELSE 0 END) AS duration_seconds\n         FROM {$opsTable}\n         WHERE created_at >= %s\n           AND op_key IN ('repricer_run','sweep_leases')\n         GROUP BY op_key, status",
            $testStartUtc
        ),
        ARRAY_A
    )
    : $wpdb->get_results(
        $wpdb->prepare(
            "SELECT op_key, status, COUNT(*) AS total, SUM(CASE WHEN started_at IS NOT NULL AND finished_at IS NOT NULL THEN TIMESTAMPDIFF(SECOND, started_at, finished_at) ELSE 0 END) AS duration_seconds\n         FROM {$opsTable}\n         WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d MINUTE)\n           AND op_key IN ('repricer_run','sweep_leases')\n         GROUP BY op_key, status",
            $windowMinutes
        ),
        ARRAY_A
    );
$summaryRowsFiltered = $summaryRowsFiltered ?: [];

$opsSummary = [];
foreach ($summaryRows as $row) {
    $key = $row['op_key'] . ':' . $row['status'];
    $opsSummary[$key] = [
        'op_key' => $row['op_key'],
        'status' => $row['status'],
        'total' => (int) $row['total'],
        'duration_seconds' => (int) $row['duration_seconds'],
    ];
}

$opsSummaryFiltered = [];
$opsErrorsFiltered = 0;
foreach ($summaryRowsFiltered as $row) {
    $key = $row['op_key'] . ':' . $row['status'];
    $opsSummaryFiltered[$key] = [
        'op_key' => $row['op_key'],
        'status' => $row['status'],
        'total' => (int) $row['total'],
        'duration_seconds' => (int) $row['duration_seconds'],
    ];
    if ($row['status'] === 'error') {
        $opsErrorsFiltered += (int) $row['total'];
    }
}

$lastFeedRun = $wpdb->get_row(
    "SELECT id, status, started_at, finished_at, message, error, meta_json FROM {$opsTable} WHERE op_key='feed_run' ORDER BY id DESC LIMIT 1",
    ARRAY_A
);
$lastFeedMeta = null;
$feedThroughput = null;
if ($lastFeedRun && !empty($lastFeedRun['meta_json'])) {
    $decoded = json_decode($lastFeedRun['meta_json'], true);
    if (is_array($decoded)) {
        $lastFeedMeta = $decoded;
        $rows = isset($decoded['rows']) ? (int) $decoded['rows'] : 0;
        $duration = isset($decoded['duration']) ? (float) $decoded['duration'] : 0.0;
        if ($rows > 0 && $duration > 0) {
            $feedThroughput = $rows / $duration;
        }
    }
}

$feedProgress = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT run_id, status, snapshot_version, file_part, rows_written, bytes_written, last_product_id, started_at, updated_at, error FROM {$feedProgressTable} ORDER BY run_id DESC LIMIT %d",
        5
    ),
    ARRAY_A
) ?: [];

$queueStats = [];
try {
    $queueStats = Queue_Manager::instance()->stats();
} catch (Throwable $e) {
    $queueStats = [ 'error' => $e->getMessage() ];
}

$runtimeStats = [];
try {
    $runtimeStats = Runtime_Stats::instance()->getMany([
        'dedup_hits_total',
        'lease_sweep_recovered_total',
        'lease_sweep_dead_total',
    ]);
} catch (Throwable $e) {
    $runtimeStats = [ 'error' => $e->getMessage() ];
}

$snapshotVersions = $wpdb->get_results(
    "SELECT table_name, current_version, updated_at FROM {$snapshotTable}",
    ARRAY_A
) ?: [];

$actionCounts = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT status, COUNT(*) AS total FROM {$actionTable} WHERE hook = %s GROUP BY status",
        'aurora_ops_dispatch'
    ),
    ARRAY_A
) ?: [];

$actionsByStatus = [];
foreach ($actionCounts as $row) {
    $actionsByStatus[$row['status']] = (int) $row['total'];
}

$lastFeedOption = get_option('aurora_last_feed_meta', []);
if (is_string($lastFeedOption)) {
    $decoded = json_decode($lastFeedOption, true);
    if (is_array($decoded)) {
        $lastFeedOption = $decoded;
    }
}

$payload = [
    'generated_at_utc' => current_time('mysql', true),
    'window_minutes' => $windowMinutes,
    'ops_recent' => $recentRuns,
    'ops_summary' => array_values($opsSummary),
    'ops_summary_filtered' => array_values($opsSummaryFiltered),
    'ops_errors_filtered' => $opsErrorsFiltered,
    'feed_last_run' => $lastFeedRun,
    'feed_last_meta' => $lastFeedMeta,
    'feed_throughput_rows_per_sec' => $feedThroughput,
    'feed_progress_recent' => $feedProgress,
    'queue_stats' => $queueStats,
    'runtime_stats' => $runtimeStats,
    'snapshot_versions' => $snapshotVersions,
    'action_scheduler_counts' => $actionsByStatus,
    'last_feed_option' => $lastFeedOption,
];

echo wp_json_encode($payload) . "\n";
