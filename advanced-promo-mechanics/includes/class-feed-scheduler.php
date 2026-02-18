<?php
namespace APM;

class Feed_Scheduler {
    private const HOOK_PREFIX    = 'apm_feed_profile_';
    private const SCHEDULE_SLUG  = 'apm_interval_';
    private const INTERVALS      = [
        '1h'  => [ 'interval' => HOUR_IN_SECONDS,          'label' => 'Ogni 1 ora' ],
        '2h'  => [ 'interval' => 2 * HOUR_IN_SECONDS,      'label' => 'Ogni 2 ore' ],
        '4h'  => [ 'interval' => 4 * HOUR_IN_SECONDS,      'label' => 'Ogni 4 ore' ],
        '6h'  => [ 'interval' => 6 * HOUR_IN_SECONDS,      'label' => 'Ogni 6 ore' ],
        '12h' => [ 'interval' => 12 * HOUR_IN_SECONDS,     'label' => 'Ogni 12 ore' ],
        '24h' => [ 'interval' => DAY_IN_SECONDS,           'label' => 'Ogni 24 ore' ],
    ];

    private Feed_Profiles $profiles;
    private Feed_Exporter $exporter;
    private Feed_Logs $logs;

    public function __construct( Feed_Profiles $profiles, Feed_Exporter $exporter, Feed_Logs $logs ) {
        $this->profiles = $profiles;
        $this->exporter = $exporter;
        $this->logs     = $logs;
    }

    public function init() : void {
        add_filter( 'cron_schedules', [ self::class, 'register_schedules' ] );
        add_action( 'init', [ $this, 'ensure_schedules' ] );

        foreach ( array_keys( self::INTERVALS ) as $key ) {
            add_action( self::hook_name( $key ), function () use ( $key ) {
                $this->run_for_schedule( $key );
            } );
        }
    }

    public function ensure_schedules() : void {
        // Clean up legacy hooks
        wp_clear_scheduled_hook( 'apm_feed_profile_daily' );
        wp_clear_scheduled_hook( 'apm_feed_profile_hourly' );

        foreach ( self::INTERVALS as $key => $data ) {
            $hook = self::hook_name( $key );
            $slug = self::schedule_slug( $key );
            if ( ! wp_next_scheduled( $hook ) ) {
                $offset = isset( $data['interval'] ) ? (int) $data['interval'] : HOUR_IN_SECONDS;
                wp_schedule_event( time() + $offset, $slug, $hook );
            }
        }
    }

    private function run_for_schedule( string $schedule ) : void {
        $profiles = array_filter(
            $this->profiles->all(),
            static fn( $profile ) => isset( $profile['schedule'] ) && $profile['schedule'] === $schedule
        );

        if ( empty( $profiles ) ) {
            return;
        }

        foreach ( $profiles as $profile ) {
            $result = $this->exporter->generate_for_profile( $profile );
            if ( ! $result['success'] ) {
                $message = sprintf( __( 'Errore export pianificato: %s', 'advanced-promo-mechanics' ), $result['message'] ?? '' );
                $this->logs->record( (int) ( $profile['id'] ?? 0 ), 'error', $message );
            }
        }
    }

    private static function hook_name( string $key ) : string {
        return self::HOOK_PREFIX . $key;
    }

    private static function schedule_slug( string $key ) : string {
        return self::SCHEDULE_SLUG . $key;
    }

    public static function register_schedules( array $schedules ) : array {
        foreach ( self::INTERVALS as $key => $definition ) {
            $slug = self::schedule_slug( $key );
            $schedules[ $slug ] = [
                'interval' => (int) $definition['interval'],
                'display'  => $definition['label'] ?? sprintf( __( 'Ogni %s', 'advanced-promo-mechanics' ), $key ),
            ];
        }
        return $schedules;
    }

    public static function get_schedule_choices() : array {
        $choices = [ 'manual' => __( 'Manuale (solo su Salva)', 'advanced-promo-mechanics' ) ];
        foreach ( self::INTERVALS as $key => $definition ) {
            $label = $definition['label'] ?? strtoupper( $key );
            $choices[ $key ] = __( $label, 'advanced-promo-mechanics' );
        }
        return $choices;
    }

    public static function get_interval_definitions() : array {
        return self::INTERVALS;
    }
}
