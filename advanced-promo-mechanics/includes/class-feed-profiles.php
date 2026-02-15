<?php
namespace APM;

class Feed_Profiles {
    private string $tenant_id;

    public function __construct() {
        $this->tenant_id = apply_filters( 'apm_current_tenant', 'default' );
    }

    private function table() : string {
        global $wpdb;
        return $wpdb->prefix . 'apm_feed_profiles';
    }

    public function all() : array {
        global $wpdb;
        $table = $this->table();
        return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE tenant_id = %s ORDER BY created_at DESC", $this->tenant_id ), ARRAY_A );
    }

    public function save( array $data ) : bool {
        global $wpdb;
        $table = $this->table();
        $payload = [
            'tenant_id'   => $this->tenant_id,
            'name'        => sanitize_text_field( $data['name'] ?? '' ),
            'merchant'    => sanitize_key( $data['merchant'] ?? 'custom' ),
            'format'      => sanitize_key( $data['format'] ?? 'xml' ),
            'destination' => sanitize_text_field( $data['destination'] ?? '' ),
            'schedule'    => sanitize_key( $data['schedule'] ?? 'manual' ),
            'notes'       => sanitize_textarea_field( $data['notes'] ?? '' ),
        ];
        if ( empty( $payload['name'] ) ) {
            return false;
        }
        return false !== $wpdb->insert( $table, $payload, [ '%s', '%s', '%s', '%s', '%s', '%s', '%s' ] );
    }

    public function delete( int $id ) : bool {
        global $wpdb;
        return false !== $wpdb->delete( $this->table(), [ 'tenant_id' => $this->tenant_id, 'id' => $id ], [ '%s', '%d' ] );
    }
}
