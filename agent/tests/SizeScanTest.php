<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\TestCase;
use WpSync\Excludes;
use WpSync\SizeScan;

final class SizeScanTest extends TestCase
{
    /** @var string */
    private $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/wpsync-sizescan-' . bin2hex(random_bytes(4));
        $files = [
            'plugins/akismet/akismet.php' => 10,
            'plugins/akismet/readme.txt'  => 5,
            'plugins/hello.php'           => 3,
            'themes/astra/style.css'      => 7,
            'uploads/2024/05/a.jpg'       => 100,
            'uploads/2025/01/b.jpg'       => 50,
            'uploads/elementor/css/x.css' => 4,
            'backups-dup-pro/site.zip'    => 1000,
            'debug.log'                   => 2,
            '.git/HEAD'                   => 1,
        ];
        foreach ($files as $path => $size) {
            @mkdir(dirname($this->dir . '/' . $path), 0777, true);
            file_put_contents($this->dir . '/' . $path, str_repeat('x', $size));
        }
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function testBuckets(): void
    {
        $this->assertSame('plugins/akismet', SizeScan::bucket('plugins/akismet/akismet.php'));
        $this->assertSame('plugins/hello', SizeScan::bucket('plugins/hello.php'));
        $this->assertSame('themes/astra', SizeScan::bucket('themes/astra/style.css'));
        $this->assertSame('uploads/2024', SizeScan::bucket('uploads/2024/05/a.jpg'));
        $this->assertSame('uploads/other', SizeScan::bucket('uploads/elementor/css/x.css'));
        $this->assertSame('uploads/other', SizeScan::bucket('uploads/2024'));
        $this->assertSame('top/backups-dup-pro', SizeScan::bucket('backups-dup-pro/site.zip'));
        $this->assertSame('top/.', SizeScan::bucket('debug.log'));
    }

    public function testFullScanInOnePage(): void
    {
        $page = (new SizeScan($this->dir))->page('', microtime(true) + 60);
        $this->assertNull($page['next']);
        $this->assertSame(['files' => 2, 'bytes' => 15], $page['buckets']['plugins/akismet']);
        $this->assertSame(['files' => 1, 'bytes' => 3], $page['buckets']['plugins/hello']);
        $this->assertSame(['files' => 1, 'bytes' => 100], $page['buckets']['uploads/2024']);
        $this->assertSame(['files' => 1, 'bytes' => 4], $page['buckets']['uploads/other']);
        $this->assertSame(['files' => 1, 'bytes' => 1000], $page['buckets']['top/backups-dup-pro']);
        $this->assertArrayNotHasKey('top/.git', $page['buckets']);
        $this->assertSame(9, array_sum(array_column($page['buckets'], 'files')));
        $this->assertSame([], $page['large']);
    }

    public function testExpiredDeadlineStillProgressesAndAccumulates(): void
    {
        $scan   = new SizeScan($this->dir);
        $page   = ['buckets' => [], 'large' => [], 'next' => ''];
        $rounds = 0;
        do {
            $page = $scan->page((string) $page['next'], microtime(true) - 1, $page['buckets'], $page['large']);
            $this->assertSame(1, $page['files']);
            $rounds++;
        } while ($page['next'] !== null && $rounds < 100);

        $this->assertSame(9, $rounds, 'one file per page when the deadline has passed');
        $this->assertSame(9, array_sum(array_column($page['buckets'], 'files')));
    }

    public function testCursorIsThePathOfTheLastCountedFile(): void
    {
        $page = (new SizeScan($this->dir))->page('', microtime(true) - 1);
        $this->assertSame('backups-dup-pro/site.zip', $page['next']);
    }

    public function testResumeSurvivesFilesVanishingBeforeTheCursor(): void
    {
        $scan = new SizeScan($this->dir);
        $page = $scan->page('', microtime(true) - 1);
        $page = $scan->page((string) $page['next'], microtime(true) - 1, $page['buckets'], $page['large']);
        $this->assertSame('debug.log', $page['next']);

        unlink($this->dir . '/backups-dup-pro/site.zip'); // ein Index-Cursor würde jetzt eine Datei überspringen
        $page = $scan->page((string) $page['next'], microtime(true) + 60, $page['buckets'], $page['large']);

        $this->assertNull($page['next']);
        $this->assertSame(9, array_sum(array_column($page['buckets'], 'files')));
    }

    public function testResumeDoesNotEnterDirectoriesBeforeTheCursor(): void
    {
        chmod($this->dir . '/plugins', 0000); // nicht lesbar: wer hineinläuft, verliert die Plugins nicht – er hat sie schon
        try {
            $page = (new SizeScan($this->dir))->page('themes/astra/style.css', microtime(true) + 60);
        } finally {
            chmod($this->dir . '/plugins', 0777);
        }
        $this->assertNull($page['next']);
        $this->assertSame(3, $page['files']);
        $this->assertSame(['uploads/2024', 'uploads/2025', 'uploads/other'], array_keys($page['buckets']));
    }

    public function testLargeFilesAreListed(): void
    {
        $handle = fopen($this->dir . '/uploads/2025/huge.mp4', 'w');
        ftruncate($handle, Excludes::MAX_FILE_BYTES + 1); // sparse, belegt keinen Platz
        fclose($handle);

        $page = (new SizeScan($this->dir))->page('', microtime(true) + 60);
        $this->assertSame([['path' => 'wp-content/uploads/2025/huge.mp4', 'bytes' => Excludes::MAX_FILE_BYTES + 1]], $page['large']);
    }

    /** Spec 2b 5.10: eine Staging-Kopie (oder ihr Rest) zählt im Infosheet nicht mit und wird nicht genannt. */
    public function testStagingCopyIsNeitherCountedNorNamed(): void
    {
        foreach (['wpsync-staging-0123456789ab/wp-config.php', 'uploads/WPSYNC-staging-0123456789ab/wp-content/big.bin'] as $path) {
            mkdir(dirname($this->dir . '/' . $path), 0777, true);
            file_put_contents($this->dir . '/' . $path, 'x');
        }
        $page = (new SizeScan($this->dir))->page('', microtime(true) + 60);
        $this->assertNull($page['next']);
        $this->assertSame(9, array_sum(array_column($page['buckets'], 'files')));
        foreach (array_keys($page['buckets']) as $bucket) {
            $this->assertStringNotContainsStringIgnoringCase('wpsync-staging', (string) $bucket);
        }
        $this->assertSame(['files' => 1, 'bytes' => 4], $page['buckets']['uploads/other']);
    }
}
