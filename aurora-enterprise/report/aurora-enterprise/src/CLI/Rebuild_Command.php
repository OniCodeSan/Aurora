<?php
namespace Aurora\Enterprise\CLI;

use WP_CLI_Command;
use WP_CLI;
use Aurora\Enterprise\Indexer\PriceIndexer;
use Aurora\Enterprise\Indexer\StockIndexer;
use Aurora\Enterprise\Indexer\VisibilityIndexer;

class Rebuild_Command extends WP_CLI_Command {
    /**
     * Rebuild indexes.
     *
     * ## OPTIONS
     * [--indexer=<price|stock|visibility|all>]
     */
    public function __invoke( array $args, array $assoc_args ) : void {
        $target = $assoc_args['indexer'] ?? 'all';
        $indexers = [
            'price'      => new PriceIndexer(),
            'stock'      => new StockIndexer(),
            'visibility' => new VisibilityIndexer(),
        ];
        $start = microtime( true );
        foreach ( $indexers as $key => $service ) {
            if ( 'all' !== $target && $key !== $target ) {
                continue;
            }
            $service->fullRebuild();
            update_option( 'aurora_last_rebuild_' . $key, current_time( 'mysql', true ), false );
            WP_CLI::log( sprintf( 'Rebuilt %s index', $key ) );
        }
        WP_CLI::success( sprintf( 'Done in %.2fs', microtime( true ) - $start ) );
    }
}
