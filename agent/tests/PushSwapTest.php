<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\TestCase;
use WpSync\PushSwap;

final class PushSwapTest extends TestCase
{
    private string $root;
    private string $old;
    private string $stage;
    private string $new;

    protected function setUp(): void
    {
        $this->root  = sys_get_temp_dir() . '/wpsync-swap-' . bin2hex(random_bytes(4));
        $this->old   = $this->root . '/plugins/x';
        $this->stage = $this->root . '/work/stage';
        $this->new   = $this->root . '/work/new';
        $this->put($this->old . '/main.php', 'old main', 1700000000, 0640);
        $this->put($this->old . '/inc/keep.php', 'keep', 1700000100, 0644);
        $this->put($this->old . '/inc/remove.php', 'remove', 1700000200, 0644);
        $this->put($this->old . '/debug.log', 'log', 1700000300, 0600);
        symlink('/etc/hosts', $this->old . '/link.php');
        chmod($this->old, 0750);
        $this->put($this->stage . '/main.php', 'new main', 1800000000, 0600);
        $this->put($this->stage . '/inc/added.php', 'added', 1800000100, 0600);
    }

    protected function tearDown(): void
    {
        exec('chmod -R u+rwx ' . escapeshellarg($this->root) . ' && rm -rf ' . escapeshellarg($this->root));
    }

