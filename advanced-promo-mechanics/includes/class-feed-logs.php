<?php
namespace APM;

class Feed_Logs {
    private string $tenant_id;

    public function __construct() {
        $this->tenant_id = apply_filters( 'apm_current_tenant', 'default' );
    }

    private function table() : string {
        global $wpdb;
        return $wpdb->prefix . 'apm_feed_logs';
    }

    public function record( int $profile_id, string $status, string $message = '', string $file_path = '' ) : void {
        global $wpdb;
        $wpdb->insert( $this->table(), [
            'tenant_id'  => $this->tenant_id,
            'profile_id' => $profile_id,
            'status'     => $status,
            'message'    => $message,
            'file_path'  => $file_path,
        ], [ '%s', '%d', '%s', '%s', '%s' ] );
    }

    public function latest( int $limit = 20 ) : array {
        global $wpdb;
        $table = $this->table();
        return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE tenant_id = %s ORDER BY created_at DESC LIMIT %d", $this->tenant_id, $limit ), ARRAY_A );
    }
}
