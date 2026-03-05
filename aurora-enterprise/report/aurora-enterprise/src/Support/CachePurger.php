<?php
namespace Aurora\Enterprise\Support;

class CachePurger {
    public function purgeProducts( array $productIds ) : void {
        foreach ( $productIds as $product_id ) {
            clean_post_cache( $product_id );
            wp_cache_delete( $product_id, 'posts' );
        }
        do_action( 'aurora_enterprise_cache_purged', $productIds );
    }
}
