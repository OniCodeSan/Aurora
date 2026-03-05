<?php
namespace Aurora\Enterprise\Support;

use wpdb;

class Logger {
    private wpdb $db;
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->db    = $wpdb;
        $this->table = $wpdb->prefix . 'product_index_logs';
    }

    public function info( string $indexer, string $message, array $context = [] ) : void {
        $this->write( 'info', $indexer, $message, $context );
    }

    public function error( string $indexer, string $message, array $context = [] ) : void {
        $this->write( 'error', $indexer, $message, $context );
    }

    private function write( string $level, string $indexer, string $message, array $context ) : void {
        $this->db->insert(
            $this->table,
            [
                'job_id'    => $context['job_id'] ?? null,
                'indexer'   => $indexer,
                'level'     => $level,
                'message'   => $message,
                'context'   => $context ? wp_json_encode( $context ) : null,
                'created_at'=> current_time( 'mysql', true ),
            ],
            [ '%s', '%s', '%s', '%s', '%s', '%s' ]
        );
    }
}
