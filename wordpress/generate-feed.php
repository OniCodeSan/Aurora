<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'wc_get_products' ) ) {
    WP_CLI::error( 'WooCommerce non è disponibile.' );
}

apm_ensure_feed_dir();
$paths   = apm_get_feed_paths();
$feed    = trailingslashit( $paths['dir'] ) . 'test.xml';

WP_CLI::log( sprintf( 'Genero feed in %s', $feed ) );

$ids = wc_get_products([
    'status' => 'publish',
    'limit'  => -1,
    'return' => 'ids',
]);

$writer = new XMLWriter();
$writer->openMemory();
$writer->setIndent( true );
$writer->startDocument( '1.0', 'UTF-8' );
$writer->startElement( 'products' );
$writer->writeAttribute( 'generated', gmdate( 'c' ) );

foreach ( $ids as $product_id ) {
    $product = wc_get_product( $product_id );
    if ( ! $product ) {
        continue;
    }

    $writer->startElement( 'product' );
    $writer->writeElement( 'id', (string) $product->get_id() );
    $writer->writeElement( 'sku', (string) $product->get_sku() );
    $writer->writeElement( 'name', $product->get_name() );
    $writer->writeElement( 'description', wp_strip_all_tags( $product->get_description() ) );
    $writer->writeElement( 'short_description', wp_strip_all_tags( $product->get_short_description() ) );
    $writer->writeElement( 'price', $product->get_regular_price() );
    if ( $product->get_sale_price() ) {
        $writer->writeElement( 'sale_price', $product->get_sale_price() );
    }
    $writer->writeElement( 'stock_status', $product->get_stock_status() );
    if ( $product->managing_stock() ) {
        $writer->writeElement( 'stock_quantity', (string) $product->get_stock_quantity() );
    }
    $writer->writeElement( 'permalink', get_permalink( $product_id ) );

    $image_id = $product->get_image_id();
    if ( $image_id ) {
        $image_url = wp_get_attachment_url( $image_id );
        if ( $image_url ) {
            $writer->writeElement( 'image', $image_url );
        }
    }

    $writer->endElement();
}

$writer->endElement();
$writer->endDocument();

$result = file_put_contents( $feed, $writer->outputMemory() );
if ( false === $result ) {
    WP_CLI::error( 'Errore durante la scrittura del feed.' );
}

WP_CLI::success( sprintf( 'Feed creato: %s (%d prodotti).', $feed, count( $ids ) ) );
