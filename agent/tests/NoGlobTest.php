<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\TestCase;
use WpSync\PushRescue;
use WpSync\PushSwap;
use WpSync\PushUnits;
use WpSync\Staging;
use WpSync\StagingGuard;

/**
 * Kein glob() im Agent (Security-Review P3, H1): für glob() sind [ ] * ? auch im Pfad davor
 * Muster – unter einem Webroot wie /kunden/[alt]/htdocs fände es weder Arbeitsordner noch Marker
 * noch Plugin-Dateien. Aufgelistet wird mit scandir() und einem Muster nur für den Namen.
 */
final class NoGlobTest extends TestCase
{
    private const ID = 'p_20261009_0123456789ab';

    private string $root;

    protected function setUp(): void
    {
        $this->root = (string) realpath(sys_get_temp_dir()) . '/wpsync-[glob]-' . bin2hex(random_bytes(4)) . '/kunde [1]*?';
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg(dirname($this->root)));
    }

    public function testNoSourceFileCallsGlob(): void
    {
        $files = array_merge(PushSwap::entries(dirname(__DIR__) . '/src', '/\.php\z/'), [dirname(__DIR__) . '/rescue.php', dirname(__DIR__) . '/wpsync-agent.php']);
        $this->assertGreaterThan(60, count($files));
        foreach ($files as $file) {
            $code = '';
            foreach (token_get_all((string) file_get_contents($file)) as $token) {
                $code .= is_array($token) ? (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $token[1]) : $token;
            }
            $this->assertSame(0, preg_match('/(?<![A-Za-z0-9_>:$])glob\s*\(/i', $code), basename($file) . ' ruft glob()');
        }
    }

    public function testEntriesListsByNameBelowAPathFullOfPatternCharacters(): void
    {
        foreach (['b.php', 'a.php', '.hidden.php', 'c.txt', 'sub.php/x'] as $rel) {
            @mkdir(dirname($this->root . '/' . $rel), 0777, true);
            file_put_contents($this->root . '/' . $rel, 'x');
        }
        $this->assertSame([$this->root . '/a.php', $this->root . '/b.php', $this->root . '/sub.php'], PushSwap::entries($this->root, '/^[^.].*\.php\z/'));
        $this->assertSame([], PushSwap::entries($this->root . '/fehlt', '/./'));
        $this->assertSame([], PushSwap::entries($this->root, '/^\.\.?\z/'), 'nie . und ..');
    }

    public function testTheVersionOfAPluginIsFoundBelowSuchAPath(): void
    {
        mkdir($this->root . '/plugins/x', 0777, true);
        file_put_contents($this->root . '/plugins/x/.main.php', "<?php\n/* Plugin Name: Versteckt\n Version: 0.0.1 */\n");
        file_put_contents($this->root . '/plugins/x/main.php', "<?php\n/**\n * Plugin Name: X\n * Version: 1.4.0\n */\n");
        $this->assertSame('1.4.0', PushUnits::version($this->root . '/plugins/x', 'plugins/x'));
    }

    public function testRescueFindsThePushBelowSuchAPath(): void
    {
        $content = $this->root . '/wp-content';
        $work    = $content . '/wpsync-push-0123456789abcdef';
        $key     = PushRescue::key(str_repeat('ab', 32), self::ID, 'salt');
        mkdir($content . '/plugins/x', 0777, true);
        mkdir($work . '/' . self::ID . '/old/0', 0777, true);
        file_put_contents($content . '/plugins/x/main.php', 'new');
        file_put_contents($work . '/' . self::ID . '/old/0/main.php', 'old');
        file_put_contents($content . '/wpsync-push-datei', 'kein Ordner');
        symlink($work, $content . '/wpsync-push-link');
        PushRescue::write($work, self::ID, hash('sha256', $key), [[
            'unit' => 'plugins/x', 'target' => $content . '/plugins/x', 'snapshot' => $work . '/' . self::ID . '/old/0', 'discard' => $work . '/' . self::ID . '/discard/0',
        ]], PushRescue::COMMITTED);

        $this->assertSame([$work], PushRescue::workDirs($content), 'nur echte Ordner, keine Symlinks');
        list($status, $body) = PushRescue::handle([$content], ['action' => 'rollback', 'push_id' => self::ID, 'key' => $key], 1000);
        $this->assertSame([200, 'rolled_back'], [$status, $body['status'] ?? null]);
        $this->assertSame('old', file_get_contents($content . '/plugins/x/main.php'));
        $this->assertFileExists(PushRescue::pendingFile($work));
    }

    /** Staging::write() räumt den Rest eines abgebrochenen Schreibens weg – auch unter so einem Pfad. */
    public function testStagingWriteRemovesAStaleTemporaryFileBelowSuchAPath(): void
    {
        $dir = 'wpsync-staging-0123456789ab';
        mkdir($this->root . '/' . $dir);
        $file = $this->root . '/' . $dir . '/.htaccess';
        file_put_contents($file . '.0123456789ab.tmp', 'halb');
        file_put_contents($file . '.bak', 'bleibt');
        $write = new \ReflectionMethod(Staging::class, 'write');
        if (PHP_VERSION_ID < 80100) {
            $write->setAccessible(true);
        }
        $write->invoke(null, new StagingGuard('wp_', 'stgabcdef_', $this->root, $dir), $file, "deny\n");
        $this->assertSame("deny\n", file_get_contents($file));
        $this->assertFileDoesNotExist($file . '.0123456789ab.tmp');
        $this->assertFileExists($file . '.bak');
    }
}
