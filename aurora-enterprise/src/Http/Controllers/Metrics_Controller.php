<?php
namespace Aurora\Enterprise\Http\Controllers;

use Aurora\Enterprise\Support\Runtime_Stats;
use Aurora\Enterprise\Support\CheckpointStore;
use Aurora\Enterprise\Support\Config;
use WP_REST_Request;
use WP_REST_Response;

use function array_fill_keys;
use function current_time;
use function strtotime;
use function time;
use function max;

class Metrics_Controller {
    private const CHANNELS = [ 'price', 'stock', 'visibility', 'feed' ];

    public function register_routes() : void {
        register_rest_route( 'aurora/v1', '/metrics', [
            'methods'             => 'GET',
            'permission_callback' => [ $this, 'can_manage' ],
            'callback'            => [ $this, 'metrics' ],
        ] );
    }

    public function can_manage() : bool {
        return current_user_can( 'manage_woocommerce' );
    }

    public function metrics( WP_REST_Request $request ) : WP_REST_Response {
        global $wpdb;
        if ( ! $wpdb ) {
            return new WP_REST_Response( [ 'error' => 'wpdb unavailable' ], 500 );
        }
        $table         = $wpdb->prefix . 'product_index_queue';
        $totalShards   = Config::totalShards();
        $checkpointMap = ( new CheckpointStore() )->fetchForChannels( self::CHANNELS );
        $channels = [];
        foreach ( self::CHANNELS as $channel ) {
            $channels[ $channel ] = $this->collectChannelStats( $wpdb, $table, $channel, $totalShards, $checkpointMap[ $channel ] ?? [] );
        }
        $expired = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'processing' AND lease_expires_at IS NOT NULL AND lease_expires_at <= UTC_TIMESTAMP()" );
        $versions = $this->collectSnapshotVersions( $wpdb );
        $runtime = Runtime_Stats::instance()->getMany( [
            'dedup_hits_total',
            'lease_sweep_recovered_total',
            'lease_sweep_dead_total',
        ] );

        return new WP_REST_Response( [
            'generated_at'               => current_time( 'mysql', true ),
            'channels'                   => $channels,
            'leases_expired_processing'  => $expired,
            'snapshot_versions'          => $versions,
            'dedup_hits_total'           => $runtime['dedup_hits_total'] ?? 0,
            'lease_sweep_recovered_total'=> $runtime['lease_sweep_recovered_total'] ?? 0,
            'lease_sweep_dead_total'     => $runtime['lease_sweep_dead_total'] ?? 0,
        ] );
    }

    private function collectChannelStats( \wpdb $wpdb, string $table, string $channel, int $totalShards, array $checkpointData ) : array {
        $channelTotals = array_fill_keys( [ 'pending', 'processing', 'dead' ], 0 );
        $shards = [];
        for ( $s = 0; $s < $totalShards; $s++ ) {
            $shards[ $s ] = [
                'id'                          => $s,
                'pending'                     => 0,
                'processing'                  => 0,
                'dead'                        => 0,
                'oldest_pending_age_seconds'   => 0,
                'checkpoint_updated_at'       => $checkpointData[ $s ]['checkpoint_updated_at'] ?? null,
                'last_job_uuid'               => $checkpointData[ $s ]['last_job_uuid'] ?? null,
            ];
        }
        $counts = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT shard, status, COUNT(*) AS total FROM {$table} WHERE queue = %s GROUP BY shard, status",
                $channel
            ),
            ARRAY_A
        ) ?: [];
        foreach ( $counts as $row ) {
            $shard  = (int) ( $row['shard'] ?? 0 );
            $status = $row['status'];
            $count  = (int) $row['total'];
            if ( isset( $channelTotals[ $status ] ) ) {
                $channelTotals[ $status ] += $count;
            }
            if ( isset( $shards[ $shard ] ) && array_key_exists( $status, $shards[ $shard ] ) ) {
                $shards[ $shard ][ $status ] = $count;
            }
        }
        $oldestRows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT shard, MIN(available_at) AS oldest FROM {$table} WHERE queue = %s AND status = 'pending' GROUP BY shard",
                $channel
            ),
            ARRAY_A
        ) ?: [];
        foreach ( $oldestRows as $row ) {
            $shard = (int) ( $row['shard'] ?? 0 );
            $age   = $this->computeAgeSeconds( $row['oldest'] ?? null );
            if ( isset( $shards[ $shard ] ) ) {
                $shards[ $shard ]['oldest_pending_age_seconds'] = $age;
            }
        }
        $overallAge = 0;
        foreach ( $shards as $stats ) {
            $overallAge = max( $overallAge, $stats['oldest_pending_age_seconds'] );
        }
        return [
            'pending'                     => $channelTotals['pending'],
            'processing'                  => $channelTotals['processing'],
            'dead'                        => $channelTotals['dead'],
            'oldest_pending_age_seconds'  => $overallAge,
            'total_shards'                => $totalShards,
            'shards'                      => array_values( $shards ),
        ];
    }

    private function collectSnapshotVersions( \wpdb $wpdb ) : array {
        $table = $wpdb->prefix . 'aurora_snapshot_versions';
        $rows  = $wpdb->get_results( "SELECT table_name, current_version FROM {$table}", ARRAY_A ) ?: [];
        $versions = [];
        foreach ( $rows as $row ) {
            $versions[ $row['table_name'] ] = (int) $row['current_version'];
        }
        return $versions;
    }
}

    private function computeAgeSeconds( ?string $datetime ) : int {
        if ( empty( $datetime ) ) {
            return 0;
        }
        $timestamp = strtotime( $datetime );
        if ( false === $timestamp ) {
            return 0;
        }
        return max( 0, time() - $timestamp );
    }
}
