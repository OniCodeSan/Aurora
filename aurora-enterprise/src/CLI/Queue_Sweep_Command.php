<?php
namespace Aurora\Enterprise\CLI;

use WP_CLI_Command;
use WP_CLI;
use Aurora\Enterprise\Queue\Queue_Manager;
use Aurora\Enterprise\Queue\DatabaseQueue;
use Aurora\Enterprise\Support\Config;

class Queue_Sweep_Command extends WP_CLI_Command {
    /**
     * Sweep expired leases and requeue stuck jobs.
     *
     * ## OPTIONS
     * [--channel=<price|stock|visibility|feed|all>]
     * [--older-than=<seconds>]
     * [--shard=<int>]
     * [--total=<int>]
     */
    public function __invoke( array $args, array $assoc_args ) : void {
        $channel   = $assoc_args['channel'] ?? 'all';
        $allowed   = [ 'price', 'stock', 'visibility', 'feed', 'all' ];
        if ( ! in_array( $channel, $allowed, true ) ) {
            WP_CLI::error( 'Invalid channel. Use price, stock, visibility, feed, or all.' );
        }
        $olderThan = isset( $assoc_args['older-than'] ) ? (int) $assoc_args['older-than'] : 60;
        if ( $olderThan < 0 ) {
            WP_CLI::error( 'older-than must be >= 0 seconds' );
        }
        $queue = Queue_Manager::instance()->driver();
        if ( ! $queue instanceof DatabaseQueue ) {
            WP_CLI::error( 'Lease sweeping is only supported for the database queue driver.' );
        }
        $totalShards = isset( $assoc_args['total'] ) ? max( 1, (int) $assoc_args['total'] ) : Config::totalShards();
        $shardArg = array_key_exists( 'shard', $assoc_args ) ? (int) $assoc_args['shard'] : null;
        if ( null !== $shardArg && ( $shardArg < 0 || $shardArg >= $totalShards ) ) {
            WP_CLI::error( sprintf( 'Shard must be between 0 and %d', $totalShards - 1 ) );
        }
        $targetShards = null === $shardArg ? range( 0, $totalShards - 1 ) : [ $shardArg ];
        $totalRequeued = 0;
        $totalDead     = 0;
        foreach ( $targetShards as $shard ) {
            $result = $queue->sweepExpiredLeases( 'all' === $channel ? null : $channel, $olderThan, $shard );
            $requeued = (int) ( $result['requeued'] ?? 0 );
            $dead     = (int) ( $result['dead'] ?? 0 );
            $totalRequeued += $requeued;
            $totalDead     += $dead;
            WP_CLI::log( sprintf( 'Shard %d => requeued %d, dead %d', $shard, $requeued, $dead ) );
        }
        WP_CLI::success( sprintf( 'Total: requeued %d, dead %d', $totalRequeued, $totalDead ) );
    }
}
