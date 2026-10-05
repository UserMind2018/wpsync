<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\TestCase;
use WpSync\PushManifest;

final class PushManifestTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/wpsync-manifest-' . bin2hex(random_bytes(4));
        $files = [
            'main.php'       => '<?php // main',
            'inc/api.php'    => '<?php // api',
            'debug.log'      => 'log',
            '.git/HEAD'      => 'ref',
            '.env'           => 'SECRET=1',
            'assets/app.sql' => 'CREATE TABLE x;',
            '123'            => 'numeric name',
        ];
        foreach ($files as $rel => $content) {
            $full = $this->dir . '/' . $rel;
            if (!is_dir(dirname($full))) {
                mkdir(dirname($full), 0777, true);
            }
            file_put_contents($full, $content);
            touch($full, 1700000000);
        }
        symlink('/etc/hosts', $this->dir . '/link.php');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    /** Dieselbe Sicht wie /delta: ohne Symlinks und ohne feste Ausschlüsse – sonst gäbe es Scheinkonflikte. */
    public function testStampsMatchWhatAPullDelivers(): void
    {
        $stamps = PushManifest::stamps($this->dir, 'plugins/x');
        $this->assertSame(['123', 'assets/app.sql', 'inc/api.php', 'main.php'], array_map('strval', array_keys($stamps)));
        $this->assertSame(['size' => 13, 'mtime' => 1700000000], $stamps['main.php']);
    }

    public function testStampsOfMissingDirectoryAreEmpty(): void
    {
        $this->assertSame([], PushManifest::stamps($this->dir . '/fehlt', 'plugins/x'));
    }

    /** U4: was der Pull nie liefert, überlebt den Tausch. */
    public function testCarriedAreExcludedProtectedAndLinkedFiles(): void
    {
        $this->assertSame(['.env', '.git/HEAD', 'debug.log', 'link.php'], PushManifest::carried($this->dir, 'plugins/x'));

        file_put_contents($this->dir . '/00-local-mailguard.php', '<?php');
        file_put_contents($this->dir . '/wpsync-loader.php', '<?php');
        $carried = PushManifest::carried($this->dir, 'mu-plugins');
        $this->assertContains('00-local-mailguard.php', $carried);
        $this->assertContains('wpsync-loader.php', $carried);
        $this->assertNotContains('main.php', $carried);
    }

    /** AC-57 */
    public function testConflictsNameChangedAddedAndRemovedFiles(): void
    {
        $server = PushManifest::stamps($this->dir, 'plugins/x');
        $this->assertSame([], PushManifest::conflicts($server, $server));

        $base                = $server;
        $base['main.php']    = ['size' => 13, 'mtime' => 1600000000]; // auf dem Server geändert
        $base['gone.php']    = ['size' => 1, 'mtime' => 1];           // auf dem Server gelöscht
        unset($base['inc/api.php']);                                  // auf dem Server neu
        $this->assertSame(['gone.php', 'inc/api.php', 'main.php'], PushManifest::conflicts($server, $base));
    }

    public function testConflictsForMissingUnit(): void
    {
        $this->assertSame([], PushManifest::conflicts([], []), 'new unit without baseline');
        $this->assertSame(['a.php'], PushManifest::conflicts([], ['a.php' => ['size' => 1, 'mtime' => 1]]), 'unit vanished on the server');
    }

    public function testNeedListsFilesTheServerDoesNotHave(): void
    {
        $files = [
            'main.php'    => ['size' => 13, 'sha256' => hash('sha256', '<?php // main')],
            'inc/api.php' => ['size' => 12, 'sha256' => hash('sha256', '<?php // API')], // gleiche Grösse, anderer Inhalt
            'new.php'     => ['size' => 5, 'sha256' => hash('sha256', '<?php')],
            '123'         => ['size' => 12, 'sha256' => hash('sha256', 'numeric name')],
            'link.php'    => ['size' => 1, 'sha256' => str_repeat('0', 64)],
        ];
        $this->assertSame(['inc/api.php', 'new.php', 'link.php'], PushManifest::need($this->dir, $files));
        $this->assertSame(['main.php', 'inc/api.php', 'new.php', '123', 'link.php'], PushManifest::need($this->dir . '/fehlt', $files));
    }
}
