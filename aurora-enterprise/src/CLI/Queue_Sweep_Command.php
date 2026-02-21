<?php
namespace Aurora\Enterprise\CLI;

use WP_CLI_Command;
use WP_CLI;
use Aurora\Enterprise\Queue\Queue_Manager;
use Aurora\Enterprise\Queue\DatabaseQueue;

class Queue_Sweep_Command extends WP_CLI_Command {
    /**
     * Sweep expired leases and requeue stuck jobs.
     *
     * ## OPTIONS
     * [--channel=<price|stock|visibility|feed|all>]
     * [--older-than=<seconds>]
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
        $result = $queue->sweepExpiredLeases( 'all' === $channel ? null : $channel, $olderThan );
        WP_CLI::success( sprintf(
            'Requeued %d leases, marked %d jobs dead.',
            $result['requeued'] ?? 0,
            $result['dead'] ?? 0
        ) );
    }
}
