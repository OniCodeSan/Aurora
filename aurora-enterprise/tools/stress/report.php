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

$k6ExitCode = null;
$precheckFailed = 0;
$precheckMessage = 'n/a';
$metaFile = dirname($outFile) . '/k6-meta.txt';
if (is_file($metaFile)) {
    $lines = file($metaFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
        $parts = explode('=', $line, 2);
        $key = $parts[0] ?? '';
        $val = $parts[1] ?? '';
        if ($key === 'k6_exit_code') {
            $k6ExitCode = is_numeric($val) ? (int) $val : null;
        } elseif ($key === 'precheck_failed') {
            $precheckFailed = (int) $val;
        } elseif ($key === 'precheck_message') {
            $precheckMessage = $val !== '' ? $val : 'n/a';
        }
    }
}

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

function metric_count($entry) {
    if (!is_array($entry)) {
        return 0;
    }
    if (isset($entry['count'])) {
        return (float) $entry['count'];
    }
    if (isset($entry['values']) && is_array($entry['values'])) {
        if (isset($entry['values']['count'])) {
            return (float) $entry['values']['count'];
        }
        if (isset($entry['values']['value'])) {
            return (float) $entry['values']['value'];
        }
    }
    if (isset($entry['value'])) {
        return (float) $entry['value'];
    }
    return 0;
}

function parse_tagged_metrics($metrics, $metricName) {
    $out = [];
    foreach ($metrics as $name => $entry) {
        if (preg_match('/^' . preg_quote($metricName, '/') . '\\{(.+)\\}$/', $name, $m)) {
            $tags = [];
            foreach (explode(',', $m[1]) as $pair) {
                if ($pair === '') {
                    continue;
                }
                $parts = explode(':', $pair, 2);
                $key = $parts[0] ?? '';
                $val = $parts[1] ?? '';
                if ($key !== '') {
                    $tags[$key] = $val;
                }
            }
            $out[] = [
                'tags' => $tags,
                'count' => metric_count($entry),
            ];
        }
    }
    return $out;
}

$pages = [
    'page_home_duration' => 'home',
    'page_category_duration' => 'category',
    'page_product_duration' => 'product',
    'page_search_duration' => 'search',
];

$results = [];
foreach ($pages as $metric => $label) {
    $p95 = metric($k6, $metric, 'p(95)');
    $p99 = metric($k6, $metric, 'p(99)');
    $results[$label] = [
        'p95' => is_numeric($p95) ? (float) $p95 : null,
        'p99' => is_numeric($p99) ? (float) $p99 : null,
    ];
}

$errorRate = metric($k6, 'http_error_rate', 'rate');

$statusCounts = [];
$errorCountsByEndpoint = [];
foreach (parse_tagged_metrics($k6, 'http_status_count') as $row) {
    $status = $row['tags']['status'] ?? '';
    if ($status === '') {
        continue;
    }
    $statusCounts[$status] = ($statusCounts[$status] ?? 0) + $row['count'];
}
foreach (parse_tagged_metrics($k6, 'http_error_count') as $row) {
    $endpoint = $row['tags']['endpoint'] ?? '';
    if ($endpoint === '') {
        continue;
    }
    $errorCountsByEndpoint[$endpoint] = ($errorCountsByEndpoint[$endpoint] ?? 0) + $row['count'];
}

if (count($statusCounts) === 0) {
    $statusMetricMap = [
        '200' => 'http_status_200',
        '301' => 'http_status_301',
        '302' => 'http_status_302',
        '400' => 'http_status_400',
        '401' => 'http_status_401',
        '403' => 'http_status_403',
        '404' => 'http_status_404',
        '429' => 'http_status_429',
        '500' => 'http_status_500',
        '502' => 'http_status_502',
        '503' => 'http_status_503',
        '504' => 'http_status_504',
        '0' => 'http_status_s0',
    ];
    foreach ($statusMetricMap as $status => $metricName) {
        $statusCounts[$status] = metric_count($k6[$metricName] ?? []);
    }
    $otherStatusCount = metric_count($k6['http_status_other'] ?? []);
    if ($otherStatusCount > 0) {
        $statusCounts['other'] = $otherStatusCount;
    }
}

