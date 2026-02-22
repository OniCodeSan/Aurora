<?php
namespace Aurora\Enterprise\Queue;

use Aurora\Enterprise\Support\Config;
use function wp_json_encode;

class ShardResolver {
    public static function determine( string $channel, array $payload, ?int $total = null ) : int {
        $total = max( 1, $total ?? Config::totalShards() );
        $productId   = (int) ( $payload['product_id'] ?? $payload['id'] ?? 0 );
        $variationId = (int) ( $payload['variation_id'] ?? 0 );
        if ( $productId <= 0 && isset( $payload['items'][0]['product_id'] ) ) {
            $productId   = (int) $payload['items'][0]['product_id'];
            $variationId = (int) ( $payload['items'][0]['variation_id'] ?? 0 );
        }
        if ( $productId <= 0 ) {
            $key = $channel . ':' . ( $payload['payload_hash'] ?? wp_json_encode( $payload ) );
        } else {
            $key = sprintf( '%s:%d:%d', $channel, $productId, $variationId );
        }
        return (int) ( crc32( $key ) % $total );
    }
}
