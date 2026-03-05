<?php
namespace Aurora\Enterprise\CLI;

use WP_CLI_Command;
use WP_CLI;
use Aurora\Enterprise\Worker\WorkerRunner;
use Aurora\Enterprise\Worker\CrashSimulationException;
use Aurora\Enterprise\Queue\Queue_Manager;
use Aurora\Enterprise\Support\Config;

class Worker_Command extends WP_CLI_Command {
    /**
     * Run the Aurora worker loop.
     *
     * ## OPTIONS
     *
     * [--indexer=<price|stock|visibility|all>]
     * [--batch=<int>]
     * [--max-loops=<int>]
     * [--simulate-crash-after=<int>]
     * [--shard=<int>]
     * [--total=<int>]
     * [--aurora-debug]
     */
    public function __invoke( array $args, array $assoc_args ) : void {
        $indexer  = $assoc_args['indexer'] ?? 'all';
        $batch    = isset( $assoc_args['batch'] ) ? (int) $assoc_args['batch'] : 750;
        $maxLoops = isset( $assoc_args['max-loops'] ) ? (int) $assoc_args['max-loops'] : 1;
        $simulate = isset( $assoc_args['simulate-crash-after'] ) ? (int) $assoc_args['simulate-crash-after'] : null;
        $totalShards = isset( $assoc_args['total'] ) ? max( 1, (int) $assoc_args['total'] ) : Config::totalShards();
        $shard   = isset( $assoc_args['shard'] ) ? (int) $assoc_args['shard'] : null;
        $debug   = isset( $assoc_args['aurora-debug'] );

        if ( null !== $simulate && $simulate <= 0 ) {
            WP_CLI::error( '--simulate-crash-after must be greater than zero.' );
        }
        if ( null !== $shard ) {
            if ( $shard < 0 || $shard >= $totalShards ) {
                WP_CLI::error( sprintf( 'Shard must be between 0 and %d', $totalShards - 1 ) );
            }
        }

        $runner = new WorkerRunner( $indexer, $batch, $maxLoops, $simulate, $shard, $totalShards, $debug );
        if ( $debug ) {
            $driver = Queue_Manager::instance()->driver();
            $driverClass = get_class( $driver );
            $definedDriver = defined( 'AURORA_QUEUE_DRIVER' ) ? AURORA_QUEUE_DRIVER : 'unset';
            $redisExt = extension_loaded( 'redis' ) ? 'on' : 'off';
            $prefix = $GLOBALS['wpdb']->prefix ?? 'n/a';
            $blogId = function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 'n/a';
            \WP_CLI::log( sprintf( 'worker.debug driver=%s env_driver=%s redis_ext=%s wpdb_prefix=%s blog_id=%s', $driverClass, $definedDriver, $redisExt, $prefix, $blogId ) );
        }
        try {
            $processed = $runner->run();
            WP_CLI::success( sprintf( 'Processed %d jobs', $processed ) );
        } catch ( CrashSimulationException $exception ) {
            WP_CLI::error( $exception->getMessage() );
        }
    }
}
