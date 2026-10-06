<?php
namespace WpSync;

defined('ABSPATH') || exit;

/**
 * Dateien, die der Agent in die Staging-Kopie schreibt (Spec Stufe 2b 5.3, 5.4): eine eigene
 * wp-config.php und die .htaccess – offen nur mit Zugangs-Cookie, während eines Jobs und nach
 * dem Verfall ganz gesperrt (V7).
 */
final class StagingConfig
{
    public const SALTS = [
        'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY',
        'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT', 'WP_CACHE_KEY_SALT',
    ];
    public const PROBE_DENY    = 'wpsync-probe-deny.txt';
    public const PROBE_REWRITE = 'wpsync-probe-rewrite';
    public const PROBE_TARGET  = 'wpsync-probe-target.txt';
    public const PROBE_FILES   = 'wpsync-probe.log';

    private const PATH_RE = '#^(/[A-Za-z0-9._~-]+)*/wpsync-staging-[a-f0-9]{12}\z#';
    private const URL_RE  = '#^https?://[A-Za-z0-9.-]+(:[0-9]+)?(/[A-Za-z0-9._~/-]*)?\z#';
    private const DENY    = "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>";

    /** URLs, die unverändert in .htaccess und PHP-Quelltext landen dürfen. */
    public static function validUrl(string $url): bool
    {
        return preg_match(self::URL_RE, $url) === 1;
    }

    /** @return array<string, string> frische Salts und Cache-Salt (Leitplanken 5, 6) */
    public static function salts(): array
    {
        $out = [];
        foreach (self::SALTS as $name) {
            $out[$name] = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        }
        return $out;
    }

    /**
     * @param array<string, string> $db    DB_NAME, DB_USER, DB_PASSWORD, DB_HOST, DB_CHARSET, DB_COLLATE von Live
     * @param array<string, string> $salts aus salts()
     * @param array<string, string> $extra weitere Konstanten von Live (V15)
     */
    public static function wpConfig(array $db, string $prefix, string $url, array $salts, array $extra): string
    {
        $path  = rtrim((string) parse_url($url, PHP_URL_PATH), '/');
        $lines = ['<?php', '// wpsync Staging – vom wpsync-Agent erzeugt; refresh überschreibt, delete löscht diese Datei.'];
        foreach (['DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST', 'DB_CHARSET', 'DB_COLLATE'] as $name) {
            $lines[] = self::define($name, (string) ($db[$name] ?? ''));
        }
        $lines[] = '$table_prefix = ' . var_export($prefix, true) . ';';
        $lines[] = self::define('WP_HOME', $url);
        $lines[] = self::define('WP_SITEURL', $url);
        foreach (self::SALTS as $name) {
            $lines[] = self::define($name, (string) $salts[$name]);
        }
        // Eigene Sitzungen (Leitplanke 6): Cookies gelten nur unter dem Staging-Pfad.
        $lines[] = self::define('COOKIEHASH', md5($url));
        $lines[] = self::define('COOKIEPATH', $path . '/');
        $lines[] = self::define('SITECOOKIEPATH', $path . '/');
        $lines[] = self::define('ADMIN_COOKIE_PATH', $path . '/wp-admin');
        $lines[] = self::define('PLUGINS_COOKIE_PATH', $path . '/wp-content/plugins');
        foreach (['WP_CACHE' => false, 'DISABLE_WP_CRON' => true, 'WP_AUTO_UPDATE_CORE' => false, 'AUTOMATIC_UPDATER_DISABLED' => true, 'DISALLOW_FILE_MODS' => true, 'WPSYNC_STAGING' => true] as $name => $on) {
            $lines[] = 'define(' . var_export($name, true) . ', ' . ($on ? 'true' : 'false') . ');';
        }
        $lines[] = self::define('WP_ENVIRONMENT_TYPE', 'staging');
        foreach ($extra as $name => $value) {
            if (preg_match('/^[A-Z_]{1,64}\z/', (string) $name) === 1) {
                $lines[] = self::define((string) $name, (string) $value);
            }
        }
        if (strpos($url, 'https://') === 0) {
            // Die wp-config.php von Live wird nicht übernommen – hinter einem Proxy fehlte sonst HTTPS (V15).
            $lines[] = "if ((\$_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') { \$_SERVER['HTTPS'] = 'on'; }";
        }
        $lines[] = "if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }";
        $lines[] = "require_once ABSPATH . 'wp-settings.php';";
        return implode("\n", $lines) . "\n";
    }

