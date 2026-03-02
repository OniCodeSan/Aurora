<?php
declare(strict_types=1);

namespace Aurora\Enterprise\Repricer;

use wpdb;

class RepriceRollbackService {
    private wpdb $db;

    public function __construct() {
        global $wpdb;
        $this->db = $wpdb;
    }

    /**
     * @return array{selected:int,rolled:int,skipped:int,dry_run:bool}
     */
    public function rollback_run( int $targetRunId, int $limit = 200, bool $dryRun = false ) : array {
        $table = $this->db->prefix . 'aurora_reprice_decisions';
        $rows = $this->db->get_results(
            $this->db->prepare(
                "SELECT id, product_id, old_price_applied_from, rollback_status
                 FROM {$table}
                 WHERE run_id = %d AND applied = 1 AND (rollback_status IS NULL OR rollback_status != 'rolled_back')
                 ORDER BY id ASC
                 LIMIT %d",
                $targetRunId,
                $limit
            ),
            ARRAY_A
        );
        $selected = count( $rows ?? [] );
        $rolled = 0;
        $skipped = 0;

        if ( $dryRun || $selected === 0 ) {
            return [
                'selected' => $selected,
                'rolled'   => 0,
                'skipped'  => 0,
                'dry_run'  => $dryRun,
            ];
        }

        foreach ( (array) $rows as $row ) {
            $pid = (int) $row['product_id'];
            $old = $row['old_price_applied_from'];
            if ( null === $old || $old === '' ) {
                $skipped++;
                $this->db->update(
                    $table,
                    [
                        'rollback_status'    => 'skipped',
                        'rolled_back_at_utc' => gmdate( 'Y-m-d H:i:s' ),
                    ],
                    [ 'id' => (int) $row['id'] ]
                );
                continue;
            }
            // restore price
            update_post_meta( $pid, '_price', $old );
            update_post_meta( $pid, '_regular_price', $old );

            $this->db->update(
                $table,
                [
                    'rollback_status'    => 'rolled_back',
                    'rolled_back_at_utc' => gmdate( 'Y-m-d H:i:s' ),
                ],
                [ 'id' => (int) $row['id'] ]
            );
            $rolled++;
        }

        return [
            'selected' => $selected,
            'rolled'   => $rolled,
            'skipped'  => $skipped,
            'dry_run'  => $dryRun,
        ];
    }
}
