<?php
namespace WpSync;

defined('ABSPATH') || exit;

/**
 * Werkzeuge → wpsync: Pairing-Code erzeugen, Pairings ansehen und widerrufen.
 * Das Secret wird nie angezeigt (Spike B19, AC-5).
 */
final class Admin
{
    public static function register(): void
    {
        add_action('admin_menu', static function (): void {
            add_management_page('wpsync', 'wpsync', 'manage_options', 'wpsync', [self::class, 'render']);
        });
    }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        Store::install();

        $code = null;
        if (isset($_POST['wpsync_action']) && check_admin_referer('wpsync_admin')) {
            $action = sanitize_key((string) wp_unslash($_POST['wpsync_action']));
            if ($action === 'new_code') {
                $code = Store::issuePairingCode();
            } elseif ($action === 'revoke' && isset($_POST['key_id'])) {
                Store::deletePairing(sanitize_key((string) wp_unslash($_POST['key_id'])));
                echo '<div class="notice notice-success"><p>Pairing widerrufen.</p></div>';
            }
        }
        ?>
        <div class="wrap">
            <h1>wpsync</h1>
            <p>Lesende Schnittstelle für <code>wpsync pull</code> (Live → Lokal).</p>

            <h2>Neues Gerät koppeln</h2>
            <?php if ($code !== null) : ?>
                <p>Pairing-Code (10 Minuten gültig, einmalig):</p>
                <p><code style="font-size:1.6em;letter-spacing:.15em"><?php echo esc_html($code); ?></code></p>
                <p>Im Terminal ausführen:<br>
                    <code>wpsync pair <?php echo esc_html(home_url()); ?> <?php echo esc_html($code); ?></code></p>
            <?php else : ?>
                <form method="post">
                    <?php wp_nonce_field('wpsync_admin'); ?>
                    <input type="hidden" name="wpsync_action" value="new_code">
                    <p><button type="submit" class="button button-primary">Pairing-Code erzeugen</button></p>
                </form>
            <?php endif; ?>

            <h2>Gekoppelte Geräte</h2>
            <table class="widefat striped">
                <thead><tr><th>Gerät</th><th>Gekoppelt</th><th>Zuletzt benutzt</th><th></th></tr></thead>
                <tbody>
                <?php foreach (Store::pairings() as $pairing) : ?>
                    <tr>
                        <td><?php echo esc_html($pairing['device']); ?></td>
                        <td><?php echo esc_html(wp_date('d.m.Y H:i', $pairing['created'])); ?></td>
                        <td><?php echo $pairing['last_used'] === null ? '–' : esc_html(wp_date('d.m.Y H:i', $pairing['last_used'])); ?></td>
                        <td>
                            <form method="post">
                                <?php wp_nonce_field('wpsync_admin'); ?>
                                <input type="hidden" name="wpsync_action" value="revoke">
                                <input type="hidden" name="key_id" value="<?php echo esc_attr($pairing['key_id']); ?>">
                                <button type="submit" class="button">Widerrufen</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}
