<?php
$summaryFile = $argv[1] ?? '';
$metricsFile = $argv[2] ?? '';
$outFile = $argv[3] ?? '';

if ($summaryFile === '' || $metricsFile === '' || $outFile === '') {
    fwrite(STDERR, "usage: report.php <k6-summary.json> <metrics.json> <out.txt>\n");
    exit(1);
}

$summary = json_decode(@file_get_contents($summaryFile), true) ?: [];
$metrics = json_decode(@file_get_contents($metricsFile), true) ?: [];

$k6 = $summary['metrics'] ?? [];

function metric($metrics, $name, $key) {
    if (!isset($metrics[$name]) || !is_array($metrics[$name])) {
        return null;
    }
    $entry = $metrics[$name];
    if (isset($entry['values']) && is_array($entry['values'])) {
        return $entry['values'][$key] ?? null;
    }
    if ($key === 'rate' && isset($entry['value'])) {
        return $entry['value'];
    }
    return $entry[$key] ?? null;
}

function to_ms($value, &$converted) {
    if ($value === null) {
        return null;
    }
    if (!is_numeric($value)) {
        return null;
    }
    $v = (float) $value;
    if ($v < 1000 && abs($v - round($v)) > 0.000001) {
        $converted = true;
        return $v * 1000;
    }
    return $v;
}

$pages = [
    'page_home_duration' => 'home',
    'page_category_duration' => 'category',
    'page_product_duration' => 'product',
    'page_search_duration' => 'search',
];

$results = [];
$timingConverted = false;
foreach ($pages as $metric => $label) {
    $results[$label] = [
        'p95' => to_ms(metric($k6, $metric, 'p(95)'), $timingConverted),
        'p99' => to_ms(metric($k6, $metric, 'p(99)'), $timingConverted),
    ];
}

$errorRate = metric($k6, 'http_error_rate', 'rate');

$opsSummary = $metrics['ops_summary'] ?? [];
$opsSummaryFiltered = $metrics['ops_summary_filtered'] ?? [];
$opsErrorsFiltered = 0;
$opsErrors = 0;
foreach ($opsSummary as $row) {
    if (($row['status'] ?? '') === 'error') {
        $opsErrors += (int) ($row['total'] ?? 0);
    }
}
foreach ($opsSummaryFiltered as $row) {
    if (($row['status'] ?? '') === 'error') {
        $opsErrorsFiltered += (int) ($row['total'] ?? 0);
    }
}

$deadQueue = 0;
if (isset($metrics['queue_stats']['dead'])) {
    $deadQueue = (int) $metrics['queue_stats']['dead'];
}

$feedThroughput = $metrics['feed_throughput_rows_per_sec'] ?? null;

$maxHttpErrorRate = (float) (getenv('MAX_HTTP_ERROR_RATE') ?: 0.05);
$maxOpsErrors = (int) (getenv('MAX_OPS_ERRORS') ?: 0);
$opsProfile = getenv('OPS_PROFILE') ?: 'repricer';
$maxDeadQueue = (int) (getenv('MAX_DEAD_QUEUE') ?: 0);

$thresholds = [
    'home_p95' => (float) (getenv('MAX_HOME_P95_MS') ?: 800),
    'home_p99' => (float) (getenv('MAX_HOME_P99_MS') ?: 1500),
    'category_p95' => (float) (getenv('MAX_CATEGORY_P95_MS') ?: 1000),
    'category_p99' => (float) (getenv('MAX_CATEGORY_P99_MS') ?: 2000),
    'product_p95' => (float) (getenv('MAX_PRODUCT_P95_MS') ?: 1200),
    'product_p99' => (float) (getenv('MAX_PRODUCT_P99_MS') ?: 2500),
    'search_p95' => (float) (getenv('MAX_SEARCH_P95_MS') ?: 1500),
    'search_p99' => (float) (getenv('MAX_SEARCH_P99_MS') ?: 3000),
];

$pass = true;
$reasons = [];

if ($errorRate !== null && $errorRate > $maxHttpErrorRate) {
    $pass = false;
    $reasons[] = "http_error_rate>{$maxHttpErrorRate}";
}

foreach ($results as $label => $vals) {
    $p95 = $vals['p95'];
    $p99 = $vals['p99'];
    if ($p95 !== null && $p95 > $thresholds[$label . '_p95']) {
        $pass = false;
        $reasons[] = "{$label}_p95>{$thresholds[$label . '_p95']}";
    }
    if ($p99 !== null && $p99 > $thresholds[$label . '_p99']) {
        $pass = false;
        $reasons[] = "{$label}_p99>{$thresholds[$label . '_p99']}";
    }
}

if ($opsProfile === 'repricer') {
    if ($opsErrorsFiltered > $maxOpsErrors) {
        $pass = false;
        $reasons[] = "ops_errors_filtered>{$maxOpsErrors}";
    }
} elseif ($opsErrors > $maxOpsErrors) {
    $pass = false;
    $reasons[] = "ops_errors>{$maxOpsErrors}";
}

if ($deadQueue > $maxDeadQueue) {
    $pass = false;
    $reasons[] = "dead_queue>{$maxDeadQueue}";
}

$out = [];
$out[] = "RESULT=" . ($pass ? 'PASS' : 'FAIL');
$out[] = "http_error_rate=" . ($errorRate !== null ? $errorRate : 'n/a');
foreach ($results as $label => $vals) {
    $out[] = "{$label}_p95=" . ($vals['p95'] !== null ? $vals['p95'] : 'n/a');
    $out[] = "{$label}_p99=" . ($vals['p99'] !== null ? $vals['p99'] : 'n/a');
}
$out[] = "ops_errors=" . $opsErrors;
$out[] = "ops_errors_filtered=" . $opsErrorsFiltered;
$out[] = "ops_profile=" . $opsProfile;
$out[] = "timing_unit=ms";
if ($timingConverted) {
    $out[] = "timing_converted_from=s";
}
$out[] = "dead_queue=" . $deadQueue;
$out[] = "feed_throughput_rows_per_sec=" . ($feedThroughput !== null ? $feedThroughput : 'n/a');
if (!$pass) {
    $out[] = "reasons=" . implode(',', $reasons);
}

$reportText = implode("\n", $out) . "\n";
file_put_contents($outFile, $reportText);
echo $reportText;
