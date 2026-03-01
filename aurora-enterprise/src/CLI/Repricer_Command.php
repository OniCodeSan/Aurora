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
     * [--max=<int>]                Number of products (default 10000)
     * [--chunk=<int>]              Chunk size (default 500)
     * [--timebox_seconds=<int>]    Timebox per invocation (default 90)
     * [--min_margin_percent=<num>] Minimum margin percent (default 10)
     * [--min_margin_abs=<num>]     Minimum absolute margin (default 1.0)
     */
    public function simulate( array $args, array $assoc_args ) : void {
        delete_option( 'aurora_reprice_lock' );
        $max     = isset( $assoc_args['max'] ) ? max( 1, (int) $assoc_args['max'] ) : 10000;
        $chunk   = isset( $assoc_args['chunk'] ) ? max( 1, (int) $assoc_args['chunk'] ) : 500;
        $timebox = isset( $assoc_args['timebox_seconds'] ) ? max( 1, (int) $assoc_args['timebox_seconds'] ) : 90;
        $minPct  = isset( $assoc_args['min_margin_percent'] ) ? (float) $assoc_args['min_margin_percent'] : 10.0;
        $minAbs  = isset( $assoc_args['min_margin_abs'] ) ? (float) $assoc_args['min_margin_abs'] : 1.0;
        $payload = [
            'dry_run' => true,
            'max_products' => $max,
            'chunk_size' => $chunk,
            'min_margin_percent' => $minPct,
            'min_margin_abs' => $minAbs,
            'timebox_seconds' => $timebox,
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
        $runs    = Ops_Run_Manager::instance();
        $started = microtime( true );
        $loops   = 0;

        do {
            $manager->start( $run_id, $payload );
            $run    = $runs->find( $run_id );
            $status = $run['status'] ?? '';
            $loops++;
            if ( 'partial' === $status ) {
                sleep( 1 );
            }
        } while ( 'partial' === $status && $loops < 300 && ( microtime( true ) - $started ) < 3600 );

        $progressTable = $wpdb->prefix . 'aurora_reprice_progress';
        $progress = $wpdb->get_row(
            $wpdb->prepare( "SELECT processed_count, updated_count FROM {$progressTable} WHERE run_id=%d", $run_id ),
            ARRAY_A
        );
        $processed = (int) ( $progress['processed_count'] ?? 0 );
        $updated   = (int) ( $progress['updated_count'] ?? 0 );

        WP_CLI::log( sprintf( 'run_id=%d status=%s processed=%d updated=%d loops=%d', $run_id, $status, $processed, $updated, $loops ) );

        $breakdown = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT rule_applied, COUNT(*) c FROM {$wpdb->prefix}aurora_reprice_decisions WHERE run_id=%d GROUP BY rule_applied ORDER BY rule_applied",
                $run_id
            ),
            ARRAY_A
        );
        foreach ( (array) $breakdown as $row ) {
            WP_CLI::log( sprintf( '%s: %d', $row['rule_applied'], $row['c'] ) );
        }

        if ( 'partial' === $status ) {
            WP_CLI::warning( 'run ended in partial; max retries reached' );
        } elseif ( 'error' === $status ) {
            WP_CLI::error( sprintf( 'repricer simulate run_id=%d status=error', $run_id ) );
        } else {
            WP_CLI::success( sprintf( 'repricer simulate run_id=%d status=%s', $run_id, $status ) );
        }
    }
}
