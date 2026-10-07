<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\TestCase;
use WpSync\StagingAccess;
use WpSync\StagingConfig;

final class StagingConfigTest extends TestCase
{
    private const PATH = '/wpsync-staging-0123456789ab';
    private const URL  = 'https://example.com/wpsync-staging-0123456789ab';

    /** @return array<string, string> */
    private function db(): array
    {
        return ['DB_NAME' => 'live', 'DB_USER' => 'u', 'DB_PASSWORD' => "pa'ss\\word", 'DB_HOST' => 'localhost', 'DB_CHARSET' => 'utf8mb4', 'DB_COLLATE' => ''];
    }

    /** Spec 5.3, Leitplanken 5 und 6 */
    public function testWpConfigSeparatesSessionsAndCaches(): void
    {
        $salts  = StagingConfig::salts();
        $config = StagingConfig::wpConfig($this->db(), 'stgabc123_', self::URL, $salts, ['WP_MEMORY_LIMIT' => '256M']);
        $this->assertStringContainsString("\$table_prefix = 'stgabc123_';", $config);
        $this->assertStringContainsString("define('WP_HOME', '" . self::URL . "');", $config);
        $this->assertStringContainsString("define('COOKIEPATH', '" . self::PATH . "/');", $config);
        $this->assertStringContainsString("define('COOKIEHASH', '" . md5(self::URL) . "');", $config);
        $this->assertStringContainsString("define('WP_CACHE_KEY_SALT', '" . $salts['WP_CACHE_KEY_SALT'] . "');", $config);
        $this->assertStringContainsString("define('DB_PASSWORD', 'pa\\'ss\\\\word');", $config);
        foreach (['WP_CACHE' => 'false', 'DISABLE_WP_CRON' => 'true', 'DISALLOW_FILE_MODS' => 'true', 'WPSYNC_STAGING' => 'true', 'AUTOMATIC_UPDATER_DISABLED' => 'true'] as $name => $value) {
            $this->assertStringContainsString("define('" . $name . "', " . $value . ');', $config);
        }
        $this->assertStringContainsString("define('WP_ENVIRONMENT_TYPE', 'staging');", $config);
        $this->assertStringContainsString("define('WP_MEMORY_LIMIT', '256M');", $config);
        $this->assertStringContainsString('HTTP_X_FORWARDED_PROTO', $config);
        $this->assertNotSame($salts, StagingConfig::salts());

        $file = sys_get_temp_dir() . '/wpsync-config-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($file, $config);
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $out, $code);
        unlink($file);
        $this->assertSame(0, $code, implode("\n", $out));
    }

    /**
     * N1: die Zugangsprüfung läuft in der wp-config.php vor wp-settings.php – ohne Zugang lädt
     * nichts aus WordPress, auch kein mu-plugin. Gegen den eingebauten Webserver von PHP.
     */
    public function testWpConfigChecksAccessBeforeWordPressLoads(): void
    {
        $root = sys_get_temp_dir() . '/wpsync-gate-' . bin2hex(random_bytes(6));
        $copy = $root . self::PATH;
        mkdir($copy . '/wp-content/mu-plugins/wpsync-staging', 0777, true);
        copy(__DIR__ . '/../src/StagingAccess.php', $copy . '/wp-content/mu-plugins/wpsync-staging/StagingAccess.php');
        file_put_contents($copy . '/wp-config.php', StagingConfig::wpConfig($this->db(), 'stgabc123_', 'http://127.0.0.1' . self::PATH, StagingConfig::salts(), []));
        file_put_contents($copy . '/wp-settings.php', "<?php echo 'WP-LOADED';\n");
        foreach (['index.php', 'wp-login.php'] as $entry) {
            file_put_contents($copy . '/' . $entry, "<?php require __DIR__ . '/wp-config.php';\n");
        }
        $access = new StagingAccess($copy . '/wp-content/wpsync-staging.json');
        $access->init('https://example.com', 'https://example.com/wp-content/uploads', self::PATH, time(), time());
        $token  = $access->issueToken(time());
        $cookie = (string) $access->redeemToken($access->issueToken(time()), time());

        $port   = random_int(20000, 60000);
        $server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root], [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
        $this->assertIsResource($server);
        try {
            for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $port) === false; $i++) {
                usleep(100000);
            }
            $get = static function (string $path, string $cookie = '') use ($port): array {
                $socket = fsockopen('127.0.0.1', $port);
                fwrite($socket, "GET $path HTTP/1.0\r\nHost: 127.0.0.1\r\n" . ($cookie === '' ? '' : 'Cookie: ' . StagingAccess::COOKIE . '=' . $cookie . "\r\n") . "\r\n");
                $raw = (string) stream_get_contents($socket);
                fclose($socket);
                [$head, $body] = array_pad(explode("\r\n\r\n", $raw, 2), 2, '');
                return [explode("\r\n", $head)[0], $body, $head];
            };

            $denied = $get(self::PATH . '/');
            $this->assertStringContainsString(' 403', $denied[0]);
            $this->assertStringNotContainsString('WP-LOADED', $denied[1]);
            $this->assertStringContainsString('Kein gültiger Zugang.', $denied[1]);
            foreach (['Cache-Control: no-store, private', 'Referrer-Policy: no-referrer', 'X-Robots-Tag: noindex, nofollow'] as $header) {
                $this->assertStringContainsString($header, $denied[2]);
            }
            $this->assertStringContainsString(' 403', $get(self::PATH . '/wp-login.php', str_repeat('a', 64))[0], 'forged cookie');
            $this->assertStringContainsString(' 403', $get(self::PATH . '/wp-login.php?wpsync_login=' . $token)[0], 'a link counts only at the entry point');
            $forged = $get(self::PATH . '/?wpsync_login=' . str_repeat('a', 64));
            $this->assertStringContainsString(' 403', $forged[0]);
            $this->assertStringContainsString('abgelaufen', $forged[1]);

            $this->assertSame('WP-LOADED', $get(self::PATH . '/wp-login.php', $cookie)[1]);
            // Den Link löst erst der Riegel ein – die frühe Prüfung lässt ihn nur durch.
            $this->assertSame('WP-LOADED', $get(self::PATH . '/?wpsync_login=' . $token)[1]);
            $this->assertNotNull($access->redeemToken($token, time()), 'the early check does not use the link up');

            unlink($copy . '/wp-content/mu-plugins/wpsync-staging/StagingAccess.php');
            $this->assertStringContainsString(' 403', $get(self::PATH . '/wp-login.php', $cookie)[0], 'fail-closed without the class');
        } finally {
            proc_terminate($server);
            proc_close($server);
        }

        // WP-CLI prüft keinen Zugang.
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($copy . '/index.php') . ' 2>&1', $out);
        $this->assertSame('WP-LOADED', implode("\n", $out));
        exec('rm -rf ' . escapeshellarg($root));
    }

    public function testHttpCopyHasNoProxyLine(): void
    {
        $config = StagingConfig::wpConfig($this->db(), 'stgabc123_', 'http://example.test' . self::PATH, StagingConfig::salts(), []);
        $this->assertStringNotContainsString('HTTP_X_FORWARDED_PROTO', $config);
    }

    /** Jede Variante der wp-config.php und jede .env* bleibt gesperrt, auch mit Zugangs-Cookie. */
    public function testHtaccessDeniesConfigVariantsAndEnvFiles(): void
    {
        $rules = StagingConfig::htaccess(self::PATH, 'https://example.com/wp-content/uploads');
        $this->assertSame(1, preg_match('/^<FilesMatch "(.+)">$/m', $rules, $m));
        $pattern = '~' . $m[1] . '~';
        foreach (['wp-config.php', 'wp-config-old.php', 'wp-config.php.bak', 'wp-config-sample.php', '.env', '.env.local', '.envrc', 'wpsync-staging.json', '.htaccess', 'debug.log', 'dump.sql'] as $name) {
            $this->assertSame(1, preg_match($pattern, $name), $name);
        }
        foreach (['index.php', 'wp-login.php', 'wp-settings.php', 'style.css', 'my.env.php', 'old-wp-config.txt'] as $name) {
            $this->assertSame(0, preg_match($pattern, $name), $name);
        }
    }

    /** Spec 5.4, V6 */
    public function testHtaccessLocksEverythingButTheLoginLink(): void
    {
        $rules = StagingConfig::htaccess(self::PATH, 'https://example.com/wp-content/uploads');
        $this->assertStringContainsString('RewriteBase ' . self::PATH . '/', $rules);
        $this->assertStringContainsString('Header always set X-Robots-Tag "noindex, nofollow"', $rules);
        $this->assertStringContainsString('<FilesMatch "^(wp-config|\.env)|^(wpsync-staging\.json|\.htaccess)$|\.(log|sql)$">', $rules);
        $this->assertStringContainsString('RewriteCond %{HTTP_COOKIE} !(^|;\s*)wpsync_stg=', $rules);
        $this->assertStringContainsString('RewriteRule ^(index\.php)?$ - [S=1]', $rules);
        $this->assertStringContainsString('RewriteRule ^ - [F]', $rules);
        $this->assertStringContainsString('RewriteRule ^wp-content/uploads/(.*)$ https://example.com/wp-content/uploads/$1 [R=302,L]', $rules);
        $this->assertStringContainsString('RewriteRule . ' . self::PATH . '/index.php [L]', $rules);
        $this->assertStringNotContainsString(StagingConfig::PROBE_REWRITE, $rules);
        $this->assertStringContainsString('RewriteRule ^wpsync-probe-rewrite$ wpsync-probe-target.txt [L]', StagingConfig::htaccess(self::PATH, 'https://example.com/wp-content/uploads', 'token'));
    }

    public function testHtaccessRejectsUnsafeValues(): void
    {
        foreach ([[self::PATH, 'https://example.com/up loads'], [self::PATH, 'javascript:alert(1)'], ['/wpsync-staging-x', 'https://example.com'], [self::PATH . "\n", 'https://example.com']] as $args) {
            try {
                StagingConfig::htaccess($args[0], $args[1]);
                $this->fail('accepted ' . json_encode($args));
            } catch (\InvalidArgumentException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** V7, AC-102 */
    public function testLockedHtaccessDeniesEverything(): void
    {
        $rules = StagingConfig::locked();
        $this->assertStringContainsString('Require all denied', $rules);
        $this->assertStringNotContainsString('RewriteEngine', $rules);
    }

    /** Anhang A */
    public function testNginxRuleNamesTheFolder(): void
    {
        $rule = StagingConfig::nginxRule(self::PATH);
        $this->assertStringContainsString('location ^~ ' . self::PATH . '/ {', $rule);
        $this->assertStringContainsString('if ($http_cookie !~ "wpsync_stg=")', $rule);
        $this->assertStringContainsString('try_files $uri $uri/ ' . self::PATH . '/index.php?$args;', $rule);
    }
}
