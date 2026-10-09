<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use WpSync\PushRescue;

/**
 * AC-161 (Spec Content-Push P3 §4.1 Nr. 2, §10): vor bestandener Schlüsselprüfung lädt rescue.php
 * nichts ausser PushSwap und PushRescue – keine Content-Klasse, nicht den Umschlag, nicht die
 * Datenbank. Jeder Test läuft in einem eigenen Prozess: dort ist eine Klasse genau dann bekannt,
 * wenn der Aufruf sie geladen hat.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PushRescueKeyGateTest extends TestCase
{
    private const ID    = 'p_20261009_0123456789ab';
    private const OTHER = 'p_20261010_ba9876543210';
    /** Hinter der Schlüsselprüfung: der Einstieg und alles, was Umschlag, Abbilder und Datenbank anfasst. */
    private const BEHIND = ['RescueContent', 'RescueSeal', 'RescueDb', 'MysqliLink', 'ContentRollback', 'ContentImage', 'ContentSql', 'ContentState'];

    private string $content;
    private string $work;
    private string $key;

    protected function setUp(): void
    {
        $this->content = (string) realpath(sys_get_temp_dir()) . '/wpsync-keygate-' . bin2hex(random_bytes(4));
        $this->work    = $this->content . '/wpsync-push-0123456789abcdef';
        $this->key     = PushRescue::key(str_repeat('ab', 32), self::ID, 'salt');
        mkdir($this->work . '/' . self::ID . '/content', 0777, true);
        PushRescue::write($this->work, self::ID, hash('sha256', $this->key), [], PushRescue::COMMITTED, [], str_repeat('ab', 32));
        PushRescue::setContent($this->work, self::ID, PushRescue::CONTENT_APPLIED);
        // Ein Umschlag und ein Vorher-Abbild liegen da – was darin steht, erfährt nur, wer sie liest.
        file_put_contents($this->work . '/' . self::ID . '/rescue.sealed', "wpsync-rescue:v1:sodium\nkein echter Umschlag");
        file_put_contents($this->work . '/' . self::ID . '/content/before.json', '{"keys":[]}');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->content));
    }

    /** @return list<string> */
    private function loaded(): array
    {
        return array_values(array_filter(self::BEHIND, static function (string $name): bool {
            return class_exists('WpSync\\' . $name, false) || interface_exists('WpSync\\' . $name, false);
        }));
    }

    /**
     * @param array<string, mixed> $over
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function rescue(array $over = []): array
    {
        return PushRescue::handle([$this->content], $over + ['action' => 'rollback', 'push_id' => self::ID, 'key' => $this->key, 'content' => '1'], 1000);
    }

    public function testAWrongKeyLoadsNothing(): void
    {
        $this->assertSame([], $this->loaded());
        for ($i = 0; $i < 6; $i++) {
            $this->assertContains($this->rescue(['key' => 'falsch ' . $i])[0], [403, 429]);
        }
        $this->assertContains($this->rescue(['key' => 'falsch', 'action' => 'cache'])[0], [403, 429]);
        $this->assertSame(404, $this->rescue(['push_id' => self::OTHER])[0]);
        $this->assertSame(400, $this->rescue(['push_id' => '../../etc'])[0]);
        $this->assertSame(400, $this->rescue(['key' => ['x']])[0]);
        $this->assertSame(200, $this->rescue(['action' => 'ping'])[0]);
        $this->assertSame([], $this->loaded(), 'keine Content-Klasse, kein Umschlag, keine Datenbank');
    }

    public function testARefusedPushLoadsNothingEvenWithTheRightKey(): void
    {
        PushRescue::setStatus($this->work, self::ID, PushRescue::CONFIRMED);
        $this->assertSame(409, $this->rescue()[0]);
        PushRescue::setStatus($this->work, self::ID, PushRescue::COMMITTED);
        PushRescue::write($this->work, self::OTHER, 'x', [], PushRescue::COMMITTED);
        $record                  = (array) PushRescue::read($this->work, self::ID);
        $record['superseded_by'] = self::OTHER;
        file_put_contents(PushRescue::file($this->work, self::ID), json_encode($record));
        $this->assertSame(409, $this->rescue()[0]);
        $record['superseded_by'] = null;
        file_put_contents(PushRescue::file($this->work, self::ID), json_encode($record));

        $lock = PushRescue::lock($this->work, self::ID);
        $this->assertSame(423, $this->rescue()[0]);
        PushRescue::unlock($lock);
        $this->assertSame(409, $this->rescue(['action' => 'cache'])[0]);
        $this->assertSame([], $this->loaded());
    }

    /** R11: ohne content=1 bleibt es bei Code und Uploads – auch dann wird nichts geladen. */
    public function testWithoutTheWishForContentNothingIsLoaded(): void
    {
        $this->assertSame([200, ['ok' => true, 'status' => 'rolled_back', 'warnings' => ['content_not_rolled_back']]], $this->rescue(['content' => '']));
        $this->assertSame([], $this->loaded());
    }

    /** Die Gegenprobe: mit richtigem Schlüssel und content=1 lädt derselbe Aufruf die Liste – und liest den Umschlag. */
    public function testTheRightKeyLoadsTheListAndReadsTheEnvelope(): void
    {
        list($status, $body) = $this->rescue();
        $this->assertSame(200, $status);
        $this->assertSame(['state' => 'kept', 'error' => ['code' => 'rescue_db_unavailable']], $body['content'], 'der Umschlag ist keiner');
        $this->assertSame(self::BEHIND, $this->loaded());
        $this->assertFalse(class_exists('WpSync\\SecretKey', false));
        $this->assertFalse(class_exists('WpSync\\SecretBox', false));
        $this->assertFalse(class_exists('WpSync\\ContentApply', false));
        $this->assertFalse(class_exists('WpSync\\ContentCheck', false));
    }
}
