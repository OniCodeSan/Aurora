<?php
namespace Aurora\Enterprise\Ops;

class Dashboard_Data_Provider {
    private const SUMMARY_TRANSIENT = 'aurora_dashboard_summary';
    private const RUNS_TRANSIENT    = 'aurora_dashboard_runs';
    private const SUMMARY_TTL       = 30;
    private const RUNS_TTL          = 60;

    /** @var array<string,bool> */
    private static array $tableExistsCache = [];

    /** @var array<string,array<int,string>> */
    private static array $columnsCache = [];

    public function get_summary() : array {
        $cached = get_transient( self::SUMMARY_TRANSIENT );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        $kpis   = $this->get_kpis();
        $alerts = $this->get_alerts( $kpis );
        [ $status, $reasons ] = $this->get_global_status( $kpis, $alerts );

        $payload = [
            'status'           => $status,
            'reasons'          => $reasons,
            'kpis'             => $kpis,
            'alerts'           => $alerts,
            'generated_at_utc' => gmdate( 'Y-m-d H:i:s' ),
        ];

        set_transient( self::SUMMARY_TRANSIENT, $payload, self::SUMMARY_TTL );
        return $payload;
    }

    public function get_runs( int $limit = 20 ) : array {
        $limit = max( 1, min( 100, $limit ) );
        $cacheKey = self::RUNS_TRANSIENT . ':' . $limit;
        $cached = get_transient( $cacheKey );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        global $wpdb;
        $opsTable = $wpdb->prefix . 'aurora_ops_runs';
        $runs = [];

        if ( $this->table_exists( $opsTable ) ) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, op_key, status, created_at, started_at, finished_at, message, error, meta_json
                     FROM {$opsTable}
                     ORDER BY id DESC
                     LIMIT %d",
                    $limit
                ),
                ARRAY_A
            ) ?: [];

            foreach ( $rows as $row ) {
                $meta = [];
                if ( isset( $row['meta_json'] ) ) {
                    $decoded = json_decode( (string) $row['meta_json'], true );
                    if ( is_array( $decoded ) ) {
                        $meta = $decoded;
                    }
                }
                $runs[] = [
                    'id'         => (int) ( $row['id'] ?? 0 ),
                    'run_id'     => (int) ( $row['id'] ?? 0 ),
                    'op_key'     => (string) ( $row['op_key'] ?? '' ),
                    'status'     => (string) ( $row['status'] ?? '' ),
                    'created_at' => (string) ( $row['created_at'] ?? '' ),
                    'started_at' => (string) ( $row['started_at'] ?? '' ),
                    'finished_at'=> (string) ( $row['finished_at'] ?? '' ),
                    'message'    => sanitize_text_field( (string) ( $row['message'] ?? '' ) ),
                    'error'      => sanitize_text_field( (string) ( $row['error'] ?? '' ) ),
                    'meta'       => $meta,
                ];
            }
        } else {
            $runs = $this->fallback_runs_from_action_scheduler( $limit );
        }

        set_transient( $cacheKey, $runs, self::RUNS_TTL );
        return $runs;
    }

    public function get_events( int $limit = 10 ) : array {
        $limit = max( 1, min( 100, $limit ) );
        global $wpdb;
        $table = $wpdb->prefix . 'aurora_event_log';

        if ( $this->table_exists( $table ) ) {
            $columns = $this->table_columns( $table );
            $select = [];
            foreach ( [ 'id', 'event_key', 'level', 'message', 'created_at', 'context_json' ] as $column ) {
                if ( in_array( $column, $columns, true ) ) {
                    $select[] = $column;
                }
            }
            if ( ! empty( $select ) ) {
                $rows = $wpdb->get_results(
                    $wpdb->prepare(
                        'SELECT ' . implode( ',', $select ) . " FROM {$table} ORDER BY created_at DESC LIMIT %d",
                        $limit
                    ),
                    ARRAY_A
                ) ?: [];

                $events = [];
                foreach ( $rows as $row ) {
                    $events[] = [
                        'id'         => isset( $row['id'] ) ? (int) $row['id'] : 0,
                        'event_key'  => sanitize_text_field( (string) ( $row['event_key'] ?? 'event' ) ),
                        'level'      => sanitize_text_field( (string) ( $row['level'] ?? 'info' ) ),
                        'message'    => sanitize_text_field( (string) ( $row['message'] ?? '' ) ),
                        'created_at' => (string) ( $row['created_at'] ?? '' ),
                        'context'    => isset( $row['context_json'] ) ? $this->decode_json_array( (string) $row['context_json'] ) : [],
                    ];
                }
                return $events;
            }
        }

        $fallback = get_option( 'aurora_recent_events', [] );
        if ( ! is_array( $fallback ) ) {
            return [];
        }
        $fallback = array_slice( $fallback, 0, $limit );
        $events = [];
        foreach ( $fallback as $event ) {
            if ( ! is_array( $event ) ) {
                continue;
            }
            $events[] = [
                'id'         => isset( $event['id'] ) ? (int) $event['id'] : 0,
                'event_key'  => sanitize_text_field( (string) ( $event['event_key'] ?? $event['type'] ?? 'event' ) ),
                'level'      => sanitize_text_field( (string) ( $event['level'] ?? 'info' ) ),
                'message'    => sanitize_text_field( (string) ( $event['message'] ?? '' ) ),
                'created_at' => sanitize_text_field( (string) ( $event['created_at'] ?? '' ) ),
                'context'    => is_array( $event['context'] ?? null ) ? $event['context'] : [],
            ];
        }
        return $events;
    }

    public function clear_cache() : void {
        delete_transient( self::SUMMARY_TRANSIENT );
        delete_transient( self::RUNS_TRANSIENT );
        // Backward-compatible explicit keys requested by operational prompts.
        delete_transient( 'aurora_dashboard_summary' );
        delete_transient( 'aurora_dashboard_runs' );
        for ( $limit = 1; $limit <= 100; $limit++ ) {
            delete_transient( self::RUNS_TRANSIENT . ':' . $limit );
        }
    }

    private function get_global_status( array $kpis, array $alerts ) : array {
        $status = 'ok';
        $reasons = [];

        foreach ( $alerts as $alert ) {
            $severity = (string) ( $alert['severity'] ?? 'info' );
            if ( 'error' === $severity ) {
                $status = 'error';
                $reasons[] = (string) ( $alert['code'] ?? 'alert_error' );
            } elseif ( 'warn' === $severity && 'error' !== $status ) {
                $status = 'warn';
                $reasons[] = (string) ( $alert['code'] ?? 'alert_warn' );
            }
        }

        $opsErrors = (int) ( $kpis['ops_errors_24h'] ?? 0 );
        if ( $opsErrors > 0 && 'error' !== $status ) {
            $status = 'warn';
            $reasons[] = 'ops_errors_24h';
        }

        if ( empty( $reasons ) ) {
            $reasons[] = 'none';
        }

        return [ $status, array_values( array_unique( $reasons ) ) ];
    }

    private function get_kpis() : array {
        global $wpdb;
        $opsTable = $wpdb->prefix . 'aurora_ops_runs';
        $tick = get_option( 'aurora_repricer_tick_last', [] );
        if ( ! is_array( $tick ) ) {
            $tick = [];
        }

        $kpis = [
            'last_tick'          => sanitize_text_field( (string) ( $tick['at'] ?? '' ) ),
            'enqueued'           => isset( $tick['enqueued'] ) ? (int) $tick['enqueued'] : 0,
            'past_due'           => null,
            'ops_errors_24h'     => 0,
            'last_feed_run'      => '',
            'last_repricer_run'  => '',
            'queue_backlog'      => null,
        ];

        $asTable = $wpdb->prefix . 'actionscheduler_actions';
        if ( $this->table_exists( $asTable ) ) {
            $kpis['past_due'] = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$asTable}
                 WHERE hook='aurora_ops_dispatch'
                   AND status='pending'
                   AND scheduled_date_gmt < UTC_TIMESTAMP()"
            );
        }

        if ( $this->table_exists( $opsTable ) ) {
            $kpis['ops_errors_24h'] = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$opsTable}
                 WHERE status='error'
                   AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)"
            );
            $kpis['last_feed_run'] = (string) $wpdb->get_var(
                "SELECT COALESCE(finished_at, started_at, requested_at, created_at)
                 FROM {$opsTable}
                 WHERE op_key='feed_run'
                 ORDER BY id DESC
                 LIMIT 1"
            );
            $kpis['last_repricer_run'] = (string) $wpdb->get_var(
                "SELECT COALESCE(finished_at, started_at, requested_at, created_at)
                 FROM {$opsTable}
                 WHERE op_key='repricer_run'
                 ORDER BY id DESC
                 LIMIT 1"
            );
        }

        $queueTable = $wpdb->prefix . 'product_index_queue';
        if ( $this->table_exists( $queueTable ) ) {
            $kpis['queue_backlog'] = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$queueTable} WHERE status='pending'"
            );
        }

        return $kpis;
    }

    private function get_alerts( array $kpis ) : array {
        global $wpdb;
        $alerts = [];

        if ( ! class_exists( 'WooCommerce' ) ) {
            $alerts[] = [
                'code'     => 'woocommerce_inactive',
                'severity' => 'error',
                'message'  => 'WooCommerce non è attivo.',
            ];
        }

        if ( ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ) {
            $alerts[] = [
                'code'     => 'wp_cron_enabled',
                'severity' => 'info',
                'message'  => 'WP-Cron attivo: consigliato cron di sistema in produzione.',
            ];
        }

        $asTable = $wpdb->prefix . 'actionscheduler_actions';
        if ( ! $this->table_exists( $asTable ) ) {
            $alerts[] = [
                'code'     => 'action_scheduler_missing',
                'severity' => 'warn',
                'message'  => 'Action Scheduler non disponibile.',
            ];
        } else {
            $pastDue = isset( $kpis['past_due'] ) ? (int) $kpis['past_due'] : 0;
            if ( $pastDue > 0 ) {
                $alerts[] = [
                    'code'     => 'action_scheduler_past_due',
                    'severity' => 'warn',
                    'message'  => sprintf( 'Action Scheduler: %d azioni past-due.', $pastDue ),
                ];
            }
        }

        return $alerts;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function fallback_runs_from_action_scheduler( int $limit ) : array {
        global $wpdb;
        $table = $wpdb->prefix . 'actionscheduler_actions';
        if ( ! $this->table_exists( $table ) ) {
            return [];
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT action_id, hook, status, scheduled_date_gmt, last_attempt_gmt, args
                 FROM {$table}
                 WHERE hook='aurora_ops_dispatch'
                 ORDER BY action_id DESC
                 LIMIT %d",
                $limit
            ),
            ARRAY_A
        ) ?: [];

        $runs = [];
        foreach ( $rows as $row ) {
            $args = $this->decode_json_array( (string) ( $row['args'] ?? '' ) );
            $opKey = '';
            $runId = 0;
            if ( isset( $args[0] ) && is_array( $args[0] ) ) {
                $opKey = sanitize_text_field( (string) ( $args[0]['op_key'] ?? '' ) );
                $runId = (int) ( $args[0]['run_id'] ?? 0 );
            }

            $runs[] = [
                'id'         => (int) ( $row['action_id'] ?? 0 ),
                'run_id'     => $runId,
                'op_key'     => $opKey,
                'status'     => sanitize_text_field( (string) ( $row['status'] ?? '' ) ),
                'created_at' => sanitize_text_field( (string) ( $row['scheduled_date_gmt'] ?? '' ) ),
                'started_at' => sanitize_text_field( (string) ( $row['last_attempt_gmt'] ?? '' ) ),
                'finished_at'=> '',
                'message'    => '',
                'error'      => '',
                'meta'       => [],
            ];
        }

        return $runs;
    }

    private function decode_json_array( string $value ) : array {
        if ( '' === $value ) {
            return [];
        }
        $decoded = json_decode( $value, true );
        return is_array( $decoded ) ? $decoded : [];
    }

    private function table_exists( string $table ) : bool {
        if ( isset( self::$tableExistsCache[ $table ] ) ) {
            return self::$tableExistsCache[ $table ];
        }
        global $wpdb;
        $exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        self::$tableExistsCache[ $table ] = ( $exists === $table );
        return self::$tableExistsCache[ $table ];
    }

    /**
     * @return array<int,string>
     */
    private function table_columns( string $table ) : array {
        if ( isset( self::$columnsCache[ $table ] ) ) {
            return self::$columnsCache[ $table ];
        }

        global $wpdb;
        $rows = $wpdb->get_results( "SHOW COLUMNS FROM {$table}", ARRAY_A ) ?: [];
        $columns = [];
        foreach ( $rows as $row ) {
            if ( isset( $row['Field'] ) ) {
                $columns[] = (string) $row['Field'];
            }
        }

        self::$columnsCache[ $table ] = $columns;
        return $columns;
    }
}
