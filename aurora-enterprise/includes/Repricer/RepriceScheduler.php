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
        $skippedOutWindow = 0;
        $lastError = null;
        $mode = 'interval';
        $inWindow = null;

        $assignments = $this->assignments->list_enabled_ordered( 500 );
        if ( empty( $assignments ) ) {
            $this->persist_stats( $enqueued, $skipped, $skippedOutWindow, $cursor, $mode, $inWindow, $lastError );
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

            $due = $this->is_due_now( $assignment );
            $mode = $due['mode'];
            $inWindow = $due['in_window'];
            if ( ! $due['due'] ) {
                if ( false === $due['in_window'] && 'windows' === $due['mode'] ) {
                    $skippedOutWindow++;
                } else {
                    $skipped++;
                }
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

        $this->persist_stats( $enqueued, $skipped, $skippedOutWindow, $cursor, $mode, $inWindow, $lastError );
    }

    /**
     * @param array<string,mixed> $assignment
     * @return array{due:bool,mode:string,in_window:?bool}
     */
    private function is_due_now( array $assignment ) : array {
        $schedule = $assignment['schedule_json'] ?? [];
        if ( ! is_array( $schedule ) || empty( $schedule ) ) {
            return [ 'due' => false, 'mode' => 'none', 'in_window' => null ];
        }
        $type = (string) ( $schedule['type'] ?? 'interval' );
        if ( 'windows' === $type ) {
            $inWindow = $this->is_in_window( $schedule );
            return [ 'due' => $inWindow, 'mode' => 'windows', 'in_window' => $inWindow ];
        }
        // interval
        $minutes = max( 1, (int) ( $schedule['interval_minutes'] ?? 0 ) );
        $lastTs  = $this->last_run_timestamp( (int) $assignment['id'] );
        if ( 0 === $lastTs ) {
            return [ 'due' => true, 'mode' => 'interval', 'in_window' => true ];
        }
        return [ 'due' => ( time() - $lastTs ) >= ( $minutes * 60 ), 'mode' => 'interval', 'in_window' => true ];
    }

    /**
     * @param array<string,mixed> $schedule
     */
    private function is_in_window( array $schedule ) : bool {
        $tz = $schedule['timezone'] ?? 'Europe/Rome';
        $windows = $schedule['windows'] ?? [];
        if ( ! is_array( $windows ) || empty( $windows ) ) {
            return false;
        }
        try {
            $nowTz = new \DateTime( 'now', new \DateTimeZone( $tz ) );
        } catch ( \Exception $e ) {
            $nowTz = new \DateTime( 'now', new \DateTimeZone( 'UTC' ) );
        }
        $nowMinutes = (int) $nowTz->format( 'H' ) * 60 + (int) $nowTz->format( 'i' );

        foreach ( $windows as $win ) {
            if ( ! is_array( $win ) ) {
                continue;
            }
            $from = isset( $win['from'] ) ? (string) $win['from'] : null;
            $to   = isset( $win['to'] ) ? (string) $win['to'] : null;
            if ( empty( $from ) || empty( $to ) ) {
                continue;
            }
            $fromParts = explode( ':', $from );
            $toParts   = explode( ':', $to );
            if ( count( $fromParts ) < 2 || count( $toParts ) < 2 ) {
                continue;
            }
            $fromM = (int) $fromParts[0] * 60 + (int) $fromParts[1];
            $toM   = (int) $toParts[0] * 60 + (int) $toParts[1];

            if ( $fromM <= $toM ) {
                // same day window
                if ( $nowMinutes >= $fromM && $nowMinutes <= $toM ) {
                    return true;
                }
            } else {
                // overnight window (e.g., 23:00-02:00)
                if ( $nowMinutes >= $fromM || $nowMinutes <= $toM ) {
                    return true;
                }
            }
        }
        return false;
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

    private function persist_stats( int $enqueued, int $skipped, int $skippedOutWindow, int $cursor, string $mode, ?bool $inWindow, ?string $lastError ) : void {
        update_option(
            'aurora_repricer_tick_last',
            [
                'at'       => current_time( 'mysql', true ),
                'enqueued' => $enqueued,
                'skipped'  => $skipped,
                'skipped_out_window' => $skippedOutWindow,
                'cursor'   => $cursor,
                'mode'     => $mode,
                'in_window'=> $inWindow,
                'error'    => $lastError,
            ],
            false
        );
        update_option( 'aurora_repricer_tick_cursor', $cursor, false );
    }
}
