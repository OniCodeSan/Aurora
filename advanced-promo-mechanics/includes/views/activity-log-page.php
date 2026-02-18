<?php
/** @var array $entries */
?>
<div class="wrap apm-admin">
    <h1><?php esc_html_e( 'Log attività', 'advanced-promo-mechanics' ); ?></h1>
    <p class="description"><?php esc_html_e( 'Cronologia delle azioni eseguite da Aurora Project (feed, repricer, marketplace, ecc.).', 'advanced-promo-mechanics' ); ?></p>
    <table class="widefat striped">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Timestamp', 'advanced-promo-mechanics' ); ?></th>
                <th><?php esc_html_e( 'Evento', 'advanced-promo-mechanics' ); ?></th>
                <th><?php esc_html_e( 'Messaggio', 'advanced-promo-mechanics' ); ?></th>
                <th><?php esc_html_e( 'Utente', 'advanced-promo-mechanics' ); ?></th>
                <th><?php esc_html_e( 'Dettagli', 'advanced-promo-mechanics' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ( empty( $entries ) ) : ?>
                <tr><td colspan="5"><?php esc_html_e( 'Ancora nessun evento registrato.', 'advanced-promo-mechanics' ); ?></td></tr>
            <?php else :
                foreach ( $entries as $entry ) :
                    $meta = [];
                    if ( ! empty( $entry['meta'] ) ) {
                        $decoded = json_decode( $entry['meta'], true );
                        if ( is_array( $decoded ) ) {
                            $meta = $decoded;
                        }
                    }
                    $user_display = __( 'Sistema', 'advanced-promo-mechanics' );
                    if ( ! empty( $entry['user_id'] ) ) {
                        $user = get_user_by( 'id', (int) $entry['user_id'] );
                        if ( $user ) {
                            $user_display = $user->display_name;
                        }
                    }
                    ?>
                    <tr>
                        <td><?php echo esc_html( $entry['created_at'] ); ?></td>
                        <td><code><?php echo esc_html( $entry['event'] ); ?></code></td>
                        <td><?php echo esc_html( $entry['message'] ); ?></td>
                        <td><?php echo esc_html( $user_display ); ?></td>
                        <td>
                            <?php if ( empty( $meta ) ) : ?>
                                &mdash;
                            <?php else : ?>
                                <details>
                                    <summary><?php esc_html_e( 'Mostra', 'advanced-promo-mechanics' ); ?></summary>
                                    <pre style="white-space:pre-wrap; background:#f6f7f7; padding:12px; border-radius:8px;"><?php echo esc_html( wp_json_encode( $meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></pre>
                                </details>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach;
            endif; ?>
        </tbody>
    </table>
</div>
