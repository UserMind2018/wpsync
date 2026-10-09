<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use WpSync\ContentImage;
use WpSync\ContentTarget;
use WpSync\Push;
use WpSync\PushContent;
use WpSync\PushRescue;
use WpSync\RescueContent;
use WpSync\RescueSeal;
use WpSync\Staging;
use WpSync\Store;

require_once __DIR__ . '/PushRescueFlowCase.php';

/**
 * rescue.php und der Agent im echten Push-Ablauf (Spec Content-Push P3 §4, §7.1, §8; AC-160,
 * AC-165, AC-167, AC-168): der Wettlauf von Commit und Rücknahme, der Umschlag beim Begin und
 * was der Agent nachholt, sobald WordPress wieder lädt.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PushRescueFlowTest extends PushRescueFlowCase
{
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

    /**
     * Security-Review P3, M1: confirm nimmt die Sperre des Pushs. Läuft gerade eine Rücknahme, wird
     * nichts bestätigt – weder in rescue.json noch in der Datenbank.
     */
    public function testConfirmIsBusyWhileARollbackHoldsTheLock(): void
    {
        Push::$gateWait = 0.2;
        list($id) = $this->push($this->stage($this->rows()), 'new');
        $before   = file_get_contents($this->work($this->live) . '/' . $id . '/rescue.json');
        $lock     = PushRescue::lock($this->work($this->live), $id); // rescue.php läuft

        $started = microtime(true);
        $error   = $this->assertRefused('wpsync_push_busy', 423, Push::confirm(['push_id' => $id], self::KEY));
        $this->assertGreaterThan(0.2, microtime(true) - $started, 'confirm hat auf die Rücknahme gewartet');
        $this->assertStringContainsString($id, $error->message);
        $this->assertSame($before, file_get_contents($this->work($this->live) . '/' . $id . '/rescue.json'));
        $this->assertSame('committed', Store::getPush($id)['status']);
        $this->assertFileExists($this->sealed($this->live, $id), 'der Umschlag bleibt für die Rücknahme');

        PushRescue::unlock($lock);
        $confirm = Push::confirm(['push_id' => $id], self::KEY);
        $this->assertInstanceOf(\WP_REST_Response::class, $confirm, $confirm instanceof \WP_Error ? $confirm->code . ' ' . $confirm->message : '');
        $this->assertSame('confirmed', $this->rescue($this->live, $id)['status']);
        $this->assertSame('confirmed', Store::getPush($id)['status']);
        $again = PushRescue::lock($this->work($this->live), $id);
        $this->assertIsResource($again, 'nach confirm ist die Sperre frei');
        PushRescue::unlock($again);
    }

    /**
     * M1: rescue.php nimmt den Push zurück, während confirm unterwegs ist – nach dessen sync(), vor
     * dessen Sperre. confirm sieht unter der Sperre den Stand von rescue.php und bestätigt nichts:
     * nie „bestätigt“ in der Datenbank und zurückgetauscht auf der Platte.
     */
    public function testAConfirmThatRescueOvertookConfirmsNothing(): void
    {
        $old      = $this->liveDb->data;
        list($id) = $this->push($this->stage($this->rows()), 'new');
        $GLOBALS['wpsync_post_actions'] = [];
        $rescue   = null;
        PushRescue::$onLock = function (string $pushId) use ($id, &$rescue): void {
            if ($pushId !== $id) {
                return;
            }
            PushRescue::$onLock = null; // genau hier: confirm hat sync() hinter sich und will die Sperre
            $keys               = ContentImage::$keys;
            ContentImage::$keys = [];   // rescue.php kennt den Schlüssel der Installation nicht
            $rescue             = $this->rescueDb($id);
            ContentImage::$keys = $keys;
        };

        $error = $this->assertRefused('wpsync_push_state', 409, Push::confirm(['push_id' => $id], self::KEY));

        $this->assertSame(200, $rescue[0] ?? null, (string) json_encode($rescue));
        $this->assertStringContainsString('rolled_back', $error->message);
        $push = Store::getPush($id);
        $this->assertSame(['rolled_back', true], [$push['status'], $push['pruned']], 'nicht bestätigt');
        $this->assertSame('old', file_get_contents($this->live . '/plugins/x/main.php'));
        ksort($old['postmeta']);
        ksort($this->liveDb->data['postmeta']);
        $this->assertSame($old, $this->liveDb->data);
        $this->assertContains('clean_post_cache [219]', $GLOBALS['wpsync_post_actions'], 'die Nacharbeiten sind nachgeholt');
        $this->assertDirectoryDoesNotExist($this->work($this->live) . '/' . $id);
        $this->assertNull(Push::pending());
    }

    /**
     * M1, U18: ein confirm überholt die Rücknahme über den Agent – nach deren Prüfung (unbestätigt:
     * kein Fenster nötig), vor deren Sperre. Unter der Sperre ist der Push bestätigt: die Rücknahme
     * lehnt ab, bevor sie die Datenbank berührt; für einen bestätigten Push gilt das Push-Fenster.
     */
    public function testAnAgentRollbackThatAConfirmOvertookIsRefusedBeforeTheDatabase(): void
    {
        list($id) = $this->push($this->stage($this->rows()), 'new');
        $pushed   = $this->liveDb->data;
        Store::$until      = 0; // das Push-Fenster ist zu
        $this->liveDb->log = [];
        $confirm           = null;
        PushRescue::$onLock = static function (string $pushId) use ($id, &$confirm): void {
            if ($pushId === $id) {
                PushRescue::$onLock = null;
                $confirm            = Push::confirm(['push_id' => $id], self::KEY);
            }
        };

        $error = $this->assertRefused('wpsync_push_state', 409, Push::rollback(['push_id' => $id], self::KEY));

        $this->assertInstanceOf(\WP_REST_Response::class, $confirm);
        $this->assertStringContainsString('bestätigt', $error->message);
        $this->assertSame($pushed, $this->liveDb->data);
        $this->assertSame([], $this->liveDb->log, 'die Datenbank wurde nicht berührt');
        $this->assertSame('new', file_get_contents($this->live . '/plugins/x/main.php'));
        $this->assertSame('confirmed', Store::getPush($id)['status']);
        $this->assertSame('confirmed', $this->rescue($this->live, $id)['status']);
        // Der zweite Aufruf sieht den bestätigten Push – und das geschlossene Fenster.
        $this->assertRefused('wpsync_push_window', 403, Push::rollback(['push_id' => $id], self::KEY));
    }

    /** R1, R2, R13: der echte Begin eines Pushs mit Inhalten legt den Umschlag an – nach einer Probe, nur für den Besitzer lesbar. */
    public function testTheBeginSealsAnEnvelopeForAPushWithContent(): void
    {
        $begin = $this->begin($this->stage($this->rows()), [], 'new');
        $this->assertInstanceOf(\WP_REST_Response::class, $begin, $begin instanceof \WP_Error ? $begin->code . ' ' . $begin->message : '');
        $this->assertSame(['ok' => true], $begin->data['rescue']['db']);
        $this->assertSame(['url', 'salt', 'hardening', 'db'], array_keys($begin->data['rescue']));
        $this->assertSame(1, $this->connected, 'die Probe hat einmal verbunden');
        $id   = (string) $begin->data['push_id'];
        $file = $this->sealed($this->live, $id);
        $this->assertSame('0600', substr(sprintf('%o', fileperms($file)), -4));
        $raw = (string) file_get_contents($file);
        $this->assertStringNotContainsString('geh3im!', $raw);

        // Öffnen kann ihn, wer aus Pairing-Secret und Salt den Rescue-Key ableitet – der Hash im Plan genügt nicht.
        $key    = PushRescue::key((string) Store::secretFor(self::KEY), $id, (string) $begin->data['rescue']['salt']);
        $plan   = json_decode((string) file_get_contents($this->work($this->live) . '/' . $id . '/plan.json'), true);
        $opened = RescueSeal::open($raw, $key, $id);
        $this->assertSame(hash('sha256', $key), $plan['key_hash']);
        $this->assertSame(['geh3im!', 'wp_', 'live'], [$opened['db']['password'], $opened['prefix'], $opened['target']]);
        $this->assertNull(RescueSeal::open($raw, $plan['key_hash'], $id));
        $this->assertStringNotContainsString($key, (string) json_encode($plan));
        $this->assertNotNull(RescueContent::check($opened, $id, $this->live));
        // Die Sperre der Probe ist wieder frei.
        $lock = PushRescue::lock($this->work($this->live), $id);
        $this->assertIsResource($lock);
        PushRescue::unlock($lock);
    }

    /** §5.3: der Probelauf sagt, was ein echter Begin ergäbe – ohne Probe, ohne Datei; ohne Inhalte kein Wort. */
    public function testTheDryRunOnlyTellsAndCodeAloneSaysNothing(): void
    {
        $dry = $this->begin($this->stage($this->rows()), ['dry' => true], 'new');
        $this->assertSame(['ok' => true], $dry->data['rescue']['db']);
        $this->assertSame(0, $this->connected);
        $this->assertSame([], glob($this->work($this->live) . '/p_*') ?: []);

        PushContent::$rescueData = null; // die Attrappe der Datenbank ist kein mysqli
        $dry = $this->begin($this->stage($this->rows()), ['dry' => true], 'new');
        $this->assertSame(['ok' => false, 'reason' => 'driver'], $dry->data['rescue']['db']);

        $code = $this->begin(null, ['dry' => true], 'new');
        $this->assertArrayNotHasKey('db', $code->data['rescue']);
        $real = $this->begin(null, [], 'new');
        $this->assertArrayNotHasKey('db', $real->data['rescue']);
        $this->assertFileDoesNotExist($this->sealed($this->live, (string) $real->data['push_id']));
    }

    /** Richtet her, warum es keinen Umschlag gibt (Closures lassen sich einem Test im eigenen Prozess nicht mitgeben). */
    private function withoutEnvelope(string $reason): void
    {
        switch ($reason) {
            case 'driver': // die Attrappe der Datenbank ist kein mysqli
                PushContent::$rescueData = null;
                return;
            case 'no_image_key':
                PushContent::$rescueData = static function (): string {
                    return RescueContent::NO_IMAGE_KEY;
                };
                return;
            case 'probe_failed':
                RescueContent::$resolve = static function (): ?ContentTarget {
                    return null;
                };
                return;
            case 'throws':
                PushContent::$rescueData = static function (): array {
                    throw new \RuntimeException("Access denied for user 'wp_user'");
                };
                return;
        }
    }

    /**
     * R12, AC-160: ohne Umschlag wird trotzdem gepusht – der Begin nennt den Grund. Im Notfall gehen
     * dann nur Code und Uploads zurück, der Push bleibt offen, und der Agent holt die Inhalte nach.
     */
    #[\PHPUnit\Framework\Attributes\TestWith(['driver', 'driver'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['no_image_key', 'no_image_key'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['probe_failed', 'probe_failed'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['throws', 'probe_failed'])]
    public function testWithoutAnEnvelopeThePushStillGoesAndRescueKeepsTheContent(string $case, string $reason): void
    {
        $old = $this->liveDb->data;
        $this->withoutEnvelope($case);
        $begin = $this->begin($this->stage($this->rows()), [], 'new');
        $this->assertInstanceOf(\WP_REST_Response::class, $begin, $begin instanceof \WP_Error ? $begin->code . ' ' . $begin->message : '');
        $this->assertSame(['ok' => false, 'reason' => $reason], $begin->data['rescue']['db']);
        $id         = (string) $begin->data['push_id'];
        $this->salt = (string) $begin->data['rescue']['salt'];
        $this->assertFileDoesNotExist($this->sealed($this->live, $id));
        Push::upload(['push_id' => $id, 'unit' => 0, 'files' => [['path' => 'main.php', 'data' => base64_encode('new'), 'offset' => 0]]], self::KEY);
        $this->assertInstanceOf(\WP_REST_Response::class, Push::commit(['push_id' => $id], self::KEY));
        $pushed = $this->liveDb->data;

        list($status, $body) = $this->rescueDb($id);
        $this->assertSame(200, $status);
        $this->assertSame(['state' => 'kept', 'error' => ['code' => 'rescue_db_unavailable']], $body['content']);
        $this->assertSame(['content_not_rolled_back'], $body['warnings']);
        $this->assertSame($pushed, $this->liveDb->data);
        $this->assertSame('old', file_get_contents($this->live . '/plugins/x/main.php'));

        // Wie nach P2: offen, bis der Agent die Inhalte nachholt.
        Push::sync();
        $this->assertSame('committed', Store::getPush($id)['status']);
        $this->assertInstanceOf(\WP_REST_Response::class, Push::rollback(['push_id' => $id], self::KEY));
        ksort($old['postmeta']);
        ksort($this->liveDb->data['postmeta']);
        $this->assertSame($old, $this->liveDb->data);
        $this->assertSame('rolled_back', Store::getPush($id)['status']);
    }

    /** AC-158 (soweit ohne echte Datenbank): ein Aufruf von rescue.php nimmt Inhalte, Code und Uploads zurück. */
    public function testRescueTakesTheWholeSetBack(): void
    {
        $old               = $this->liveDb->data;
        list($id, $commit) = $this->push($this->stage($this->rows()), 'new', ['2026/10/neu.png' => (string) base64_decode(self::PNG)]);
        $this->assertInstanceOf(\WP_REST_Response::class, $commit, $commit instanceof \WP_Error ? $commit->code . ' ' . $commit->message : '');
        $this->assertFileExists($this->sealed($this->live, $id));
        $this->connected = 0;
        ContentImage::$keys = []; // rescue.php kennt den Schlüssel der Installation nicht

        list($status, $body) = $this->rescueDb($id);
        $this->assertSame(200, $status);
        $this->assertSame(['ok' => true, 'status' => 'rolled_back', 'content' => ['state' => 'rolled_back', 'cache' => 'none']], $body);
        ksort($old['postmeta']);
        ksort($this->liveDb->data['postmeta']);
        $this->assertSame($old, $this->liveDb->data);
        $this->assertSame('old', file_get_contents($this->live . '/plugins/x/main.php'));
        $this->assertFileDoesNotExist($this->live . '/uploads/2026/10/neu.png');
        $this->assertSame(1, $this->connected);
        $this->assertFileDoesNotExist($this->sealed($this->live, $id));
    }

    /** AC-164: ein Push nach Staging legt den Umschlag in der Kopie ab; rescue.php nimmt dort zurück und fasst Live nicht an. */
    public function testAStagingPushIsSealedAndTakenBackInTheCopy(): void
    {
        $old               = $this->stagingDb->data;
        $live              = $this->liveDb->data;
        list($id, $commit) = $this->push($this->stage($this->rows()), 'new', [], 'staging');
        $this->assertInstanceOf(\WP_REST_Response::class, $commit, $commit instanceof \WP_Error ? $commit->code . ' ' . $commit->message : '');
        $this->assertFileExists($this->sealed($this->staging, $id));
        $this->assertFileDoesNotExist($this->sealed($this->live, $id));
        $this->liveDb->log = [];

        list($status, $body) = $this->rescueDb($id);
        $this->assertSame(200, $status);
        $this->assertSame(['state' => 'rolled_back', 'cache' => 'none'], $body['content']);
        ksort($old['postmeta']);
        ksort($this->stagingDb->data['postmeta']);
        $this->assertSame($old, $this->stagingDb->data);
        $this->assertSame($live, $this->liveDb->data);
        $this->assertSame([], $this->liveDb->log, 'keine Zeile von Live');
        $this->assertSame('stg-old', file_get_contents($this->staging . '/plugins/x/main.php'));
        $this->assertSame('old', file_get_contents($this->live . '/plugins/x/main.php'));
    }

    /** R10, R13: lässt sich der Push nicht sperren, nähme rescue.php die Inhalte nicht zurück – der Begin legt dann keinen Umschlag an. */
    public function testWithoutAWorkingLockTheBeginSealsNothing(): void
    {
        $id  = 'p_20261009_0123456789ab';
        $key = PushRescue::key('secret', $id, 'salt');
        $this->assertSame(['ok' => false, 'reason' => 'probe_failed'], PushContent::rescueDb('live', $this->live, $this->root . '/gibt-es-nicht', $id, $key));
        $this->assertSame(0, $this->connected, 'ohne Sperre keine Probe');
        mkdir($this->work($this->live) . '/' . $id, 0777, true);
        $held = PushRescue::lock($this->work($this->live), $id);
        $this->assertSame(['ok' => false, 'reason' => 'probe_failed'], PushContent::rescueDb('live', $this->live, $this->work($this->live), $id, $key));
        PushRescue::unlock($held);
        $this->assertSame(['ok' => true], PushContent::rescueDb('live', $this->live, $this->work($this->live), $id, $key));
        $this->assertFileExists($this->sealed($this->live, $id));
    }
}
