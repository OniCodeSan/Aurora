<?php
namespace Aurora\Enterprise\CLI;

use WP_CLI_Command;
use WP_CLI;
use Aurora\Enterprise\Queue\Queue_Manager;

use function sprintf;
use function hash;
use function substr;

class Test_Command extends WP_CLI_Command {
    /**
     * Seed the queue with deterministic payloads for test scenarios.
     *
     * Preferred usage: `wp aurora test seed-queue` (alias: seed_queue).
     *
     * @subcommand seed-queue
     *
     * ## OPTIONS
     *
     * [--count=<int>]
     * : Number of jobs to enqueue. Default: 60.
     *
     * [--channel=<price|stock|visibility|feed>]
     * : Target queue channel. Default: price.
     *
     * [--reset]
     * : Truncate queue + idempotence cache before seeding.
     */
    public function seed_queue( array $args, array $assoc_args ) : void {
        $count   = isset( $assoc_args['count'] ) ? max( 1, (int) $assoc_args['count'] ) : 60;
        $channel = $assoc_args['channel'] ?? 'price';
        $reset   = isset( $assoc_args['reset'] );

        if ( $reset ) {
            $this->resetQueues();
            WP_CLI::log( 'Queue + idempotence cache truncated.' );
        }

        $queue = Queue_Manager::instance();
        $first = null;
        $last  = null;

        for ( $i = 1; $i <= $count; $i++ ) {
            $payload = $this->buildPayload( $channel, $i );
            $jobId   = $queue->enqueue( $channel, $payload );
            $first   = $first ?? $jobId;
            $last    = $jobId;
        }

        WP_CLI::success( sprintf( 'Seeded %d jobs on %s (first: %s, last: %s)', $count, $channel, $first, $last ) );
    }

    private function resetQueues() : void {
        global $wpdb;
        $queueTable       = $wpdb->prefix . 'product_index_queue';
        $idempotenceTable = $wpdb->prefix . 'aurora_idempotence_cache';
        $wpdb->query( "TRUNCATE TABLE {$queueTable}" );
        $wpdb->query( "TRUNCATE TABLE {$idempotenceTable}" );
    }

    private function buildPayload( string $channel, int $sequence ) : array {
        $productId = 100000 + $sequence;
        $fingerprint = substr( hash( 'sha256', $channel . ':' . $productId ), 0, 16 );

        return [
            'product_id' => $productId,
            'channel'    => $channel,
            'checksum'   => $fingerprint,
            'price'      => 10.0 + $sequence,
            'retry_key'  => $fingerprint,
        ];
    }
}
