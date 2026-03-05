<?php
namespace Aurora\Enterprise\Ops;

class Feed_Metadata_Store {
    public static function update( array $data ) : void {
        $defaults = [
            'file_name'        => '',
            'rows'             => 0,
            'snapshot_version' => 0,
            'generated_at_utc' => gmdate( 'Y-m-d H:i:s' ),
            'size_bytes'       => 0,
        ];
        update_option( 'aurora_last_feed_meta', wp_json_encode( array_merge( $defaults, $data ) ), false );
    }

    public static function get() : ?array {
        $raw = get_option( 'aurora_last_feed_meta' );
        $decoded = json_decode( (string) $raw, true );
        return is_array( $decoded ) ? $decoded : null;
    }
}
