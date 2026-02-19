<?php
namespace Aurora\Enterprise\Indexer;

use WC_Product;
use function wc_get_product;
use function wp_upload_dir;
use function wp_mkdir_p;
use function sanitize_file_name;
use function trailingslashit;
use function current_time;
use Aurora\Enterprise\Support\Logger;

class FeedIndexer extends AbstractIndexer {
    private Logger $logger;

    public function __construct() {
        $this->logger = new Logger();
    }

    public function getChannel() : string {
        return 'feed';
    }

    /**
     * @param array<int,array<string,mixed>> $jobs
     */
    public function processBatch( array $jobs ) : void {
        if ( empty( $jobs ) ) {
            return;
        }
        $first    = $jobs[0];
        $feedId   = sanitize_file_name( (string) ( $first['feed_id'] ?? wp_generate_uuid4() ) );
        $chunk    = (int) ( $first['chunk'] ?? 1 );
        $total    = (int) ( $first['total_chunks'] ?? 1 );
        $upload   = wp_upload_dir();
        $dir      = trailingslashit( $upload['basedir'] ) . 'aurora-feeds/';
        if ( ! wp_mkdir_p( $dir ) ) {
            throw new \RuntimeException( 'Unable to create feed directory.' );
        }
        $tmpPath  = $dir . $feedId . '.jsonl';

        $handle = fopen( $tmpPath, 'a' );
        if ( ! $handle ) {
            throw new \RuntimeException( sprintf( 'Unable to open feed file %s for writing.', $tmpPath ) );
        }

        foreach ( $jobs as $job ) {
            $productIds = array_map( 'intval', (array) ( $job['product_ids'] ?? [] ) );
            if ( empty( $productIds ) ) {
                continue;
            }
            foreach ( $productIds as $product_id ) {
                $product = wc_get_product( $product_id );
                if ( ! $product instanceof WC_Product ) {
                    continue;
                }
                $data = [
                    'feed_id'    => $feedId,
                    'product_id' => $product->get_id(),
                    'sku'        => $product->get_sku(),
                    'name'       => $product->get_name(),
                    'price'      => $product->get_price(),
                    'stock'      => $product->get_stock_quantity(),
                    'status'     => $product->get_status(),
                    'updated_at' => current_time( 'mysql', true ),
                ];
                fwrite( $handle, wp_json_encode( $data ) . "\n" );
            }
        }
        fclose( $handle );

        $this->logger->info( 'feed', 'Processed feed chunk', [
            'feed_id' => $feedId,
            'chunk'   => $chunk,
            'total'   => $total,
        ] );

        if ( $chunk >= $total ) {
            $finalPath = $dir . $feedId . '-' . gmdate( 'YmdHis' ) . '.jsonl';
            rename( $tmpPath, $finalPath );
            $this->logger->info( 'feed', 'Feed file completed', [
                'feed_id' => $feedId,
                'path'    => $finalPath,
            ] );
        }
    }
}
