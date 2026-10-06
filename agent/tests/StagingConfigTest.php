<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\TestCase;
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
