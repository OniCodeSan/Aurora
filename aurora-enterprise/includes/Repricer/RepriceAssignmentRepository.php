<?php
declare(strict_types=1);

namespace Aurora\Enterprise\Repricer;

use wpdb;

class RepriceAssignmentRepository {
    private wpdb $db;
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->db    = $wpdb;
        $this->table = $wpdb->prefix . 'aurora_reprice_assignments';
    }

    /**
     * @param array<string,mixed> $data
     */
    public function create( array $data ) : int {
        $now = current_time( 'mysql', true );
        $inserted = $this->db->insert(
            $this->table,
            [
                'name'        => (string) ( $data['name'] ?? '' ),
                'enabled'     => isset( $data['enabled'] ) ? (int) $data['enabled'] : 1,
                'scope_type'  => (string) ( $data['scope_type'] ?? '' ),
                'scope_json'  => wp_json_encode( $data['scope_json'] ?? [] ),
                'rule_json'   => wp_json_encode( $data['rule_json'] ?? [] ),
                'last_run_id' => isset( $data['last_run_id'] ) ? (int) $data['last_run_id'] : null,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]
        );
        return false === $inserted ? 0 : (int) $this->db->insert_id;
    }

    public function get( int $id ) : ?array {
        $row = $this->db->get_row(
            $this->db->prepare(
                "SELECT * FROM {$this->table} WHERE id = %d LIMIT 1",
                $id
            ),
            ARRAY_A
        );
        return is_array( $row ) ? $row : null;
    }

    public function list( int $limit = 50, int $offset = 0 ) : array {
        $rows = $this->db->get_results(
            $this->db->prepare(
                "SELECT * FROM {$this->table} ORDER BY id DESC LIMIT %d OFFSET %d",
                max( 1, $limit ),
                max( 0, $offset )
            ),
            ARRAY_A
        );
        return $rows ?: [];
    }

    public function touch_last_run( int $id, int $run_id ) : void {
        $this->db->update(
            $this->table,
            [
                'last_run_id' => $run_id,
                'updated_at'  => current_time( 'mysql', true ),
            ],
            [ 'id' => $id ]
        );
    }

    public function count_enabled() : int {
        return (int) $this->db->get_var( "SELECT COUNT(*) FROM {$this->table} WHERE enabled = 1" );
    }

    public function last_assignment() : ?array {
        $row = $this->db->get_row(
            "SELECT id, name, scope_type, last_run_id FROM {$this->table} ORDER BY id DESC LIMIT 1",
            ARRAY_A
        );
        return is_array( $row ) ? $row : null;
    }
}
