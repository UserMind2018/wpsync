<?php
/**
 * Plugin Name: wpsync Staging
 * Description: Zugangssperre und Riegel dieser Staging-Kopie. Vom wpsync-Agent angelegt, nie pushbar; refresh ersetzt die Datei.
 * Version:     1.0.0
 * Author:      wpsync
 *
 * Spec Stufe 2b 5.5: Zugang nur mit Einmal-Link bzw. Zugangs-Cookie, Mails an die Sink-Adresse,
 * keine Anfragen an Mail-, Zahlungs- und Newsletter-Dienste oder an Live, noindex, kein
 * Action-Scheduler-Runner, Hinweis in der Admin-Leiste. Schreibt nur wp-content/wpsync-staging.json.
 * Muss unter PHP 7.4 laufen.
 */

defined('ABSPATH') || exit;

// Nur in einer Kopie, deren wp-config.php der Agent geschrieben hat – auf Live sperrte die Datei alle aus.
if (!defined('WPSYNC_STAGING') || !WPSYNC_STAGING) {
    return;
}

/**
 * Für jede 403 und für die Antwort auf einen eingelösten Link: nichts davon in einen Cache,
 * und die Adresse (mit dem Token) nie als Referrer weiter.
 *
 * @return list<string>
 */
function wpsync_staging_headers(): array
{
    return ['Cache-Control: no-store, private', 'Referrer-Policy: no-referrer', 'X-Robots-Tag: noindex, nofollow'];
}

