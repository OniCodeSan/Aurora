#!/usr/bin/env php
<?php
if ( php_sapi_name() !== 'cli' ) {
    exit( 1 );
}
require dirname( __DIR__, 4 ) . '/wp-load.php';

$indexer  = $argv[1] ?? 'all';
$batch    = isset( $argv[2] ) ? (int) $argv[2] : 750;
$loops    = isset( $argv[3] ) ? (int) $argv[3] : 1;

$runner = new Aurora\Enterprise\Worker\WorkerRunner( $indexer, $batch, $loops );
$processed = $runner->run();
fprintf( STDOUT, "Processed %d jobs\n", $processed );
