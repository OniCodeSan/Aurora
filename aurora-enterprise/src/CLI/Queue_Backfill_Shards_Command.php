<?php
namespace Aurora\Enterprise\CLI;

use WP_CLI_Command;
use WP_CLI;
use wpdb;
use Aurora\Enterprise\Support\Config;
use Aurora\Enterprise\Queue\ShardResolver;

class Queue_Backfill_Shards_Command extends WP_CLI_Command {
    /**
     * Backfill shard values for queued jobs.
     *
     * ## OPTIONS
     * [--batch=<int>]
     * : Number of rows to process per iteration (default: 1000).
     *
     * [--total=<int>]
     * : Total number of shards (default: Config::totalShards()).
     *
     * [--force]
     * : Recompute shard for all rows (default processes shard=0 only).
     */
    public function __invoke( array $args, array $assoc_args ) : void {
        global $wpdb;
        $batch = isset( $assoc_args['batch'] ) ? max( 100, (int) $assoc_args['batch'] ) : 1000;
        $total = isset( $assoc_args['total'] ) ? max( 1, (int) $assoc_args['total'] ) : Config::totalShards();
        $force = isset( $assoc_args['force'] );
        $table = $wpdb->prefix . 'product_index_queue';

        $processed = 0;
        $lastId   = 0;
        while ( true ) {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, payload FROM {$table} WHERE id > %d" . ( $force ? '' : " AND shard = 0" ) . " ORDER BY id ASC LIMIT %d",
                $lastId,
                $batch
            ), ARRAY_A );
            if ( empty( $rows ) ) {
                break;
            }
            foreach ( $rows as $row ) {
                $payload = json_decode( $row['payload'], true ) ?: [];
                $channel = $payload['queue'] ?? 'price';
                $shard = ShardResolver::determine( $channel, $payload, $total );
                $wpdb->update( $table, [ 'shard' => $shard ], [ 'id' => (int) $row['id'] ], [ '%d' ], [ '%d' ] );
                $lastId = (int) $row['id'];
                $processed++;
            }
            WP_CLI::log( sprintf( 'Processed %d rows...', $processed ) );
        }
        $mode = $force ? 'force' : 'missing-only';
        WP_CLI::success( sprintf( 'Backfill completed. Total rows: %d (shards=%d, mode=%s).', $processed, $total, $mode ) );
    }

}
