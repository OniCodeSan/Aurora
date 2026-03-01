<?php
namespace Aurora\Enterprise\Ops;

use Aurora\Enterprise\Indexer\PriceIndexer;
use Aurora\Enterprise\Indexer\StockIndexer;
use Aurora\Enterprise\Indexer\VisibilityIndexer;
use Aurora\Enterprise\Queue\Queue_Manager;
use Aurora\Enterprise\Support\Config;
use Aurora\Enterprise\Support\SnapshotVersionGuard;
use Aurora\Enterprise\Support\SnapshotVersionManager;
use Aurora\Enterprise\Worker\WorkerRunner;
use Aurora\Enterprise\Feed\FeedLockManager;
use Aurora\Enterprise\Feed\FeedChunkProcessor;
use Aurora\Enterprise\Feed\FeedValidator;
use Aurora\Enterprise\Feed\FeedRunManager;
use Aurora\Enterprise\Repricer\RepriceLockManager;
use Aurora\Enterprise\Repricer\RepriceChunkProcessor;
use Aurora\Enterprise\Repricer\RepriceRunManager;

class Ops_Executor {
    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function execute( string $opKey, array $payload = [] ) : array {
        switch ( $opKey ) {
            case 'sweep_leases':
                return $this->execute_sweep_leases( $payload );
            case 'rebuild':
                return $this->execute_rebuild( $payload );
            case 'feed_enqueue':
                return $this->execute_feed_enqueue( $payload );
            case 'feed_run':
                return $this->execute_feed_run( $payload );
            case 'repricer_run':
                return $this->execute_repricer_run( $payload );
            default:
                throw new \RuntimeException( 'Unsupported op key: ' . $opKey );
        }
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function execute_sweep_leases( array $payload ) : array {
        $channel = isset( $payload['channel'] ) ? (string) $payload['channel'] : null;
        if ( 'all' === $channel || '' === $channel ) {
            $channel = null;
        }
        if ( null !== $channel && ! in_array( $channel, [ 'price', 'stock', 'visibility', 'feed' ], true ) ) {
            throw new \RuntimeException( 'Invalid sweep channel: ' . $channel );
        }

        $olderThan = isset( $payload['older_than'] ) ? max( 0, (int) $payload['older_than'] ) : Config::leaseTtlSeconds();
        $shard = array_key_exists( 'shard', $payload ) ? (int) $payload['shard'] : null;
        $totalShards = isset( $payload['total_shards'] ) ? max( 1, (int) $payload['total_shards'] ) : Config::totalShards();

        $result = Queue_Manager::instance()->sweep_leases( $channel, $olderThan, $shard, $totalShards );
        $result['message'] = sprintf(
            'sweep ok: requeued=%d, dead=%d, ttl=%d',
            (int) ( $result['requeued'] ?? 0 ),
            (int) ( $result['dead'] ?? 0 ),
            $olderThan
        );

        return $result;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function execute_rebuild( array $payload ) : array {
        $target = isset( $payload['indexer'] ) ? (string) $payload['indexer'] : 'all';
        if ( ! in_array( $target, [ 'price', 'stock', 'visibility', 'all' ], true ) ) {
            throw new \RuntimeException( 'Invalid rebuild indexer: ' . $target );
        }

        $indexers = [
            'price'      => new PriceIndexer(),
            'stock'      => new StockIndexer(),
            'visibility' => new VisibilityIndexer(),
        ];

        $rebuilt = [];
        foreach ( $indexers as $key => $service ) {
            if ( 'all' !== $target && $key !== $target ) {
                continue;
            }
            $service->fullRebuild();
            update_option( 'aurora_last_rebuild_' . $key, current_time( 'mysql', true ), false );
            $rebuilt[] = $key;
        }

        return [
            'message' => sprintf( 'rebuild done indexer=%s count=%d', $target, count( $rebuilt ) ),
            'indexer' => $target,
            'rebuilt' => $rebuilt,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function execute_feed_enqueue( array $payload ) : array {
        $chunkSize = isset( $payload['chunk_size'] ) ? max( 100, (int) $payload['chunk_size'] ) : 1000;
        $guard = new SnapshotVersionGuard();
        $snapshotReport = $guard->report();
        if ( ! $snapshotReport['aligned'] ) {
            throw new \RuntimeException( 'Snapshot mismatch: ' . wp_json_encode( $snapshotReport ) );
        }
        if ( ! empty( $snapshotReport['pending_out_of_range'] ) ) {
            throw new \RuntimeException( 'Shard mismatch: ' . wp_json_encode( $snapshotReport ) );
        }

        global $wpdb;
        $versionManager = new SnapshotVersionManager();
        $tableName      = $wpdb->prefix . 'aurora_price_snapshot';
        $cutVersion     = $versionManager->currentVersion( $tableName );
        $feedId         = isset( $payload['feed_id'] ) ? sanitize_key( (string) $payload['feed_id'] ) : wp_generate_uuid4();
        if ( '' === $feedId ) {
            $feedId = wp_generate_uuid4();
        }

        $productIds = get_posts( [
            'post_type'      => 'product',
            'fields'         => 'ids',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
        ] );

        if ( empty( $productIds ) ) {
            return [
                'message'  => 'feed queued chunks=0 (no products found)',
                'feed_id'  => $feedId,
                'chunks'   => 0,
                'products' => 0,
            ];
        }

        $chunks = array_chunk( $productIds, $chunkSize );
        $queue  = Queue_Manager::instance();
        $total  = count( $chunks );
        foreach ( $chunks as $index => $chunk ) {
            $queue->enqueue( 'feed', [
                'feed_id'               => $feedId,
                'chunk'                 => $index + 1,
                'total_chunks'          => $total,
                'product_ids'           => $chunk,
                'snapshot_cut_version'  => $cutVersion,
            ] );
        }

        return [
            'message'          => sprintf( 'feed queued chunks=%d feed_id=%s', $total, $feedId ),
            'feed_id'          => $feedId,
            'chunks'           => $total,
            'chunk_size'       => $chunkSize,
            'snapshot_version' => $cutVersion,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function execute_feed_run( array $payload ) : array {
        $runId = (int) ( $payload['run_id'] ?? 0 );
        if ( $runId <= 0 ) {
            throw new \RuntimeException( 'Missing run_id for feed_run' );
        }
        $manager = new FeedRunManager(
            new FeedLockManager(),
            new FeedChunkProcessor(),
            new FeedValidator()
        );
        $result = $manager->start( $runId );
        return [
            'message' => $result['status'] ?? 'feed_run',
            'run_id'  => $runId,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function execute_repricer_run( array $payload ) : array {
        $runId = (int) ( $payload['run_id'] ?? 0 );
        if ( $runId <= 0 ) {
            throw new \RuntimeException( 'Missing run_id for repricer_run' );
        }
        $manager = new RepriceRunManager(
            new RepriceChunkProcessor(),
            new RepriceLockManager()
        );
        $manager->start( $runId, $payload );
        return [
            'message' => 'repricer_run',
            'run_id'  => $runId,
        ];
    }
}
