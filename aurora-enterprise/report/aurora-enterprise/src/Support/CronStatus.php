<?php
namespace Aurora\Enterprise\Support;

class CronStatus {
    private const OPTION = 'aurora_cron_profiles';

    /**
     * @return array<string,array<string,mixed>>
     */
    public function all() : array {
        $stored = get_option( self::OPTION, [] );
        $defaults = $this->defaults();
        if ( ! is_array( $stored ) ) {
            return $defaults;
        }
        foreach ( $stored as $key => $profile ) {
            if ( isset( $defaults[ $key ] ) && is_array( $profile ) ) {
                $defaults[ $key ] = array_merge( $defaults[ $key ], array_intersect_key( $profile, $defaults[ $key ] ) );
            }
        }
        return $defaults;
    }

    public function update( string $key, array $data ) : ?array {
        $profiles = $this->all();
        if ( ! isset( $profiles[ $key ] ) ) {
            return null;
        }
        if ( isset( $data['interval'] ) ) {
            $profiles[ $key ]['interval'] = sanitize_text_field( $data['interval'] );
        }
        if ( isset( $data['status'] ) && isset( $this->statuses()[ $data['status'] ] ) ) {
            $profiles[ $key ]['status'] = $data['status'];
        }
        update_option( self::OPTION, $profiles, false );
        return $profiles[ $key ];
    }

    public function markRun( string $key ) : void {
        $profiles = $this->all();
        if ( ! isset( $profiles[ $key ] ) ) {
            return;
        }
        $profiles[ $key ]['last_run'] = current_time( 'mysql', true );
        if ( 'processed' !== $profiles[ $key ]['status'] ) {
            $profiles[ $key ]['status'] = 'processed';
        }
        update_option( self::OPTION, $profiles, false );
    }

    /**
     * @return array<string,array<string,string>>
     */
    public function statuses() : array {
        return [
            'processed'    => [ 'label' => __( 'Processed', 'aurora-enterprise' ), 'color' => 'green' ],
            'paused'       => [ 'label' => __( 'Paused', 'aurora-enterprise' ), 'color' => 'yellow' ],
            'cancelled'    => [ 'label' => __( 'Cancelled', 'aurora-enterprise' ), 'color' => 'red' ],
            'not_delivered'=> [ 'label' => __( 'Not delivered', 'aurora-enterprise' ), 'color' => 'red' ],
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function defaults() : array {
        return [
            'price_worker' => [
                'label'    => __( 'Price worker', 'aurora-enterprise' ),
                'interval' => '*/5 * * * *',
                'status'   => 'processed',
                'last_run' => null,
            ],
            'stock_worker' => [
                'label'    => __( 'Stock worker', 'aurora-enterprise' ),
                'interval' => '*/5 * * * *',
                'status'   => 'processed',
                'last_run' => null,
            ],
            'visibility_worker' => [
                'label'    => __( 'Visibility worker', 'aurora-enterprise' ),
                'interval' => '*/15 * * * *',
                'status'   => 'processed',
                'last_run' => null,
            ],
            'rebuild_daily' => [
                'label'    => __( 'Daily rebuild', 'aurora-enterprise' ),
                'interval' => '0 3 * * *',
                'status'   => 'processed',
                'last_run' => null,
            ],
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function formatted() : array {
        $profiles = $this->all();
        $statuses = $this->statuses();
        foreach ( $profiles as $key => &$profile ) {
            $statusKey = $profile['status'] ?? 'processed';
            $status    = $statuses[ $statusKey ] ?? $statuses['processed'];
            $profile['status_label'] = $status['label'];
            $profile['color']        = $status['color'];
        }
        return $profiles;
    }
}
