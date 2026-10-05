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

    public function testDirNamesAreCaseInsensitive(): void
    {
        $this->assertTrue(Excludes::dir('Cache', true));
        $this->assertTrue(Excludes::dir('.GIT', false));
        $this->assertTrue(Excludes::dir('.svn', false));
        $this->assertTrue(Excludes::dir('.hg', false));
        $this->assertTrue(Excludes::dir('backups', true));
        $this->assertTrue(Excludes::dir('backup-2026-01', true));
        $this->assertFalse(Excludes::dir('backups', false), 'plugins may ship a backups/ folder with code');
    }

    public function testFileNamesAreCaseInsensitiveAndCoverSecrets(): void
    {
        $this->assertSame('log', Excludes::file('debug.LOG', 10));
        $this->assertSame('secret', Excludes::file('.htpasswd', 10));
        $this->assertSame('secret', Excludes::file('.env', 10));
        $this->assertSame('secret', Excludes::file('.ENV.production', 10));
        $this->assertSame('unsafe_name', Excludes::file("bad\0name.php", 10));
        $this->assertNull(Excludes::file('environment.php', 10));
    }

    public function testPathChecksEveryComponent(): void
    {
        $this->assertSame('excluded_dir', Excludes::path('updraft/backup_db.gz', 10));
        $this->assertSame('excluded_dir', Excludes::path('cache/page.html', 10));
        $this->assertSame('excluded_dir', Excludes::path('.git/config', 10));
        $this->assertSame('excluded_dir', Excludes::path('plugins/e2e-objects/.git/config', 10));
        $this->assertNull(Excludes::path('plugins/elementor/core/upgrade/manager.php', 10), 'top dirs only count at the top (Spike B12)');
        $this->assertSame('log', Excludes::path('uploads/x/debug.LOG', 10));
        $this->assertSame('secret', Excludes::path('.htpasswd', 10));
        $this->assertSame('unsafe_name', Excludes::path("uploads/a\nb/c.jpg", 10));
        $this->assertSame('too_large', Excludes::path('uploads/2026/huge.mp4', Excludes::MAX_FILE_BYTES + 1));
        $this->assertNull(Excludes::path('themes/t/style.css', 10));
    }

    public function testPathExcludesDumpsAndTopLevelArchives(): void
    {
        $this->assertSame('dump', Excludes::path('uploads/dump.sql', 10));
        $this->assertSame('dump', Excludes::path('db.SQL.GZ', 10));
        $this->assertNull(Excludes::path('plugins/foo/install.sql', 10), 'plugins ship schema files');
        $this->assertNull(Excludes::path('themes/foo/demo.sql', 10));
        $this->assertSame('backup', Excludes::path('site-backup.zip', 10));
        $this->assertSame('backup', Excludes::path('site.tar.gz', 10));
        $this->assertNull(Excludes::path('uploads/2026/archive.zip', 10), 'archives below the top level are content');
    }

    /** AC-71: der Arbeitsordner eines Pushs verlässt den Server nie. */
    public function testPushWorkDirIsExcludedAtTop(): void
    {
        $this->assertTrue(Excludes::dir('wpsync-push-0123456789abcdef', true));
        $this->assertFalse(Excludes::dir('wpsync-push-0123456789abcdef', false), 'only directly below wp-content');
        $this->assertSame('excluded_dir', Excludes::path('wpsync-push-0123456789abcdef/p_20261005_0123456789ab/old/0/main.php', 10));
    }
}
