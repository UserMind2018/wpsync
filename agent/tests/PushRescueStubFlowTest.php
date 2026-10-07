<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use WpSync\Push;
use WpSync\PushRescue;
use WpSync\PushRescueStub;
use WpSync\Staging;
use WpSync\Store;

/**
 * Rescue-Stub und verwaiste Arbeitsordner im Ablauf eines Pushs (Spec Stufe 2, 12): Push läuft
 * echt auf einem temporären Webroot, WordPress, Store und Staging sind Attrappen (PushHarness.php).
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PushRescueStubFlowTest extends TestCase
{
    private const KEY    = 'k_test';
    private const PLUGIN = 'https://example.test/wp-content/plugins/wpsync-agent/rescue.php';

    private string $root;
    private string $live;

    protected function setUp(): void
    {
        require_once __DIR__ . '/PushHarness.php';

        $this->root = (string) realpath(sys_get_temp_dir()) . '/wpsync-stubflow-' . bin2hex(random_bytes(4));
        $this->live = $this->root . '/wp-content';
        foreach ([$this->live . '/plugins/wpsync-agent', $this->live . '/plugins/x', $this->live . '/themes'] as $dir) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($this->live . '/plugins/wpsync-agent/rescue.php', '<?php // test');
        file_put_contents($this->live . '/plugins/x/main.php', 'old');

        define('WP_CONTENT_DIR', $this->live);
        $GLOBALS['wpdb'] = new \WpsyncHarnessDb();
        Staging::$root   = $this->root;
        Store::$until    = time() + 3600;
        Push::register($this->live . '/plugins/wpsync-agent');
    }

    protected function tearDown(): void
    {
        @chmod($this->root, 0777);
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    /**
     * @param array<string, mixed> $extra
     * @return \WP_REST_Response|\WP_Error
     */
    private function begin(array $extra = [])
    {
        return Push::begin($extra + ['force' => true, 'units' => [[
            'path'  => 'plugins/x',
            'files' => ['main.php' => ['size' => 3, 'sha256' => hash('sha256', 'new'), 'mtime' => 1700000000]],
            'base'  => [],
        ]]], self::KEY);
    }

    /** @param \WP_REST_Response|\WP_Error $r */
    private function url($r): string
    {
        $this->assertInstanceOf(\WP_REST_Response::class, $r, $r instanceof \WP_Error ? $r->code : '');
        return (string) $r->data['rescue']['url'];
    }

    /** @return list<string> */
    private function stubs(): array
    {
        $out = [];
        foreach (scandir($this->root) ?: [] as $name) {
            if (preg_match(PushRescueStub::NAME, $name) === 1) {
                $out[] = $name;
            }
        }
        return $out;
    }

    /** Begin mit Stub, Upload und Commit; liefert die Push-ID. */
    private function swap(): string
    {
        $begin = $this->begin(['rescue_stub' => true]);
        $this->assertInstanceOf(\WP_REST_Response::class, $begin, $begin instanceof \WP_Error ? $begin->code : '');
        $id = (string) $begin->data['push_id'];
        $this->assertInstanceOf(\WP_REST_Response::class, Push::upload(['push_id' => $id, 'unit' => 0, 'files' => [['path' => 'main.php', 'data' => base64_encode('new'), 'offset' => 0]]], self::KEY));
        $commit = Push::commit(['push_id' => $id], self::KEY);
        $this->assertInstanceOf(\WP_REST_Response::class, $commit, $commit instanceof \WP_Error ? $commit->code : '');
        return $id;
    }

    /** AC-133, R3 */
    public function testWithoutTheFlagTheUrlStaysInThePluginFolder(): void
    {
        $this->assertSame(self::PLUGIN, $this->url($this->begin(['dry' => true])));
        $this->assertSame([], $this->stubs());
    }

    /** N2: ein Stub nur bei offenem Push-Fenster – sonst bricht die CLI ohnehin vor dem Ping ab. */
    public function testAClosedWindowGetsThePluginUrlAndNoStub(): void
    {
        Store::$until = time() - 60;
        $this->assertSame(self::PLUGIN, $this->url($this->begin(['dry' => true, 'rescue_stub' => true])));
        $this->assertSame([], $this->stubs());
        $this->assertNull(Store::getState('rescue_stub'));
    }

    /** AC-132, R4 */
    public function testTheDryRunCreatesTheStubAndTheRealBeginReusesIt(): void
    {
        $dry = $this->url($this->begin(['dry' => true, 'rescue_stub' => true]));
        $this->assertMatchesRegularExpression('#^https://example\.test/wpsync-rescue-[a-f0-9]{32}\.php\z#', $dry);
        $this->assertSame([basename($dry)], $this->stubs());
        $this->assertSame($dry, $this->url($this->begin(['rescue_stub' => true])));
        $this->assertSame([basename($dry)], $this->stubs());
    }

    public function testAMissingStubIsCreatedAnew(): void
    {
        $first = $this->url($this->begin(['dry' => true, 'rescue_stub' => true]));
        unlink($this->root . '/' . basename($first));
        $second = $this->url($this->begin(['dry' => true, 'rescue_stub' => true]));
        $this->assertNotSame($first, $second);
        $this->assertSame([basename($second)], $this->stubs());
    }

    /** AC-133, R6 */
    public function testAReadOnlyWebrootFallsBackToThePluginUrl(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root ignores file permissions');
        }
        chmod($this->root, 0555);
        $this->assertSame(self::PLUGIN, $this->url($this->begin(['dry' => true, 'rescue_stub' => true])));
    }

    /** R6 */
    public function testAContentUrlOutsideTheWebrootFallsBack(): void
    {
        $GLOBALS['wpsync_test_content_url'] = 'https://cdn.example.test/content';
        $this->assertSame(self::PLUGIN, $this->url($this->begin(['dry' => true, 'rescue_stub' => true])));
        $this->assertSame([], $this->stubs());
    }

    /** R2: auch ein Push nach Staging bekommt den Stub im Live-Webroot. */
    public function testAStagingPushUsesTheStubInTheLiveWebroot(): void
    {
        $copy = $this->root . '/' . Staging::DIR . '/wp-content';
        mkdir($copy . '/plugins/x', 0777, true);
        mkdir($copy . '/themes', 0777, true);
        file_put_contents($copy . '/plugins/x/main.php', 'stg');

        $url = $this->url($this->begin(['target' => 'staging', 'dry' => true, 'rescue_stub' => true]));
        $this->assertMatchesRegularExpression('#^https://example\.test/wpsync-rescue-[a-f0-9]{32}\.php\z#', $url);
        $this->assertFileExists($this->root . '/' . basename($url));
        $this->assertSame([], glob($this->root . '/' . Staging::DIR . '/wpsync-rescue-*') ?: []);
    }

    /** AC-134 */
    public function testAnUnconfirmedPushKeepsItsStub(): void
    {
        $id   = $this->swap();
        $stub = $this->stubs();
        $this->assertCount(1, $stub);
        Push::prune(time() + 86400);
        $this->assertSame($stub, $this->stubs(), 'pending push');

        $this->assertInstanceOf(\WP_REST_Response::class, Push::rollbackPush($id));
        $this->assertSame(Push::CRON, ($GLOBALS['wpsync_test_single_events'][0] ?? [0, ''])[1], 'tidy run scheduled');
        Push::prune(time() + Push::LOCK_TTL + 1);
        $this->assertSame([], $this->stubs());
        $this->assertNull(Store::getState('rescue_stub'));
    }

    /** AC-134, R5: nach confirm noch 10 Minuten da – eine verlorene confirm-Antwort braucht rescue.php (U18). */
    public function testConfirmKeepsTheStubForTenMinutes(): void
    {
        $id = $this->swap();
        $this->assertInstanceOf(\WP_REST_Response::class, Push::confirm(['push_id' => $id], self::KEY));
        $this->assertCount(1, $this->stubs());
        $events = $GLOBALS['wpsync_test_single_events'] ?? [];
        $this->assertCount(1, $events);
        $this->assertSame(Push::CRON, $events[0][1]);
        $this->assertGreaterThan(time() + Push::LOCK_TTL, $events[0][0]);

        Push::prune(time() + Push::LOCK_TTL - 5);
        $this->assertCount(1, $this->stubs());
        Push::prune(time() + Push::LOCK_TTL + 1);
        $this->assertSame([], $this->stubs());
    }

    /** N3, R5: liegt der Commit länger als 10 Minuten zurück, zählen confirm und Rollback neu. */
    public function testALateConfirmKeepsTheStub(): void
    {
        $id = $this->swap();
        $this->age();
        $this->assertInstanceOf(\WP_REST_Response::class, Push::confirm(['push_id' => $id], self::KEY));
        $this->assertCount(1, $this->stubs());
    }

    /** N3, R5 */
    public function testALateRollbackKeepsTheStub(): void
    {
        $id = $this->swap();
        $this->age();
        $this->assertInstanceOf(\WP_REST_Response::class, Push::rollbackPush($id));
        Push::prune(time());
        $this->assertCount(1, $this->stubs());
    }

    /** Datiert Stub-Zustand und Sperre zurück, als läge der Commit mehr als 10 Minuten zurück. */
    private function age(): void
    {
        foreach (['rescue_stub', 'push_lock'] as $name) {
            $state = Store::getState($name);
            $this->assertNotNull($state, $name);
            $state['touched'] = time() - Push::LOCK_TTL - 60;
            Store::setState($name, $state);
        }
    }

    /** AC-134 */
    public function testAStubNobodyUsesIsPrunedAfterTenMinutes(): void
    {
        $this->begin(['dry' => true, 'rescue_stub' => true]);
        Push::prune(time() + 10);
        $this->assertCount(1, $this->stubs());
        Push::prune(time() + Push::LOCK_TTL + 1);
        $this->assertSame([], $this->stubs());
    }

    /** R9 */
    public function testUninstallRemovesEveryStub(): void
    {
        $this->begin(['dry' => true, 'rescue_stub' => true]);
        Push::uninstall();
        $this->assertSame([], $this->stubs());
    }

    /** AC-136 */
    public function testHardeningPluginsAreNamed(): void
    {
        $GLOBALS['wpsync_test_active_plugins'] = ['ithemes-security-pro/ithemes-security-pro.php', 'akismet/akismet.php'];
        $begin = $this->begin(['dry' => true]);
        $this->assertInstanceOf(\WP_REST_Response::class, $begin);
        $this->assertSame(['ithemes-security-pro'], $begin->data['rescue']['hardening']);
    }

    /**
     * Legt <work>/<id> an, optional mit rescue.json, und datiert ihn zurück.
     */
    private function workDir(string $content, string $id, ?string $status, int $mtime): string
    {
        $work = $content . '/' . Store::pushDirName();
        mkdir($work . '/' . $id . '/stage', 0777, true);
        if ($status !== null) {
            PushRescue::write($work, $id, str_repeat('0', 64), [], $status);
        }
        touch($work . '/' . $id, $mtime);
        return $work . '/' . $id;
    }

    /** AC-138, R10 */
    public function testOrphanedWorkDirsArePruned(): void
    {
        $old       = time() - Push::UPLOAD_TTL - 60;
        $orphan    = $this->workDir($this->live, 'p_20261001_aaaaaaaaaaaa', null, $old);
        $rolled    = $this->workDir($this->live, 'p_20261001_bbbbbbbbbbbb', PushRescue::ROLLED_BACK, $old);
        $committed = $this->workDir($this->live, 'p_20261001_cccccccccccc', PushRescue::COMMITTED, $old);
        $young     = $this->workDir($this->live, 'p_20261001_dddddddddddd', null, time());
        $owned     = $this->workDir($this->live, 'p_20261001_eeeeeeeeeeee', null, $old);
        Store::addPush(['push_id' => 'p_20261001_eeeeeeeeeeee', 'key_id' => self::KEY, 'device' => 'd', 'target' => 'live',
            'status' => Push::UPLOADING, 'forced' => 0, 'units' => '[]', 'created' => time()]);
        $other = $this->live . '/' . Store::pushDirName() . '/not-a-push';
        mkdir($other);
        touch($other, $old);

        Push::prune(time());

        $this->assertDirectoryDoesNotExist($orphan);
        $this->assertDirectoryDoesNotExist($rolled);
        $this->assertDirectoryExists($committed, 'the only snapshot of a swapped push');
        $this->assertDirectoryExists($young, 'a begin may still be writing its row');
        $this->assertDirectoryExists($owned);
        $this->assertDirectoryExists($other);
    }

    /** M1: ohne verlässliche Datenbank ist eine fehlende Zeile kein Beweis – nichts wird gelöscht. */
    public function testADatabaseErrorKeepsEveryWorkDir(): void
    {
        $old       = time() - Push::UPLOAD_TTL - 60;
        $orphan    = $this->workDir($this->live, 'p_20261001_aaaaaaaaaaaa', null, $old);
        $confirmed = $this->workDir($this->live, 'p_20261001_bbbbbbbbbbbb', PushRescue::CONFIRMED, $old);
        Store::$dbError = true;

        Push::prune(time());

        $this->assertDirectoryExists($orphan);
        $this->assertDirectoryExists($confirmed, 'a snapshot whose row could not be read');
    }

    /** M1: der Stub bleibt, solange pending, Sperre und Stub-Zustand nicht lesbar sind. */
    public function testADatabaseErrorKeepsTheStub(): void
    {
        $this->begin(['dry' => true, 'rescue_stub' => true]);
        $this->assertCount(1, $this->stubs());
        Store::$dbError = true;

        Push::prune(time() + Push::LOCK_TTL + 1);

        $this->assertCount(1, $this->stubs());
    }

    /** M1: gelöscht wird nur, was nachweislich fertig ist; eine kaputte rescue.json bleibt. */
    public function testOnlyProvablyFinishedWorkDirsArePruned(): void
    {
        $old    = time() - Push::UPLOAD_TTL - 60;
        $broken = $this->workDir($this->live, 'p_20261001_111111111111', null, $old);
        file_put_contents($broken . '/rescue.json', '{"push_id":');
        touch($broken, $old);
        $odd = $this->workDir($this->live, 'p_20261001_222222222222', 'unknown', $old);
        $confirmed = $this->workDir($this->live, 'p_20261001_333333333333', PushRescue::CONFIRMED, $old);
        $pruned    = $this->workDir($this->live, 'p_20261001_444444444444', PushRescue::CONFIRMED, $old);
        Store::addPush(['push_id' => 'p_20261001_444444444444', 'key_id' => self::KEY, 'device' => 'd', 'target' => 'live',
            'status' => PushRescue::CONFIRMED, 'forced' => 0, 'units' => '[]', 'created' => time(), 'pruned' => 1]);

        Push::prune(time());

        $this->assertDirectoryExists($broken, 'unreadable rescue.json');
        $this->assertDirectoryExists($odd, 'unknown status');
        $this->assertDirectoryDoesNotExist($confirmed, 'confirmed and its row is gone');
        $this->assertDirectoryDoesNotExist($pruned, 'confirmed and its row is pruned');
    }

    /** M1: ein Arbeitsordner, der ein Symlink ist, wird nicht durchsucht. */
    public function testASymlinkedWorkDirIsSkipped(): void
    {
        $elsewhere = $this->root . '/elsewhere';
        mkdir($elsewhere . '/p_20261001_555555555555', 0777, true);
        touch($elsewhere . '/p_20261001_555555555555', time() - Push::UPLOAD_TTL - 60);
        symlink($elsewhere, $this->live . '/' . Store::pushDirName());

        Push::prune(time());

        $this->assertDirectoryExists($elsewhere . '/p_20261001_555555555555');
    }

    /** AC-138: auch im Arbeitsordner der Staging-Kopie. */
    public function testOrphanedWorkDirsOfTheCopyArePruned(): void
    {
        $copy = $this->root . '/' . Staging::DIR . '/wp-content';
        mkdir($copy . '/plugins', 0777, true);
        $orphan = $this->workDir($copy, 'p_20261001_ffffffffffff', null, time() - Push::UPLOAD_TTL - 60);

        Push::prune(time());

        $this->assertDirectoryDoesNotExist($orphan);
    }
}
