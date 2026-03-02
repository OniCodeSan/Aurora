<?php
declare(strict_types=1);

namespace Aurora\Enterprise\Repricer;

use Aurora\Enterprise\Ops\Ops_Dispatcher;
use wpdb;

class RepriceScheduler {
    private RepriceAssignmentRepository $assignments;
    private wpdb $db;
    private int $maxPerTick = 50;

    public function __construct( ?RepriceAssignmentRepository $assignments = null ) {
        $this->assignments = $assignments ?? new RepriceAssignmentRepository();
        global $wpdb;
        $this->db = $wpdb;
    }

    public function hooks() : void {
        add_action( 'aurora_repricer_tick', [ $this, 'handle_tick' ] );
        add_action( 'init', [ $this, 'ensure_recurring' ] );
    }

    public function ensure_recurring() : void {
        if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( 'aurora_repricer_tick', [], 'aurora' ) ) {
            return;
        }
        if ( function_exists( 'as_schedule_recurring_action' ) ) {
            as_schedule_recurring_action( time() + 30, 5 * MINUTE_IN_SECONDS, 'aurora_repricer_tick', [], 'aurora' );
        }
    }

    public function handle_tick() : void {
        $cursor   = (int) get_option( 'aurora_repricer_tick_cursor', 0 );
        $enqueued = 0;
        $skipped  = 0;

        $assignments = $this->assignments->list_enabled_ordered( 500 );
        if ( empty( $assignments ) ) {
            $this->persist_stats( $enqueued, $skipped, $cursor );
            return;
        }

        // Order already priority DESC, id ASC from repository; rotate by cursor
        $ordered = $assignments;
        if ( $cursor > 0 ) {
            $startIndex = 0;
            foreach ( $assignments as $idx => $row ) {
                if ( (int) $row['id'] === $cursor ) {
                    $startIndex = ($idx + 1) % count( $assignments );
                    break;
                }
            }
            $ordered = array_merge( array_slice( $assignments, $startIndex ), array_slice( $assignments, 0, $startIndex ) );
        }

        foreach ( $ordered as $assignment ) {
            if ( $enqueued >= $this->maxPerTick ) {
                break;
            }
            $aid    = (int) $assignment['id'];
            $cursor = $aid;

            if ( ! $this->is_due_now( $assignment ) ) {
                $skipped++;
                continue;
            }
            if ( $this->has_pending_run( $aid ) ) {
                $skipped++;
                continue;
            }
            if ( $this->enqueue_run( $aid ) ) {
                $enqueued++;
            } else {
                $skipped++;
            }
        }

        $this->persist_stats( $enqueued, $skipped, $cursor );
    }

    /**
     * @param array<string,mixed> $assignment
     */
    private function is_due_now( array $assignment ) : bool {
        $schedule = $assignment['schedule_json'] ?? [];
        if ( ! is_array( $schedule ) || empty( $schedule ) ) {
            return false;
        }
        if ( ( $schedule['type'] ?? '' ) !== 'interval' ) {
            return false;
        }
        $minutes = max( 1, (int) ( $schedule['interval_minutes'] ?? 0 ) );
        $lastTs  = $this->last_run_timestamp( (int) $assignment['id'] );
        if ( 0 === $lastTs ) {
            return true;
        }
        return ( time() - $lastTs ) >= ( $minutes * 60 );
    }

    private function last_run_timestamp( int $assignmentId ) : int {
        $row = $this->db->get_row(
            $this->db->prepare(
                "SELECT updated_at FROM {$this->db->prefix}aurora_ops_runs WHERE op_key='repricer_run' AND meta_json LIKE %s AND status IN ('success','error') ORDER BY id DESC LIMIT 1",
                '%"assignment_id":' . $assignmentId . '%'
            ),
            ARRAY_A
        );
        if ( ! $row || empty( $row['updated_at'] ) ) {
            return 0;
        }
        return (int) strtotime( $row['updated_at'] );
    }

    private function has_pending_run( int $assignmentId ) : bool {
        $row = $this->db->get_row(
            $this->db->prepare(
                "SELECT id FROM {$this->db->prefix}aurora_ops_runs WHERE op_key='repricer_run' AND status IN ('requested','running','partial') AND meta_json LIKE %s LIMIT 1",
                '%"assignment_id":' . $assignmentId . '%'
            ),
            ARRAY_A
        );
        return is_array( $row );
    }

    private function enqueue_run( int $assignmentId ) : bool {
        $now = current_time( 'mysql', true );
        $payload = [
            'assignment_id'   => $assignmentId,
            'mode'            => 'dry_run',
            'dry_run'         => true,
            'timebox_seconds' => 90,
            'chunk_size'      => 500,
            'max_products'    => 10000,
        ];
        $inserted = $this->db->insert(
            $this->db->prefix . 'aurora_ops_runs',
            [
                'op_key'       => 'repricer_run',
                'action_type'  => 'repricer_run',
                'status'       => 'requested',
                'requested_at' => $now,
                'created_at'   => $now,
                'updated_at'   => $now,
                'meta_json'    => wp_json_encode( $payload ),
            ]
        );
        if ( false === $inserted ) {
            return false;
        }
        $runId = (int) $this->db->insert_id;
        $args = [
            [
                'run_id'  => $runId,
                'op_key'  => 'repricer_run',
                'payload' => $payload,
            ],
        ];
        if ( function_exists( 'as_enqueue_async_action' ) ) {
            as_enqueue_async_action( 'aurora_ops_dispatch', $args, 'aurora' );
        } else {
            ( new Ops_Dispatcher() )->handle( $args );
        }
        return true;
    }

    private function persist_stats( int $enqueued, int $skipped, int $cursor ) : void {
        update_option(
            'aurora_repricer_tick_last',
            [
                'at'       => current_time( 'mysql', true ),
                'enqueued' => $enqueued,
                'skipped'  => $skipped,
                'cursor'   => $cursor,
            ],
            false
        );
        update_option( 'aurora_repricer_tick_cursor', $cursor, false );
    }
}
