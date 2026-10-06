<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\TestCase;
use WpSync\FileWalker;
use WpSync\Scope;
use WpSync\StagingException;
use WpSync\StagingFiles;
use WpSync\StagingGuard;

final class StagingFilesTest extends TestCase
{
    private string $abs;
    private string $to;
    private StagingGuard $guard;

    protected function setUp(): void
    {
        // realpath: StagingGuard vergleicht aufgelöste Pfade (macOS: /var -> /private/var)
        $base      = realpath(sys_get_temp_dir()) . '/wpsync-files-' . bin2hex(random_bytes(4));
        $this->abs = $base . '/live';
        $this->to  = $base . '/live/wpsync-staging-0123456789ab';
        foreach ([
            'index.php', 'wp-config.php', 'wp-login.php', 'wp-settings.php', 'xmlrpc.php', 'readme.html',
            'wp-admin/index.php', 'wp-admin/setup-config.php', 'wp-includes/version.php',
            'wp-content/index.php', 'wp-content/advanced-cache.php', 'wp-content/object-cache.php',
            'wp-content/plugins/index.php', 'wp-content/plugins/hello.php', 'wp-content/plugins/x/x.php', 'wp-content/plugins/x/inc/a.php',
            'wp-content/plugins/x/.git/config', 'wp-content/plugins/x/debug.log', 'wp-content/plugins/x/.env',
            'wp-content/plugins/wpsync-agent/wpsync-agent.php', 'wp-content/plugins/excluded/e.php',
            'wp-content/themes/t/style.css', 'wp-content/mu-plugins/m.php', 'wp-content/mu-plugins/00-local-mailguard.php',
            'wp-content/uploads/2020/01/a.jpg', 'wp-content/languages/de_DE.mo',
        ] as $i => $rel) {
            $this->put($rel, 'content ' . $rel, 1700000000 + $i);
        }
        symlink($this->abs . '/wp-content/plugins/x', $this->abs . '/wp-content/plugins/linked');
        mkdir($this->to);
        $this->guard = new StagingGuard('wp_', 'stgabcdef_', $this->abs, basename($this->to));
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg(dirname($this->abs)));
    }

    private function put(string $rel, string $content, int $mtime): void
    {
        $path = $this->abs . '/' . $rel;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $content);
        touch($path, $mtime);
    }

    /** @return list<string> */
    private function items(): array
    {
        return array_merge(
            StagingFiles::coreItems($this->abs),
            StagingFiles::contentItems($this->abs . '/wp-content', Scope::fromArray(['exclude_plugins' => ['excluded']]))
        );
    }

    /** @return list<string> Dateien der Kopie, relativ, sortiert */
    private function copied(): array
    {
        $out = [];
        $it  = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->to, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $out[] = substr($file->getPathname(), strlen($this->to) + 1);
        }
        sort($out, SORT_STRING);
        return $out;
    }

    private function copyAll(): void
    {
        $result = StagingFiles::copy($this->abs, $this->to, $this->items(), [0, ''], microtime(true) + 30, [$this->guard, 'path']);
        $this->assertNull($result['next']);
    }

    /** Spec 5.2 Phase 3 */
    public function testListsCoreWithoutConfigAndUnitsOfTheProfile(): void
    {
        $this->assertSame(['index.php', 'wp-admin', 'wp-includes', 'wp-login.php', 'wp-settings.php', 'xmlrpc.php'], StagingFiles::coreItems($this->abs));
        $this->assertSame([
            'wp-content/index.php',
            'wp-content/plugins/hello.php',
            'wp-content/plugins/index.php',
            'wp-content/plugins/x',
            'wp-content/themes/t',
            'wp-content/mu-plugins/m.php',
            'wp-content/languages',
        ], StagingFiles::contentItems($this->abs . '/wp-content', Scope::fromArray(['exclude_plugins' => ['excluded']])));
    }

    /** Spec 5.2 Phase 3, AC-96, V7 */
    public function testCopiesCodeButNoConfigDropInsUploadsOrSecrets(): void
    {
        $result = StagingFiles::copy($this->abs, $this->to, $this->items(), [0, ''], microtime(true) + 30, [$this->guard, 'path']);
        $this->assertNull($result['next']);
        foreach (['index.php', 'wp-admin/index.php', 'wp-includes/version.php', 'wp-content/plugins/x/inc/a.php', 'wp-content/plugins/hello.php', 'wp-content/mu-plugins/m.php', 'wp-content/languages/de_DE.mo'] as $rel) {
            $this->assertFileExists($this->to . '/' . $rel);
            $this->assertSame(filemtime($this->abs . '/' . $rel), filemtime($this->to . '/' . $rel), $rel);
        }
        foreach ([
            'wp-config.php', 'readme.html', 'wp-admin/setup-config.php', 'wp-content/advanced-cache.php', 'wp-content/object-cache.php',
            'wp-content/uploads', 'wp-content/plugins/wpsync-agent', 'wp-content/plugins/excluded', 'wp-content/plugins/linked',
            'wp-content/plugins/x/.git', 'wp-content/plugins/x/debug.log', 'wp-content/plugins/x/.env', 'wp-content/mu-plugins/00-local-mailguard.php',
            'wpsync-staging-0123456789ab',
        ] as $rel) {
            $this->assertFileDoesNotExist($this->to . '/' . $rel, $rel);
        }
    }

    /** Leitplanke 8: mindestens eine Datei pro Schritt, Fortsetzung am Cursor */
    public function testResumesAtTheCursor(): void
    {
        $cursor = [0, ''];
        $files  = 0;
        $steps  = 0;
        do {
            $result = StagingFiles::copy($this->abs, $this->to, $this->items(), $cursor, microtime(true) - 1, [$this->guard, 'path']);
            $files += $result['files'];
            $cursor = $result['next'] ?? $cursor;
            $steps++;
        } while ($result['next'] !== null && $steps < 100);
        $this->assertSame(14, $files);
        $this->assertSame(14, $steps);
    }

    public function testEveryTargetGoesThroughTheCheck(): void
    {
        $check = static function (string $path): string {
            if (strpos($path, '/themes/') !== false) {
                throw StagingException::guard('no themes');
            }
            return $path;
        };
        $this->expectException(StagingException::class);
        StagingFiles::copy($this->abs, $this->to, $this->items(), [0, ''], microtime(true) + 30, $check);
    }

    /** Spec 5.7 */
    public function testSizeOrNullWhenOutOfTime(): void
    {
        $bytes = StagingFiles::size($this->abs, ['wp-content/plugins/x', 'index.php'], microtime(true) + 30);
        $this->assertSame(strlen('content wp-content/plugins/x/x.php') + strlen('content wp-content/plugins/x/inc/a.php') + strlen('content index.php'), $bytes);
        $this->assertNull(StagingFiles::size($this->abs, ['wp-content/plugins/x'], microtime(true) - 1));
    }

    public function testRemovesInSteps(): void
    {
        StagingFiles::copy($this->abs, $this->to, $this->items(), [0, ''], microtime(true) + 30, [$this->guard, 'path']);
        symlink('/etc', $this->to . '/wp-content/link');
        $steps = 0;
        while (!StagingFiles::remove($this->to, microtime(true) - 1, [$this->guard, 'path']) && $steps < 200) {
            $steps++;
        }
        $this->assertDirectoryDoesNotExist($this->to);
        $this->assertDirectoryExists('/etc');
        $this->assertGreaterThan(1, $steps);
    }

    /** Ein Symlink in der Kopie wird entfernt, nie verfolgt – auch mit dem echten Guard (V12). */
    public function testRemovesSymlinksWithoutTouchingTheirTargets(): void
    {
        $this->copyAll();
        $outside = dirname($this->abs) . '/outside';
        mkdir($outside);
        file_put_contents($outside . '/keep.txt', 'live');
        symlink($outside, $this->to . '/wp-content/plugins/x/dirlink');
        symlink($outside . '/keep.txt', $this->to . '/wp-content/plugins/x/filelink');
        symlink($this->abs . '/wp-content/uploads', $this->to . '/wp-content/uploads');

        $this->assertTrue(StagingFiles::remove($this->to, INF, [$this->guard, 'path']));
        $this->assertDirectoryDoesNotExist($this->to);
        $this->assertSame('live', file_get_contents($outside . '/keep.txt'));
        $this->assertFileExists($this->abs . '/wp-content/uploads/2020/01/a.jpg');
    }

    public function testRefusesToRemoveThroughASymlinkedRoot(): void
    {
        rmdir($this->to);
        symlink($this->abs . '/wp-content', $this->to);
        try {
            StagingFiles::remove($this->to, INF, [$this->guard, 'path']);
            $this->fail('no exception');
        } catch (StagingException $e) {
            $this->assertSame(StagingException::GUARD, $e->reason());
        }
        $this->assertFileExists($this->abs . '/wp-content/index.php');
    }

    /** Dateien direkt unter plugins/, themes/, mu-plugins/ laufen durch dieselben Ausschlüsse. */
    public function testTopLevelFilesPassTheExcludes(): void
    {
        foreach (['plugins/debug.log', 'plugins/.env', 'themes/.env.local', 'mu-plugins/.env', 'mu-plugins/error.log', 'mu-plugins/.htpasswd', 'plugins/install.sql'] as $rel) {
            $this->put('wp-content/' . $rel, 'x', 1700000000);
        }
        $this->copyAll();
        foreach (['plugins/debug.log', 'plugins/.env', 'themes/.env.local', 'mu-plugins/.env', 'mu-plugins/error.log', 'mu-plugins/.htpasswd'] as $rel) {
            $this->assertFileDoesNotExist($this->to . '/wp-content/' . $rel, $rel);
        }
        // wie beim Pull: SQL unter plugins/ ist Code
        $this->assertFileExists($this->to . '/wp-content/plugins/install.sql');
    }

    /** Varianten der wp-config.php tragen die Zugangsdaten von Live. */
    public function testNeverCopiesConfigVariants(): void
    {
        $variants = [
            'wp-config-old.php', 'wp-config-sample.php', 'wp-config-backup.php',
            'wp-admin/wp-config.php', 'wp-content/plugins/x/WP-Config.php', 'wp-content/plugins/x/inc/wp-config.php.bak',
            'wp-content/mu-plugins/wp-config-old.php',
        ];
        foreach ($variants as $rel) {
            $this->put($rel, 'x', 1700000000);
        }
        $this->assertSame(['index.php', 'wp-admin', 'wp-includes', 'wp-login.php', 'wp-settings.php', 'xmlrpc.php'], StagingFiles::coreItems($this->abs));
        $this->copyAll();
        foreach ($variants as $rel) {
            $this->assertFileDoesNotExist($this->to . '/' . $rel, $rel);
        }
    }

    /** Eine .htaccess mit RewriteEngine im Unterordner hebt die Cookie-Sperre der Kopie auf. */
    public function testSkipsRewriteOverridesAndUserIni(): void
    {
        $this->put('wp-admin/.htaccess', "RewriteEngine On\nRewriteRule ^ - [L]\n", 1700000000);
        $this->put('wp-content/plugins/x/.htaccess', "<IfModule mod_rewrite.c>\n  rewriteengine on\n</IfModule>\n", 1700000000);
        $this->put('wp-content/plugins/x/inc/.htaccess', "Deny from all\n", 1700000000);
        $this->put('wp-content/plugins/x/.user.ini', "auto_prepend_file=x\n", 1700000000);
        $this->put('wp-admin/.user.ini', "auto_prepend_file=x\n", 1700000000);
        $this->put('wp-content/mu-plugins/.htaccess', "RewriteEngine On\n", 1700000000);
        $this->copyAll();
        foreach (['wp-admin/.htaccess', 'wp-content/plugins/x/.htaccess', 'wp-content/plugins/x/.user.ini', 'wp-admin/.user.ini', 'wp-content/mu-plugins/.htaccess'] as $rel) {
            $this->assertFileDoesNotExist($this->to . '/' . $rel, $rel);
        }
        $this->assertFileExists($this->to . '/wp-content/plugins/x/inc/.htaccess');
    }

    /** Unter wp-content gilt, was der Pull für denselben Bereich liefert (Excludes::path). */
    public function testContentMatchesThePull(): void
    {
        foreach ([
            'languages/dump.sql', 'languages/plugins/old.sql.gz', 'languages/plugins/x-de_DE.mo',
            'plugins/x/schema.sql', 'plugins/x/backup.zip', 'plugins/x/backups/a.txt', 'plugins/x/cache/c.php',
            'plugins/dump.sql', 'plugins/backup.zip', 'themes/t/.svn/entries', 'themes/t/inc/error.LOG',
        ] as $rel) {
            $this->put('wp-content/' . $rel, 'data ' . $rel, 1700000000);
        }
        $scope   = Scope::fromArray(['exclude_plugins' => ['excluded']]);
        $content = StagingFiles::contentItems($this->abs . '/wp-content', $scope);
        $pull    = [];
        $walker  = new FileWalker($this->abs, $this->abs . '/wp-content', '', $scope);
        foreach ($walker->page(0, microtime(true) + 30)['files'] as $file) {
            foreach ($content as $item) {
                if ($file['path'] === $item || strpos($file['path'], $item . '/') === 0) {
                    $pull[] = $file['path'];
                }
            }
        }
        sort($pull, SORT_STRING);

        $result = StagingFiles::copy($this->abs, $this->to, $content, [0, ''], microtime(true) + 30, [$this->guard, 'path']);
        $this->assertNull($result['next']);
        $this->assertSame($pull, $this->copied());
        $this->assertNotContains('wp-content/languages/dump.sql', $pull);
        $this->assertContains('wp-content/plugins/x/schema.sql', $pull);

        $bytes = 0;
        foreach ($this->copied() as $rel) {
            $bytes += filesize($this->to . '/' . $rel);
        }
        $this->assertSame($bytes, StagingFiles::size($this->abs, $content, microtime(true) + 30));
    }
}
