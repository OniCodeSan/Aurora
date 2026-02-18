<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$target_count = 1000; // Number of new demo products to create

$adjectives = [
    'Artisan', 'Bold', 'Crystal', 'Dynamic', 'Essential', 'Future', 'Graphite', 'Harmony', 'Ivory',
    'Jet', 'Kinetic', 'Luminous', 'Modular', 'Nebula', 'Opal', 'Prism', 'Quantum', 'Radiant',
    'Solar', 'Titan', 'Ultralight', 'Velvet', 'Wild', 'Xenon', 'Young', 'Zen'
];

$nouns = [
    'Backpack', 'Bottle', 'Camera', 'Desk Lamp', 'Earbuds', 'Frame', 'Guitar Strap',
    'Headset', 'Instrument Case', 'Jacket', 'Keyboard', 'Lamp', 'Mug', 'Notebook', 'Organizer',
    'Planner', 'Quilt', 'Rug', 'Speaker', 'Thermos', 'Umbrella', 'Vase', 'Wallet', 'Xylophone',
    'Yoga Mat', 'Zip Case'
];

function generate_sentence(array $words, int $min = 8, int $max = 18): string {
    shuffle($words);
    $length = rand($min, $max);
    $selected = array_slice($words, 0, $length);
    $sentence = ucfirst(strtolower(implode(' ', $selected)));
    return rtrim($sentence, '.') . '.';
}

$word_bank = [
    'crafted', 'from', 'premium', 'materials', 'for', 'daily', 'use', 'this', 'demo', 'product',
    'highlights', 'flexible', 'workflows', 'lightweight', 'design', 'and', 'modular', 'accessories',
    'ideal', 'for', 'rapid', 'showcases', 'and', 'storefront', 'benchmarks', 'supports', 'multiple',
    'colorways', 'smooth', 'textures', 'thoughtful', 'storage', 'comfortable', 'fit', 'clean',
    'lines', 'precise', 'detailing', 'authentic', 'feel', 'timeless', 'character', 'polished',
    'edges', 'smart', 'organization', 'adaptable', 'layouts', 'future-ready', 'aesthetic'
];

for ( $i = 1; $i <= $target_count; $i++ ) {
    $name = sprintf(
        '%s %s #%04d',
        $adjectives[ array_rand( $adjectives ) ],
        $nouns[ array_rand( $nouns ) ],
        $i
    );

    $product = new WC_Product_Simple();
    $product->set_name( $name );
    $product->set_status( 'publish' );
    $product->set_catalog_visibility( 'visible' );
    $product->set_regular_price( number_format( rand( 1500, 5500 ) / 100, 2, '.', '' ) );
    $product->set_description( generate_sentence( $word_bank, 20, 32 ) . '\n\n' . generate_sentence( $word_bank, 18, 26 ) );
    $product->set_short_description( generate_sentence( $word_bank, 12, 18 ) );
    $product->set_manage_stock( false );
    $product->set_sku( 'DEMO-' . strtoupper( wp_generate_password( 8, false, false ) ) );

    $product_id = $product->save();

    WP_CLI::log( sprintf( 'Created product #%d → ID %d (%s)', $i, $product_id, $name ) );
}

WP_CLI::success( sprintf( 'Generated %d demo products.', $target_count ) );
