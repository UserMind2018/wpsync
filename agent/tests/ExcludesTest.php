<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\TestCase;
use WpSync\Excludes;

final class ExcludesTest extends TestCase
{
    public function testTopLevelDirsOnlyExcludedAtTop(): void
    {
        $this->assertTrue(Excludes::dir('upgrade', true));
        $this->assertFalse(Excludes::dir('upgrade', false), 'elementor/core/upgrade must be pulled (Spike B12)');
        $this->assertTrue(Excludes::dir('duplicator-backups', true));
    }

    public function testGitExcludedEverywhere(): void
    {
        $this->assertTrue(Excludes::dir('.git', true));
        $this->assertTrue(Excludes::dir('.git', false));
    }

    public function testFileReasons(): void
    {
        $this->assertSame('log', Excludes::file('debug.log', 10));
        $this->assertSame('too_large', Excludes::file('video.mp4', Excludes::MAX_FILE_BYTES + 1));
        $this->assertSame('unsafe_name', Excludes::file("bad\tname.php", 10));
        $this->assertSame('unsafe_name', Excludes::file("bad\nname.php", 10));
        $this->assertNull(Excludes::file('style.css', 10));
    }

    public function testBackupDirsAreExcludedTopDirs(): void
    {
        $this->assertContains('backups-dup-pro', Excludes::BACKUP_DIRS);
        $this->assertNotContains('cache', Excludes::BACKUP_DIRS);
        foreach (Excludes::BACKUP_DIRS as $dir) {
            $this->assertTrue(Excludes::dir($dir, true), $dir . ' must never be pulled');
        }
    }
}