/** 403, bevor ein Theme oder Plugin etwas ausgibt. */
function wpsync_staging_deny(string $why): void
{
    if (!headers_sent()) {
        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        foreach (wpsync_staging_headers() as $wpsync_staging_header) {
            header($wpsync_staging_header);
        }
    }
    echo '<!doctype html><meta charset="utf-8"><meta name="robots" content="noindex, nofollow"><title>Staging</title>'
        . '<p>Diese Staging-Kopie ist gesperrt. ' . htmlspecialchars($why, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p>Zugang: <code>wpsync staging open &lt;site&gt;</code></p>';
    exit;
}

// Ohne die beiden Klassen gibt es weder Zugangsprüfung noch Riegel: sperren statt mit einem Fatal enden.
foreach ([__DIR__ . '/wpsync-staging/StagingAccess.php', __DIR__ . '/wpsync-staging/StagingHosts.php'] as $wpsync_staging_file) {
    if (!is_readable($wpsync_staging_file)) {
        wpsync_staging_deny('Der Riegel ist unvollständig.');
    }
    require_once $wpsync_staging_file;
}

/**
 * Das Token eines Einmal-Links – nur am Einstieg der Kopie (Staging-Pfad mit oder ohne /, index.php),
 * unabhängig von der .htaccess. An jeder anderen Stelle gilt die Anfrage als eine ohne Token, und
 * das Token bleibt unverbraucht.
 *
 * @param array<mixed> $query
 */
function wpsync_staging_login_token(array $query, string $requestUri, string $stagingPath): ?string
{
    if (!isset($query['wpsync_login']) || !is_string($query['wpsync_login'])) {
        return null;
    }
    $base = rtrim($stagingPath, '/');
    $path = explode('?', $requestUri, 2)[0];
    if ($base === '' || !in_array($path, [$base, $base . '/', $base . '/index.php'], true)) {
        return null;
    }
    return $query['wpsync_login'];
}

/**
 * Upload-Ordner der Kopie (Leitplanke 4), egal was in der Datenbank steht: ein absolutes upload_path
 * von Live zeigte sonst auf die Uploads von Live, und Löschen oder Neuberechnen auf Staging träfe
 * Live. Die Adresse liegt unter der Kopie, damit fehlende Medien per .htaccess von Live kommen.
 * Ein Unterordner bleibt, solange er nicht aus dem Ordner herausführt.
 *
 * @param mixed $dirs Ergebnis von wp_upload_dir()
 * @return array<mixed>
 */
function wpsync_staging_upload_dir($dirs, string $contentDir, string $home): array
{
    $dirs   = is_array($dirs) ? $dirs : [];
    $subdir = isset($dirs['subdir']) && is_string($dirs['subdir']) ? $dirs['subdir'] : '';
    if ($subdir !== '' && (preg_match('#^(/[^/\\\\\0]+)+\z#', $subdir) !== 1 || preg_match('#/\.\.?(/|\z)#', $subdir) === 1)) {
        $subdir = '';
    }
    $dirs['basedir'] = rtrim($contentDir, '/') . '/uploads';
    $dirs['baseurl'] = rtrim($home, '/') . '/wp-content/uploads';
    $dirs['subdir']  = $subdir;
    $dirs['path']    = $dirs['basedir'] . $subdir;
    $dirs['url']     = $dirs['baseurl'] . $subdir;
    $dirs['error']   = $dirs['error'] ?? false;
    return $dirs;
}

/**
 * Zugangsprüfung, fail-closed: wirft StagingAccess (Zustandsdatei nicht zu öffnen), ist das eine
 * Ablehnung – nie ein Durchlassen und nie ein Fatal.
 *
 * @return array{0: string, 1: string} ['login', Cookie-Wert], ['pass', ''] oder ['deny', Grund]
 */
function wpsync_staging_gate(\WpSync\StagingAccess $access, ?string $login, string $cookie, int $now): array
{
    try {
        if ($login !== null) {
            $value = $access->redeemToken($login, $now);
            return $value === null ? ['deny', 'Der Link ist abgelaufen oder wurde schon benutzt.'] : ['login', $value];
        }
        if (!$access->cookieValid($cookie, $now)) {
            return ['deny', 'Kein gültiger Zugang.'];
        }
        $access->touch($now);
        return ['pass', ''];
    } catch (\Throwable $e) {
        error_log('wpsync-staging: access check: ' . $e->getMessage());
        return ['deny', 'Der Zugang liess sich nicht prüfen.'];
    }
}

/**
 * Grund, aus dem eine ausgehende Anfrage gesperrt ist, oder null. Jeder Grund sperrt.
 *
 * @param mixed                $url
 * @param array<string, mixed> $state
 */
function wpsync_staging_blocked_reason($url, array $state): ?string
{
    if (!is_string($url)) {
        return 'invalid';
    }
    return \WpSync\StagingHosts::blocked($url, (string) $state['live_url'], (string) $state['staging_path'], (string) $state['uploads_url']);
}

/**
 * @param mixed $value Empfänger aus wp_mail: String (auch mit Kommas) oder Liste
 * @return list<string>
 */
function wpsync_staging_addresses($value): array
{
    $out = [];
    foreach ((array) $value as $item) {
        foreach (explode(',', (string) $item) as $part) {
            $part = trim($part);
            if ($part !== '') {
                $out[] = $part;
            }
        }
    }
    return $out;
}

/** Legt den Staging-Admin wpsync an oder setzt seine Rolle zurück und meldet ihn an (T1). */
function wpsync_staging_login(): void
{
    $user = get_user_by('login', 'wpsync');
    if ($user === false) {
        $id = wp_insert_user([
            'user_login'   => 'wpsync',
            'user_email'   => 'wpsync@example.invalid',
            'user_pass'    => wp_generate_password(32, true, true),
            'display_name' => 'wpsync Staging',
            'role'         => 'administrator',
        ]);
        if (is_wp_error($id)) {
            wpsync_staging_deny('Der Staging-Admin liess sich nicht anlegen: ' . $id->get_error_message());
        }
    } else {
        $id = $user->ID;
        $user->set_role('administrator');
    }
    wp_set_auth_cookie((int) $id, false, is_ssl());
    foreach (wpsync_staging_headers() as $header) {
        header($header);
    }
    wp_safe_redirect(admin_url());
    exit;
}

$wpsync_staging       = new \WpSync\StagingAccess(WP_CONTENT_DIR . '/wpsync-staging.json');
$wpsync_staging_state = $wpsync_staging->read();

// WP-CLI auf dem Server prüft keinen Zugang: wer dort eine Shell hat, braucht keinen Link.
if (PHP_SAPI !== 'cli') {
    $wpsync_now = time();
    // phpcs:disable WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
    $wpsync_uri   = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    $wpsync_login = wpsync_staging_login_token($_GET, $wpsync_uri, $wpsync_staging_state['staging_path']);
    $wpsync_value = isset($_COOKIE[\WpSync\StagingAccess::COOKIE]) && is_string($_COOKIE[\WpSync\StagingAccess::COOKIE]) ? (string) $_COOKIE[\WpSync\StagingAccess::COOKIE] : '';
    // phpcs:enable
    $wpsync_gate = wpsync_staging_gate($wpsync_staging, $wpsync_login, $wpsync_value, $wpsync_now);
    if ($wpsync_gate[0] === 'login') {
        setcookie(\WpSync\StagingAccess::COOKIE, $wpsync_gate[1], [
            'expires'  => $wpsync_now + \WpSync\StagingAccess::COOKIE_TTL,
            'path'     => SITECOOKIEPATH,
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        add_action('init', 'wpsync_staging_login', 0);
    } elseif ($wpsync_gate[0] !== 'pass') {
        wpsync_staging_deny($wpsync_gate[1]);
    }
}

// Mail-Riegel 1 wie local-mailguard: alle Empfänger auf die Sink-Adresse, Cc/Bcc entfernt.
add_filter('wp_mail', static function (array $args): array {
    $recipients = wpsync_staging_addresses($args['to'] ?? []);
    $headers    = $args['headers'] ?? '';
    $lines      = is_array($headers) ? $headers : (preg_split("/\r\n|\r|\n/", (string) $headers) ?: []);
    $kept       = [];
    foreach ($lines as $line) {
        if (preg_match('/^\s*(cc|bcc)\s*:/i', (string) $line) === 1) {
            $recipients = array_merge($recipients, wpsync_staging_addresses(substr((string) $line, strpos((string) $line, ':') + 1)));
            continue;
        }
        if (trim((string) $line) !== '') {
            $kept[] = $line;
        }
    }
    $recipients      = array_values(array_unique($recipients));
    $kept[]          = 'X-Mailguard: blocked';
    $kept[]          = 'X-Mailguard-Original-To: ' . implode(', ', $recipients);
    $args['to']      = \WpSync\StagingHosts::SINK;
    $args['headers'] = $kept;
    $args['subject'] = '[STAGING → ' . ($recipients[0] ?? 'unbekannt') . '] ' . ($args['subject'] ?? '');
    return $args;
}, PHP_INT_MAX);

// Mail-Riegel 2: nach jedem SMTP-Plugin – Empfänger und Transport zurück auf PHP mail().
add_action('phpmailer_init', static function ($phpmailer): void {
    if (!is_object($phpmailer)) {
        return;
    }
    try {
        $phpmailer->clearAllRecipients();
        $phpmailer->addAddress(\WpSync\StagingHosts::SINK);
        $phpmailer->isMail();
        $phpmailer->Host       = '';
        $phpmailer->SMTPAuth   = false;
        $phpmailer->Username   = '';
        $phpmailer->Password   = '';
        $phpmailer->SMTPSecure = '';
    } catch (\Throwable $e) {
        error_log('wpsync-staging: phpmailer_init: ' . $e->getMessage());
    }
}, PHP_INT_MAX);

// Mail-Riegel 3 und Leitplanke 7: Mail-, Zahlungs- und Newsletter-Dienste und Live selbst.
add_filter('pre_http_request', static function ($preempt, $args, $url) use ($wpsync_staging_state) {
    $why = wpsync_staging_blocked_reason($url, $wpsync_staging_state);
    if ($why === null) {
        return $preempt;
    }
    error_log('wpsync-staging: ' . $why . ' gesperrt: ' . (is_string($url) ? $url : gettype($url)));
    return new WP_Error('wpsync_staging_blocked', 'wpsync Staging: ausgehende Anfrage gesperrt (' . $why . ').');
}, PHP_INT_MAX, 3);

// Ein Redirect läuft nicht noch einmal durch pre_http_request: jedes Ziel wird wie die erste Anfrage
// geprüft. WordPress fängt die Ausnahme der HTTP-Bibliothek und liefert sie dem Aufrufer als WP_Error.
add_action('requests-requests.before_redirect', static function ($location) use ($wpsync_staging_state): void {
    $why = wpsync_staging_blocked_reason($location, $wpsync_staging_state);
    if ($why === null) {
        return;
    }
    error_log('wpsync-staging: ' . $why . ' gesperrt (Weiterleitung): ' . (is_string($location) ? $location : gettype($location)));
    $class = class_exists('\WpOrg\Requests\Exception') ? '\WpOrg\Requests\Exception' : '\Requests_Exception'; // vor WordPress 6.2
    throw new $class('wpsync Staging: Weiterleitung gesperrt (' . $why . ').', 'wpsync_staging_blocked');
}, 0, 1);

// Leitplanke 4: nach jedem Plugin – Uploads der Kopie nie im Ordner von Live.
add_filter('upload_dir', static function ($dirs): array {
    return wpsync_staging_upload_dir($dirs, WP_CONTENT_DIR, defined('WP_HOME') ? (string) WP_HOME : '');
}, PHP_INT_MAX);

// noindex (AC-93) – zusätzlich zum Header der .htaccess.
add_filter('wp_robots', static function (array $robots): array {
    $robots['noindex']  = true;
    $robots['nofollow'] = true;
    return $robots;
}, 999);
add_filter('pre_option_blog_public', static function () {
    return '0';
});
add_action('send_headers', static function (): void {
    header('X-Robots-Tag: noindex, nofollow');
});

// Kein Action-Scheduler-Runner (AC-95); WP-Cron ist per DISABLE_WP_CRON aus.
add_filter('action_scheduler_allow_async_request_runner', '__return_false', 999);
add_filter('action_scheduler_queue_runner_time_limit', '__return_zero', 999);

// Hinweis in der Admin-Leiste und im Backend.
add_action('admin_bar_menu', static function ($bar) use ($wpsync_staging_state): void {
    $title = 'STAGING – Kopie von ' . $wpsync_staging_state['live_url'] . ', Stand ' . wp_date('d.m.Y H:i', $wpsync_staging_state['copied_at']);
    $bar->add_node(['id' => 'wpsync-staging', 'title' => esc_html($title), 'meta' => ['class' => 'wpsync-staging']]);
}, 0);
$wpsync_staging_style = static function (): void {
    echo '<style>#wpadminbar .wpsync-staging > .ab-item{background:#b32d2e!important;color:#fff!important;font-weight:600}</style>';
};
add_action('wp_head', $wpsync_staging_style);
add_action('admin_head', $wpsync_staging_style);
add_action('admin_notices', static function () use ($wpsync_staging_state): void {
    if (current_user_can('manage_options')) {
        printf(
            '<div class="notice notice-warning"><p><strong>STAGING</strong> – Kopie von %s. Mails gehen an %s, Zahlungsanbieter, Newsletter-Dienste und Live sind gesperrt.</p></div>',
            esc_html($wpsync_staging_state['live_url']),
            esc_html(\WpSync\StagingHosts::SINK)
        );
    }
});
