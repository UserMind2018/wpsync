<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\TestCase;
use WpSync\StagingAccess;
use WpSync\StagingHosts;

/** Der Riegel braucht WordPress – hier nur, was ohne WordPress prüfbar ist; das Verhalten prüft e2e-staging.sh. */
final class StagingRiegelTest extends TestCase
{
    private const NOW = 1700000000;

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/wpsync-riegel-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/mu-plugins/wpsync-staging', 0777, true);
        mkdir($this->root . '/content');
        copy(__DIR__ . '/../staging/00-wpsync-staging.php', $this->root . '/mu-plugins/00-wpsync-staging.php');
        // Wie WordPress ein mu-plugin lädt, nur ohne WordPress: die Hooks laufen ins Leere.
        file_put_contents($this->root . '/run.php', <<<'PHP'
<?php
define('ABSPATH', __DIR__ . '/');
define('WPSYNC_STAGING', true);
define('WP_CONTENT_DIR', __DIR__ . '/content');
function add_filter(...$args): void {}
function add_action(...$args): void {}
require __DIR__ . '/mu-plugins/00-wpsync-staging.php';
$access = new \WpSync\StagingAccess($argv[1]);
if ($argv[1] === 'headers') {
    echo "\n" . json_encode(wpsync_staging_headers());
    exit;
}
$login = $argv[2] === '-' ? null : $argv[2];
if (isset($argv[5])) {
    $login = wpsync_staging_login_token(['wpsync_login' => $argv[2]], $argv[5], '/wpsync-staging-0123456789ab');
}
echo "\n" . json_encode(wpsync_staging_gate($access, $login, $argv[3], (int) $argv[4]));
PHP);
    }

    protected function tearDown(): void
    {
        @chmod($this->root . '/content/wpsync-staging.json', 0644);
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function source(): string
    {
        return (string) file_get_contents(__DIR__ . '/../staging/00-wpsync-staging.php');
    }

    private function withClasses(): void
    {
        foreach (['StagingAccess.php', 'StagingHosts.php'] as $file) {
            copy(__DIR__ . '/../src/' . $file, $this->root . '/mu-plugins/wpsync-staging/' . $file);
        }
    }

    /** @return list<string> */
    private function php(string $file, string $login, string $cookie, int $now, ?string $uri = null): array
    {
        $cmd = escapeshellarg(PHP_BINARY) . ' -d error_log=' . escapeshellarg($this->root . '/php.log') . ' ' . escapeshellarg($this->root . '/run.php');
        foreach (array_merge([$file, $login, $cookie, (string) $now], $uri === null ? [] : [$uri]) as $arg) {
            $cmd .= ' ' . escapeshellarg($arg);
        }
        exec($cmd . ' 2>&1', $out);
        return $out;
    }

    /** @return array{0: string, 1: string} */
    private function gate(string $file, string $login, string $cookie, int $now, ?string $uri = null): array
    {
        $out    = $this->php($file, $login, $cookie, $now, $uri);
        $result = json_decode((string) end($out), true);
        $this->assertIsArray($result, implode("\n", $out));
        return $result;
    }

    public function testIsValidPhp(): void
    {
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg(__DIR__ . '/../staging/00-wpsync-staging.php') . ' 2>&1', $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
    }

    /** NFA: PHP 7.4 */
    public function testUsesNoPhp8Syntax(): void
    {
        foreach (['str_ends_with(', 'str_starts_with(', 'str_contains(', ' match (', '?->', 'readonly '] as $php8) {
            $this->assertStringNotContainsString($php8, $this->source(), $php8);
        }
    }

    /** Spec 5.5 */
    public function testHasEveryBolt(): void
    {
        $src = $this->source();
        foreach ([
            "defined('WPSYNC_STAGING')",
            '/wpsync-staging/StagingAccess.php',
            '/wpsync-staging/StagingHosts.php',
            "add_filter('wp_mail'",
            "add_action('phpmailer_init'",
            "add_filter('pre_http_request'",
            "add_action('requests-requests.before_redirect'",
            'StagingHosts::SINK',
            'StagingHosts::blocked(',
            "add_filter('wp_robots'",
            "add_filter('pre_option_blog_public'",
            "add_filter('action_scheduler_allow_async_request_runner'",
            "add_filter('action_scheduler_queue_runner_time_limit'",
            "add_action('admin_bar_menu'",
            'redeemToken(',
            'cookieValid(',
            "'samesite' => 'Lax'",
        ] as $needle) {
            $this->assertStringContainsString($needle, $src, $needle);
        }
        $this->assertSame(3, substr_count($src, 'PHP_INT_MAX'), 'mail filters run after every other plugin');
        $this->assertSame('wpsync_stg', StagingAccess::COOKIE);
        $this->assertSame('blocked@mailguard.invalid', StagingHosts::SINK);
    }

    /** Leitplanke 7: jeder Grund sperrt (auch invalid), und ein Redirect wird wie die erste Anfrage geprüft. */
    public function testOutgoingRequestsAreCheckedOnEveryHop(): void
    {
        $src = $this->source();
        $this->assertSame(3, substr_count($src, 'wpsync_staging_blocked_reason('), 'one check, used by both hooks');
        $this->assertSame(1, substr_count($src, 'StagingHosts::blocked('));
        $this->assertStringNotContainsString("=== 'live'", $src, 'no list of reasons: anything but null blocks');
        $this->assertStringNotContainsString("=== 'mail'", $src);
    }

    public function testGateLetsTokenAndCookieThrough(): void
    {
        $this->withClasses();
        $file   = $this->root . '/content/wpsync-staging.json';
        $access = new StagingAccess($file);
        $access->init('https://kunde.example', 'https://kunde.example/wp-content/uploads', '/wpsync-staging-0123456789ab', self::NOW, self::NOW);
        $token = $access->issueToken(self::NOW);

        $login = $this->gate($file, $token, '', self::NOW);
        $this->assertSame('login', $login[0]);
        $this->assertSame(['pass', ''], $this->gate($file, '-', $login[1], self::NOW + 60));
        $this->assertSame('deny', $this->gate($file, $token, '', self::NOW + 61)[0], 'a link works once');
        $this->assertSame('deny', $this->gate($file, '-', str_repeat('a', 64), self::NOW)[0]);
        $this->assertSame('deny', $this->gate($file, '-', '', self::NOW)[0]);
    }

    /** Fail-closed: StagingAccess wirft, wenn die Zustandsdatei nicht zu schreiben ist – das ist ein 403, kein Fatal. */
    public function testGateDeniesWhenTheStateFileFails(): void
    {
        $this->withClasses();
        $missing = $this->gate($this->root . '/fehlt/wpsync-staging.json', str_repeat('a', 64), '', self::NOW);
        $this->assertSame('deny', $missing[0]);

        $file   = $this->root . '/content/wpsync-staging.json';
        $access = new StagingAccess($file);
        $access->init('https://kunde.example', '', '/wpsync-staging-0123456789ab', self::NOW, self::NOW);
        $cookie = (string) $access->redeemToken($access->issueToken(self::NOW), self::NOW);
        chmod($file, 0444);
        if (is_writable($file)) {
            $this->markTestSkipped('runs as root: a read-only file stays writable');
        }
        // Gültiges Cookie, aber die letzte Nutzung lässt sich nicht festhalten.
        $readonly = $this->gate($file, '-', $cookie, self::NOW + 2 * StagingAccess::TOUCH_EVERY);
        $this->assertSame('deny', $readonly[0]);
        $this->assertStringNotContainsString($this->root, $readonly[1], 'no server path in the answer');
    }

    /** T1: ein Link gilt nur am Einstieg der Kopie – woanders zählt er nicht und bleibt gültig. */
    public function testTokenIsRedeemedOnlyAtTheEntryPoint(): void
    {
        $this->withClasses();
        $file   = $this->root . '/content/wpsync-staging.json';
        $access = new StagingAccess($file);
        $access->init('https://kunde.example', '', '/wpsync-staging-0123456789ab', self::NOW, self::NOW);
        $token = $access->issueToken(self::NOW);
        $base  = '/wpsync-staging-0123456789ab';

        foreach ([
            $base . '/wp-login.php',
            $base . '/wp-admin/',
            $base . '/wp-json/wp/v2/users',
            $base . '/index.php/wp-json/',
            $base . '//',
            $base . 'x/',
            '/',
            '/index.php',
            '',
        ] as $path) {
            $this->assertSame(['deny', 'Kein gültiger Zugang.'], $this->gate($file, $token, '', self::NOW, $path . '?wpsync_login=' . $token), $path);
        }
        foreach ([$base, $base . '/', $base . '/index.php'] as $path) {
            $token = $access->issueToken(self::NOW);
            $this->assertSame('login', $this->gate($file, $token, '', self::NOW, $path . '?wpsync_login=' . $token)[0], $path);
        }
    }

    /** Das Token steht in der Adresse: keine Antwort des Riegels darf sie als Referrer weitergeben oder im Cache landen. */
    public function testDenialAndLoginSendNoReferrerAndNoStore(): void
    {
        $this->withClasses();
        $out     = $this->php('headers', '-', '', self::NOW);
        $headers = json_decode((string) end($out), true);
        $this->assertIsArray($headers, implode("\n", $out));
        $this->assertContains('Referrer-Policy: no-referrer', $headers);
        $this->assertContains('Cache-Control: no-store, private', $headers);

        $src = $this->source();
        $this->assertSame(3, substr_count($src, 'wpsync_staging_headers()'), 'defined once, sent by the denial and by the login');
        $login = substr($src, (int) strpos($src, 'function wpsync_staging_login('));
        $this->assertLessThan(strpos($login, 'wp_safe_redirect('), strpos($login, 'wpsync_staging_headers()'), 'before the redirect');
        $this->assertStringNotContainsString("\$_GET['wpsync_login']", $src, 'the token is read in one place only');
    }

    public function testDeniesWithoutItsClasses(): void
    {
        $out = implode("\n", $this->php($this->root . '/content/wpsync-staging.json', '-', '', self::NOW));
        $this->assertStringContainsString('Diese Staging-Kopie ist gesperrt', $out);
        $this->assertStringNotContainsString('Fatal error', $out);
        $this->assertStringNotContainsString('["', $out, 'nothing runs after the denial');
    }
}