    private function put(string $path, string $content, int $mtime, int $mode): void
    {
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $content);
        chmod($path, $mode);
        touch($path, $mtime);
    }

    /** @return array<string, array{size: int, sha256: string}> */
    private function manifest(): array
    {
        $files = [];
        foreach (['main.php' => 'new main', 'inc/keep.php' => 'keep', 'inc/added.php' => 'added'] as $rel => $content) {
            $files[$rel] = ['size' => strlen($content), 'sha256' => hash('sha256', $content)];
        }
        return $files;
    }

    /** AC-54, P14, U3, U4 */
    public function testBuildTakesUploadsFromStageAndTheRestFromOld(): void
    {
        $next = PushSwap::build($this->old, $this->stage, $this->new, $this->manifest(), ['debug.log', 'link.php'], 0, microtime(true) + 30);

        $this->assertNull($next);
        $this->assertSame('new main', file_get_contents($this->new . '/main.php'));
        $this->assertSame('keep', file_get_contents($this->new . '/inc/keep.php'));
        $this->assertSame('added', file_get_contents($this->new . '/inc/added.php'));
        $this->assertFileDoesNotExist($this->new . '/inc/remove.php', 'not in the manifest');
        $this->assertSame('log', file_get_contents($this->new . '/debug.log'), 'carried over');
        $this->assertTrue(is_link($this->new . '/link.php'));
        $this->assertSame('/etc/hosts', readlink($this->new . '/link.php'));

        $this->assertSame(1800000000, filemtime($this->new . '/main.php'), 'uploaded file keeps the client mtime');
        $this->assertSame(1700000100, filemtime($this->new . '/inc/keep.php'), 'copied file keeps its mtime');
        $this->assertSame(0640, fileperms($this->new . '/main.php') & 0777, 'mode of the replaced file');
        $this->assertSame(0644, fileperms($this->new . '/inc/added.php') & 0777, 'default for new files');
        $this->assertSame(0600, fileperms($this->new . '/debug.log') & 0777);
        $this->assertSame(0750, fileperms($this->new) & 0777, 'mode of the old directory');
        $this->assertSame('old main', file_get_contents($this->old . '/main.php'), 'old stays untouched');
    }

    public function testBuildStopsAtTheDeadlineAndResumes(): void
    {
        $next = PushSwap::build($this->old, $this->stage, $this->new, $this->manifest(), ['debug.log'], 0, microtime(true) - 1);
        $this->assertSame(1, $next, 'always makes progress, then yields');
        $this->assertFileDoesNotExist($this->new . '/debug.log');

        $next = PushSwap::build($this->old, $this->stage, $this->new, $this->manifest(), ['debug.log'], $next, microtime(true) + 30);
        $this->assertNull($next);
        $this->assertFileExists($this->new . '/debug.log');
        $this->assertFileExists($this->new . '/inc/added.php');
    }

    /** AC-60 */
    public function testBuildRejectsContentThatDiffersFromTheManifest(): void
    {
        file_put_contents($this->stage . '/main.php', 'tampered');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('main.php');
        PushSwap::build($this->old, $this->stage, $this->new, $this->manifest(), [], 0, microtime(true) + 30);
    }

    public function testBuildFailsWhenAFileIsNowhere(): void
    {
        $files            = $this->manifest();
        $files['gone.php'] = ['size' => 1, 'sha256' => hash('sha256', 'x')];
        $this->expectException(\RuntimeException::class);
        PushSwap::build($this->old, $this->stage, $this->new, $files, [], 0, microtime(true) + 30);
    }

    public function testBuildOfANewUnit(): void
    {
        $missing = $this->root . '/plugins/neu';
        $files   = ['main.php' => ['size' => 8, 'sha256' => hash('sha256', 'new main')]];
        $this->assertNull(PushSwap::build($missing, $this->stage, $this->new, $files, [], 0, microtime(true) + 30));
        $this->assertSame(0755, fileperms($this->new) & 0777);
        $this->assertSame(0644, fileperms($this->new . '/main.php') & 0777);
    }

    /** AC-55 */
    public function testSwapAndRestore(): void
    {
        PushSwap::build($this->old, $this->stage, $this->new, $this->manifest(), [], 0, microtime(true) + 30);
        $snapshot = $this->root . '/work/old';
        $discard  = $this->root . '/work/discard';

        PushSwap::swap($this->old, $this->new, $snapshot);
        $this->assertSame('new main', file_get_contents($this->old . '/main.php'));
        $this->assertSame('old main', file_get_contents($snapshot . '/main.php'));
        $this->assertDirectoryDoesNotExist($this->new);

        $this->assertTrue(PushSwap::restore($this->old, $snapshot, $discard));
        $this->assertSame('old main', file_get_contents($this->old . '/main.php'));
        $this->assertSame('new main', file_get_contents($discard . '/main.php'));
        $this->assertDirectoryDoesNotExist($snapshot);
    }

    public function testSwapOfANewUnitHasNoSnapshotAndRestoreRemovesIt(): void
    {
        $target = $this->root . '/plugins/neu';
        $files  = ['main.php' => ['size' => 8, 'sha256' => hash('sha256', 'new main')]];
        PushSwap::build($target, $this->stage, $this->new, $files, [], 0, microtime(true) + 30);

        PushSwap::swap($target, $this->new, null);
        $this->assertFileExists($target . '/main.php');

        $this->assertTrue(PushSwap::restore($target, null, $this->root . '/work/discard'));
        $this->assertDirectoryDoesNotExist($target);
    }

    public function testSwapPutsTheOldDirectoryBackWhenTheNewOneCannotMoveIn(): void
    {
        $snapshot = $this->root . '/work/old';
        try {
            PushSwap::swap($this->old, $this->root . '/work/missing', $snapshot);
            $this->fail('expected exception');
        } catch (\RuntimeException $e) {
            $this->assertSame('old main', file_get_contents($this->old . '/main.php'));
            $this->assertDirectoryDoesNotExist($snapshot);
        }
    }

    public function testRestoreRefusesWithoutSnapshot(): void
    {
        $this->assertFalse(PushSwap::restore($this->old, $this->root . '/work/missing', $this->root . '/work/discard'));
        $this->assertSame('old main', file_get_contents($this->old . '/main.php'), 'target stays');
    }

    public function testRemoveDeletesRecursivelyWithoutFollowingLinks(): void
    {
        $outside = $this->root . '/outside';
        $this->put($outside . '/precious.txt', 'x', 1, 0644);
        symlink($outside, $this->old . '/inc/linked-dir');

        PushSwap::remove($this->old);

        $this->assertDirectoryDoesNotExist($this->old);
        $this->assertFileExists($outside . '/precious.txt');
        PushSwap::remove($this->root . '/does-not-exist'); // kein Fehler
    }

    /** P14 */
    public function testProbe(): void
    {
        $this->assertTrue(PushSwap::probe($this->root . '/work', $this->root . '/plugins'));
        $this->assertSame([], glob($this->root . '/plugins/.wpsync-probe-*'), 'leaves nothing behind');

        chmod($this->root . '/plugins', 0555);
        $this->assertFalse(PushSwap::probe($this->root . '/work', $this->root . '/plugins'));
        chmod($this->root . '/plugins', 0755);
        $this->assertSame([], glob($this->root . '/work/.wpsync-probe-*'));
    }
}
