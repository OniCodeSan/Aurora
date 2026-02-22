<?php
namespace Aurora\Enterprise\CLI;

use WP_CLI_Command;
use WP_CLI;
use Aurora\Enterprise\Queue\Queue_Manager;
use Aurora\Enterprise\Support\SnapshotVersionManager;
use Aurora\Enterprise\Support\SnapshotVersionGuard;
use function sanitize_key;

class Feed_Command extends WP_CLI_Command {
    /**
     * Enqueue feed generation jobs chunked by product IDs.
     *
     * ## OPTIONS
     *
     * [--chunk-size=<int>]
     * : Number of products per job (default 1000).
     *
     * [--feed-id=<string>]
     * : Optional feed identifier. Defaults to UUID.
     */
    public function enqueue( array $args, array $assoc_args ) : void {
        $chunkSize = isset( $assoc_args['chunk-size'] ) ? max( 100, (int) $assoc_args['chunk-size'] ) : 1000;
        $guard = new SnapshotVersionGuard();
        if ( ! $guard->isAligned() ) {
            WP_CLI::error( 'Snapshot versions not aligned. Run rebuild price/stock/visibility before enqueuing feed.' );
        }
        $versionManager = new SnapshotVersionManager();
        global $wpdb;
        $tableName = $wpdb->prefix . 'aurora_price_snapshot';
        $cutVersion = $versionManager->currentVersion( $tableName );
        WP_CLI::log( sprintf( 'Using snapshot cut version %d for feed.', $cutVersion ) );
        $feedId    = isset( $assoc_args['feed-id'] ) ? sanitize_key( $assoc_args['feed-id'] ) : wp_generate_uuid4();

        $product_ids = get_posts( [
            'post_type'      => 'product',
            'fields'         => 'ids',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
        ] );
        if ( empty( $product_ids ) ) {
            WP_CLI::warning( 'No products found to include in feed.' );
            return;
        }

        $chunks = array_chunk( $product_ids, $chunkSize );
        $queue  = Queue_Manager::instance();
        $total  = count( $chunks );
        foreach ( $chunks as $index => $chunk ) {
            $queue->enqueue( 'feed', [
                'feed_id'     => $feedId,
                'chunk'       => $index + 1,
                'total_chunks'=> $total,
                'product_ids' => $chunk,
                'snapshot_cut_version' => $cutVersion,
            ] );
        }
        WP_CLI::success( sprintf( 'Queued feed %s (%d chunks of %d products) @ version %d.', $feedId, $total, $chunkSize, $cutVersion ) );
    }
}
