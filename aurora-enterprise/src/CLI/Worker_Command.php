<?php
namespace Aurora\Enterprise\CLI;

use WP_CLI_Command;
use WP_CLI;
use Aurora\Enterprise\Worker\WorkerRunner;

class Worker_Command extends WP_CLI_Command {
    /**
     * Run the Aurora worker loop.
     *
     * ## OPTIONS
     *
     * [--indexer=<price|stock|visibility|all>]
     * [--batch=<int>]
     * [--max-loops=<int>]
     */
    public function __invoke( array $args, array $assoc_args ) : void {
        $indexer  = $assoc_args['indexer'] ?? 'all';
        $batch    = isset( $assoc_args['batch'] ) ? (int) $assoc_args['batch'] : 750;
        $maxLoops = isset( $assoc_args['max-loops'] ) ? (int) $assoc_args['max-loops'] : 1;

        $runner = new WorkerRunner( $indexer, $batch, $maxLoops );
        $processed = $runner->run();
        WP_CLI::success( sprintf( 'Processed %d jobs', $processed ) );
    }
}
