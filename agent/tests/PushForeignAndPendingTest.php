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
 * Spec 0.9.1 Block F und H: der Push eines anderen Geräts nennt sich als solcher (AC-241–AC-243),
 * und confirm lehnt einen Push ab, dessen Inhalts-Schritt nie quittiert wurde (AC-250–AC-252).
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PushForeignAndPendingTest extends PushContentFlowCase
{
    private const OTHER = 'fedcba9876543210';

    /** AC-241, AC-243: rollback und confirm auf den Push eines anderen Geräts – 404 wie bisher, mit Grund und Gerät. */
    public function testAForeignPushNamesItsDevice(): void
    {
        list($id, $commit) = $this->push($this->stage($this->rows()), 'new');
        $this->assertInstanceOf(\WP_REST_Response::class, $commit);
        foreach ([Push::rollback(['push_id' => $id], self::OTHER), Push::confirm(['push_id' => $id], self::OTHER)] as $answer) {
            $error = $this->assertRefused('wpsync_push_unknown', 404, $answer);
            $this->assertSame(['status' => 404, 'reason' => 'push_foreign_device', 'device' => 'test-device'], $error->data);
            $this->assertSame('Push ' . $id . ' stammt von einem anderen Gerät (test-device) – zurücknehmen kann ihn nur dieses Gerät oder ein Administrator unter Werkzeuge → wpsync.', $error->message);
        }
        $this->assertSame(PushRescue::COMMITTED, Store::getPush($id)['status'], 'nichts wurde angefasst');
        $this->assertSame('new', file_get_contents($this->live . '/plugins/x/main.php'));
        // Das eigene Gerät kommt weiter an seinen Push.
        $this->assertInstanceOf(\WP_REST_Response::class, Push::confirm(['push_id' => $id], self::KEY));
    }

    /** AC-242: ein Push, den es nicht gibt, bleibt „Unbekannter Push.“ – ohne Grund, für jedes Gerät. */
    public function testAnInventedPushStaysUnknown(): void
    {
        $this->push($this->stage($this->rows()), 'new');
        foreach (['p_20261005_0123456789ab', 'kein-push', ''] as $id) {
            foreach ([self::KEY, self::OTHER] as $key) {
                foreach ([Push::rollback(['push_id' => $id], $key), Push::confirm(['push_id' => $id], $key)] as $answer) {
                    $error = $this->assertRefused('wpsync_push_unknown', 404, $answer);
                    $this->assertSame(['status' => 404], $error->data);
                    $this->assertSame('Unbekannter Push.', $error->message);
                }
            }
        }
    }

    /** Ein Gerätename aus der Datenbank steht nur mit druckbaren Zeichen und gekürzt in Meldung und Feld. */
    public function testTheDeviceNameIsBounded(): void
    {
        list($id) = $this->push($this->stage($this->rows()), 'new');
        Store::$pushes[$id]['device'] = "Büro\x1b[31m" . str_repeat('x', 300);
        $error = $this->assertRefused('wpsync_push_unknown', 404, Push::rollback(['push_id' => $id], self::OTHER));
        $this->assertSame(100, mb_strlen($error->data['device'], 'UTF-8'));
        $this->assertStringStartsWith('Büro[31mxxx', $error->data['device']);
        $this->assertStringNotContainsString("\x1b", $error->message);
    }

    /**
     * AC-250: die Transaktion kam nie an (content.state: pending) – confirm lehnt ab, der Push bleibt
     * getauscht, die Rücknahme danach gelingt.
     */
    public function testConfirmRefusesAnUnacknowledgedContentStep(): void
    {
        $old               = $this->liveDb->data;
        list($id, $commit) = $this->push($this->stage($this->rows()), 'new');
        $this->assertInstanceOf(\WP_REST_Response::class, $commit);
        // Wie nach einem Commit, der vor COMMIT starb: die Datenbank hat nichts behalten, der Datensatz sagt pending.
        $this->liveDb->data = $old;
        $this->assertTrue(PushRescue::setContent($this->work($this->live), $id, PushRescue::CONTENT_PENDING));

        $error = $this->assertRefused('wpsync_push_state', 409, Push::confirm(['push_id' => $id], self::KEY));
        $this->assertSame(['status' => 409, 'reason' => 'content_pending'], $error->data);
        $this->assertSame('Der Inhalts-Schritt von Push ' . $id . ' ist nicht quittiert – erst `wpsync rollback <site> ' . $id . '`, dann neu pushen.', $error->message);
        $this->assertSame(PushRescue::COMMITTED, Store::getPush($id)['status']);
        $this->assertSame(PushRescue::COMMITTED, $this->rescue($this->live, $id)['status']);
        $this->assertSame('new', file_get_contents($this->live . '/plugins/x/main.php'));
        // Wiederholen ändert nichts.
        $this->assertRefused('wpsync_push_state', 409, Push::confirm(['push_id' => $id], self::KEY));

        $back = Push::rollback(['push_id' => $id], self::KEY);
        $this->assertInstanceOf(\WP_REST_Response::class, $back, $back instanceof \WP_Error ? $back->code . ' ' . $back->message : '');
        $this->assertSame(PushRescue::ROLLED_BACK, Store::getPush($id)['status']);
        $this->assertSame('old', file_get_contents($this->live . '/plugins/x/main.php'));
        $this->assertEquals($old, $this->liveDb->data);
    }

    /** Dasselbe, wenn der COMMIT doch ankam und nur der Vermerk fehlt: erst die Rücknahme, sie nimmt die Inhalte mit. */
    public function testRollbackAfterTheRefusalTakesLandedContentBack(): void
    {
        $old               = $this->liveDb->data;
        list($id, $commit) = $this->push($this->stage($this->rows()), 'new');
        $this->assertInstanceOf(\WP_REST_Response::class, $commit);
        PushRescue::setContent($this->work($this->live), $id, PushRescue::CONTENT_PENDING);
        $this->assertRefused('wpsync_push_state', 409, Push::confirm(['push_id' => $id], self::KEY));
        $this->assertSame('Neu', $this->liveDb->data['posts']['219']['post_title']);
        $this->assertInstanceOf(\WP_REST_Response::class, Push::rollback(['push_id' => $id], self::KEY));
        $this->assertSame($old['posts']['219']['post_title'], $this->liveDb->data['posts']['219']['post_title']);
    }

    /** AC-251: ein Push mit quittierten Inhalten und einer ganz ohne Inhalte werden bestätigt wie bisher. */
    public function testConfirmOfAnAcknowledgedPushIsUnchanged(): void
    {
        list($id, $commit) = $this->push($this->stage($this->rows()), 'new');
        $this->assertInstanceOf(\WP_REST_Response::class, $commit);
        $this->assertSame(PushRescue::CONTENT_APPLIED, $this->rescue($this->live, $id)['content']['state']);
        $confirm = Push::confirm(['push_id' => $id], self::KEY);
        $this->assertInstanceOf(\WP_REST_Response::class, $confirm);
        $this->assertSame(['ok' => true, 'status' => PushRescue::CONFIRMED], $confirm->data);
        // Eine verlorene Antwort lässt sich wiederholen.
        $this->assertSame(['ok' => true, 'status' => PushRescue::CONFIRMED], Push::confirm(['push_id' => $id], self::KEY)->data);

        list($code, $commit) = $this->push(null, 'newer');
        $this->assertInstanceOf(\WP_REST_Response::class, $commit);
        $this->assertNull($this->rescue($this->live, $code)['content']);
        $this->assertSame(['ok' => true, 'status' => PushRescue::CONFIRMED], Push::confirm(['push_id' => $code], self::KEY)->data);
    }

    /**
     * AC-252, H2: hat rescue.php Code und Uploads schon zurückgenommen und der DB-Anteil ist offen, nimmt
     * confirm die Inhalte an (content_kept) – auch bei pending. Kein Zustand ohne Ausgang.
     */
    public function testTheWayOutAfterRescueStays(): void
    {
        list($id, $commit) = $this->push($this->stage($this->rows()), 'new');
        $this->assertInstanceOf(\WP_REST_Response::class, $commit);
        $work   = $this->work($this->live);
        $record = $this->rescue($this->live, $id);
        // Wie nach rescue.php ohne Umschlag: Code zurück, Inhalte stehen, DB-Anteil offen.
        PushRescue::setContent($work, $id, PushRescue::CONTENT_PENDING);
        PushRescue::setStatus($work, $id, PushRescue::ROLLED_BACK);
        $this->assertSame(PushRescue::CONTENT_PENDING, $this->rescue($this->live, $id)['content']['state']);
        $this->assertNotNull($record);
        $confirm = Push::confirm(['push_id' => $id], self::KEY);
        $this->assertInstanceOf(\WP_REST_Response::class, $confirm, $confirm instanceof \WP_Error ? $confirm->code . ' ' . $confirm->message : '');
        $this->assertSame(['ok' => true, 'status' => PushRescue::ROLLED_BACK, 'warnings' => [PushRescue::CONTENT_KEPT]], $confirm->data);
    }
}
