<?php
namespace APM;

class Activity_Log {
    private string $tenant_id;

    public function __construct() {
        $this->tenant_id = apply_filters( 'apm_current_tenant', 'default' );
    }

    private function table() : string {
        global $wpdb;
        return $wpdb->prefix . 'apm_activity_log';
    }

    public function record( string $event, string $message, array $meta = [] ) : void {
        global $wpdb;
        $wpdb->insert( $this->table(), [
            'tenant_id' => $this->tenant_id,
            'event'     => sanitize_key( $event ),
            'message'   => wp_strip_all_tags( $message ),
            'user_id'   => get_current_user_id() ?: 0,
            'meta'      => wp_json_encode( $meta, JSON_UNESCAPED_SLASHES ),
        ], [ '%s', '%s', '%s', '%d', '%s' ] );
    }

    public function latest( int $limit = 100 ) : array {
        global $wpdb;
        $limit = max( 1, min( 500, $limit ) );
        $table = $this->table();
        return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE tenant_id = %s ORDER BY created_at DESC LIMIT %d", $this->tenant_id, $limit ), ARRAY_A );
    }
}
