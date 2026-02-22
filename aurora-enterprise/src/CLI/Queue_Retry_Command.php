<?php
namespace Aurora\Enterprise\CLI;

use WP_CLI_Command;
use WP_CLI;
use Aurora\Enterprise\Queue\Queue_Manager;

class Queue_Retry_Command extends WP_CLI_Command {
    /**
     * Retry jobs in the dead-letter queue.
     *
     * ## OPTIONS
     * [--channel=<price|stock|visibility|feed|all>]
     * [--limit=<int>]
     */
    public function __invoke( array $args, array $assoc_args ) : void {
        $queue = $assoc_args['channel'] ?? null;
        $limit = isset( $assoc_args['limit'] ) ? max( 1, min( 1000, (int) $assoc_args['limit'] ) ) : 100;
        $count = Queue_Manager::instance()->retryDead( $queue ?: null, $limit );
        if ( $count <= 0 ) {
            WP_CLI::warning( 'No dead-letter jobs retried.' );
            return;
        }
        WP_CLI::success( sprintf( 'Retried %d job(s)%s.', $count, $queue ? " on {$queue}" : '' ) );
    }
}
