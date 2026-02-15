<?php
namespace APM;

class Marketplace_Credentials {
    private string $tenant_id;

    public function __construct() {
        $this->tenant_id = apply_filters( 'apm_current_tenant', 'default' );
    }

    public function all() : array {
        global $wpdb;
        $table = $wpdb->prefix . 'apm_marketplace_accounts';
        $rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE tenant_id = %s ORDER BY marketplace, label", $this->tenant_id ), ARRAY_A );
        foreach ( $rows as &$row ) {
            $row['data'] = apm_decrypt_data( $row['data'] );
        }
        return $rows;
    }

    public function get( int $id ) : ?array {
        global $wpdb;
        $table = $wpdb->prefix . 'apm_marketplace_accounts';
        $row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE tenant_id = %s AND id = %d", $this->tenant_id, $id ), ARRAY_A );
        if ( ! $row ) {
            return null;
        }
        $row['data'] = apm_decrypt_data( $row['data'] );
        return $row;
    }

    public function upsert( string $marketplace, string $label, array $data ) {
        global $wpdb;
        $table = $wpdb->prefix . 'apm_marketplace_accounts';
        $payload = [
            'tenant_id'   => $this->tenant_id,
            'marketplace' => $marketplace,
            'label'       => $label,
            'data'        => apm_encrypt_data( $data ),
        ];
        if ( empty( $payload['data'] ) ) {
            return false;
        }
        $result = $wpdb->replace( $table, $payload, [ '%s', '%s', '%s', '%s' ] );
        if ( false === $result ) {
            return false;
        }
        $insert_id = $wpdb->insert_id;
        if ( ! $insert_id ) {
            $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE tenant_id = %s AND marketplace = %s AND label = %s", $this->tenant_id, $marketplace, $label ) );
            return $existing ? (int) $existing : false;
        }
        return (int) $insert_id;
    }

    public function delete( int $id ) : bool {
        global $wpdb;
        $table = $wpdb->prefix . 'apm_marketplace_accounts';
        return false !== $wpdb->delete( $table, [ 'id' => $id, 'tenant_id' => $this->tenant_id ], [ '%d', '%s' ] );
    }
}
