<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\TestCase;
use WpSync\PushUploads;

/** Einheit uploads ohne WordPress (Spec Content-Push §8): Regeln, Plan, Anlegen, Stempel. */
final class PushUploadsTest extends TestCase
{
    private string $content;

    protected function setUp(): void
    {
        $this->content = (string) realpath(sys_get_temp_dir()) . '/wpsync-uploads-' . bin2hex(random_bytes(4));
        mkdir($this->content . '/uploads/2026/10', 0777, true);
    }

    protected function tearDown(): void
    {
        exec('chmod -R u+w ' . escapeshellarg($this->content) . ' 2>/dev/null; rm -rf ' . escapeshellarg($this->content));
    }

    /** @return array{size: int, sha256: string, mtime: int} */
    private function entry(string $content): array
    {
        return ['size' => strlen($content), 'sha256' => hash('sha256', $content), 'mtime' => 1700000000];
    }

    private function perms(string $path): string
    {
        clearstatcache();
        return substr(sprintf('%o', fileperms($path)), -4);
    }

    public function testValidFiles(): void
    {
        foreach (['2026/10/bild.jpg', 'elementor/css/post-12.css', 'größe-ä.png', 'a/b/c/d.pdf'] as $rel) {
            $this->assertTrue(PushUploads::validFile($rel), $rel);
        }
        $invalid = [
            '', '../x.jpg', '2026/../../x.jpg', '/2026/x.jpg', '2026//x.jpg', './x.jpg', "x\0.jpg", 'a\\b.jpg',
            '.git/x.jpg', 'a/.svn/x.jpg', 'wpsync-staging-0123456789ab/x.jpg', '2026/WPSYNC-PUSH-0123456789abcdef/x.jpg',
            "x\u{202e}gpj.exe",
        ];
        foreach ($invalid as $rel) {
            $this->assertFalse(PushUploads::validFile($rel), var_export($rel, true));
        }
    }

    /** AC-142 (fester Teil): ausführbar, Server-Konfiguration, versteckt, Logs, Dumps, Zugangsdaten. */
    public function testBlockedNames(): void
    {
        $blocked = [
            'x.php', '2026/10/X.PHP', 'x.php7', 'x.phtml', 'x.phar', 'x.pht', 'x.phps', 'bild.php.jpg', '.htaccess',
            '2026/.htaccess', '.user.ini', '2026/10/.versteckt.jpg', 'debug.log', 'dump.sql', 'dump.sql.gz', '.env',
            '2026/.env.local', '.htpasswd',
        ];
        foreach ($blocked as $rel) {
            $this->assertTrue(PushUploads::blockedName($rel), $rel);
        }
        foreach (['bild.jpg', 'bericht.pdf', 'php-handbuch.pdf', 'alphabet.png', 'x.phpx', 'archiv.zip'] as $rel) {
            $this->assertFalse(PushUploads::blockedName($rel), $rel);
        }
    }

    /** AC-140/AC-141: fehlt → need, gleicher Inhalt → same, alles andere am Pfad → conflicts. */
    public function testPlanSortsEveryFile(): void
    {
        $dir = $this->content . '/uploads/2026/10';
        file_put_contents($dir . '/gleich.jpg', 'same');
        file_put_contents($dir . '/anders.jpg', 'old');
        mkdir($dir . '/ordner.jpg');
        symlink($dir . '/gleich.jpg', $dir . '/link.jpg');
        mkdir($this->content . '/elsewhere');
        symlink($this->content . '/elsewhere', $this->content . '/uploads/2026/11');
        $files = [
            '2026/10/neu.jpg'    => $this->entry('new'),
            '2026/10/gleich.jpg' => $this->entry('same'),
            '2026/10/anders.jpg' => $this->entry('new'),
            '2026/10/ordner.jpg' => $this->entry('x'),
            '2026/10/link.jpg'   => $this->entry('same'),
            '2026/11/x.jpg'      => $this->entry('x'),
            '2027/01/neu.jpg'    => $this->entry('n'),
        ];
        $this->assertSame([
            'need'      => ['2026/10/neu.jpg', '2027/01/neu.jpg'],
            'same'      => ['2026/10/gleich.jpg'],
            'conflicts' => ['2026/10/anders.jpg', '2026/10/ordner.jpg', '2026/10/link.jpg', '2026/11/x.jpg'],
        ], PushUploads::plan($this->content, $files));
    }

