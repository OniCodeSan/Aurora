<?php
declare(strict_types=1);

// Dev smoke script: minimal feed chunk + writer test.

require_once __DIR__ . '/../wp-load.php';

use Aurora\Enterprise\Feed\FeedChunkProcessor;
use Aurora\Enterprise\Feed\FeedJsonlWriter;

global $wpdb;

$version = (int) $wpdb->get_var(
    $wpdb->prepare(
        "SELECT current_version FROM {$wpdb->prefix}aurora_snapshot_versions WHERE table_name = %s",
        $wpdb->prefix . 'aurora_price_snapshot'
    )
);
if ($version <= 0) {
    $version = 0; // fallback: will force index path
}

$processor = new FeedChunkProcessor(10);
$chunk = $processor->fetchChunk($version, 0, 10);

$writer = new FeedJsonlWriter(9999, 1024 * 1024, 3);
$writer->open(1, true);
foreach ($chunk['rows'] as $row) {
    $writer->writeLine(wp_json_encode($row));
    $writer->maybeRotate();
}
$final = $writer->finalizeCurrentPart();
$writer->close();

echo "Feed smoke written: {$final}\n";
echo 'Rows: ' . $chunk['count'] . " Bytes: " . $writer->getWrittenBytes() . "\n";
