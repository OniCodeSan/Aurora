<?php
namespace APM;

class License {
    private const OPTION_KEY = 'apm_license';
    private const INSTALL_OPTION = 'apm_install_date';
    private const TRIAL_DAYS = 15;

    private array $data;

    public function __construct() {
        $stored = get_option( self::OPTION_KEY, [] );
        $this->data = is_array( $stored ) ? $stored : [];
    }

    public function ensure_install_date() : void {
        if ( ! get_option( self::INSTALL_OPTION ) ) {
            update_option( self::INSTALL_OPTION, time(), false );
        }
    }

    public function has_valid_license() : bool {
        return ! empty( $this->data['key'] );
    }

    public function store_license( string $key ) : void {
        $key = sanitize_text_field( $key );
        $this->data = [
            'key'          => $key,
            'activated_on' => time(),
        ];
        update_option( self::OPTION_KEY, $this->data, false );
    }

    public function remove_license() : void {
        delete_option( self::OPTION_KEY );
        $this->data = [];
    }

    public function get_license_key() : string {
        return $this->data['key'] ?? '';
    }

    public function is_trial_active() : bool {
        $installed = (int) get_option( self::INSTALL_OPTION );
        if ( ! $installed ) {
            return true;
        }
        $diff_days = ( time() - $installed ) / DAY_IN_SECONDS;
        return $diff_days < self::TRIAL_DAYS;
    }

    public function days_left() : int {
        $installed = (int) get_option( self::INSTALL_OPTION );
        if ( ! $installed ) {
            return self::TRIAL_DAYS;
        }
        $elapsed = ( time() - $installed ) / DAY_IN_SECONDS;
        $remaining = self::TRIAL_DAYS - (int) floor( $elapsed );
        return max( 0, $remaining );
    }

    public function is_active() : bool {
        return $this->has_valid_license() || $this->is_trial_active();
    }

    public function get_status_message() : string {
        if ( $this->has_valid_license() ) {
            return __( 'Licenza attiva', 'advanced-promo-mechanics' );
        }
        if ( $this->is_trial_active() ) {
            /* translators: %d: remaining days */
            return sprintf( __( 'Trial attiva: %d giorni rimanenti', 'advanced-promo-mechanics' ), $this->days_left() );
        }
        return __( 'Trial scaduta: inserisci una license key per continuare a usare Advanced Promo Mechanics.', 'advanced-promo-mechanics' );
    }
}
