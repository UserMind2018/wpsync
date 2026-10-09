<?php
namespace WpSync;

defined('ABSPATH') || exit;

/**
 * Werkzeuge → wpsync: Pairing-Code erzeugen, Pairings ansehen und widerrufen, Push-Fenster
 * öffnen, Pushes ansehen und zurückrollen, Stand der Staging-Kopie ansehen. Das Secret wird nie
 * angezeigt (Spike B19, AC-5).
 */
final class Admin
{
    private const STATUS = [
        Push::UPLOADING         => 'Upload läuft',
        PushRescue::COMMITTED   => 'getauscht, nicht bestätigt',
        PushRescue::CONFIRMED   => 'bestätigt',
        PushRescue::ROLLED_BACK => 'zurückgerollt',
        Push::FAILED            => 'fehlgeschlagen',
        Push::EXPIRED           => 'verfallen',
    ];

    private const STAGING = [
        Staging::CREATING   => 'wird angelegt',
        Staging::READY      => 'bereit',
        Staging::REFRESHING => 'wird aufgefrischt',
        Staging::LOCKED     => 'gesperrt (14 Tage ungenutzt)',
        Staging::FAILED     => 'fehlgeschlagen',
        Staging::DELETING   => 'wird gelöscht',
    ];

    private const SECRET_STATE = [
        Store::SECRET_SEALED => 'verschlüsselt',
        Store::SECRET_PLAIN  => 'Klartext',
        Store::SECRET_BROKEN => 'nicht entschlüsselbar – neu koppeln',
    ];

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
            $keyId  = isset($_POST['key_id']) ? sanitize_key((string) wp_unslash($_POST['key_id'])) : '';
            if ($action === 'new_code') {
                $code = Store::issuePairingCode();
            } elseif ($action === 'revoke' && $keyId !== '') {
                Store::deletePairing($keyId);
                self::notice('success', 'Pairing widerrufen.');
            } elseif ($action === 'open_window' && $keyId !== '') {
                $seconds = isset($_POST['seconds']) ? (int) wp_unslash($_POST['seconds']) : 0;
                if (self::openWindow($keyId, $seconds, get_current_user_id())) {
                    self::notice('success', 'Push-Fenster geöffnet für ' . PushWindow::DURATIONS[$seconds] . '.');
                }
            } elseif ($action === 'close_window' && $keyId !== '') {
                self::closeWindow($keyId);
                self::notice('success', 'Push-Fenster geschlossen.');
            } elseif ($action === 'rollback' && isset($_POST['push_id'])) {
                $result = Push::rollbackPush(sanitize_text_field((string) wp_unslash($_POST['push_id'])));
                if ($result instanceof \WP_Error) {
                    self::notice('error', $result->get_error_message());
                } else {
                    self::notice('success', 'Push zurückgerollt.');
                    $data = (array) $result->get_data();
                    $kept = is_array($data['kept'] ?? null) ? $data['kept'] : [];
                    if ($kept !== []) {
                        self::notice('warning', 'Seit dem Push geändert, bleibt unter wp-content/uploads liegen: ' . implode(', ', array_map('strval', $kept)));
                    }
                }
            }
        }
        Push::sync();
        $now = time();
        ?>
        <div class="wrap">
            <h1>wpsync</h1>
            <p>Schnittstelle für <code>wpsync pull</code> (Live → Lokal), <code>wpsync push</code> (Code, nur im Push-Fenster) und <code>wpsync staging</code> (Kopie auf dem Server).</p>
            <?php self::keyNotices(); ?>

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
            <p>Ein Gerät kann Code nur pushen, solange sein Push-Fenster offen ist. Das Fenster schliesst sich von selbst.</p>
            <p>Die Secrets der Geräte liegen verschlüsselt in der Datenbank. Der Schlüssel stammt aus <code>wp-config.php</code>:
                aus <code>WPSYNC_KEY</code>, sonst aus <code>AUTH_KEY</code> und <code>SECURE_AUTH_KEY</code>.
                Werden diese WordPress-Salts erneuert (das tun auch manche Sicherheits-Plugins), müssen alle Geräte neu
                gekoppelt werden. Empfehlung: in <code>wp-config.php</code> einen eigenen Schlüssel mit mindestens
                32 zufälligen Zeichen setzen, etwa <code>define('WPSYNC_KEY', '…');</code> – bestehende Kopplungen bleiben dabei gültig.</p>
            <table class="widefat striped">
                <thead><tr><th>Gerät</th><th>Gekoppelt</th><th>Zuletzt benutzt</th><th>Secret</th><th>Push-Fenster</th><th></th></tr></thead>
                <tbody>
                <?php foreach (Store::pairings() as $pairing) : ?>
                    <tr>
                        <td><?php echo esc_html($pairing['device']); ?></td>
                        <td><?php echo esc_html(wp_date('d.m.Y H:i', $pairing['created'])); ?></td>
                        <td><?php echo $pairing['last_used'] === null ? '–' : esc_html(wp_date('d.m.Y H:i', $pairing['last_used'])); ?></td>
                        <td<?php echo $pairing['secret_state'] === Store::SECRET_SEALED ? '' : ' style="color:#b32d2e"'; ?>><?php echo esc_html(self::SECRET_STATE[$pairing['secret_state']] ?? $pairing['secret_state']); ?></td>
                        <td>
                            <?php echo esc_html(PushWindow::label($pairing['push_until'], $now)); ?>
                            <form method="post" style="display:inline-block;margin-left:1em">
                                <?php wp_nonce_field('wpsync_admin'); ?>
                                <input type="hidden" name="key_id" value="<?php echo esc_attr($pairing['key_id']); ?>">
                                <?php if (PushWindow::open($pairing['push_until'], $now)) : ?>
                                    <input type="hidden" name="wpsync_action" value="close_window">
                                    <button type="submit" class="button">Schliessen</button>
                                <?php else : ?>
                                    <input type="hidden" name="wpsync_action" value="open_window">
                                    <select name="seconds">
                                        <?php foreach (PushWindow::DURATIONS as $seconds => $label) : ?>
                                            <option value="<?php echo esc_attr((string) $seconds); ?>"><?php echo esc_html($label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="button">Öffnen</button>
                                <?php endif; ?>
                            </form>
                        </td>
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

            <h2>Pushes</h2>
            <?php $pushes = Store::pushes(20); ?>
            <?php if ($pushes === []) : ?>
                <p>Noch kein Push.</p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead><tr><th>Zeit</th><th>Ziel</th><th>Gerät</th><th>Fenster von</th><th>Einheiten</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($pushes as $push) : ?>
                        <tr>
                            <td><?php echo esc_html(wp_date('d.m.Y H:i', $push['created'])); ?><br><code><?php echo esc_html($push['push_id']); ?></code></td>
                            <td><?php echo esc_html($push['target'] === 'staging' ? 'Staging' : 'Live'); ?></td>
                            <td><?php echo esc_html($push['device']); ?></td>
                            <td><?php echo esc_html(self::opener($push['opened_by'] ?? null)); ?></td>
                            <td>
                                <?php foreach ($push['units'] as $unit) : ?>
                                    <?php echo esc_html(self::unitLine($unit)); ?><br>
                                <?php endforeach; ?>
                            </td>
                            <td><?php echo esc_html((self::STATUS[$push['status']] ?? $push['status']) . ($push['forced'] ? ' (--force)' : '')); ?></td>
                            <td>
                                <?php if (!$push['pruned'] && in_array($push['status'], [PushRescue::COMMITTED, PushRescue::CONFIRMED], true)) : ?>
                                    <form method="post">
                                        <?php wp_nonce_field('wpsync_admin'); ?>
                                        <input type="hidden" name="wpsync_action" value="rollback">
                                        <input type="hidden" name="push_id" value="<?php echo esc_attr($push['push_id']); ?>">
                                        <button type="submit" class="button">Zurückrollen</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <h2>Staging</h2>
            <?php self::staging(); ?>
        </div>
        <?php
    }

    /**
     * Öffnet das Push-Fenster eines Pairings für eine der festen Dauern und merkt sich, wer es
     * geöffnet hat (Spec Content-Push §9: Autor neuer Beiträge in P2).
     */
    public static function openWindow(string $keyId, int $seconds, int $userId): bool
    {
        if (!isset(PushWindow::DURATIONS[$seconds])) {
            return false;
        }
        Store::setPushUntil($keyId, PushWindow::until($seconds, time()), $userId > 0 ? $userId : null);
        return true;
    }

    public static function closeWindow(string $keyId): void
    {
        Store::setPushUntil($keyId, 0);
    }

    /**
     * Login des Benutzers, der beim Begin das Push-Fenster offen hatte; „–“ ohne (WP-CLI, Agent vor 0.6.0).
     *
     * @param mixed $id
     */
    private static function opener($id): string
    {
        if (!is_int($id) || $id <= 0) {
            return '–';
        }
        $user = get_userdata($id);
        return $user ? (string) $user->user_login : '#' . $id;
    }

    /**
     * Abschnitt Staging: nur Anzeige, keine Aktion. Anlegen, Auffrischen, Löschen und der
     * Einmal-Link laufen ausschliesslich über die signierten Routen der CLI (T1) – wer die
     * Admin-Seite von Live sieht, ist damit noch nicht Administrator der Kopie. Alles aus dem
     * Datensatz wird escaped: er kommt aus der Datenbank.
     */
    public static function staging(): void
    {
        $staging = Staging::summary();
        if ($staging === null) {
            ?>
            <p>Keine Staging-Kopie. Anlegen im Terminal: <code>wpsync staging create &lt;site&gt;</code></p>
            <?php
            return;
        }
        $record = (array) Staging::record();
        $status = (string) $staging['status'];
        $error  = is_string($record['error'] ?? null) ? $record['error'] : '';
        if ($status === Staging::LOCKED) {
            self::notice('warning', 'Die Staging-Kopie ist nach 14 Tagen ohne Nutzung gesperrt. Entsperren: wpsync staging open <site>; löschen: wpsync staging delete <site>.');
        }
        ?>
        <table class="widefat striped">
            <tbody>
            <tr><th>Status</th><td><?php echo esc_html(self::STAGING[$status] ?? $status); ?></td></tr>
            <tr><th>Adresse</th><td><code><?php echo esc_html((string) $staging['url']); ?></code> (nur mit Link aus <code>wpsync staging open</code>)</td></tr>
            <tr><th>Ordner</th><td><code><?php echo esc_html(is_string($record['dir'] ?? null) ? $record['dir'] : ''); ?></code></td></tr>
            <tr><th>Tabellen-Präfix</th><td><code><?php echo esc_html(is_string($record['prefix'] ?? null) ? $record['prefix'] : ''); ?></code></td></tr>
            <tr><th>Zuletzt genutzt</th><td><?php echo $staging['last_used'] > 0 ? esc_html(wp_date('d.m.Y H:i', (int) $staging['last_used'])) : '–'; ?></td></tr>
            <?php if ($error !== '') : ?>
                <tr><th>Fehler</th><td><?php echo esc_html($error); ?></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        <p>Die Kopie liegt in diesem Ordner im WordPress-Verzeichnis und in Tabellen mit eigenem Präfix in derselben Datenbank. Backup-Plugins sichern sie mit; Sicherheits-Scanner können den zweiten WordPress-Core melden.</p>
        <?php
    }

    /** Warnt, wenn neue Secrets im Klartext landen würden (SEC-006). */
    private static function keyNotices(): void
    {
        if (SecretKey::ownKeyTooShort()) {
            self::notice('warning', 'WPSYNC_KEY ist kürzer als ' . SecretKey::MIN_LENGTH . ' Zeichen und wird ignoriert.');
        }
        if (SecretKey::current() === null) {
            self::notice('error', 'Pairing-Secrets liegen im Klartext – AUTH_KEY/SECURE_AUTH_KEY fehlen oder sind Standardwerte; WPSYNC_KEY in wp-config.php setzen.');
        } elseif (!Store::secretColumnFits()) {
            self::notice('error', 'Pairing-Secrets liegen im Klartext – die Tabelle ' . Store::table('pairings') . ' liess sich nicht erweitern (ALTER TABLE). Datenbankrechte prüfen, dann das Plugin deaktivieren und wieder aktivieren (entfernt alle Kopplungen).');
        }
    }

    /** @param array<string, mixed> $unit */
    public static function unitLine(array $unit): string
    {
        // Der Plugin-Zustand eines Pushs ist keine Einheit mit Dateien (Spec Content-Push P4 §8.5).
        if (($unit['path'] ?? '') === PushPlugins::UNIT) {
            return self::pluginLine($unit);
        }
        $line = (string) ($unit['path'] ?? '');
        $old  = (string) ($unit['old_version'] ?? '');
        $new  = (string) ($unit['new_version'] ?? '');
        if (empty($unit['exists'])) {
            $line .= ' (neu)';
        }
        if ($new !== '' && $old !== $new) {
            $line .= ' ' . ($old !== '' ? $old . ' → ' : '') . $new;
        } elseif ($new !== '') {
            $line .= ' ' . $new;
        }
        $line .= ' – ' . (int) ($unit['uploaded'] ?? 0) . ' von ' . (int) ($unit['files'] ?? 0) . ' Dateien übertragen';
        // Inhalte: womit das Paket über die Whitelist des Agents hinausging.
        $parts = [];
        foreach (['post_types' => 'Beitragstypen', 'taxonomies' => 'Taxonomien', 'meta_exceptions' => 'Meta-Ausnahmen'] as $field => $label) {
            $names = array_filter(is_array($unit['extensions'][$field] ?? null) ? $unit['extensions'][$field] : [], 'is_string');
            if ($names !== []) {
                $parts[] = $label . ' ' . implode(', ', $names);
            }
        }
        if ($parts !== []) {
            $line .= ' – Projekt-Erweiterungen: ' . implode('; ', $parts);
        }
        // Inhalte, die rescue.php ohne WordPress zurückgenommen hat (Spec Content-Push P3 §8.2).
        if (($unit['via'] ?? '') === PushRescue::VIA_RESCUE) {
            $line  .= ' – über rescue.php zurückgenommen';
            $steps  = is_array($unit['post_actions'] ?? null) ? $unit['post_actions'] : [];
            $failed = count(array_filter($steps, static function ($step): bool {
                return is_array($step) && empty($step['ok']);
            }));
            if ($steps !== []) {
                $line .= ', Nacharbeiten nachgeholt' . ($failed > 0 ? ' (' . $failed . ' fehlgeschlagen)' : '');
            }
            $left = (int) ($unit['left_total'] ?? 0);
            if ($left > 0) {
                $line .= ', ' . $left . ' fremde Stelle(n) an eingefügten Objekten blieben stehen';
                foreach ($steps as $step) {
                    if (is_array($step) && ($step['step'] ?? '') === Push::LEFT_CLEANUP && !empty($step['ok'])) {
                        $line .= ' (Meta und Zuordnungen davon inzwischen entfernt)';
                    }
                }
            }
        }
        return $line;
    }

    /**
     * Die Zeile der Einheit plugins: was der Push an der Liste der aktiven Plugins vorhatte bzw. getan
     * hat, und was daraus wurde. Die Namen kommen aus der Datenbank; ausgegeben wird die Zeile escaped.
     *
     * @param array<string, mixed> $unit
     */
    private static function pluginLine(array $unit): string
    {
        $names = static function ($raw): array {
            return array_values(array_filter(is_array($raw) ? $raw : [], 'is_string'));
        };
        $done   = array_key_exists('activated', $unit) || array_key_exists('deactivated', $unit);
        $labels = $done ? ['activated' => 'aktiviert: ', 'deactivated' => 'deaktiviert: '] : ['activate' => 'aktivieren ', 'deactivate' => 'deaktivieren '];
        $parts  = [];
        foreach ($labels as $field => $label) {
            $list = $names($unit[$field] ?? null);
            if ($list !== []) {
                $parts[] = $label . implode(', ', $list);
            }
        }
        if ($done) {
            $line = 'Plugin-Zustand – ' . ($parts === [] ? 'unverändert' : implode('; ', $parts));
        } else {
            $line = 'Plugin-Zustand – vorgesehen: ' . ($parts === [] ? 'nichts' : implode('; ', $parts));
        }
        if (is_array($unit['back'] ?? null)) {
            $line .= ' – Plugin-Zustand zurückgenommen' . (($unit['via'] ?? '') === PushRescue::VIA_RESCUE ? ' (über rescue.php)' : '');
        } elseif (!empty($unit['kept'])) {
            $line .= ' – steht noch (mit confirm angenommen)';
        }
        return $line;
    }

    private static function notice(string $type, string $message): void
    {
        echo '<div class="notice notice-' . esc_attr($type) . '"><p>' . esc_html($message) . '</p></div>';
    }
}
