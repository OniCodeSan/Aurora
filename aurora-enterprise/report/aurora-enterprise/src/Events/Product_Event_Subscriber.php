<?php
namespace Aurora\Enterprise\Events;

use Aurora\Enterprise\Queue\Queue_Manager;

class Product_Event_Subscriber {
    public function hooks() : void {
        add_action( 'save_post_product', [ $this, 'on_product_saved' ], 20, 3 );
        add_action( 'woocommerce_product_import_inserted_product_object', [ $this, 'on_product_imported' ], 10, 2 );
    }

    public function on_product_saved( int $post_id, $post, bool $update ) : void {
        if ( 'product' !== $post->post_type ) {
            return;
        }
        $this->enqueue_all( $post_id );
    }

    public function on_product_imported( $product, $data ) : void {
        $this->enqueue_all( $product->get_id() );
    }

    private function enqueue_all( int $product_id ) : void {
        $queue = Queue_Manager::instance();
        foreach ( [ 'price', 'stock', 'visibility' ] as $channel ) {
            $queue->enqueue( $channel, [ 'product_id' => $product_id, 'trigger' => current_time( 'mysql', true ) ] );
        }
    }
}
