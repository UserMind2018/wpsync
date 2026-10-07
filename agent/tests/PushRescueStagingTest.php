<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\TestCase;
use WpSync\PushRescue;
use WpSync\StagingGuard;

final class PushRescueStagingTest extends TestCase
{
    private const ID    = 'p_20261006_0123456789ab';
    private const OTHER = 'p_20261006_ba9876543210';
    private const KEY   = 'rescue-key';
    private string $root;
    private string $live;
    private string $staging;

    protected function setUp(): void
    {
        $this->root    = (string) realpath(sys_get_temp_dir()) . '/wpsync-rescue2-' . bin2hex(random_bytes(4));
        $this->live    = $this->root . '/wp-content';
        $this->staging = $this->root . '/wpsync-staging-0123456789ab/wp-content';
        foreach ([$this->live . '/plugins', $this->staging . '/plugins/x', $this->work() . '/' . self::ID . '/old/0'] as $dir) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($this->staging . '/plugins/x/main.php', 'new');
        file_put_contents($this->work() . '/' . self::ID . '/old/0/main.php', 'old');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function work(): string
    {
        return $this->staging . '/wpsync-push-0123456789abcdef';
    }

    private function liveWork(): string
    {
        return $this->live . '/wpsync-push-0123456789abcdef';
    }

    private function record(string $target, ?string $work = null, string $id = self::ID): void
    {
        $work = $work ?? $this->work();
        PushRescue::write($work, $id, hash('sha256', self::KEY), [[
            'unit'     => 'plugins/x',
            'target'   => $target,
            'snapshot' => $work . '/' . $id . '/old/0',
            'discard'  => $work . '/' . $id . '/discard/0',
        ]], PushRescue::COMMITTED);
    }

    /**
     * @param list<string>|null $dirs
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function rollback(?array $dirs = null, string $id = self::ID): array
    {
        return PushRescue::handle($dirs ?? [$this->live, $this->staging], ['action' => 'rollback', 'push_id' => $id, 'key' => self::KEY], 1000);
    }

    /** V8, AC-99 */
    public function testRollsBackAStagingPush(): void
    {
        $this->record($this->staging . '/plugins/x');
        $result = $this->rollback();
        $this->assertSame(200, $result[0], (string) json_encode($result[1]));
        $this->assertSame('old', file_get_contents($this->staging . '/plugins/x/main.php'));
    }

    /** Ein Datensatz der Kopie darf nichts auf Live tauschen. */
    public function testAStagingRecordCannotTouchLive(): void
    {
        mkdir($this->live . '/plugins/x');
        file_put_contents($this->live . '/plugins/x/main.php', 'live');
        $this->record($this->live . '/plugins/x');
        $this->assertSame(409, $this->rollback()[0]);
        $this->assertSame('live', file_get_contents($this->live . '/plugins/x/main.php'));
    }

    /** … auch nicht über einen Symlink, der aus der Kopie nach Live zeigt. */
    public function testAStagingRecordCannotReachLiveThroughASymlink(): void
    {
        mkdir($this->live . '/plugins/x');
        file_put_contents($this->live . '/plugins/x/main.php', 'live');
        exec('rm -rf ' . escapeshellarg($this->staging . '/plugins'));
        symlink($this->live . '/plugins', $this->staging . '/plugins');
        $this->record($this->staging . '/plugins/x');

        $this->assertSame([409, ['ok' => false, 'error' => 'path outside wp-content']], $this->rollback());
        $this->assertSame('live', file_get_contents($this->live . '/plugins/x/main.php'));
        $this->assertFileExists($this->work() . '/' . self::ID . '/old/0/main.php');
    }

    /** Umgekehrt: ein Datensatz von Live tauscht nichts in der Kopie. */
    public function testALiveRecordCannotTouchStaging(): void
    {
        mkdir($this->liveWork() . '/' . self::OTHER . '/old/0', 0777, true);
        file_put_contents($this->liveWork() . '/' . self::OTHER . '/old/0/main.php', 'old');
        $this->record($this->staging . '/plugins/x', $this->liveWork(), self::OTHER);

        $this->assertSame(409, $this->rollback(null, self::OTHER)[0]);
        $this->assertSame('new', file_get_contents($this->staging . '/plugins/x/main.php'));
    }

    public function testALiveRecordCannotReachStagingThroughASymlink(): void
    {
        mkdir($this->liveWork() . '/' . self::OTHER . '/old/0', 0777, true);
        file_put_contents($this->liveWork() . '/' . self::OTHER . '/old/0/main.php', 'old');
        symlink($this->staging . '/plugins', $this->live . '/themes');
        $this->record($this->live . '/themes/x', $this->liveWork(), self::OTHER);

        $this->assertSame(409, $this->rollback(null, self::OTHER)[0]);
        $this->assertSame('new', file_get_contents($this->staging . '/plugins/x/main.php'));
    }

    /** Ein Arbeitsordner, der nur ein Symlink ist, zählt nicht – sonst läge der Datensatz woanders. */
    public function testASymlinkedWorkDirIsIgnored(): void
    {
        mkdir($this->live . '/plugins/x');
        file_put_contents($this->live . '/plugins/x/main.php', 'new');
        mkdir($this->liveWork() . '/' . self::OTHER . '/old/0', 0777, true);
        $this->record($this->live . '/plugins/x', $this->liveWork(), self::OTHER);
        symlink($this->liveWork(), $this->staging . '/wpsync-push-fedcba9876543210');

        $this->assertSame(404, $this->rollback([$this->staging], self::OTHER)[0]);
        $this->assertSame(200, $this->rollback(null, self::OTHER)[0], 'found in its own wp-content');
    }

    /** Der falsche Schlüssel bleibt falsch, egal in welchem wp-content der Datensatz liegt. */
    public function testWrongKeyOnAStagingPush(): void
    {
        $this->record($this->staging . '/plugins/x');
        $result = PushRescue::handle([$this->live, $this->staging], ['action' => 'rollback', 'push_id' => self::ID, 'key' => 'nope'], 1000);
        $this->assertSame(403, $result[0]);
        $this->assertSame('new', file_get_contents($this->staging . '/plugins/x/main.php'));
        $this->assertSame(1, PushRescue::read($this->work(), self::ID)['attempts']);
    }

    public function testContentDirsFindsLiveAndTheStagingCopy(): void
    {
        $this->assertSame([$this->live, $this->staging], PushRescue::contentDirs($this->live));
    }

    public function testContentDirsIgnoresSymlinksAndForeignNames(): void
    {
        $elsewhere = $this->root . '/elsewhere';
        mkdir($elsewhere . '/wp-content', 0777, true);
        symlink($elsewhere, $this->root . '/wpsync-staging-ffffffffffff');
        mkdir($this->root . '/wpsync-staging-aaaaaaaaaaaa');
        symlink($this->live, $this->root . '/wpsync-staging-aaaaaaaaaaaa/wp-content');
        mkdir($this->root . '/wpsync-staging-evil/wp-content', 0777, true);
        mkdir($this->root . '/wpsync-staging-0123456789abc/wp-content', 0777, true);
        mkdir($this->root . '/wpsync-staging-bbbbbbbbbbbb');

        $this->assertSame([$this->live, $this->staging], PushRescue::contentDirs($this->live));
    }

    /** rescue.php lädt StagingGuard nicht – das Muster des Ordnernamens steht deshalb zweimal da. */
    public function testStagingDirPatternMatchesTheGuard(): void
    {
        $this->assertSame(StagingGuard::DIR_RE, PushRescue::STAGING_DIR);
        $this->assertSame(1, preg_match(PushRescue::STAGING_DIR, StagingGuard::newDirName()));
    }

    public function testConfined(): void
    {
        $this->assertTrue(PushRescue::confined($this->staging, $this->staging . '/plugins/x'));
        $this->assertTrue(PushRescue::confined($this->staging, $this->staging . '/mu-plugins'));
        $this->assertTrue(PushRescue::confined($this->staging, $this->staging . '/plugins/neu'), 'new unit');
        $this->assertFalse(PushRescue::confined($this->staging, $this->live . '/plugins/x'));
        $this->assertFalse(PushRescue::confined($this->staging, $this->staging . '/plugins/../../../wp-content/plugins/x'));
        $this->assertFalse(PushRescue::confined($this->live, $this->staging . '/plugins/x'));
        $this->assertTrue(PushRescue::confined($this->live, $this->live . '/plugins/x'));

        // Live darf einen Symlink auf dem Weg haben (2a) – nur nicht in eine Kopie hinein.
        mkdir($this->root . '/shared/themes', 0777, true);
        symlink($this->root . '/shared/themes', $this->live . '/themes');
        $this->assertTrue(PushRescue::confined($this->live, $this->live . '/themes/t'));
        symlink($this->staging . '/plugins', $this->live . '/mu-plugins');
        $this->assertFalse(PushRescue::confined($this->live, $this->live . '/mu-plugins/x'));

        symlink($this->root . '/shared/themes', $this->staging . '/themes');
        $this->assertFalse(PushRescue::confined($this->staging, $this->staging . '/themes/t'));
    }
}
