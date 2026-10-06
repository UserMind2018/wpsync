<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\TestCase;
use WpSync\PushRescue;

final class PushRescueTest extends TestCase
{
    private const SECRET = '000102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f';
    private const ID     = 'p_20261005_0123456789ab';
    private const OTHER  = 'p_20261006_ba9876543210';

    private string $content;
    private string $work;
    private string $key;

    protected function setUp(): void
    {
        $this->content = (string) realpath(sys_get_temp_dir()) . '/wpsync-rescue-' . bin2hex(random_bytes(4));
        $this->work    = $this->content . '/wpsync-push-0123456789abcdef';
        $this->key     = PushRescue::key(self::SECRET, self::ID, 'salt');
        $this->write($this->content . '/plugins/x/main.php', 'new');
        $this->write($this->work . '/' . self::ID . '/old/0/main.php', 'old');
        PushRescue::write($this->work, self::ID, hash('sha256', $this->key), [$this->pair(self::ID, 'plugins/x', true)], PushRescue::COMMITTED);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->content));
    }

    private function write(string $path, string $content): void
    {
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $content);
    }

    /** @return array{unit: string, target: string, snapshot: string|null, discard: string} */
    private function pair(string $id, string $unit, bool $withSnapshot): array
    {
        return [
            'unit'     => $unit,
            'target'   => $this->content . '/' . $unit,
            'snapshot' => $withSnapshot ? $this->work . '/' . $id . '/old/0' : null,
            'discard'  => $this->work . '/' . $id . '/discard/0',
        ];
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function post(string $id, string $key, int $now = 1000): array
    {
        return PushRescue::handle($this->content, ['action' => 'rollback', 'push_id' => $id, 'key' => $key], $now);
    }

    public function testIdAndKey(): void
    {
        $id = PushRescue::newId(1791158400);
        $this->assertMatchesRegularExpression(PushRescue::ID, $id);
        $this->assertStringStartsWith('p_20261005_', $id);
        $this->assertNotSame($id, PushRescue::newId(1791158400));

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}\z/', $this->key);
        $this->assertSame($this->key, PushRescue::key(self::SECRET, self::ID, 'salt'));
        $this->assertNotSame($this->key, PushRescue::key(self::SECRET, self::ID, 'other'));
        $this->assertNotSame($this->key, PushRescue::key(self::SECRET, self::OTHER, 'salt'));
    }

    public function testPingNeedsNoKey(): void
    {
        $this->assertSame([200, ['ok' => true]], PushRescue::handle($this->content, ['action' => 'ping'], 1000));
    }

    /** AC-64 */
    public function testRollbackWithTheRightKeyRestoresTheSnapshot(): void
    {
        [$status, $body] = $this->post(self::ID, $this->key);

        $this->assertSame(200, $status);
        $this->assertSame(['ok' => true, 'status' => PushRescue::ROLLED_BACK], $body);
        $this->assertSame('old', file_get_contents($this->content . '/plugins/x/main.php'));
        $this->assertSame('new', file_get_contents($this->work . '/' . self::ID . '/discard/0/main.php'));
        $this->assertSame(PushRescue::ROLLED_BACK, PushRescue::read($this->work, self::ID)['status']);
    }

    public function testASecondRollbackChangesNothing(): void
    {
        $this->post(self::ID, $this->key);
        [$status, $body] = $this->post(self::ID, $this->key);
        $this->assertSame(200, $status);
        $this->assertSame(PushRescue::ROLLED_BACK, $body['status']);
        $this->assertSame('old', file_get_contents($this->content . '/plugins/x/main.php'));
    }

    /** AC-65 */
    public function testWrongKeyIsRejectedAndLocksAfterFiveAttempts(): void
    {
        for ($i = 1; $i <= 4; $i++) {
            $this->assertSame(403, $this->post(self::ID, str_repeat('0', 64))[0], 'attempt ' . $i);
        }
        $this->assertSame(403, $this->post(self::ID, str_repeat('0', 64))[0], 'fifth attempt');
        $this->assertSame(429, $this->post(self::ID, $this->key, 1000)[0], 'locked even for the right key');
        $this->assertSame('new', file_get_contents($this->content . '/plugins/x/main.php'));

        $this->assertSame(200, $this->post(self::ID, $this->key, 1000 + PushRescue::LOCK_SECONDS + 1)[0], 'lock expires');
    }

    public function testUnknownAndMalformedRequests(): void
    {
        $this->assertSame(404, $this->post(self::OTHER, $this->key)[0]);
        $this->assertSame(400, $this->post('../../etc', $this->key)[0]);
        $this->assertSame(400, PushRescue::handle($this->content, ['action' => 'delete'], 1000)[0]);
        $this->assertSame(400, PushRescue::handle($this->content, ['action' => 'rollback', 'push_id' => ['x'], 'key' => ['y']], 1000)[0]);
    }

    public function testRollbackOfANewUnitRemovesIt(): void
    {
        $this->write($this->content . '/plugins/neu/main.php', 'new');
        PushRescue::write($this->work, self::OTHER, 'unused', [$this->pair(self::OTHER, 'plugins/neu', false)], PushRescue::CONFIRMED);

        $this->assertSame(200, PushRescue::rollback($this->content, $this->work, self::OTHER)[0]);
        $this->assertDirectoryDoesNotExist($this->content . '/plugins/neu');
    }

    public function testRollbackRefusesPathsOutsideWpContent(): void
    {
        $outside = dirname($this->content) . '/wpsync-rescue-outside-' . bin2hex(random_bytes(4));
        $this->write($outside . '/main.php', 'x');
        $pair           = $this->pair(self::OTHER, 'plugins/x', true);
        $pair['target'] = $this->content . '/plugins/../../' . basename($outside);
        PushRescue::write($this->work, self::OTHER, 'unused', [$pair], PushRescue::COMMITTED);

        $this->assertSame(409, PushRescue::rollback($this->content, $this->work, self::OTHER)[0]);
        $this->assertFileExists($outside . '/main.php');
        exec('rm -rf ' . escapeshellarg($outside));
    }

    /** Bricht der Tausch nach dem ersten von zwei Paaren ab, stellt der Rollback genau dieses eine wieder her. */
    public function testRollbackSkipsPairsThatWereNeverSwapped(): void
    {
        $this->write($this->content . '/themes/t/style.css', 'untouched');
        $pairs = [$this->pair(self::ID, 'plugins/x', true), $this->pair(self::ID, 'themes/t', true)];
        $pairs[1]['snapshot'] = $this->work . '/' . self::ID . '/old/1';
        $pairs[1]['discard']  = $this->work . '/' . self::ID . '/discard/1';
        PushRescue::write($this->work, self::ID, hash('sha256', $this->key), $pairs, PushRescue::COMMITTED);

        $this->assertSame(200, PushRescue::rollback($this->content, $this->work, self::ID)[0]);
        $this->assertSame('old', file_get_contents($this->content . '/plugins/x/main.php'));
        $this->assertSame('untouched', file_get_contents($this->content . '/themes/t/style.css'));
    }

    /** U6 */
    public function testSupersededPushCannotBeRolledBackUntilTheLaterOneIs(): void
    {
        $this->write($this->work . '/' . self::OTHER . '/old/0/main.php', 'new');
        $this->write($this->content . '/plugins/x/main.php', 'newer');
        PushRescue::write($this->work, self::OTHER, 'unused', [$this->pair(self::OTHER, 'plugins/x', true)], PushRescue::COMMITTED);
        PushRescue::supersede($this->work, self::OTHER, ['plugins/x']);

        $this->assertSame(self::OTHER, PushRescue::read($this->work, self::ID)['superseded_by']);
        $this->assertSame(409, PushRescue::rollback($this->content, $this->work, self::ID)[0]);
        $this->assertSame('newer', file_get_contents($this->content . '/plugins/x/main.php'));

        $this->assertSame(200, PushRescue::rollback($this->content, $this->work, self::OTHER)[0]);
        $this->assertNull(PushRescue::read($this->work, self::ID)['superseded_by'], 'released');
        $this->assertSame(200, PushRescue::rollback($this->content, $this->work, self::ID)[0]);
        $this->assertSame('old', file_get_contents($this->content . '/plugins/x/main.php'));
    }

    public function testSupersedeIgnoresOtherUnits(): void
    {
        PushRescue::write($this->work, self::OTHER, 'unused', [$this->pair(self::OTHER, 'themes/t', false)], PushRescue::COMMITTED);
        PushRescue::supersede($this->work, self::OTHER, ['themes/t']);
        $this->assertNull(PushRescue::read($this->work, self::ID)['superseded_by']);
    }

    /** U18: rescue.php ist der Notfallweg für den unbestätigten Push; bestätigte nur über den Agent. */
    public function testRescueRefusesAConfirmedPush(): void
    {
        PushRescue::setStatus($this->work, self::ID, PushRescue::CONFIRMED);

        $this->assertSame([409, ['ok' => false, 'error' => 'confirmed']], $this->post(self::ID, $this->key));
        $this->assertSame('new', file_get_contents($this->content . '/plugins/x/main.php'));
        $this->assertSame(PushRescue::CONFIRMED, PushRescue::read($this->work, self::ID)['status']);
        $this->assertSame(403, $this->post(self::ID, str_repeat('0', 64))[0], 'the key is checked first');
    }

    /** U18: der Agent (REST mit Fenster, WP-Admin) rollt auch einen bestätigten Push zurück. */
    public function testAgentRollbackStillTakesBackAConfirmedPush(): void
    {
        PushRescue::setStatus($this->work, self::ID, PushRescue::CONFIRMED);

        $this->assertSame(200, PushRescue::rollback($this->content, $this->work, self::ID)[0]);
        $this->assertSame('old', file_get_contents($this->content . '/plugins/x/main.php'));
    }

    public function testSetStatus(): void
    {
        $this->assertTrue(PushRescue::setStatus($this->work, self::ID, PushRescue::CONFIRMED));
        $this->assertSame(PushRescue::CONFIRMED, PushRescue::read($this->work, self::ID)['status']);
        $this->assertFalse(PushRescue::setStatus($this->work, self::OTHER, PushRescue::CONFIRMED));
    }
}
