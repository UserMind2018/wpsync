<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\TestCase;
use WpSync\Scope;
use WpSync\StagingException;
use WpSync\StagingFiles;

final class StagingFilesTest extends TestCase
{
    private string $abs;
    private string $to;

    protected function setUp(): void
    {
        $base      = sys_get_temp_dir() . '/wpsync-files-' . bin2hex(random_bytes(4));
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

    public static function same(string $path): string
    {
        return $path;
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
        $result = StagingFiles::copy($this->abs, $this->to, $this->items(), [0, ''], microtime(true) + 30, [self::class, 'same']);
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
            $result = StagingFiles::copy($this->abs, $this->to, $this->items(), $cursor, microtime(true) - 1, [self::class, 'same']);
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
        StagingFiles::copy($this->abs, $this->to, $this->items(), [0, ''], microtime(true) + 30, [self::class, 'same']);
        symlink('/etc', $this->to . '/wp-content/link');
        $steps = 0;
        while (!StagingFiles::remove($this->to, microtime(true) - 1, [self::class, 'same']) && $steps < 200) {
            $steps++;
        }
        $this->assertDirectoryDoesNotExist($this->to);
        $this->assertDirectoryExists('/etc');
        $this->assertGreaterThan(1, $steps);
    }
}
