<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use WpSync\Push;
use WpSync\PushRescue;
use WpSync\Staging;
use WpSync\Store;

/**
 * Ziel eines Pushs (Spec 2b 5.8, V8, V9): Push läuft hier echt auf einem temporären Webroot, nur
 * WordPress, Store und Staging sind Attrappen (PushHarness.php) – deshalb ein Prozess pro Test.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PushTargetTest extends TestCase
{
    private const KEY = 'k_test';

    private string $root;
    private string $live;
    private string $staging;

    protected function setUp(): void
    {
        require_once __DIR__ . '/PushHarness.php';

        $this->root    = (string) realpath(sys_get_temp_dir()) . '/wpsync-pushtarget-' . bin2hex(random_bytes(4));
        $this->live    = $this->root . '/wp-content';
        $this->staging = $this->root . '/' . Staging::DIR . '/wp-content';
        foreach ([$this->live . '/plugins/wpsync-agent', $this->live . '/plugins/x', $this->live . '/themes', $this->staging . '/plugins/x', $this->staging . '/themes'] as $dir) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($this->live . '/plugins/x/main.php', 'live-old');
        file_put_contents($this->staging . '/plugins/x/main.php', 'stg-old');

        define('WP_CONTENT_DIR', $this->live);
        $GLOBALS['wpdb'] = new \WpsyncHarnessDb();
        Staging::$root   = $this->root;
        Store::$until    = time() + 3600;
        Push::register($this->live . '/plugins/wpsync-agent');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    /**
     * @param array<string, mixed> $extra
     * @return \WP_REST_Response|\WP_Error
     */
    private function begin(?string $target, string $content, array $extra = [])
    {
        $params = $extra + ['force' => true, 'units' => [[
            'path'  => 'plugins/x',
            'files' => ['main.php' => ['size' => strlen($content), 'sha256' => hash('sha256', $content), 'mtime' => 1700000000]],
            'base'  => [],
        ]]];
        if ($target !== null) {
            $params['target'] = $target;
        }
        return Push::begin($params, self::KEY);
    }

    /**
     * @param array<string, mixed> $extra
     * @return \WP_REST_Response|\WP_Error
     */
    private function upload(string $id, string $content, array $extra = [])
    {
        return Push::upload($extra + ['push_id' => $id, 'unit' => 0, 'files' => [['path' => 'main.php', 'data' => base64_encode($content), 'offset' => 0]]], self::KEY);
    }

    /** Ganzer Push bis nach dem Tausch; liefert die ID. */
    private function push(string $target, string $content): string
    {
        $begin = $this->begin($target, $content);
        $this->assertInstanceOf(\WP_REST_Response::class, $begin, $begin instanceof \WP_Error ? $begin->code : '');
        $id = (string) $begin->data['push_id'];
        $this->assertInstanceOf(\WP_REST_Response::class, $this->upload($id, $content));
        $commit = Push::commit(['push_id' => $id], self::KEY);
        $this->assertInstanceOf(\WP_REST_Response::class, $commit, $commit instanceof \WP_Error ? $commit->code : '');
        return $id;
    }

    private function confirm(string $id): void
    {
        $this->assertInstanceOf(\WP_REST_Response::class, Push::confirm(['push_id' => $id], self::KEY));
    }

    /** @param mixed $result */
    private function assertError(string $code, int $status, $result): void
    {
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame([$code, $status], [$result->code, $result->status]);
    }

    private function liveFile(): string
    {
        return (string) file_get_contents($this->live . '/plugins/x/main.php');
    }

    private function stagingFile(): string
    {
        return (string) file_get_contents($this->staging . '/plugins/x/main.php');
    }

    public function testTheTargetIsAFixedList(): void
    {
        foreach (['prod', '', 'STAGING', 'staging/../live', $this->live, ['staging'], 1, true] as $target) {
            $params = ['target' => $target, 'dry' => true, 'units' => [['path' => 'plugins/x', 'files' => ['main.php' => ['size' => 1, 'sha256' => str_repeat('0', 64), 'mtime' => 1]]]]];
            $this->assertError('wpsync_push_target', 400, Push::begin($params, self::KEY));
        }
        $this->assertSame('live', $this->begin(null, 'new', ['dry' => true])->data['target'], 'default');
        $this->assertSame([], glob($this->root . '/*/wpsync-push-*') ?: []);
    }

    public function testAStagingPushSwapsOnlyInTheCopy(): void
    {
        $begin = $this->begin('staging', 'new');
        $this->assertSame('staging', $begin->data['target']);
        $this->assertSame(['https://example.test/' . Staging::DIR . '/'], $begin->data['health_urls']);
        $id = (string) $begin->data['push_id'];
        $this->assertSame('staging', Store::getPush($id)['target']);
        $this->assertSame('staging', Store::getState('push_lock')['target']);

        $this->upload($id, 'new');
        $this->assertInstanceOf(\WP_REST_Response::class, Push::commit(['push_id' => $id], self::KEY));

        $this->assertSame('new', $this->stagingFile());
        $this->assertSame('live-old', $this->liveFile());
        $work = $this->staging . '/' . Store::pushDirName();
        $this->assertSame('stg-old', file_get_contents($work . '/' . $id . '/old/0/main.php'), 'snapshot lies in the copy');
        $this->assertDirectoryDoesNotExist($this->live . '/' . Store::pushDirName());
        $record = PushRescue::read($work, $id);
        $this->assertSame($this->staging . '/plugins/x', $record['pairs'][0]['target']);
        $this->assertSame(1, Staging::$used);

        $this->confirm($id);
        $this->assertSame(PushRescue::CONFIRMED, PushRescue::read($work, $id)['status']);
        $this->assertSame(PushRescue::CONFIRMED, Store::getPush($id)['status']);

        $this->assertInstanceOf(\WP_REST_Response::class, Push::rollbackPush($id));
        $this->assertSame('stg-old', $this->stagingFile());
        $this->assertSame('live-old', $this->liveFile());
    }

    public function testALivePushLeavesTheCopyAlone(): void
    {
        $id = $this->push('live', 'new');
        $this->assertSame('new', $this->liveFile());
        $this->assertSame('stg-old', $this->stagingFile());
        $this->assertDirectoryDoesNotExist($this->staging . '/' . Store::pushDirName());
        $this->assertSame(0, Staging::$used);
        $this->confirm($id);
        $this->assertSame('live', Store::getPush($id)['target']);
    }

    /** Das Ziel steht im Datensatz – upload, commit, confirm und rollback nehmen keins vom Client an. */
    public function testATargetInLaterRequestsIsIgnored(): void
    {
        $id = (string) $this->begin('staging', 'new')->data['push_id'];
        $this->upload($id, 'new', ['target' => 'live']);
        $this->assertFileExists($this->staging . '/' . Store::pushDirName() . '/' . $id . '/stage/0/main.php');
        Push::commit(['push_id' => $id, 'target' => 'live'], self::KEY);
        $this->assertSame(['live-old', 'new'], [$this->liveFile(), $this->stagingFile()]);
        Push::confirm(['push_id' => $id, 'target' => 'live'], self::KEY);
        Push::rollback(['push_id' => $id, 'target' => 'live'], self::KEY);
        $this->assertSame(['live-old', 'stg-old'], [$this->liveFile(), $this->stagingFile()]);

        $id = (string) $this->begin('live', 'new2')->data['push_id'];
        $this->upload($id, 'new2', ['target' => 'staging']);
        Push::commit(['push_id' => $id, 'target' => 'staging'], self::KEY);
        $this->assertSame(['new2', 'stg-old'], [$this->liveFile(), $this->stagingFile()]);
    }

    public function testASymlinkFromTheCopyIntoLiveIsRefused(): void
    {
        exec('rm -rf ' . escapeshellarg($this->staging . '/plugins'));
        symlink($this->live . '/plugins', $this->staging . '/plugins');

        $this->assertError('wpsync_push_unit', 400, $this->begin('staging', 'new'));
        $this->assertError('wpsync_push_unit', 400, $this->begin('staging', 'new', ['dry' => true]));
        $this->assertSame('live-old', $this->liveFile());
        $this->assertSame([], Store::$pushes);
    }

    /** Auch wenn der Symlink erst nach begin auftaucht: geprüft wird noch einmal direkt vor dem Tausch. */
    public function testASymlinkThatAppearsBeforeTheSwapIsRefused(): void
    {
        $id = (string) $this->begin('staging', 'new')->data['push_id'];
        $this->upload($id, 'new');
        rename($this->staging . '/plugins', $this->staging . '/plugins-real');
        symlink($this->live . '/plugins', $this->staging . '/plugins');

        $this->assertError('wpsync_push_build', 409, Push::commit(['push_id' => $id], self::KEY));
        $this->assertSame('live-old', $this->liveFile());
        $this->assertSame(Push::FAILED, Store::getPush($id)['status']);
        $this->assertNull(Store::getState('push_lock'));
    }

    public function testALivePushCannotReachTheCopyThroughASymlink(): void
    {
        rmdir($this->live . '/themes');
        symlink($this->staging . '/plugins', $this->live . '/themes');
        $params = ['target' => 'live', 'force' => true, 'units' => [['path' => 'themes/x', 'files' => ['main.php' => ['size' => 3, 'sha256' => hash('sha256', 'new'), 'mtime' => 1700000000]]]]];

        $this->assertError('wpsync_push_unit', 400, Push::begin($params, self::KEY));
        $this->assertSame('stg-old', $this->stagingFile());
    }

    /** Das wp-content der Kopie ist der Ordner neben Live, den auch rescue.php findet – sonst nichts. */
    public function testTheCopyIsNeverLiveOrSomewhereElse(): void
    {
        mkdir($this->root . '/elsewhere/wp-content/plugins/x', 0777, true);
        foreach ([$this->live, $this->root . '/elsewhere/wp-content', $this->live . '/plugins', $this->root . '/wpsync-staging-ffffffffffff/wp-content'] as $dir) {
            Staging::$override = $dir;
            $this->assertError('wpsync_staging_missing', 409, $this->begin('staging', 'new'));
        }
        Staging::$override = '';
        $this->assertError('wpsync_staging_missing', 409, $this->begin('staging', 'new', ['dry' => true]));
        $this->assertSame('live-old', $this->liveFile());
        $this->assertSame([], Store::$pushes);
    }

    /** V9: gesperrte Kopie 409, laufender Staging-Job 423 – beim Anlegen und noch einmal vor dem Tausch. */
    public function testALockedOrBusyCopyStopsThePush(): void
    {
        Staging::$error = new \WP_Error('wpsync_staging_locked', '', ['status' => 409]);
        $this->assertError('wpsync_staging_locked', 409, $this->begin('staging', 'new'));
        $this->assertInstanceOf(\WP_REST_Response::class, $this->begin('live', 'new', ['dry' => true]), 'live is not affected');

        Staging::$error = null;
        $id             = (string) $this->begin('staging', 'new')->data['push_id'];
        Staging::$error = new \WP_Error('wpsync_staging_busy', '', ['status' => 423]);
        $this->assertError('wpsync_staging_busy', 423, $this->upload($id, 'new'));
        $this->assertError('wpsync_staging_busy', 423, Push::commit(['push_id' => $id], self::KEY));
        $this->assertSame('stg-old', $this->stagingFile());

        Staging::$error = null;
        $this->upload($id, 'new');
        $this->assertInstanceOf(\WP_REST_Response::class, Push::commit(['push_id' => $id], self::KEY));
        $this->assertSame('new', $this->stagingFile());
    }

    /** V9: eine Sperre pro Site, ein unbestätigter Push blockiert beide Ziele. */
    public function testOneLockAndOnePendingPushForBothTargets(): void
    {
        $id = (string) $this->begin('staging', 'new')->data['push_id'];
        $this->assertTrue(Push::running('staging', time()));
        $this->assertFalse(Push::running('live', time()));
        $this->assertFalse(Push::running('staging', time() + Push::LOCK_TTL));
        $this->assertError('wpsync_push_locked', 423, $this->begin('live', 'other'));

        $this->upload($id, 'new');
        Push::commit(['push_id' => $id], self::KEY);
        $this->assertSame($id, Push::pending()['push_id']);
        $this->assertSame($id, Push::pending('staging')['push_id']);
        $this->assertNull(Push::pending('live'));
        $this->assertError('wpsync_push_pending', 409, $this->begin('live', 'other'));
        $this->assertError('wpsync_push_pending', 409, $this->begin('staging', 'other'));
        $this->assertSame('live-old', $this->liveFile());

        $this->confirm($id);
        $this->assertNull(Push::pending());
        $this->assertFalse(Push::running('staging', time()));
        $this->confirm($this->push('live', 'other'));
    }

    /** Das Push-Fenster gilt für die Kopie wie für Live (AC-50). */
    public function testAClosedWindowStopsAStagingPush(): void
    {
        Store::$until = 0;
        $this->assertFalse($this->begin('staging', 'new', ['dry' => true])->data['window_open']);
        $this->assertError('wpsync_push_window', 403, $this->begin('staging', 'new'));

        Store::$until = time() + 3600;
        $id           = (string) $this->begin('staging', 'new')->data['push_id'];
        Store::$until = 0;
        $this->assertError('wpsync_push_window', 403, $this->upload($id, 'new'));
        $this->assertSame(Push::EXPIRED, Store::getPush($id)['status']);
        $this->assertDirectoryDoesNotExist($this->staging . '/' . Store::pushDirName() . '/' . $id);
        $this->assertSame('stg-old', $this->stagingFile());
    }

    /** Konfliktprüfung gegen den Stand der Kopie, nicht gegen Live. */
    public function testConflictsAreCheckedAgainstTheCopy(): void
    {
        $base = ['main.php' => ['size' => 7, 'mtime' => (int) filemtime($this->staging . '/plugins/x/main.php')]];
        $unit = ['path' => 'plugins/x', 'files' => ['main.php' => ['size' => 3, 'sha256' => hash('sha256', 'new'), 'mtime' => 1700000000]], 'base' => $base];
        touch($this->live . '/plugins/x/main.php', 1600000000);

        $dry = Push::begin(['target' => 'staging', 'dry' => true, 'units' => [$unit]], self::KEY);
        $this->assertSame([], $dry->data['units'][0]['conflicts']);
        $this->assertSame(['main.php'], Push::begin(['target' => 'live', 'dry' => true, 'units' => [$unit]], self::KEY)->data['units'][0]['conflicts']);

        file_put_contents($this->staging . '/plugins/x/main.php', 'changed on the copy');
        $this->assertError('wpsync_push_conflict', 409, Push::begin(['target' => 'staging', 'units' => [$unit]], self::KEY));
    }

    public function testDropTargetDiscardsOnlyThePushesOfThatTarget(): void
    {
        $live = $this->push('live', 'new');
        $this->confirm($live);
        $done = $this->push('staging', 'new');
        $this->confirm($done);
        $open = (string) $this->begin('staging', 'newer')->data['push_id'];
        $work = $this->staging . '/' . Store::pushDirName();

        Push::dropTarget('nonsense');
        $this->assertFalse(Store::getPush($done)['pruned']);
        Push::dropTarget('staging');

        $this->assertSame([PushRescue::CONFIRMED, true], [Store::getPush($done)['status'], Store::getPush($done)['pruned']]);
        $this->assertSame([Push::EXPIRED, true], [Store::getPush($open)['status'], Store::getPush($open)['pruned']]);
        $this->assertDirectoryDoesNotExist($work . '/' . $done);
        $this->assertDirectoryDoesNotExist($work . '/' . $open);
        $this->assertNull(Store::getState('push_lock'));
        $this->assertError('wpsync_push_state', 409, $this->upload($open, 'newer'));
        $this->assertError('wpsync_push_state', 409, Push::commit(['push_id' => $open], self::KEY));
        $this->assertError('wpsync_push_state', 409, Push::rollbackPush($done));

        $this->assertFalse(Store::getPush($live)['pruned']);
        $this->assertInstanceOf(\WP_REST_Response::class, Push::rollbackPush($live));
        $this->assertSame('live-old', $this->liveFile());
    }

    /** Verschwindet die Kopie (FTP), darf ihr unbestätigter Push Live nicht für immer sperren. */
    public function testAVanishedCopyDoesNotBlockLive(): void
    {
        $id = $this->push('staging', 'new');
        $this->assertNotNull(Push::pending());
        exec('rm -rf ' . escapeshellarg(dirname($this->staging)));

        Push::sync();
        $this->assertNull(Push::pending());
        $this->assertTrue(Store::getPush($id)['pruned']);
        $this->assertError('wpsync_staging_missing', 409, Push::confirm(['push_id' => $id], self::KEY));
        $this->confirm($this->push('live', 'new'));
        $this->assertSame('new', $this->liveFile());
    }

    /** Spec 2b 5.8: Aufräumen getrennt pro Ziel – Pushes nach Staging verdrängen keinen Live-Snapshot. */
    public function testSnapshotsArePrunedPerTarget(): void
    {
        $live = $this->push('live', 'v0');
        $this->confirm($live);
        Store::$pushes[$live]['created'] = time() - 100;
        $ids = [];
        foreach (['v1', 'v2', 'v3', 'v4'] as $n => $content) {
            $ids[$n] = $this->push('staging', $content);
            Store::$pushes[$ids[$n]]['created'] = time() - 50 + $n;
            $this->confirm($ids[$n]);
        }

        $this->assertFalse(Store::getPush($live)['pruned']);
        $this->assertDirectoryExists($this->live . '/' . Store::pushDirName() . '/' . $live . '/old/0');
        $this->assertSame([true, false, false, false], array_map(static function (string $id): bool {
            return Store::getPush($id)['pruned'];
        }, $ids));
        $this->assertSame(['push_id', 'device', 'target'], array_slice(array_keys(Push::index()->data['pushes'][0]), 0, 3));
    }
}
