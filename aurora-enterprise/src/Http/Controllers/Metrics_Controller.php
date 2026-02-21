<?php
namespace Aurora\Enterprise\Http\Controllers;

use Aurora\Enterprise\Support\Runtime_Stats;
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
        $table   = $wpdb->prefix . 'product_index_queue';
        $channels = [];
        foreach ( self::CHANNELS as $channel ) {
            $channels[ $channel ] = $this->collectChannelStats( $wpdb, $table, $channel );
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

    private function collectChannelStats( \wpdb $wpdb, string $table, string $channel ) : array {
        $counts = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT status, COUNT(*) AS total FROM {$table} WHERE queue = %s GROUP BY status",
                $channel
            ),
            ARRAY_A
        ) ?: [];
        $stats = array_fill_keys( [ 'pending', 'processing', 'dead' ], 0 );
        foreach ( $counts as $row ) {
            $status = $row['status'];
            if ( isset( $stats[ $status ] ) ) {
                $stats[ $status ] = (int) $row['total'];
            }
        }
        $oldest = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT MIN(available_at) FROM {$table} WHERE queue = %s AND status = 'pending'",
                $channel
            )
        );
        $ageSeconds = 0;
        if ( $oldest ) {
            $timestamp = strtotime( (string) $oldest );
            if ( false !== $timestamp ) {
                $ageSeconds = max( 0, time() - $timestamp );
            }
        }
        $stats['oldest_pending_age_seconds'] = $ageSeconds;
        return $stats;
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