    /**
     * Zugangssperre, noindex, Dateisperre, Uploads von Live, WordPress-Rewrite. Mit $probeToken
     * zusätzlich die Rewrite-Probe (S5, V6).
     */
    public static function htaccess(string $path, string $uploadsUrl, ?string $probeToken = null): string
    {
        if (preg_match(self::PATH_RE, $path) !== 1 || !self::validUrl($uploadsUrl)) {
            throw new \InvalidArgumentException('invalid staging path or uploads URL');
        }
        $cookie = 'RewriteCond %{HTTP_COOKIE} !(^|;\s*)' . StagingAccess::COOKIE . '=';
        $rules  = [
            '# wpsync Staging – vom wpsync-Agent erzeugt, refresh überschreibt diese Datei.',
            '<IfModule mod_headers.c>',
            'Header always set X-Robots-Tag "noindex, nofollow"',
            '</IfModule>',
            '<FilesMatch "^(wp-config\.php|wpsync-staging\.json|\.htaccess)$|\.(log|sql)$">',
            self::DENY,
            '</FilesMatch>',
            '<IfModule mod_rewrite.c>',
            'RewriteEngine On',
            'RewriteBase ' . $path . '/',
            '# Ohne Zugangs-Cookie nur der Einmal-Link auf die Startseite',
            $cookie,
            'RewriteCond %{QUERY_STRING} (^|&)wpsync_login=',
            'RewriteRule ^(index\.php)?$ - [S=1]',
            $cookie,
            'RewriteRule ^ - [F]',
        ];
        if ($probeToken !== null) {
            $rules[] = 'RewriteRule ^' . self::PROBE_REWRITE . '$ ' . self::PROBE_TARGET . ' [L]';
        }
        array_push(
            $rules,
            '# Medien, die es nur auf Live gibt (S3)',
            'RewriteCond %{REQUEST_FILENAME} !-f',
            'RewriteRule ^wp-content/uploads/(.*)$ ' . rtrim($uploadsUrl, '/') . '/$1 [R=302,L]',
            'RewriteRule ^index\.php$ - [L]',
            'RewriteCond %{REQUEST_FILENAME} !-f',
            'RewriteCond %{REQUEST_FILENAME} !-d',
            'RewriteRule . ' . $path . '/index.php [L]',
            '</IfModule>',
            '<IfModule !mod_rewrite.c>',
            self::DENY,
            '</IfModule>'
        );
        return implode("\n", $rules) . "\n";
    }

    /** Während eines Jobs und nach dem Verfall (V7, S4): alles 403. */
    public static function locked(): string
    {
        return "# wpsync Staging – gesperrt. Entsperren: wpsync staging open <site>\n"
            . "<IfModule mod_headers.c>\nHeader always set X-Robots-Tag \"noindex, nofollow\"\n</IfModule>\n"
            . self::DENY . "\n";
    }

    /** Hinweis bei fehlgeschlagener Probe (Anhang A). */
    public static function nginxRule(string $path): string
    {
        return "location ^~ $path/ {\n"
            . "    if (\$http_cookie !~ \"wpsync_stg=\") { set \$stg_deny 1; }\n"
            . "    if (\$arg_wpsync_login) { set \$stg_deny 0; }\n"
            . "    if (\$stg_deny = 1) { return 403; }\n"
            . "    location ~ /(wp-config\\.php|wpsync-staging\\.json)\$ { return 403; }\n"
            . "    try_files \$uri \$uri/ $path/index.php?\$args;\n"
            . "}\n";
    }

    private static function define(string $name, string $value): string
    {
        return 'define(' . var_export($name, true) . ', ' . var_export($value, true) . ');';
    }
}
