<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\TestCase;
use WpSync\Excludes;
use WpSync\FileWalker;
use WpSync\Scope;

final class FileWalkerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/wpsync-walker-' . bin2hex(random_bytes(4));
        $files = [
            'wp-content/plugins/elementor/core/upgrade/manager.php' => 'a',
            'wp-content/plugins/x/.git/HEAD'                       => 'b',
            'wp-content/plugins/wpsync-agent/wpsync-agent.php'     => 'c',
            'wp-content/upgrade/tmp.zip'                           => 'd',
            'wp-content/duplicator-backups/site.daf'               => 'e',
            'wp-content/uploads/2026/a.jpg'                        => 'f',
            'wp-content/debug.log'                                 => 'g',
            'wp-content/themes/t/style.css'                        => 'h',
            'wp-config.php'                                        => 'i',
        ];
        foreach ($files as $path => $content) {
            $full = $this->root . '/' . $path;
            if (!is_dir(dirname($full))) {
                mkdir(dirname($full), 0777, true);
            }
            file_put_contents($full, $content);
        }
        symlink('/etc/hosts', $this->root . '/wp-content/link.php');
        $big = fopen($this->root . '/wp-content/uploads/2026/huge.mp4', 'w');
        ftruncate($big, Excludes::MAX_FILE_BYTES + 1); // sparse, kostet keinen Platz
        fclose($big);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function walker(): FileWalker
    {
        return new FileWalker($this->root, $this->root . '/wp-content', $this->root . '/wp-content/plugins/wpsync-agent');
    }

    public function testListsIncludedFilesOnly(): void
    {
        $page = $this->walker()->page(0, microtime(true) + 60);

        $this->assertSame([
            'wp-content/plugins/elementor/core/upgrade/manager.php',
            'wp-content/themes/t/style.css',
            'wp-content/uploads/2026/a.jpg',
        ], array_column($page['files'], 'path'));
        $this->assertSame(['wp-content/uploads/2026/huge.mp4'], array_column($page['skipped'], 'path'));
        $this->assertNull($page['next']);
        $this->assertSame(1, $page['files'][0]['size']);
    }

    public function testPagingCoversEverythingExactlyOnce(): void
    {
        $paths  = [];
        $offset = 0;
        do {
            $page   = $this->walker()->page($offset, microtime(true) + 60, 1);
            $paths  = array_merge($paths, array_column($page['files'], 'path'), array_column($page['skipped'], 'path'));
            $offset = $page['next'];
        } while ($offset !== null);

        $this->assertCount(4, $paths);
        $this->assertCount(4, array_unique($paths));
    }

    public function testExpiredDeadlineStillMakesProgress(): void
    {
        $page = $this->walker()->page(0, microtime(true) - 1);
        // debug.log (Index 0) ist ausgeschlossen – die Seite endet erst nach dem ersten gelieferten Eintrag
        $this->assertSame(['wp-content/plugins/elementor/core/upgrade/manager.php'], array_column($page['files'], 'path'));
        $this->assertSame(2, $page['next']);
    }

    public function testRejectsContentDirOutsideAbspath(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FileWalker($this->root . '/wp-content', $this->root, $this->root . '/x');
    }

    public function testScopeSkipsExcludedPluginsThemesAndOldUploads(): void
    {
        $root = sys_get_temp_dir() . '/wpsync-walker-scope-' . bin2hex(random_bytes(4));
        foreach ([
            'wp-content/plugins/keep/k.php',
            'wp-content/plugins/drop/d.php',
            'wp-content/plugins/single.php',
            'wp-content/themes/old/style.css',
            'wp-content/uploads/2019/a.jpg',
            'wp-content/uploads/2025/b.jpg',
            'wp-content/uploads/elementor/c.css',
        ] as $path) {
            @mkdir(dirname($root . '/' . $path), 0777, true);
            file_put_contents($root . '/' . $path, 'x');
        }
        $scope = Scope::fromArray(['exclude_plugins' => ['drop', 'single'], 'exclude_themes' => ['old'], 'uploads_since' => '2020']);
        $page  = (new FileWalker($root, $root . '/wp-content', $root . '/wp-content/plugins/wpsync-agent', $scope))->page(0, microtime(true) + 60);
        exec('rm -rf ' . escapeshellarg($root));

        $this->assertSame(
            ['wp-content/plugins/keep/k.php', 'wp-content/uploads/2025/b.jpg', 'wp-content/uploads/elementor/c.css'],
            array_column($page['files'], 'path')
        );
    }

    public function testAppliesTheSameExcludesAsFilesEndpoint(): void
    {
        $root = sys_get_temp_dir() . '/wpsync-walker-excl-' . bin2hex(random_bytes(4));
        foreach ([
            'wp-content/Cache/page.html',
            'wp-content/.htpasswd',
            'wp-content/site-backup.zip',
            'wp-content/uploads/dump.sql',
            'wp-content/uploads/sec-debug.LOG',
            'wp-content/plugins/foo/.svn/entries',
            'wp-content/wpsync-staging-0123456789ab/wp-config.php', // Staging-Kopie, am Namen erkannt (Spec 2b 5.10)
            'wp-content/uploads/wpsync-staging-0123456789ab/wp-content/uploads/a.jpg',
            'wp-content/plugins/foo/install.sql',
            'wp-content/themes/t/style.css',
        ] as $path) {
            if (!is_dir(dirname($root . '/' . $path))) {
                mkdir(dirname($root . '/' . $path), 0777, true);
            }
            file_put_contents($root . '/' . $path, 'x');
        }
        try {
            $page = (new FileWalker($root, $root . '/wp-content', $root . '/none'))->page(0, microtime(true) + 60);
            $this->assertSame(
                ['wp-content/plugins/foo/install.sql', 'wp-content/themes/t/style.css'],
                array_column($page['files'], 'path')
            );
        } finally {
            exec('rm -rf ' . escapeshellarg($root));
        }
    }
}