if (count($errorCountsByEndpoint) === 0) {
    $errorCountsByEndpoint = [
        'home' => metric_count($k6['http_error_endpoint_home'] ?? []),
        'category' => metric_count($k6['http_error_endpoint_category'] ?? []),
        'product' => metric_count($k6['http_error_endpoint_product'] ?? []),
        'search' => metric_count($k6['http_error_endpoint_search'] ?? []),
    ];
    foreach ($errorCountsByEndpoint as $endpoint => $count) {
        if ($count <= 0) {
            unset($errorCountsByEndpoint[$endpoint]);
        }
    }
}

$preferredStatuses = ['200', '301', '302', '400', '401', '403', '404', '429', '500', '502', '503', '504', '0'];
$statusParts = [];
foreach ($preferredStatuses as $status) {
    $statusParts[] = $status . ':' . (isset($statusCounts[$status]) ? $statusCounts[$status] : 0);
}
foreach ($statusCounts as $status => $count) {
    $statusString = (string) $status;
    if (!in_array($statusString, $preferredStatuses, true)) {
        $statusParts[] = $statusString . ':' . $count;
    }
}
$statusCountsLine = implode(',', $statusParts);

arsort($errorCountsByEndpoint);
$topFailingEndpoints = [];
foreach ($errorCountsByEndpoint as $endpoint => $count) {
    $topFailingEndpoints[] = $endpoint . ':' . $count;
    if (count($topFailingEndpoints) >= 5) {
        break;
    }
}
$topFailingLine = implode(',', $topFailingEndpoints);

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

if ($k6ExitCode !== null && $k6ExitCode !== 0) {
    $pass = false;
    $reasons[] = 'k6_failed';
}
if ((int) $precheckFailed === 1) {
    $pass = false;
    $reasons[] = 'precheck_failed';
}

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
$out[] = "k6_exit_code=" . ($k6ExitCode !== null ? $k6ExitCode : 'n/a');
$out[] = "precheck_failed=" . ((int) $precheckFailed === 1 ? '1' : '0');
$out[] = "precheck_message=" . $precheckMessage;
$out[] = "http_error_rate=" . ($errorRate !== null ? $errorRate : 'n/a');
$out[] = "status_counts=" . ($statusCountsLine !== '' ? $statusCountsLine : 'n/a');
$out[] = "top_failing_endpoints=" . ($topFailingLine !== '' ? $topFailingLine : 'n/a');
if (is_file(dirname($outFile) . '/k6-error-samples.json')) {
    $out[] = "error_samples_file=k6-error-samples.json";
} else {
    $out[] = "error_samples_file=n/a";
}
$baseUrl = getenv('BASE_URL') ?: 'n/a';
$paths = [
    'home' => getenv('HOME_PATH') ?: '',
    'category' => getenv('CATEGORY_PATH') ?: '',
    'product' => getenv('PRODUCT_PATH') ?: '',
    'search' => getenv('SEARCH_PATH') ?: '',
];
$out[] = "base_url=" . $baseUrl;
$out[] = "paths=home:" . $paths['home'] . ",category:" . $paths['category'] . ",product:" . $paths['product'] . ",search:" . $paths['search'];
foreach ($results as $label => $vals) {
    $out[] = "{$label}_p95=" . ($vals['p95'] !== null ? $vals['p95'] : 'n/a');
    $out[] = "{$label}_p99=" . ($vals['p99'] !== null ? $vals['p99'] : 'n/a');
}
$out[] = "ops_errors=" . $opsErrors;
$out[] = "ops_errors_filtered=" . $opsErrorsFiltered;
$out[] = "ops_profile=" . $opsProfile;
$out[] = "timing_unit=ms";
$out[] = "dead_queue=" . $deadQueue;
$out[] = "feed_throughput_rows_per_sec=" . ($feedThroughput !== null ? $feedThroughput : 'n/a');
if (!$pass) {
    $out[] = "reasons=" . implode(',', $reasons);
}

$reportText = implode("\n", $out) . "\n";
file_put_contents($outFile, $reportText);
echo $reportText;
