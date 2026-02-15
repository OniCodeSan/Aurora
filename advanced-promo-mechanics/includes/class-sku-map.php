<?php
namespace APM;

class Sku_Map {
    private Marketplace_Credentials $credentials;

    public function __construct( Marketplace_Credentials $credentials ) {
        $this->credentials = $credentials;
    }

    private function table() : string {
        global $wpdb;
        return $wpdb->prefix . 'apm_sku_links';
    }

    private function tenant() : string {
        return apply_filters( 'apm_current_tenant', 'default' );
    }

    public function all() : array {
        global $wpdb;
        $table = $this->table();
        return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE tenant_id = %s ORDER BY created_at DESC", $this->tenant() ), ARRAY_A );
    }

    public function upsert( int $product_id, int $variation_id, string $marketplace, string $marketplace_sku, ?string $listing_id = null ) : bool {
        global $wpdb;
        $table = $this->table();
        $data  = [
            'tenant_id'       => $this->tenant(),
            'product_id'      => $product_id,
            'variation_id'    => $variation_id,
            'marketplace'     => $marketplace,
            'marketplace_sku' => $marketplace_sku,
            'listing_id'      => $listing_id,
        ];
        return false !== $wpdb->replace( $table, $data, [ '%s', '%d', '%d', '%s', '%s', '%s' ] );
    }

    public function delete( int $id ) : bool {
        global $wpdb;
        $table = $this->table();
        return false !== $wpdb->delete( $table, [ 'id' => $id, 'tenant_id' => $this->tenant() ], [ '%d', '%s' ] );
    }
}
