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

    public function get( int $id ) : ?array {
        global $wpdb;
        $table = $this->table();
        $row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE tenant_id = %s AND id = %d", $this->tenant_id, $id ), ARRAY_A );
        return $row ?: null;
    }

    public function save( array $data ) : ?array {
        global $wpdb;
        $table = $this->table();
        $payload = [
            'tenant_id'   => $this->tenant_id,
            'name'        => sanitize_text_field( $data['name'] ?? '' ),
            'merchant'    => sanitize_key( $data['merchant'] ?? 'custom' ),
            'format'      => sanitize_key( $data['format'] ?? 'xml' ),
            'destination' => sanitize_text_field( $data['destination'] ?? '' ),
            'schedule'    => $this->sanitize_schedule( $data['schedule'] ?? 'manual' ),
            'notes'       => sanitize_textarea_field( $data['notes'] ?? '' ),
        ];
        if ( empty( $payload['name'] ) ) {
            return null;
        }
        $result = $wpdb->insert( $table, $payload, [ '%s', '%s', '%s', '%s', '%s', '%s', '%s' ] );
        if ( false === $result ) {
            return null;
        }
        return $this->get( (int) $wpdb->insert_id );
    }

    public function delete( int $id ) : bool {
        global $wpdb;
        return false !== $wpdb->delete( $this->table(), [ 'tenant_id' => $this->tenant_id, 'id' => $id ], [ '%s', '%d' ] );
    }

    private function sanitize_schedule( string $schedule ) : string {
        $allowed = array_merge( [ 'manual' ], array_keys( Feed_Scheduler::get_interval_definitions() ) );
        $value   = sanitize_key( $schedule );
        return in_array( $value, $allowed, true ) ? $value : 'manual';
    }
}
