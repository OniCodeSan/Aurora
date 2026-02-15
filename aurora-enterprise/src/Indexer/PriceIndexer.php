<?php
namespace Aurora\Enterprise\Indexer;

use function wc_get_product;
use function get_posts;
use function get_woocommerce_currency;
use function current_time;
use function wp_generate_uuid4;
use WC_Product;
use Aurora\Enterprise\Support\Logger;
use Aurora\Enterprise\Support\CachePurger;
use wpdb;

class PriceIndexer extends AbstractIndexer {
    private wpdb $db;
    private string $table;
    private string $staging;
    private Logger $logger;
    private CachePurger $cache;

    public function __construct() {
        global $wpdb;
        $this->db      = $wpdb;
        $this->table   = $wpdb->prefix . 'product_price_index';
        $this->staging = $wpdb->prefix . 'product_price_index_staging';
        $this->logger  = new Logger();
        $this->cache   = new CachePurger();
    }

    public function getChannel() : string {
        return 'price';
    }

    public function processBatch( array $jobs ) : void {
        $productIds = array_unique( array_map( static fn( array $job ) => (int) ( $job['product_id'] ?? 0 ), $jobs ) );
        $productIds = array_values( array_filter( $productIds ) );
        if ( empty( $productIds ) ) {
            return;
        }

        $batchId = wp_generate_uuid4();
        $version = wp_generate_uuid4();
        $rows    = $this->buildRows( $productIds, $version );
        if ( empty( $rows ) ) {
            return;
        }

        $this->writeStaging( $batchId, $rows );
        $this->swapBatch( $batchId, $productIds );
        $this->logger->info( 'price', 'Indexed price batch', [
            'count' => count( $rows ),
            'products' => $productIds,
            'version'  => $version,
        ] );
        update_option( 'aurora_last_rebuild_price', current_time( 'mysql', true ), false );
        $this->cache->purgeProducts( $productIds );
    }

    public function fullRebuild() : void {
        $allIds = get_posts( [
            'post_type'      => 'product',
            'post_status'    => 'any',
            'fields'         => 'ids',
            'posts_per_page' => -1,
        ] );
        $chunks = array_chunk( $allIds, 1000 );
        foreach ( $chunks as $chunk ) {
            $jobs = array_map( static fn( $id ) => [ 'product_id' => $id ], $chunk );
            $this->processBatch( $jobs );
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function buildRows( array $productIds, string $version ) : array {
        $rows = [];
        foreach ( $productIds as $product_id ) {
            $product = wc_get_product( $product_id );
            if ( ! $product instanceof WC_Product ) {
                continue;
            }
            if ( $product->is_type( 'variable' ) ) {
                foreach ( $product->get_children() as $variation_id ) {
                    $variation = wc_get_product( $variation_id );
                    if ( $variation ) {
                        $rows[] = $this->mapProduct( $variation, $version );
                    }
                }
            }
            $rows[] = $this->mapProduct( $product, $version );
        }
        return array_filter( $rows );
    }

    private function mapProduct( WC_Product $product, string $version ) : array {
        $regular  = $product->get_regular_price();
        $sale     = $product->get_sale_price();
        $effective = $product->get_price();
        $sku      = $product->get_sku() ?: (string) $product->get_id();
        return [
            'product_id'      => $product->get_parent_id() ?: $product->get_id(),
            'variation_id'    => $product->is_type( 'variation' ) ? $product->get_id() : 0,
            'sku'             => $sku,
            'currency'        => get_woocommerce_currency(),
            'regular_price'   => $regular !== '' ? (float) $regular : null,
            'sale_price'      => $sale !== '' ? (float) $sale : null,
            'effective_price' => $effective !== '' ? (float) $effective : null,
            'margin_percent'  => null,
            'version'         => $version,
        ];
    }

    private function writeStaging( string $batchId, array $rows ) : void {
        $now = current_time( 'mysql', true );
        foreach ( $rows as $row ) {
            $this->db->insert(
                $this->staging,
                [
                    'batch_id'        => $batchId,
                    'product_id'      => $row['product_id'],
                    'variation_id'    => $row['variation_id'],
                    'sku'             => $row['sku'],
                    'currency'        => $row['currency'],
                    'regular_price'   => $row['regular_price'],
                    'sale_price'      => $row['sale_price'],
                    'effective_price' => $row['effective_price'],
                    'margin_percent'  => $row['margin_percent'],
                    'version'         => $row['version'],
                    'created_at'      => $now,
                ],
                [ '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
            );
        }
    }

    private function swapBatch( string $batchId, array $productIds ) : void {
        if ( empty( $productIds ) ) {
            return;
        }
        $placeholders = implode( ',', array_fill( 0, count( $productIds ), '%d' ) );
        $sqlDelete = $this->db->prepare(
            "DELETE FROM {$this->table} WHERE product_id IN ({$placeholders})",
            ...$productIds
        );
        $this->db->query( 'START TRANSACTION' );
        $this->db->query( $sqlDelete );
        $this->db->query(
            $this->db->prepare(
                "INSERT INTO {$this->table} (product_id, variation_id, sku, currency, regular_price, sale_price, effective_price, margin_percent, version, updated_at)
                 SELECT product_id, variation_id, sku, currency, regular_price, sale_price, effective_price, margin_percent, version, %s
                 FROM {$this->staging}
                 WHERE batch_id = %s",
                current_time( 'mysql', true ),
                $batchId
            )
        );
        $this->db->delete( $this->staging, [ 'batch_id' => $batchId ], [ '%s' ] );
        $this->db->query( 'COMMIT' );
    }
}
