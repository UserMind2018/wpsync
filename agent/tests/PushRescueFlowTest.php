<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use WpSync\Push;
use WpSync\PushRescue;
use WpSync\Store;

require_once __DIR__ . '/PushContentFlowCase.php';

/**
 * rescue.php und der Agent im echten Push-Ablauf (Spec Content-Push P3 §4, §7.1, §8; AC-160,
 * AC-165, AC-167, AC-168): der Wettlauf von Commit und Rücknahme, der Umschlag beim Begin und
 * was der Agent nachholt, sobald WordPress wieder lädt.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PushRescueFlowTest extends PushContentFlowCase
{
    /**
     * rescue.php, wie eine CLI 0.8.0 es aufruft: mit content=1.
     *
     * @param array<string, mixed> $over
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function rescueDb(string $id, array $over = []): array
    {
        $key = PushRescue::key((string) Store::secretFor(self::KEY), $id, $this->salt);
        return PushRescue::handle(PushRescue::contentDirs($this->live), $over + ['action' => 'rollback', 'push_id' => $id, 'key' => $key, 'content' => '1'], time());
    }

    /** Begin und Upload des Codes; der Commit steht noch aus. */
    private function uploaded(string $sha): string
    {
        $begin = $this->begin($sha, [], 'new');
        $this->assertInstanceOf(\WP_REST_Response::class, $begin, $begin instanceof \WP_Error ? $begin->code . ' ' . $begin->message : '');
        $id         = (string) $begin->data['push_id'];
        $this->salt = (string) $begin->data['rescue']['salt'];
        $this->assertInstanceOf(\WP_REST_Response::class, Push::upload(['push_id' => $id, 'unit' => 0, 'files' => [['path' => 'main.php', 'data' => base64_encode('new'), 'offset' => 0]]], self::KEY));
        return $id;
    }

    /**
     * R10, AC-167: rescue.php kommt, während der Commit seine Transaktion offen hat. Es schliesst
     * den Push als zurückgenommen ab – der Commit fragt vor COMMIT nach und macht ROLLBACK. Nie
     * „Push abgeschlossen, Inhalte angewandt“.
     */
    public function testACommitThatRescueOvertookRollsItsTransactionBack(): void
    {
        $old    = $this->liveDb->data;
        $id     = $this->uploaded($this->stage($this->rows()));
        $rescue = null;
        // Mitten in der Transaktion, vor dem ersten Schreibzugriff: der Tausch ist durch, der DB-Anteil „pending“.
        $this->liveDb->beforeLock = function () use ($id, &$rescue): void {
            $rescue = $this->rescueDb($id);
        };
        $commit = Push::commit(['push_id' => $id], self::KEY);

        $this->assertSame([200, ['ok' => true, 'status' => 'rolled_back', 'content' => ['state' => 'nothing']]], $rescue);
        $error = $this->assertRefused('wpsync_content_content_failed', 500, $commit);
        $this->assertStringContainsString('zurückgenommen', $error->message);
        $this->assertSame($old, $this->liveDb->data, 'der Commit hat nichts festgeschrieben');
        $this->assertNotContains('commit', $this->liveDb->log);
        $this->assertSame('rollback', $this->liveDb->log[count($this->liveDb->log) - 1]);
        $this->assertSame('old', file_get_contents($this->live . '/plugins/x/main.php'));
        $push = Store::getPush($id);
        $this->assertSame(['failed', true], [$push['status'], $push['pruned']]);
        $this->assertNull(Push::pending());
        $this->assertNull(Store::getState('push_lock'));
        $this->assertDirectoryDoesNotExist($this->work($this->live) . '/' . $id);
        $this->assertSame([], $GLOBALS['wpsync_post_actions'], 'ohne Änderung keine Nacharbeiten');
    }

    /**
     * R10, AC-167: hält eine Rücknahme die Sperre, wartet der Commit an seiner Naht – und schreibt
     * nichts fest, wenn sie nicht frei wird.
     */
    public function testACommitNeverCommitsWhileARollbackHoldsTheLock(): void
    {
        Push::$gateWait = 0.2;
        $old            = $this->liveDb->data;
        $id             = $this->uploaded($this->stage($this->rows()));
        $held           = null;
        $this->liveDb->beforeLock = function () use ($id, &$held): void {
            $held = PushRescue::lock($this->work($this->live), $id); // eine Rücknahme läuft gerade
        };
        $started = microtime(true);
        $commit  = Push::commit(['push_id' => $id], self::KEY);
        $this->assertIsResource($held);
        $this->assertGreaterThan(0.2, microtime(true) - $started, 'der Commit hat gewartet');
        $this->assertRefused('wpsync_content_content_failed', 500, $commit);
        $this->assertSame($old, $this->liveDb->data);
        $this->assertNotContains('commit', $this->liveDb->log);
        $this->assertSame('old', file_get_contents($this->live . '/plugins/x/main.php'));
        $this->assertSame('failed', Store::getPush($id)['status']);
        PushRescue::unlock($held);
    }

    /** Der gewöhnliche Commit: die Sperre hält bis „applied“ und ist danach frei. */
    public function testTheCommitHoldsTheLockUntilTheContentIsApplied(): void
    {
        list($id, $commit) = $this->push($this->stage($this->rows()), 'new');
        $this->assertInstanceOf(\WP_REST_Response::class, $commit, $commit instanceof \WP_Error ? $commit->code . ' ' . $commit->message : '');
        $this->assertSame('applied', $this->rescue($this->live, $id)['content']['state']);
        $this->assertSame('Neu', $this->liveDb->data['posts']['219']['post_title']);
        $lock = PushRescue::lock($this->work($this->live), $id);
        $this->assertIsResource($lock, 'nach dem Commit ist die Sperre frei');
        PushRescue::unlock($lock);
    }

    /** R10, AC-167: rescue.php und /push/rollback schliessen sich aus – wer zu spät kommt, bekommt 423. */
    public function testTheAgentRollbackIsBusyWhileRescueRuns(): void
    {
        list($id) = $this->push($this->stage($this->rows()), 'new');
        $pushed   = $this->liveDb->data;
        $lock     = PushRescue::lock($this->work($this->live), $id); // rescue.php läuft
        $this->liveDb->log = [];

        $error = $this->assertRefused('wpsync_push_busy', 423, Push::rollback(['push_id' => $id], self::KEY));
        $this->assertStringContainsString($id, $error->message);
        $this->assertRefused('wpsync_push_busy', 423, Push::rollbackPush($id));
        $this->assertSame($pushed, $this->liveDb->data);
        $this->assertSame([], $this->liveDb->log);
        $this->assertSame('new', file_get_contents($this->live . '/plugins/x/main.php'));
        $this->assertSame('committed', Store::getPush($id)['status']);
        // Und umgekehrt: läuft die Rücknahme über den Agent, antwortet rescue.php mit busy.
        $this->assertSame([423, ['ok' => false, 'error' => 'busy']], $this->rescueDb($id));

        PushRescue::unlock($lock);
        $back = Push::rollback(['push_id' => $id], self::KEY);
        $this->assertInstanceOf(\WP_REST_Response::class, $back, $back instanceof \WP_Error ? $back->code . ' ' . $back->message : '');
        $this->assertSame('old', file_get_contents($this->live . '/plugins/x/main.php'));
        $this->assertSame('rolled_back', Store::getPush($id)['status']);
    }
}