    public function testPrepareListsFilesAndMissingFoldersTopDown(): void
    {
        $files = ['2026/10/a.jpg' => $this->entry('a'), '2027/01/b.jpg' => $this->entry('b'), '2027/01/c/d.jpg' => $this->entry('d')];
        $up    = PushUploads::prepare($this->content, $files, ['2026/10/a.jpg', '2027/01/b.jpg', '2027/01/c/d.jpg']);
        $this->assertSame([
            ['path' => '2026/10/a.jpg', 'sha256' => hash('sha256', 'a')],
            ['path' => '2027/01/b.jpg', 'sha256' => hash('sha256', 'b')],
            ['path' => '2027/01/c/d.jpg', 'sha256' => hash('sha256', 'd')],
        ], $up['added']);
        $this->assertSame(['uploads/2027', 'uploads/2027/01', 'uploads/2027/01/c'], $up['dirs']);

        // Staging-Kopie ohne uploads/: der Ordner selbst gehört dazu.
        exec('rm -rf ' . escapeshellarg($this->content . '/uploads'));
        $this->assertSame(['uploads', 'uploads/2026', 'uploads/2026/10'], PushUploads::prepare($this->content, $files, ['2026/10/a.jpg'])['dirs']);
    }

    /** AC-141: liegt die Datei inzwischen da, bricht der Satz ab. */
    public function testPrepareRefusesAFileThatAppeared(): void
    {
        file_put_contents($this->content . '/uploads/2026/10/a.jpg', 'fremd');
        try {
            PushUploads::prepare($this->content, ['2026/10/a.jpg' => $this->entry('a')], ['2026/10/a.jpg']);
            $this->fail('no exception');
        } catch (\RuntimeException $e) {
            $this->assertSame(PushUploads::EXISTS, $e->getCode());
        }
    }

    public function testPlacePutsFilesWithTheRightsOfTheirFolder(): void
    {
        chmod($this->content . '/uploads/2026', 0750);
        $stage = $this->content . '/stage';
        mkdir($stage . '/2026/11', 0777, true);
        file_put_contents($stage . '/2026/11/a.jpg', 'a');
        touch($stage . '/2026/11/a.jpg', 1700000000);
        $up     = PushUploads::prepare($this->content, ['2026/11/a.jpg' => $this->entry('a')], ['2026/11/a.jpg']);
        $placed = [];
        PushUploads::place($this->content, $stage, $up, $placed);

        $file = $this->content . '/uploads/2026/11/a.jpg';
        $this->assertSame('a', file_get_contents($file));
        $this->assertSame('0750', $this->perms($this->content . '/uploads/2026/11'));
        $this->assertSame('0640', $this->perms($file));
        $this->assertSame(1700000000, filemtime($file));
        $this->assertSame($up['added'], $placed);
        $this->assertSame(['2026/11/a.jpg' => ['size' => 1, 'mtime' => 1700000000]], PushUploads::stamps($this->content, $up));
    }

    public function testPlaceStopsAtAFileThatAppearedAndNamesWhatItPlaced(): void
    {
        $stage = $this->content . '/stage';
        mkdir($stage . '/2026/10', 0777, true);
        file_put_contents($stage . '/2026/10/a.jpg', 'a');
        file_put_contents($stage . '/2026/10/b.jpg', 'b');
        $files = ['2026/10/a.jpg' => $this->entry('a'), '2026/10/b.jpg' => $this->entry('b')];
        $up    = PushUploads::prepare($this->content, $files, ['2026/10/a.jpg', '2026/10/b.jpg']);
        file_put_contents($this->content . '/uploads/2026/10/b.jpg', 'fremd');
        $placed = [];
        try {
            PushUploads::place($this->content, $stage, $up, $placed);
            $this->fail('no exception');
        } catch (\RuntimeException $e) {
            $this->assertSame(PushUploads::EXISTS, $e->getCode());
        }
        $this->assertSame([['path' => '2026/10/a.jpg', 'sha256' => hash('sha256', 'a')]], $placed);
        $this->assertSame('fremd', file_get_contents($this->content . '/uploads/2026/10/b.jpg'));
    }
}
