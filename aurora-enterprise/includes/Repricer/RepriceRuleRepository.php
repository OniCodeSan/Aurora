<?php
declare(strict_types=1);

namespace Aurora\Enterprise\Repricer;

use wpdb;

class RepriceRuleRepository {
    private wpdb $db;
    private string $table;
    private string $auditTable;

    public function __construct() {
        global $wpdb;
        $this->db = $wpdb;
        $this->table = $wpdb->prefix . 'aurora_reprice_rules';
        $this->auditTable = $wpdb->prefix . 'aurora_reprice_rules_audit';
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function list( int $limit = 50, int $offset = 0 ) : array {
        $rows = $this->db->get_results(
            $this->db->prepare(
                "SELECT id, name, priority, is_enabled, is_exclusive, updated_at
                 FROM {$this->table}
                 ORDER BY priority ASC, id ASC
                 LIMIT %d OFFSET %d",
                max( 1, $limit ),
                max( 0, $offset )
            ),
            ARRAY_A
        ) ?: [];
        foreach ( $rows as &$row ) {
            $row['enabled'] = (int) ( $row['is_enabled'] ?? 0 ) === 1;
            $row['exclusive'] = (int) ( $row['is_exclusive'] ?? 0 ) === 1;
            unset( $row['is_enabled'], $row['is_exclusive'] );
        }
        return $rows;
    }

    public function get( int $id ) : ?array {
        $row = $this->db->get_row(
            $this->db->prepare( "SELECT * FROM {$this->table} WHERE id=%d LIMIT 1", $id ),
            ARRAY_A
        );
        if ( ! is_array( $row ) ) {
            return null;
        }
        return $this->normalize_row( $row );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function list_enabled_ordered( int $limit = 200 ) : array {
        $rows = $this->db->get_results(
            $this->db->prepare(
                "SELECT * FROM {$this->table}
                 WHERE is_enabled = 1
                 ORDER BY priority ASC, id ASC
                 LIMIT %d",
                max( 1, $limit )
            ),
            ARRAY_A
        ) ?: [];
        return array_values( array_filter( array_map( [ $this, 'normalize_row' ], $rows ) ) );
    }

    /**
     * @param array<string,mixed> $ruleJson
     */
    public function create( array $ruleJson, int $userId = 0 ) : int {
        $meta = $this->extract_meta( $ruleJson );
        $now = current_time( 'mysql', true );
        $inserted = $this->db->insert(
            $this->table,
            [
                'name'         => $meta['name'],
                'priority'     => $meta['priority'],
                'is_enabled'   => $meta['enabled'] ? 1 : 0,
                'is_exclusive' => $meta['exclusive'] ? 1 : 0,
                'rule_json'    => wp_json_encode( $ruleJson ),
                'created_at'   => $now,
                'updated_at'   => $now,
            ],
            [ '%s', '%d', '%d', '%d', '%s', '%s', '%s' ]
        );
        if ( false === $inserted ) {
            return 0;
        }
        $id = (int) $this->db->insert_id;
        $this->insert_audit( $id, 'create', null, $ruleJson, $userId );
        return $id;
    }

    /**
     * @param array<string,mixed> $ruleJson
     */
    public function update( int $id, array $ruleJson, int $userId = 0 ) : bool {
        $before = $this->get( $id );
        if ( ! is_array( $before ) ) {
            return false;
        }
        $meta = $this->extract_meta( $ruleJson );
        $updated = $this->db->update(
            $this->table,
            [
                'name'         => $meta['name'],
                'priority'     => $meta['priority'],
                'is_enabled'   => $meta['enabled'] ? 1 : 0,
                'is_exclusive' => $meta['exclusive'] ? 1 : 0,
                'rule_json'    => wp_json_encode( $ruleJson ),
                'updated_at'   => current_time( 'mysql', true ),
            ],
            [ 'id' => $id ],
            [ '%s', '%d', '%d', '%d', '%s', '%s' ],
            [ '%d' ]
        );
        if ( false === $updated ) {
            return false;
        }
        $this->insert_audit(
            $id,
            'update',
            is_array( $before['rule_json'] ?? null ) ? $before['rule_json'] : null,
            $ruleJson,
            $userId
        );
        return true;
    }

    private function table_exists( string $table ) : bool {
        $exists = $this->db->get_var( $this->db->prepare( 'SHOW TABLES LIKE %s', $table ) );
        return ! empty( $exists );
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function normalize_row( array $row ) : array {
        $decoded = [];
        if ( is_string( $row['rule_json'] ?? null ) && '' !== $row['rule_json'] ) {
            $decodedCandidate = json_decode( (string) $row['rule_json'], true );
            if ( is_array( $decodedCandidate ) ) {
                $decoded = $decodedCandidate;
            }
        }
        $row['enabled'] = (int) ( $row['is_enabled'] ?? 0 ) === 1;
        $row['exclusive'] = (int) ( $row['is_exclusive'] ?? 0 ) === 1;
        $row['rule_json'] = $decoded;
        unset( $row['is_enabled'], $row['is_exclusive'] );
        return $row;
    }

    /**
     * @param array<string,mixed> $ruleJson
     * @return array{name:string,priority:int,enabled:bool,exclusive:bool}
     */
    private function extract_meta( array $ruleJson ) : array {
        $meta = is_array( $ruleJson['rule_meta'] ?? null ) ? $ruleJson['rule_meta'] : [];
        $name = sanitize_text_field( (string) ( $meta['name'] ?? '' ) );
        if ( '' === $name ) {
            $name = 'rule_' . gmdate( 'Ymd_His' );
        }
        $priority = isset( $meta['priority'] ) ? (int) $meta['priority'] : 100;
        $priority = max( 0, min( 1000000, $priority ) );
        return [
            'name'      => $name,
            'priority'  => $priority,
            'enabled'   => ! isset( $meta['enabled'] ) || (bool) $meta['enabled'],
            'exclusive' => isset( $meta['exclusive'] ) && (bool) $meta['exclusive'],
        ];
    }

    /**
     * @param array<string,mixed>|null $before
     * @param array<string,mixed>|null $after
     */
    private function insert_audit( int $ruleId, string $action, ?array $before, ?array $after, int $userId ) : void {
        if ( ! $this->table_exists( $this->auditTable ) ) {
            return;
        }
        $this->db->insert(
            $this->auditTable,
            [
                'rule_id'      => $ruleId,
                'action'       => sanitize_text_field( $action ),
                'user_id'      => max( 0, $userId ),
                'before_json'  => is_array( $before ) ? wp_json_encode( $before ) : null,
                'after_json'   => is_array( $after ) ? wp_json_encode( $after ) : null,
                'created_at'   => current_time( 'mysql', true ),
            ],
            [ '%d', '%s', '%d', '%s', '%s', '%s' ]
        );
    }
}

