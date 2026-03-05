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
use Aurora\Enterprise\Support\Logger;

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
            case 'repricer_rollback':
                return $this->execute_repricer_rollback( $payload );
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
        $normalized = $this->normalize_reprice_payload( $payload );
        ( new Logger() )->info( 'repricer', 'repricer normalized payload', [ 'run_id' => $runId, 'payload' => $normalized ] );
        $manager = new RepriceRunManager(
            new RepriceChunkProcessor(),
            new RepriceLockManager()
        );
        $manager->start( $runId, $normalized );
        return [
            'message' => 'repricer_run',
            'run_id'  => $runId,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function execute_repricer_rollback( array $payload ) : array {
        $runId = (int) ( $payload['run_id'] ?? 0 );
        $targetRunId = (int) ( $payload['target_run_id'] ?? 0 );
        if ( $targetRunId <= 0 ) {
            throw new \RuntimeException( 'Missing target_run_id for repricer_rollback' );
        }
        $limit = isset( $payload['chunk_size'] ) ? max( 1, (int) $payload['chunk_size'] ) : 200;
        $dry   = isset( $payload['dry_run'] ) ? (bool) $payload['dry_run'] : false;
        $service = new \Aurora\Enterprise\Repricer\RepriceRollbackService();
        $result = $service->rollback_run( $targetRunId, $limit, $dry );
        $result['message']       = 'repricer_rollback';
        $result['run_id']        = $runId;
        $result['target_run_id'] = $targetRunId;
        return $result;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function normalize_reprice_payload( array $payload ) : array {
        $map = [
            'maxProducts'        => 'max_products',
            'max_products'       => 'max_products',
            'chunkSize'          => 'chunk_size',
            'chunk_size'         => 'chunk_size',
            'timeboxSeconds'     => 'timebox_seconds',
            'timebox_seconds'    => 'timebox_seconds',
            'timebox'            => 'timebox_seconds',
            'minMarginPercent'   => 'min_margin_percent',
            'min_margin_percent' => 'min_margin_percent',
            'minMarginAbs'       => 'min_margin_abs',
            'min_margin_abs'     => 'min_margin_abs',
            'strategy'           => 'strategy',
            'marginMode'         => 'margin_mode',
            'margin_mode'        => 'margin_mode',
            'roundingMode'       => 'rounding_mode',
            'rounding_mode'      => 'rounding_mode',
            'roundingStep'       => 'rounding_step',
            'rounding_step'      => 'rounding_step',
            'maxRaisePct'        => 'max_raise_pct',
            'max_raise_pct'      => 'max_raise_pct',
            'maxDropPct'         => 'max_drop_pct',
            'max_drop_pct'       => 'max_drop_pct',
            'beatDeltaAbs'       => 'beat_delta_abs',
            'beat_delta_abs'     => 'beat_delta_abs',
            'beatDeltaPct'       => 'beat_delta_pct',
            'beat_delta_pct'     => 'beat_delta_pct',
            'targetMarginPercent'=> 'target_margin_percent',
            'target_margin_percent'=> 'target_margin_percent',
            'targetMarginAbs'    => 'target_margin_abs',
            'target_margin_abs'  => 'target_margin_abs',
            'competitorPrice'    => 'competitor_price',
            'competitor_price'   => 'competitor_price',
            'minPrice'           => 'min_price',
            'min_price'          => 'min_price',
            'maxPrice'           => 'max_price',
            'max_price'          => 'max_price',
            'mapPrice'           => 'map_price',
            'map_price'          => 'map_price',
            'dryRun'             => 'dry_run',
            'dry_run'            => 'dry_run',
            'mode'               => 'mode',
        ];

        $normalized = [];
        foreach ( $payload as $key => $value ) {
            $target = $map[ $key ] ?? $key;
            $normalized[ $target ] = $value;
        }

        return [
            'run_id'             => (int) ( $normalized['run_id'] ?? $payload['run_id'] ?? 0 ),
            'max_products'       => isset( $normalized['max_products'] ) ? (int) $normalized['max_products'] : 10000,
            'chunk_size'         => isset( $normalized['chunk_size'] ) ? (int) $normalized['chunk_size'] : 500,
            'timebox_seconds'    => isset( $normalized['timebox_seconds'] ) ? (int) $normalized['timebox_seconds'] : 90,
            'min_margin_percent' => isset( $normalized['min_margin_percent'] ) ? (float) $normalized['min_margin_percent'] : 0.0,
            'min_margin_abs'     => isset( $normalized['min_margin_abs'] ) ? (float) $normalized['min_margin_abs'] : 0.0,
            'strategy'           => isset( $normalized['strategy'] ) ? (string) $normalized['strategy'] : 'maintain_margin',
            'margin_mode'        => isset( $normalized['margin_mode'] ) ? (string) $normalized['margin_mode'] : 'clamp',
            'rounding_mode'      => isset( $normalized['rounding_mode'] ) ? (string) $normalized['rounding_mode'] : 'none',
            'rounding_step'      => isset( $normalized['rounding_step'] ) ? (float) $normalized['rounding_step'] : 0.0,
            'max_raise_pct'      => isset( $normalized['max_raise_pct'] ) ? (float) $normalized['max_raise_pct'] : 0.0,
            'max_drop_pct'       => isset( $normalized['max_drop_pct'] ) ? (float) $normalized['max_drop_pct'] : 0.0,
            'beat_delta_abs'     => isset( $normalized['beat_delta_abs'] ) ? (float) $normalized['beat_delta_abs'] : 0.0,
            'beat_delta_pct'     => isset( $normalized['beat_delta_pct'] ) ? (float) $normalized['beat_delta_pct'] : 0.0,
            'target_margin_percent' => isset( $normalized['target_margin_percent'] ) ? (float) $normalized['target_margin_percent'] : null,
            'target_margin_abs'     => isset( $normalized['target_margin_abs'] ) ? (float) $normalized['target_margin_abs'] : null,
            'competitor_price'   => isset( $normalized['competitor_price'] ) ? (float) $normalized['competitor_price'] : null,
            'min_price'          => isset( $normalized['min_price'] ) ? (float) $normalized['min_price'] : null,
            'max_price'          => isset( $normalized['max_price'] ) ? (float) $normalized['max_price'] : null,
            'map_price'          => isset( $normalized['map_price'] ) ? (float) $normalized['map_price'] : null,
            'dry_run'            => array_key_exists( 'dry_run', $normalized ) ? (bool) $normalized['dry_run'] : true,
            'mode'               => isset( $normalized['mode'] ) ? (string) $normalized['mode'] : 'dry_run',
        ];
    }
}
