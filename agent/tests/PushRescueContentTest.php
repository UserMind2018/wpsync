<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\TestCase;
use WpSync\PushRescue;

/**
 * rescue.json eines Pushs mit DB-Anteil (Spec Content-Push §7.6, C7, AC-157): der Weg ohne
 * WordPress nimmt Code und Uploads zurück, die Inhalte nicht – und sagt das.
 */
final class PushRescueContentTest extends TestCase
{
    private const SECRET = '000102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f';
    private const ID     = 'p_20261009_0123456789ab';
    private const SHA    = 'ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12';

    private string $content;
    private string $work;
    private string $key;

    protected function setUp(): void
    {
        $this->content = (string) realpath(sys_get_temp_dir()) . '/wpsync-rescuecontent-' . bin2hex(random_bytes(4));
        $this->work    = $this->content . '/wpsync-push-0123456789abcdef';
        $this->key     = PushRescue::key(self::SECRET, self::ID, 'salt');
        mkdir($this->content . '/uploads/2026/10', 0777, true);
        mkdir($this->work . '/' . self::ID . '/old', 0777, true);
    }

    protected function tearDown(): void
    {
        exec('chmod -R u+w ' . escapeshellarg($this->content) . ' 2>/dev/null; rm -rf ' . escapeshellarg($this->content));
    }

    /** Ein getauschtes Plugin: target ist der neue Stand, snapshot der alte. */
    private function swapped(): array
    {
        $target   = $this->content . '/plugins/x';
        $snapshot = $this->work . '/' . self::ID . '/old/0';
        mkdir($target, 0777, true);
        mkdir($snapshot, 0777, true);
        file_put_contents($target . '/main.php', 'new');
        file_put_contents($snapshot . '/main.php', 'old');
        return [['unit' => 'plugins/x', 'target' => $target, 'snapshot' => $snapshot, 'discard' => $this->work . '/' . self::ID . '/discard/0']];
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function rescue(): array
    {
        return PushRescue::handle([$this->content], ['action' => 'rollback', 'push_id' => self::ID, 'key' => $this->key], time());
    }

    public function testAPushWithoutContentSaysNothingAboutIt(): void
    {
        PushRescue::write($this->work, self::ID, hash('sha256', $this->key), $this->swapped(), PushRescue::COMMITTED);
        $record = PushRescue::read($this->work, self::ID);
        $this->assertNull($record['content']);
        $this->assertFalse(PushRescue::contentOpen($record));
        $this->assertFalse(PushRescue::setContent($this->work, self::ID, PushRescue::CONTENT_APPLIED), 'ein Push ohne DB-Anteil bekommt keinen');
        $this->assertSame([200, ['ok' => true, 'status' => 'rolled_back']], $this->rescue());
    }

    /** Der DB-Anteil steht im Datensatz, bevor irgendetwas geschrieben wird – als „pending“. */
    public function testWriteNotesTheContentBeforeTheTransaction(): void
    {
        PushRescue::write($this->work, self::ID, hash('sha256', $this->key), [], PushRescue::COMMITTED, [], self::SHA);
        $record = PushRescue::read($this->work, self::ID);
        $this->assertSame(['state' => 'pending', 'sha256' => self::SHA], $record['content']);
        $this->assertTrue(PushRescue::contentOpen($record));
        $this->assertTrue(PushRescue::setContent($this->work, self::ID, PushRescue::CONTENT_APPLIED));
        $this->assertTrue(PushRescue::contentOpen((array) PushRescue::read($this->work, self::ID)));
        $this->assertTrue(PushRescue::setContent($this->work, self::ID, PushRescue::CONTENT_DONE));
        $this->assertFalse(PushRescue::contentOpen((array) PushRescue::read($this->work, self::ID)));
    }

    /** AC-157: Code und Uploads sind zurück, die Antwort nennt die Inhalte ausdrücklich. */
    public function testRescueTakesCodeAndUploadsBackAndNamesTheContent(): void
    {
        file_put_contents($this->content . '/uploads/2026/10/neu.png', 'neu');
        file_put_contents($this->content . '/uploads/2026/10/geaendert.png', 'seither geändert');
        $uploads = ['added' => [
            ['path' => '2026/10/neu.png', 'sha256' => hash('sha256', 'neu')],
            ['path' => '2026/10/geaendert.png', 'sha256' => hash('sha256', 'wie gepusht')],
        ], 'dirs' => []];
        PushRescue::write($this->work, self::ID, hash('sha256', $this->key), $this->swapped(), PushRescue::COMMITTED, $uploads, self::SHA);
        PushRescue::setContent($this->work, self::ID, PushRescue::CONTENT_APPLIED);

        list($status, $body) = $this->rescue();
        $this->assertSame(200, $status);
        $this->assertSame(['upload_changed_since_push', 'content_not_rolled_back'], $body['warnings']);
        $this->assertSame(['2026/10/geaendert.png'], $body['kept']);
        $this->assertSame('old', file_get_contents($this->content . '/plugins/x/main.php'));
        $this->assertFileDoesNotExist($this->content . '/uploads/2026/10/neu.png');

        $record = (array) PushRescue::read($this->work, self::ID);
        $this->assertSame('rolled_back', $record['status']);
        $this->assertTrue(PushRescue::contentOpen($record), 'der DB-Anteil bleibt offen – der Agent holt ihn nach');

        // Ein zweiter Aufruf ändert nichts mehr und sagt dasselbe über die Inhalte.
        $this->assertSame([200, ['ok' => true, 'status' => 'rolled_back', 'warnings' => ['content_not_rolled_back']]], $this->rescue());
    }

    /** Hat der Agent die Inhalte schon zurückgenommen, bleibt die Rücknahme des Codes ohne Warnung. */
    public function testNoWarningOnceTheContentIsTakenBack(): void
    {
        PushRescue::write($this->work, self::ID, hash('sha256', $this->key), $this->swapped(), PushRescue::COMMITTED, [], self::SHA);
        PushRescue::setContent($this->work, self::ID, PushRescue::CONTENT_DONE);
        $this->assertSame([200, ['ok' => true, 'status' => 'rolled_back']], PushRescue::rollback($this->content, $this->work, self::ID));
    }
}
