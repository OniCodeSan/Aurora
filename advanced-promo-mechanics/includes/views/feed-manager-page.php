<?php
/** @var array $profiles */
/** @var array $logs */
/** @var array $paths */
/** @var array $manual */
?>
<style>
    .apm-feed-grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(320px,1fr)); gap:24px; margin-top:24px; }
    .apm-feed-card { background:#fff; border:1px solid #dcdcde; border-radius:16px; padding:24px; box-shadow:0 3px 8px rgba(0,0,0,.04); }
    .apm-feed-card h2 { margin-top:0; font-size:18px; display:flex; align-items:center; gap:8px; }
    .apm-feed-card ul, .apm-feed-card ol { margin-left:18px; }
    .apm-feed-form { display:grid; gap:12px; margin-top:12px; }
    .apm-feed-form label { font-weight:600; display:flex; flex-direction:column; }
    .apm-feed-table-wrapper { margin-top:16px; max-height:280px; overflow:auto; border:1px solid #dcdcde; border-radius:12px; }
    .apm-feed-table-wrapper table { margin:0; }
</style>

<div class="wrap apm-admin">
    <h1><?php esc_html_e( 'Feed Export Manager', 'advanced-promo-mechanics' ); ?></h1>

    <?php if ( ! empty( $_GET['apm_notice'] ) ) : ?>
        <div class="notice notice-success is-dismissible"><p><?php echo wp_kses_post( wp_unslash( $_GET['apm_notice'] ) ); ?></p></div>
    <?php endif; ?>

    <div class="apm-feed-grid">
        <div class="apm-feed-card">
            <h2>📂 <?php esc_html_e( 'Cartella feed', 'advanced-promo-mechanics' ); ?></h2>
            <p><?php esc_html_e( 'Qui salviamo (e puoi depositare) i feed XML/CSV per Trovaprezzi, Google Shopping e altri merchant.', 'advanced-promo-mechanics' ); ?></p>
            <p><strong><?php esc_html_e( 'Percorso server', 'advanced-promo-mechanics' ); ?>:</strong> <code><?php echo esc_html( $paths['dir'] ); ?></code></p>
            <p><strong><?php esc_html_e( 'URL pubblico', 'advanced-promo-mechanics' ); ?>:</strong> <code><?php echo esc_html( $paths['url'] ); ?></code></p>
            <p class="description"><?php esc_html_e( 'Carica qui gli XML pronti: i merchant possono leggerli direttamente via URL.', 'advanced-promo-mechanics' ); ?></p>
        </div>

        <div class="apm-feed-card">
            <h2>📘 <?php esc_html_e( 'Manuale rapido', 'advanced-promo-mechanics' ); ?></h2>
            <ol>
                <?php foreach ( $manual as $step ) : ?>
                    <li><?php echo wp_kses_post( $step ); ?></li>
                <?php endforeach; ?>
            </ol>
        </div>
    </div>

    <div class="apm-feed-grid">
        <div class="apm-feed-card">
            <h2>📝 <?php esc_html_e( 'Export profiles', 'advanced-promo-mechanics' ); ?></h2>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="apm-feed-form">
                <?php wp_nonce_field( 'apm_save_feed_profile' ); ?>
                <input type="hidden" name="action" value="apm_save_feed_profile" />
                <label><?php esc_html_e( 'Nome profilo', 'advanced-promo-mechanics' ); ?><input type="text" name="name" required /></label>
                <label><?php esc_html_e( 'Merchant', 'advanced-promo-mechanics' ); ?>
                    <select name="merchant">
                        <option value="trovaprezzi">Trovaprezzi</option>
                        <option value="google">Google Shopping</option>
                        <option value="custom">Custom</option>
                    </select>
                </label>
                <label><?php esc_html_e( 'Formato', 'advanced-promo-mechanics' ); ?>
                    <select name="format">
                        <option value="xml">XML</option>
                        <option value="csv">CSV</option>
                    </select>
                </label>
                <label><?php esc_html_e( 'Export destination (URL/path)', 'advanced-promo-mechanics' ); ?><input type="text" name="destination" placeholder="https://merchant.com/feed" /></label>
                <label><?php esc_html_e( 'Schedule', 'advanced-promo-mechanics' ); ?>
                    <select name="schedule">
                        <option value="manual">Manuale</option>
                        <option value="daily">Daily</option>
                        <option value="hourly">Hourly</option>
                    </select>
                </label>
                <label><?php esc_html_e( 'Note', 'advanced-promo-mechanics' ); ?><textarea name="notes" rows="3"></textarea></label>
                <button type="submit" class="button button-primary"><?php esc_html_e( 'Salva profilo', 'advanced-promo-mechanics' ); ?></button>
            </form>

            <div class="apm-feed-table-wrapper">
                <table class="widefat">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Nome', 'advanced-promo-mechanics' ); ?></th>
                            <th><?php esc_html_e( 'Merchant', 'advanced-promo-mechanics' ); ?></th>
                            <th><?php esc_html_e( 'Formato', 'advanced-promo-mechanics' ); ?></th>
                            <th><?php esc_html_e( 'Destinazione', 'advanced-promo-mechanics' ); ?></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $profiles ) ) : ?>
                            <tr><td colspan="5"><?php esc_html_e( 'Nessun profilo ancora creato.', 'advanced-promo-mechanics' ); ?></td></tr>
                        <?php else : ?>
                            <?php foreach ( $profiles as $profile ) : ?>
                                <tr>
                                    <td><?php echo esc_html( $profile['name'] ); ?></td>
                                    <td><?php echo esc_html( ucfirst( $profile['merchant'] ) ); ?></td>
                                    <td><?php echo esc_html( strtoupper( $profile['format'] ) ); ?></td>
                                    <td><code><?php echo esc_html( $profile['destination'] ); ?></code></td>
                                    <td>
                                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                            <?php wp_nonce_field( 'apm_delete_feed_profile' ); ?>
                                            <input type="hidden" name="action" value="apm_delete_feed_profile" />
                                            <input type="hidden" name="feed_profile_id" value="<?php echo esc_attr( $profile['id'] ); ?>" />
                                            <button type="submit" class="button-link-delete" onclick="return confirm('<?php echo esc_js( __( 'Rimuovere il profilo?', 'advanced-promo-mechanics' ) ); ?>');">🗑</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="apm-feed-card">
            <h2>📜 <?php esc_html_e( 'Execution log', 'advanced-promo-mechanics' ); ?></h2>
            <div class="apm-feed-table-wrapper">
                <table class="widefat">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Timestamp', 'advanced-promo-mechanics' ); ?></th>
                            <th><?php esc_html_e( 'Profile', 'advanced-promo-mechanics' ); ?></th>
                            <th><?php esc_html_e( 'Status', 'advanced-promo-mechanics' ); ?></th>
                            <th><?php esc_html_e( 'File', 'advanced-promo-mechanics' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $logs ) ) : ?>
                            <tr><td colspan="4"><?php esc_html_e( 'Nessun log disponibile.', 'advanced-promo-mechanics' ); ?></td></tr>
                        <?php else : ?>
                            <?php foreach ( $logs as $log ) : ?>
                                <tr>
                                    <td><?php echo esc_html( $log['created_at'] ); ?></td>
                                    <td>#<?php echo esc_html( $log['profile_id'] ); ?></td>
                                    <td><strong><?php echo esc_html( strtoupper( $log['status'] ) ); ?></strong><br /><small><?php echo esc_html( $log['message'] ); ?></small></td>
                                    <td><?php echo $log['file_path'] ? '<code>' . esc_html( $log['file_path'] ) . '</code>' : '&mdash;'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
