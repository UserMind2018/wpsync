<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\TestCase;
use WpSync\PushRescue;

/** Rücknahme der Uploads ohne WordPress – der Weg von rescue.php (Spec Content-Push §8.4, C7). */
final class PushRescueUploadsTest extends TestCase
{
    private const SECRET = '000102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f';
    private const ID     = 'p_20261008_0123456789ab';

    private string $content;
    private string $work;
    private string $key;

    protected function setUp(): void
    {
        $this->content = (string) realpath(sys_get_temp_dir()) . '/wpsync-rescueup-' . bin2hex(random_bytes(4));
        $this->work    = $this->content . '/wpsync-push-0123456789abcdef';
        $this->key     = PushRescue::key(self::SECRET, self::ID, 'salt');
        mkdir($this->content . '/uploads/2026/10', 0777, true);
        mkdir($this->work . '/' . self::ID, 0777, true);
    }

    protected function tearDown(): void
    {
        exec('chmod -R u+w ' . escapeshellarg($this->content) . ' 2>/dev/null; rm -rf ' . escapeshellarg($this->content) . ' ' . escapeshellarg($this->content . '-outside'));
    }

    /** @return array{path: string, sha256: string} */
    private function put(string $rel, string $content): array
    {
        $full = $this->content . '/uploads/' . $rel;
        if (!is_dir(dirname($full))) {
            mkdir(dirname($full), 0777, true);
        }
        file_put_contents($full, $content);
        return ['path' => $rel, 'sha256' => hash('sha256', $content)];
    }

    /**
     * @param list<array{path: string, sha256: string}> $added
     * @param list<string>                              $dirs
     * @param list<array<string, mixed>>                $pairs
     */
    private function record(array $added, array $dirs, array $pairs = []): void
    {
        PushRescue::write($this->work, self::ID, hash('sha256', $this->key), $pairs, PushRescue::COMMITTED, ['added' => $added, 'dirs' => $dirs]);
    }

    private function here(string $rel): bool
    {
        clearstatcache();
        return file_exists($this->content . '/uploads/' . $rel);
    }

    public function testWriteStoresTheUploads(): void
    {
        $a = ['path' => '2026/10/a.png', 'sha256' => str_repeat('a', 64)];
        $this->record([$a], ['uploads/2026/10']);
        $this->assertSame(['added' => [$a], 'dirs' => ['uploads/2026/10']], PushRescue::read($this->work, self::ID)['uploads']);
        PushRescue::write($this->work, 'p_20261008_ba9876543210', 'x', [], PushRescue::COMMITTED);
        $this->assertSame(['added' => [], 'dirs' => []], PushRescue::read($this->work, 'p_20261008_ba9876543210')['uploads']);
    }

    /** AC-143: über rescue.php genau die hinzugefügten Dateien; geänderte bleiben und werden gemeldet. */
    public function testRescueRemovesExactlyTheAddedUploads(): void
    {
        $a    = $this->put('2026/12/a.png', 'a');
        $b    = $this->put('2026/10/b.png', 'b');
        $gone = ['path' => '2026/10/nie-angelegt.png', 'sha256' => hash('sha256', 'x')];
        $this->put('2026/10/fremd.png', 'fremd');
        $this->record([$a, $b, $gone], ['uploads/2026/12']);
        file_put_contents($this->content . '/uploads/2026/10/b.png', 'seither geändert');

        [$status, $body] = PushRescue::handle([$this->content], ['action' => 'rollback', 'push_id' => self::ID, 'key' => $this->key], 1000);

        $this->assertSame(200, $status);
        $this->assertSame(['ok' => true, 'status' => PushRescue::ROLLED_BACK, 'warnings' => [PushRescue::UPLOAD_CHANGED], 'kept' => ['2026/10/b.png'], 'push_id' => self::ID], $body);
        $this->assertFalse($this->here('2026/12/a.png'));
        $this->assertDirectoryDoesNotExist($this->content . '/uploads/2026/12');
        $this->assertSame('seither geändert', file_get_contents($this->content . '/uploads/2026/10/b.png'));
        $this->assertTrue($this->here('2026/10/fremd.png'));
        $this->assertSame(PushRescue::ROLLED_BACK, PushRescue::read($this->work, self::ID)['status']);
    }

