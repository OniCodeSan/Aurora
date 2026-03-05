<?php
namespace Aurora\Enterprise\CLI;

use WP_CLI_Command;
use WP_CLI;
use Aurora\Enterprise\Queue\Queue_Manager;

class Enqueue_Command extends WP_CLI_Command {
    /**
     * Enqueue a product for indexing.
     *
     * ## OPTIONS
     *
     * --type=<price|stock|visibility>
     * --product=<id>
     */
    public function __invoke( array $args, array $assoc_args ) : void {
        $type    = $assoc_args['type'] ?? '';
        $product = isset( $assoc_args['product'] ) ? (int) $assoc_args['product'] : 0;
        if ( ! in_array( $type, [ 'price', 'stock', 'visibility' ], true ) || ! $product ) {
            WP_CLI::error( 'Specify --type=price|stock|visibility and --product=ID' );
        }
        $queue   = Queue_Manager::instance();
        $job_id  = $queue->enqueue( $type, [ 'product_id' => $product ] );
        WP_CLI::success( sprintf( 'Enqueued job %s (%s)', $job_id, $type ) );
    }
}
