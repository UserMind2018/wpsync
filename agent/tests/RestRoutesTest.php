<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use WpSync\Rest;
use WpSync\Signature;

/**
 * Die Routen des Agents (Spec 2b 5.10, T1): alles ausser /pair hängt an derselben
 * Signaturprüfung, und in einer Staging-Kopie gibt es keine einzige Route.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class RestRoutesTest extends TestCase
{
    private const STAGING = ['staging/begin', 'staging/step', 'staging/status', 'staging/login'];

    private function boot(): void
    {
        require_once __DIR__ . '/AgentHarness.php';
        require_once __DIR__ . '/FakeWpdb.php';
        define('WPSYNC_VERSION', 'test');
        $GLOBALS['wpsync_options']['wpsync_schema'] = 'test'; // Store::install() hat nichts zu tun
        $GLOBALS['wpdb']                            = new FakeWpdb();
    }

    /** @return array<string, array<string, mixed>> */
    private function routes(): array
    {
        Rest::routes();
        return $GLOBALS['wpsync_calls']['routes'];
    }

    public function testStagingRoutesAreRegistered(): void
    {
        $this->boot();
        $routes = $this->routes();
        foreach (self::STAGING as $route) {
            $this->assertArrayHasKey('wpsync/v1/' . $route, $routes);
            $this->assertSame('POST', $routes['wpsync/v1/' . $route]['methods']);
            $this->assertTrue(is_callable($routes['wpsync/v1/' . $route]['callback']), $route);
        }
    }

    /** Kein Zweig am permission_callback vorbei: genau der der Push-Routen, für jede Route ausser /pair. */
    public function testEveryRouteButPairRunsTheSignatureCheck(): void
    {
        $this->boot();
        $routes = $this->routes();
        $signed = $routes['wpsync/v1/push/begin']['permission_callback'];
        $this->assertSame([Rest::class, 'auth'], $signed);
        foreach ($routes as $route => $args) {
            if ($route === 'wpsync/v1/pair') {
                continue;
            }
            $this->assertSame($signed, $args['permission_callback'], $route);
        }
        $this->assertGreaterThanOrEqual(20, count($routes));
    }

    /** Spec Content-Push §7.5: die Ablage eines Pakets hängt an derselben Signaturprüfung wie der Push. */
    public function testContentStageIsASignedRoute(): void
    {
        $this->boot();
        $routes = $this->routes();
        $this->assertArrayHasKey('wpsync/v1/content/stage', $routes);
        $this->assertSame('POST', $routes['wpsync/v1/content/stage']['methods']);
        $this->assertSame([Rest::class, 'contentStage'], $routes['wpsync/v1/content/stage']['callback']);
        $this->assertSame($routes['wpsync/v1/push/begin']['permission_callback'], $routes['wpsync/v1/content/stage']['permission_callback']);

        $result = Rest::auth(new \WP_REST_Request('/wpsync/v1/content/stage'));
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame(401, $result->status);
    }

    /** Spec Content-Push §4.2: das Manifest hängt an derselben Signaturprüfung wie /db. */
    public function testContentManifestIsASignedRoute(): void
    {
        $this->boot();
        $routes = $this->routes();
        $this->assertArrayHasKey('wpsync/v1/content/manifest', $routes);
        $this->assertSame('POST', $routes['wpsync/v1/content/manifest']['methods']);
        $this->assertSame([Rest::class, 'contentManifest'], $routes['wpsync/v1/content/manifest']['callback']);
        $this->assertSame($routes['wpsync/v1/db']['permission_callback'], $routes['wpsync/v1/content/manifest']['permission_callback']);

        $result = Rest::auth(new \WP_REST_Request('/wpsync/v1/content/manifest'));
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame(401, $result->status);
    }

    /** Ungültige Eingaben enden mit 400, bevor der Stream beginnt und bevor eine Abfrage läuft. */
    public function testContentManifestRejectsAnInvalidCursorOrScope(): void
    {
        $this->boot();
        foreach (['{"cursor":"x"}', '{"cursor":{"t":7,"a":""}}', '{"cursor":{"t":2,"a":["1"]}}', '{"cursor":{"t":2,"a":"1 OR 1"}}'] as $body) {
            $result = Rest::contentManifest(new \WP_REST_Request('/wpsync/v1/content/manifest', [], $body));
            $this->assertInstanceOf(\WP_Error::class, $result, $body);
            $this->assertSame('wpsync_cursor', $result->code, $body);
            $this->assertSame(400, $result->status, $body);
        }
        $result = Rest::contentManifest(new \WP_REST_Request('/wpsync/v1/content/manifest', [], '{"scope":{"tables":{"wp_posts":"all"}}}'));
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('wpsync_scope', $result->code);
        $this->assertSame(400, $result->status);
        $this->assertSame([], $GLOBALS['wpdb']->queries);
    }

    public function testStagingRoutesRejectAnUnsignedCall(): void
    {
        $this->boot();
        foreach (self::STAGING as $route) {
            $result = Rest::auth(new \WP_REST_Request('/wpsync/v1/' . $route));
            $this->assertInstanceOf(\WP_Error::class, $result, $route);
            $this->assertSame(401, $result->status, $route);
        }
    }

    public function testStagingRoutesRejectAWrongSignature(): void
    {
        $this->boot();
        $GLOBALS['wpdb']->answer('/SELECT secret FROM/', str_repeat('a', 64));
        $result = Rest::auth(new \WP_REST_Request('/wpsync/v1/staging/login', [
            'x-wpsync-key'       => str_repeat('1', 16),
            'x-wpsync-nonce'     => str_repeat('2', 32),
            'x-wpsync-timestamp' => (string) time(),
            'x-wpsync-signature' => str_repeat('0', 64),
        ]));
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('wpsync_auth', $result->code);
        $this->assertSame(401, $result->status);
        foreach ($GLOBALS['wpdb']->queries as $sql) {
            $this->assertStringNotContainsString('nonces', $sql); // erst die Signatur, dann die Nonce
        }
    }

    /** Replay: eine gültig signierte Anfrage gilt genau einmal – auch für /staging/login. */
    public function testAReplayedStagingCallIsRejected(): void
    {
        $this->boot();
        $secret = str_repeat('a', 64);
        $nonce  = str_repeat('2', 32);
        $time   = (string) time();
        $route  = '/wpsync/v1/staging/login';
        $GLOBALS['wpdb']->answer('/SELECT secret FROM/', $secret);
        $GLOBALS['wpdb']->answer('/INSERT IGNORE INTO `wp_wpsync_nonces`/', 1, 0);

        $headers = [
            'x-wpsync-key'       => str_repeat('1', 16),
            'x-wpsync-nonce'     => $nonce,
            'x-wpsync-timestamp' => $time,
            'x-wpsync-signature' => Signature::sign($secret, Signature::payload('POST', $route, (int) $time, $nonce, '')),
        ];
        $this->assertTrue(Rest::auth(new \WP_REST_Request($route, $headers)));

        $replay = Rest::auth(new \WP_REST_Request($route, $headers));
        $this->assertInstanceOf(\WP_Error::class, $replay);
        $this->assertSame('wpsync_auth', $replay->code);
        $this->assertSame('nonce reused', $replay->message);
        $this->assertSame(401, $replay->status);
    }

    /** Spec 2b 5.10: in der Kopie keine Route – auch wenn der Agent dort doch geladen wird. */
    public function testNoRoutesInsideAStagingCopy(): void
    {
        $this->boot();
        define('WPSYNC_STAGING', true);
        $this->assertSame([], $this->routes());
    }

    public function testEveryCallIsRefusedInsideAStagingCopy(): void
    {
        $this->boot();
        define('WPSYNC_STAGING', true);

        $auth = Rest::auth(new \WP_REST_Request('/wpsync/v1/ping'));
        $this->assertInstanceOf(\WP_Error::class, $auth);
        $this->assertSame('wpsync_staging_copy', $auth->code);
        $this->assertSame(403, $auth->status);

        $pair = Rest::pair(new \WP_REST_Request('/wpsync/v1/pair', [], '{"code":"x"}'));
        $this->assertInstanceOf(\WP_Error::class, $pair);
        $this->assertSame('wpsync_staging_copy', $pair->code);

        $this->assertSame([], $GLOBALS['wpdb']->queries);
    }

    /** Security-Review M5: flush() allein leert den eigenen Ausgabepuffer (gzip) nicht – die Seite wüchse darin bis zum Ende */
    public function testFlushOutputEmptiesTheOwnOutputBuffer(): void
    {
        $this->boot();
        $chunks = [];
        ob_start(static function (string $buffer) use (&$chunks): string {
            $chunks[] = $buffer;
            return '';
        });
        echo str_repeat('x', 1000);
        Rest::flushOutput();
        $left = ob_get_length();
        ob_end_clean();
        $this->assertSame(0, $left);
        $this->assertSame(1000, strlen($chunks[0]));
    }

    public function testFlushOutputLeavesABufferAloneThatMustNotBeFlushed(): void
    {
        $this->boot();
        ob_start(null, 0, PHP_OUTPUT_HANDLER_CLEANABLE | PHP_OUTPUT_HANDLER_REMOVABLE);
        echo 'abc';
        Rest::flushOutput();
        $this->assertSame('abc', ob_get_clean());
    }
}