    public function testAFolderStaysWhenItIsNotEmpty(): void
    {
        $a = $this->put('2027/01/a.png', 'a');
        $this->put('2027/01/spaeter.png', 'von WordPress');
        $this->record([$a], ['uploads/2027', 'uploads/2027/01']);
        $this->assertSame([], PushRescue::removeUploads($this->content, PushRescue::read($this->work, self::ID)['uploads']));
        $this->assertFalse($this->here('2027/01/a.png'));
        $this->assertTrue($this->here('2027/01/spaeter.png'));
    }

    /** Nichts ausserhalb von uploads/, nichts über einen Symlink – egal was im Datensatz steht. */
    public function testNothingOutsideTheUploadsIsTouched(): void
    {
        mkdir($this->content . '/plugins/x', 0777, true);
        file_put_contents($this->content . '/plugins/x/main.php', 'code');
        $outside = $this->content . '-outside';
        mkdir($outside);
        file_put_contents($outside . '/a.png', 'a');
        symlink($outside, $this->content . '/uploads/2026/link');
        $added = [
            ['path' => '../plugins/x/main.php', 'sha256' => hash('sha256', 'code')],
            ['path' => '2026/link/a.png', 'sha256' => hash('sha256', 'a')],
            ['path' => '/etc/hosts', 'sha256' => str_repeat('0', 64)],
            ['path' => ['x'], 'sha256' => 'y'],
            'kaputt',
        ];
        $dirs = ['plugins', 'uploads/../plugins', 'uploads/2026/link', '../' . basename($outside), 7];

        $this->assertSame([], PushRescue::removeUploads($this->content, ['added' => $added, 'dirs' => $dirs]));
        $this->assertFileExists($this->content . '/plugins/x/main.php');
        $this->assertFileExists($outside . '/a.png');
        $this->assertDirectoryExists($this->content . '/plugins');
        $this->assertTrue(is_link($this->content . '/uploads/2026/link'));
    }

    /** AC-144: erst der Code – scheitert er, bleiben die Uploads, der Satz bleibt ganz. */
    public function testTheCodeGoesBackBeforeTheUploads(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root ignores file permissions');
        }
        mkdir($this->content . '/plugins/x', 0777, true);
        file_put_contents($this->content . '/plugins/x/main.php', 'new');
        mkdir($this->work . '/' . self::ID . '/old/0', 0777, true);
        file_put_contents($this->work . '/' . self::ID . '/old/0/main.php', 'old');
        $pair = [
            'unit'     => 'plugins/x',
            'target'   => $this->content . '/plugins/x',
            'snapshot' => $this->work . '/' . self::ID . '/old/0',
            'discard'  => $this->work . '/' . self::ID . '/discard/0',
        ];
        $a = $this->put('2026/10/a.png', 'a');
        $this->record([$a], [], [$pair]);
        chmod($this->content . '/plugins', 0555); // plugins/x lässt sich nicht beiseite legen

        [$status, $body] = PushRescue::rollback($this->content, $this->work, self::ID);
        chmod($this->content . '/plugins', 0777);

        $this->assertSame([500, 'restore failed'], [$status, $body['error']]);
        $this->assertTrue($this->here('2026/10/a.png'), 'uploads stay while the code is not back');
        $this->assertSame('new', file_get_contents($this->content . '/plugins/x/main.php'));
    }

    /** Datensätze eines Agents vor 0.6.0 kennen kein „uploads“. */
    public function testARecordWithoutUploadsRollsBackAsBefore(): void
    {
        file_put_contents($this->work . '/' . self::ID . '/rescue.json', (string) json_encode([
            'push_id' => self::ID, 'key_hash' => 'x', 'pairs' => [], 'status' => PushRescue::COMMITTED,
            'superseded_by' => null, 'attempts' => 0, 'locked_until' => 0,
        ]));
        $this->assertSame([200, ['ok' => true, 'status' => PushRescue::ROLLED_BACK]], PushRescue::rollback($this->content, $this->work, self::ID));
    }
}
