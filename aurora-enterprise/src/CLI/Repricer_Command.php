<?php
namespace Aurora\Enterprise\CLI;

use WP_CLI_Command;
use WP_CLI;
use Aurora\Enterprise\Repricer\RepriceRunManager;
use Aurora\Enterprise\Ops\Ops_Run_Manager;

class Repricer_Command extends WP_CLI_Command {
    /**
     * Simulate repricer run synchronously (dry-run decisions only).
     *
     * ## OPTIONS
     * [--max=<int>]                Number of products (default 100, max 100)
     * [--min_margin_percent=<num>] Minimum margin percent (default 10)
     * [--min_margin_abs=<num>]     Minimum absolute margin (default 1.0)
     */
    public function simulate( array $args, array $assoc_args ) : void {
        $max = isset( $assoc_args['max'] ) ? max( 1, (int) $assoc_args['max'] ) : 100;
        $minPct = isset( $assoc_args['min_margin_percent'] ) ? (float) $assoc_args['min_margin_percent'] : 10.0;
        $minAbs = isset( $assoc_args['min_margin_abs'] ) ? (float) $assoc_args['min_margin_abs'] : 1.0;
        $payload = [
            'dry_run' => true,
            'max_products' => $max,
            'min_margin_percent' => $minPct,
            'min_margin_abs' => $minAbs,
        ];

        global $wpdb;
        $table = $wpdb->prefix . 'aurora_ops_runs';
        $now   = current_time( 'mysql', true );
        $inserted = $wpdb->insert(
            $table,
            [
                'op_key'       => 'repricer_run',
                'action_type'  => 'repricer_run',
                'indexer'      => null,
                'status'       => 'requested',
                'requested_at' => $now,
                'started_at'   => null,
                'finished_at'  => null,
                'message'      => null,
                'error'        => null,
                'meta_json'    => wp_json_encode( $payload ),
                'created_at'   => $now,
                'updated_at'   => $now,
            ]
        );
        if ( false === $inserted ) {
            WP_CLI::error( 'Unable to create ops run row.' );
        }
        $run_id = (int) $wpdb->insert_id;

        $manager = new RepriceRunManager();
        $manager->start( $run_id, $payload );
        $run = Ops_Run_Manager::instance()->find( $run_id );
        $status = $run['status'] ?? '';
        WP_CLI::success( sprintf( 'repricer simulate run_id=%d status=%s', $run_id, $status ) );
    }
}

