<?php
namespace Aurora\Enterprise\CLI;

use WP_CLI_Command;
use WP_CLI;
use Aurora\Enterprise\Queue\Queue_Manager;
use Aurora\Enterprise\Support\SnapshotVersionManager;
use Aurora\Enterprise\Support\SnapshotVersionGuard;
use Aurora\Enterprise\Feed\FeedLockManager;
use Aurora\Enterprise\Feed\FeedChunkProcessor;
use Aurora\Enterprise\Feed\FeedValidator;
use Aurora\Enterprise\Feed\FeedRunManager;
use Aurora\Enterprise\Ops\Ops_Run_Manager;
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
        $snapshotReport = $guard->report();
        if ( ! $snapshotReport['aligned'] ) {
            WP_CLI::error( sprintf( 'Snapshot mismatch: %s', wp_json_encode( $snapshotReport ) ) );
        }
        if ( ! empty( $snapshotReport['pending_out_of_range'] ) ) {
            WP_CLI::error( sprintf( 'Shard mismatch: %s', wp_json_encode( $snapshotReport ) ) );
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

    /**
     * Simulate feed generation end-to-end for stress (up to 10k products).
     *
     * ## OPTIONS
     *
     * [--sku=<int>]
     * : Number of products to include (default 10000).
     */
    public function simulate( array $args, array $assoc_args ) : void {
        $target = isset( $assoc_args['sku'] ) ? max( 1, (int) $assoc_args['sku'] ) : 10000;
        $ids = get_posts( [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'posts_per_page' => $target,
        ] );
        if ( empty( $ids ) ) {
            WP_CLI::error( 'No products found.' );
        }
        if ( count( $ids ) < $target ) {
            WP_CLI::warning( sprintf( 'Only %d products available; using all.', count( $ids ) ) );
        }

        $run    = Ops_Run_Manager::instance();
        $run_id = $run->create_run( 'feed_run', 'feed_run', null, [] );
        if ( $run_id <= 0 ) {
            WP_CLI::error( 'Unable to create ops run.' );
        }

        $lock   = new FeedLockManager();
        $chunk  = new FeedChunkProcessor();
        $validator = new FeedValidator();
        $manager = new FeedRunManager( $lock, $chunk, $validator );

        $result = [];
        $attempts = 0;
        $maxAttempts = 200;
        $deadline = time() + 1800; // 30 min wall time
        while ( $attempts < $maxAttempts && time() < $deadline ) {
            $attempts++;
            $result = $manager->start( $run_id );
            $status = $result['status'] ?? '';
            if ( in_array( $status, [ 'completed', 'success' ], true ) ) {
                break;
            }
            if ( 'partial' !== $status ) {
                break;
            }
            WP_CLI::log( sprintf( 'partial retry %d status=%s', $attempts, $status ) );
            sleep( 2 );
        }

        $meta = get_option( 'aurora_last_feed_meta' );
        $report = [
            'run_id' => $run_id,
            'status' => $result['status'] ?? '',
            'rows'   => $result['rows'] ?? null,
            'files'  => $result['files'] ?? null,
            'meta_option' => $meta,
        ];

        $upload = wp_upload_dir();
        $dir = trailingslashit( $upload['basedir'] ) . 'aurora-feeds/';
        wp_mkdir_p( $dir );
        $reportPath = $dir . sprintf( 'feed_simulate_%d.report.json', $run_id );
        file_put_contents( $reportPath, wp_json_encode( $report ) );

        WP_CLI::log( sprintf( 'Run %d status=%s report=%s', $run_id, $report['status'], $reportPath ) );
        WP_CLI::log( sprintf( 'Rows=%s Files=%s', $report['rows'], is_array( $report['files'] ) ? count( $report['files'] ) : 0 ) );
    }

    /**
     * Stress test up to 50k products.
     *
     * ## OPTIONS
     *
     * [--sku=<int>]
     * : Number of products to include (default 50000).
     */
    public function stress( array $args, array $assoc_args ) : void {
        $target = isset( $assoc_args['sku'] ) ? max( 1, (int) $assoc_args['sku'] ) : 50000;
        $ids = get_posts( [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'posts_per_page' => $target,
        ] );
        if ( empty( $ids ) ) {
            WP_CLI::error( 'No products found.' );
        }
        if ( count( $ids ) < $target ) {
            WP_CLI::warning( sprintf( 'Only %d products available; using all.', count( $ids ) ) );
        }

        $run    = Ops_Run_Manager::instance();
        $run_id = $run->create_run( 'feed_run', 'feed_run', null, [] );
        if ( $run_id <= 0 ) {
            WP_CLI::error( 'Unable to create ops run.' );
        }

        $lock   = new FeedLockManager();
        $chunk  = new FeedChunkProcessor();
        $validator = new FeedValidator();
        $manager = new FeedRunManager( $lock, $chunk, $validator );

        $attempts = 0;
        $maxAttempts = 300;
        $deadline = time() + 3600; // 1h
        $result = [];
        $seen = [];
        $maxSeen = 1000;

        $start = microtime( true );
        while ( $attempts < $maxAttempts && time() < $deadline ) {
            $attempts++;
            $result = $manager->start( $run_id );
            $status = $result['status'] ?? '';
            if ( 'completed' === $status || 'success' === $status ) {
                break;
            }
            if ( 'partial' !== $status ) {
                break;
            }
            WP_CLI::log( sprintf( 'partial retry %d status=%s', $attempts, $status ) );
            sleep( 2 );
        }
        $duration = microtime( true ) - $start;

        $meta = get_option( 'aurora_last_feed_meta' );
        $rows = (int) ( $meta['rows'] ?? 0 );
        $bytes = (int) ( $meta['bytes_total'] ?? 0 );
        $parts = (int) ( $meta['parts'] ?? ( is_array( $meta['files'] ?? null ) ? count( $meta['files'] ) : 0 ) );
        $rps = $duration > 0 ? $rows / $duration : 0;

        $report = [
            'run_id' => $run_id,
            'status' => $result['status'] ?? '',
            'rows' => $rows,
            'bytes' => $bytes,
            'parts' => $parts,
            'duration_sec' => $duration,
            'rows_per_sec' => $rps,
            'meta_option' => $meta,
        ];

        $upload = wp_upload_dir();
        $dir = trailingslashit( $upload['basedir'] ) . 'aurora-feeds/';
        wp_mkdir_p( $dir );
        $reportPath = $dir . sprintf( 'feed_stress_%d.report.json', $run_id );
        file_put_contents( $reportPath, wp_json_encode( $report ) );

        WP_CLI::success( sprintf( 'Feed stress run_id=%d status=%s rows=%d bytes=%d parts=%d rps=%.2f report=%s',
            $run_id, $report['status'], $rows, $bytes, $parts, $rps, $reportPath ) );
    }
}
