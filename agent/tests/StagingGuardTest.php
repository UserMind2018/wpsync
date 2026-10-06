<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\TestCase;
use WpSync\StagingException;
use WpSync\StagingGuard;

final class StagingGuardTest extends TestCase
{
    private const DIR = 'wpsync-staging-0123456789ab';
    private string $abs;

    protected function setUp(): void
    {
        $this->abs = sys_get_temp_dir() . '/wpsync-guard-' . bin2hex(random_bytes(4));
        mkdir($this->abs . '/' . self::DIR . '/wp-content', 0777, true);
        mkdir($this->abs . '/wp-content', 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->abs));
    }

    private function guard(): StagingGuard
    {
        return new StagingGuard('wp_', 'stgabc123_', $this->abs, self::DIR);
    }

    private function assertRejected(StagingGuard $g, string $path): void
    {
        try {
            $g->path($path);
            $this->fail('accepted ' . $path);
        } catch (StagingException $e) {
            $this->assertSame(StagingException::GUARD, $e->reason());
        }
    }

    /** AC-81 */
    public function testTableAcceptsOnlyTheStagingPrefix(): void
    {
        $g = $this->guard();
        $this->assertSame('stgabc123_options', $g->table('stgabc123_options'));
        foreach (['wp_options', 'wp_stgabc123_x', 'stgabc124_options', 'stgabc123_opt`ions', '', 'STGABC123_options'] as $bad) {
            try {
                $g->table($bad);
                $this->fail('accepted ' . $bad);
            } catch (StagingException $e) {
                $this->assertSame(StagingException::GUARD, $e->reason());
            }
        }
    }

    public function testStagingNameMapsLiveTables(): void
    {
        $this->assertSame('stgabc123_postmeta', $this->guard()->stagingName('wp_postmeta'));
        $this->expectException(StagingException::class);
        $this->guard()->stagingName('other_postmeta');
    }

    /** AC-81 */
    public function testPathAcceptsOnlyTheStagingFolder(): void
    {
        $g    = $this->guard();
        $root = $g->root();
        $this->assertSame($root, $g->path($root));
        $this->assertSame($root . '/wp-content/new/deep/file.php', $g->path($root . '/wp-content/new/deep/file.php'));
        foreach ([
            dirname($root) . '/wp-content/x.php',
            $root . '-other/x',
            $root . '/wp-content/../../wp-content/x.php',
            $root . '/./x',
            dirname($root),
        ] as $bad) {
            $this->assertRejected($g, $bad);
        }
    }

    /** AC-81 */
    public function testPathRejectsSymlinksOnTheWay(): void
    {
        $g    = $this->guard();
        $root = $g->root();
        symlink($this->abs . '/wp-content', $root . '/wp-content/escape');
        $this->assertRejected($g, $root . '/wp-content/escape');
        $this->assertRejected($g, $root . '/wp-content/escape/x.php');
        $this->assertRejected($g, $root . '/wp-content/escape/new/x.php');
    }

    public function testSqlUsesOnlyCheckedNames(): void
    {
        $g = $this->guard();
        $this->assertSame('CREATE TABLE `stgabc123_posts` LIKE `wp_posts`', $g->createLike('stgabc123_posts', 'wp_posts'));
        $this->assertSame(
            'INSERT INTO `stgabc123_posts` SELECT t.* FROM `wp_posts` t WHERE t.`ID` > 5 ORDER BY t.`ID` LIMIT 0, 100',
            $g->insertSelect('stgabc123_posts', 'wp_posts', '', 't.`ID` > 5', 't.`ID`', 100)
        );
        $this->assertSame("SHOW TABLES LIKE 'stgabc123\\_%'", $g->showTables());
        $this->assertSame('DROP TABLE IF EXISTS `stgabc123_posts`', $g->drop('stgabc123_posts', ['stgabc123_posts']));
        $this->assertSame('UPDATE `stgabc123_options` SET a = 1 WHERE b = 2', $g->update('stgabc123_options', 'a = 1', 'b = 2'));
        $forbidden = [
            static function (StagingGuard $g): string { return $g->createLike('wp_posts', 'wp_posts'); },
            static function (StagingGuard $g): string { return $g->insertSelect('stgabc123_posts', 'stgabc123_x', '', '', '', 0); },
            static function (StagingGuard $g): string { return $g->update('wp_options', 'a = 1', ''); },
            static function (StagingGuard $g): string { return $g->drop('stgabc123_posts', []); },
            static function (StagingGuard $g): string { return $g->select('wp_users', '*'); },
        ];
        foreach ($forbidden as $call) {
            try {
                $call($g);
                $this->fail('built SQL for a forbidden table');
            } catch (StagingException $e) {
                $this->assertSame(StagingException::GUARD, $e->reason());
            }
        }
    }

    /** AC-82 */
    public function testNewPrefixNeverOverlapsTheLivePrefix(): void
    {
        foreach (['wp_', 's_', 'stg_', 'w', 'e2e_', 'stga_'] as $live) {
            for ($i = 0; $i < 200; $i++) {
                $prefix = StagingGuard::newPrefix($live, []);
                $this->assertMatchesRegularExpression(StagingGuard::PREFIX_RE, $prefix);
                $this->assertFalse(StagingGuard::overlaps($live, $prefix), $live . ' / ' . $prefix);
            }
        }
    }

    /** AC-82 */
    public function testNewPrefixRerollsOnOverlapAndExistingTables(): void
    {
        $seq    = ['abcdef', 'abcdef', '123456'];
        $random = static function () use (&$seq): string {
            return (string) array_shift($seq);
        };
        $this->assertSame('stg123456_', StagingGuard::newPrefix('wp_', ['stgabcdef_options'], $random));
        $seq = ['000000', '111111'];
        $this->assertSame('stg111111_', StagingGuard::newPrefix('stg000000_', [], $random));
    }

    /** V14 */
    public function testPrefixesThatCannotCoexistAreUnsupported(): void
    {
        foreach (['', 's', 'st', 'stg'] as $live) {
            try {
                StagingGuard::newPrefix($live, []);
                $this->fail('prefix for "' . $live . '"');
            } catch (StagingException $e) {
                $this->assertSame(StagingException::UNSUPPORTED, $e->reason());
            }
        }
    }

    /** Ein Ordner, der eben noch echt war und jetzt ein Symlink ist: PHP darf das nicht aus dem Cache beantworten. */
    public function testPathSeesASymlinkSetInTheSameProcess(): void
    {
        $g   = $this->guard();
        $dir = $this->abs . '/' . self::DIR . '/wp-content/plugins';
        mkdir($dir);
        $real = (string) realpath($this->abs) . '/' . self::DIR . '/wp-content/plugins';
        $this->assertSame($real . '/x.php', $g->path($real . '/x.php'));
        $this->assertTrue(is_dir($real)); // füllt Stat- und realpath-Cache
        // Von aussen getauscht (anderer Prozess): PHP selbst leert seine Caches dabei nicht.
        exec('rmdir ' . escapeshellarg($dir) . ' && ln -s ' . escapeshellarg($this->abs . '/wp-content') . ' ' . escapeshellarg($dir));
        $this->assertRejected($g, $real . '/x.php');
        $this->assertRejected($g, $real);
    }

    public function testConstructorRejectsOverlapsAndBadNames(): void
    {
        foreach ([['stg', 'stgabc123_', self::DIR], ['wp_', 'wp_x_', self::DIR], ['wp_', 'stgabc123_', 'wpsync-staging-x']] as $args) {
            try {
                new StagingGuard($args[0], $args[1], $this->abs, $args[2]);
                $this->fail('accepted ' . implode(' ', $args));
            } catch (StagingException $e) {
                $this->assertSame(StagingException::GUARD, $e->reason());
            }
        }
        $this->assertMatchesRegularExpression(StagingGuard::DIR_RE, StagingGuard::newDirName());
    }
}
