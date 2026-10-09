<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use WpSync\Admin;
use WpSync\ContentImage;
use WpSync\Push;
use WpSync\PushRescue;
use WpSync\Store;

require_once __DIR__ . '/PushRescueFlowCase.php';

/**
 * Nach dem Wiederanlauf von WordPress (Spec Content-Push P3 §8; AC-165, AC-168): der Agent
 * übernimmt, was rescue.php zurückgenommen hat, holt die Nacharbeiten nach und räumt auf – und
 * der Umschlag lebt nur so lange, wie der Notfallweg ihn braucht (R14).
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PushSyncRescueTest extends PushRescueFlowCase
{
    /** Ein bestätigungsloser Push mit Code und Inhalten; liefert seine ID. */
    private function pushed(string $target = 'live'): string
    {
        list($id, $commit) = $this->push($this->stage($this->rows()), 'new', [], $target);
        $this->assertInstanceOf(\WP_REST_Response::class, $commit, $commit instanceof \WP_Error ? $commit->code . ' ' . $commit->message : '');
        $GLOBALS['wpsync_post_actions'] = []; // was der Commit an Nacharbeiten lief, zählt hier nicht
        return $id;
    }

    /** rescue.php läuft ohne WordPress: ohne den Schlüssel der Installation. Danach lädt WordPress wieder. */
    private function rescued(string $id): array
    {
        $keys               = ContentImage::$keys;
        ContentImage::$keys = [];
        $answer             = $this->rescueDb($id);
        ContentImage::$keys = $keys;
        $this->assertSame(200, $answer[0], json_encode($answer[1]));
        return $answer[1];
    }

    /** @return array<string, mixed> der Eintrag content im Protokoll des Pushs */
    private function contentUnit(string $id): array
    {
        foreach (Store::getPush($id)['units'] as $unit) {
            if ($unit['path'] === 'content') {
                return $unit;
            }
        }
        return [];
    }

    private function marker(string $content): string
    {
        return PushRescue::pendingFile($this->work($content));
    }

    /**
     * AC-168: nach dem nächsten Laden von WordPress ist der Push abgeschlossen, die Nacharbeiten
     * sind nachgeholt und im Protokoll vermerkt, Arbeitsordner, Abbilder, Umschlag und Marker sind weg.
     */
    public function testTheAgentCatchesUpOnThePostActionsOnceWordPressLoadsAgain(): void
    {
        $id = $this->pushed();
        $this->assertSame(['state' => 'rolled_back', 'cache' => 'none'], $this->rescued($id)['content']);
        $this->assertSame([], $GLOBALS['wpsync_post_actions'], 'rescue.php kennt kein WordPress');
        $this->assertFileExists($this->marker($this->live));
        $this->assertSame('committed', Store::getPush($id)['status'], 'die Datenbank weiss noch nichts davon');
        $this->assertSame('pending', $this->rescue($this->live, $id)['content']['post']);

        Push::catchUp(); // init, Priorität 1

        $push = Store::getPush($id);
        $this->assertSame(['rolled_back', true], [$push['status'], $push['pruned']]);
        $unit = $this->contentUnit($id);
        $this->assertSame('rescue', $unit['via']);
        $this->assertSame(['object_cache', 'rewrite_rules', 'revisions'], array_column($unit['post_actions'], 'step'), 'dieselben Schritte wie nach einer Rücknahme über den Agent');
        $this->assertSame([true, true, true], array_column($unit['post_actions'], 'ok'));
        $this->assertContains('clean_post_cache [219]', $GLOBALS['wpsync_post_actions']);
        $this->assertContains('clean_post_cache [1000001]', $GLOBALS['wpsync_post_actions']);
        $this->assertDirectoryDoesNotExist($this->work($this->live) . '/' . $id, 'Arbeitsordner, Abbilder und Umschlag sind weg');
        $this->assertFileDoesNotExist($this->marker($this->live));
        $this->assertNull(Push::pending());
        $this->assertNull(Store::getState('push_lock'));
        $this->assertStringContainsString('über rescue.php zurückgenommen, Nacharbeiten nachgeholt', Admin::unitLine($unit));

        // Ein zweiter Lauf findet nichts mehr zu tun.
        $GLOBALS['wpsync_post_actions'] = [];
        Push::catchUp();
        Push::sync();
        $this->assertSame([], $GLOBALS['wpsync_post_actions']);
    }

    /** §8.1: ohne Marker fragt init die Datenbank nicht – der Push wird erst beim nächsten sync() übernommen. */
    public function testWithoutTheMarkerInitDoesNothing(): void
    {
        $id = $this->pushed();
        $this->rescued($id);
        unlink($this->marker($this->live));
        Store::$dbError = true; // jede Abfrage fiele auf
        Push::catchUp();
        Store::$dbError = false;
        $this->assertSame('committed', Store::getPush($id)['status']);
        $this->assertSame([], $GLOBALS['wpsync_post_actions']);

        Push::sync(); // REST-Aufruf oder täglicher Cron
        $this->assertSame('rolled_back', Store::getPush($id)['status']);
        $this->assertSame('rescue', $this->contentUnit($id)['via']);
    }

    public function testInitIsHookedEarly(): void
    {
        $this->assertStringContainsString("add_action('init', [self::class, 'catchUp'], 1);", (string) file_get_contents(__DIR__ . '/../src/Push.php'));
    }

    /** Der Marker verschwindet, bevor sync() läuft: ein Fehler darin wiederholt sich nicht in jedem Request. */
    public function testTheMarkerGoesBeforeTheWorkStarts(): void
    {
        $id = $this->pushed();
        $this->rescued($id);
        Store::$dbError = true; // sync() sieht keinen Push
        Push::catchUp();
        Store::$dbError = false;
        $this->assertFileDoesNotExist($this->marker($this->live));
        $this->assertSame('committed', Store::getPush($id)['status']);
        Push::maintain(); // der Cron holt es nach
        $this->assertSame('rolled_back', Store::getPush($id)['status']);
    }

    /** §8.2: ein Fehlschlag der Nacharbeiten hält den Abschluss nie auf. */
    public function testAFailingPostActionNeverHoldsTheFinishUp(): void
    {
        $id = $this->pushed();
        $this->rescued($id);
        $GLOBALS['wpsync_post_actions_fail'] = ['clean_post_cache', 'delete_option'];
        Push::catchUp();
        $this->assertSame(['rolled_back', true], [Store::getPush($id)['status'], Store::getPush($id)['pruned']]);
        $steps = array_column($this->contentUnit($id)['post_actions'], 'ok', 'step');
        $this->assertFalse($steps['object_cache']);
        $this->assertStringContainsString('fehlgeschlagen', Admin::unitLine($this->contentUnit($id)));
    }

    /**
     * Die Nacharbeiten laufen höchstens einmal: „done“ steht in rescue.json, bevor sie beginnen. Starb
     * PHP mittendrin, schliesst der nächste Lauf den Push ab, ohne sie zu wiederholen.
     */
    public function testPostActionsThatWereStartedAreNotRepeated(): void
    {
        $id = $this->pushed();
        $this->rescued($id);
        PushRescue::setContentFields($this->work($this->live), $id, ['post' => PushRescue::POST_DONE]); // der Lauf davor kam bis hier
        Push::catchUp();
        $this->assertSame([], $GLOBALS['wpsync_post_actions']);
        $this->assertSame('rolled_back', Store::getPush($id)['status']);
        $unit = $this->contentUnit($id);
        $this->assertSame('rescue', $unit['via']);
        $this->assertArrayNotHasKey('post_actions', $unit);
    }

    /** §8.2 Nr. 1: ist after.json nicht lesbar, steht das im Protokoll – und auf Live wird wenigstens der Object-Cache geleert. */
    public function testWithoutAReadableAfterImageTheCacheIsFlushed(): void
    {
        eval('function wp_cache_flush(): bool { $GLOBALS["wpsync_post_actions"][] = "wp_cache_flush"; return true; }');
        $id = $this->pushed();
        $this->rescued($id);
        file_put_contents($this->work($this->live) . '/' . $id . '/content/after.json', 'kaputt');
        Push::catchUp();
        $this->assertSame([['step' => 'post_actions', 'ok' => false]], $this->contentUnit($id)['post_actions']);
        $this->assertSame(['wp_cache_flush'], $GLOBALS['wpsync_post_actions']);
        $this->assertSame('rolled_back', Store::getPush($id)['status']);
    }

    /** Kam die Transaktion des Pushs nie an (nothing), gibt es nichts nachzuholen. */
    public function testAPushWhoseContentNeverArrivedNeedsNoPostActions(): void
    {
        $old = $this->liveDb->data;
        $id  = $this->pushed();
        $this->liveDb->data = $old;
        $this->assertSame(['state' => 'nothing'], $this->rescued($id)['content']);
        Push::catchUp();
        $this->assertSame([], $GLOBALS['wpsync_post_actions']);
        $this->assertSame('rolled_back', Store::getPush($id)['status']);
        $unit = $this->contentUnit($id);
        $this->assertSame('rescue', $unit['via']);
        $this->assertArrayNotHasKey('post_actions', $unit);
    }

    /**
     * R7, §8.2: blieben die Inhalte stehen (kept), bleibt alles wie in P2 – Push offen, Vorher-Abbild
     * und Umschlag liegen, bis der Agent die Inhalte nachholt. Der Umschlag verfällt nach 24 Stunden (R14).
     */
    public function testKeptContentStaysOpenUntilTheAgentTakesItBack(): void
    {
        $old = $this->liveDb->data;
        $id  = $this->pushed();
        $this->liveDb->data['posts']['219']['post_title'] = 'nach dem Push geändert';
        $this->assertSame('changed_since_push', $this->rescued($id)['content']['error']['code']);

        Push::catchUp();
        Push::prune(time() + 30 * 86400);
        $dir = $this->work($this->live) . '/' . $id;
        $this->assertSame(['committed', false], [Store::getPush($id)['status'], Store::getPush($id)['pruned']]);
        $this->assertFileExists($dir . '/content/before.json');
        $this->assertFileExists($this->sealed($this->live, $id));
        $this->assertFileDoesNotExist($this->marker($this->live));
        $this->assertSame($id, Push::pending()['push_id']);
        $this->assertSame([], $GLOBALS['wpsync_post_actions']);

        // Jünger als 24 Stunden bleibt der Umschlag, danach räumt ihn der tägliche Cron weg – das Vorher-Abbild nie.
        touch($this->sealed($this->live, $id), time() - Push::RESCUE_DB_TTL + 60);
        Push::maintain();
        $this->assertFileExists($this->sealed($this->live, $id));
        touch($this->sealed($this->live, $id), time() - Push::RESCUE_DB_TTL - 60);
        Push::maintain();
        $this->assertFileDoesNotExist($this->sealed($this->live, $id));
        $this->assertFileExists($dir . '/content/before.json');

        // Der Ausweg wie in P2: die Zeile zurückstellen, dann nimmt der Agent die Inhalte zurück.
        $this->liveDb->data['posts']['219']['post_title'] = 'Neu';
        $back = Push::rollback(['push_id' => $id], self::KEY);
        $this->assertInstanceOf(\WP_REST_Response::class, $back, $back instanceof \WP_Error ? $back->code . ' ' . $back->message : '');
        ksort($old['postmeta']);
        ksort($this->liveDb->data['postmeta']);
        $this->assertSame($old, $this->liveDb->data);
        $this->assertSame('rolled_back', Store::getPush($id)['status']);
        $this->assertArrayNotHasKey('via', $this->contentUnit($id), 'über den Agent, nicht über rescue.php');
    }

    /** AC-165: nach confirm liegt kein Umschlag mehr da, und rescue.php lehnt ab, ohne die Datenbank zu berühren. */
    public function testConfirmDeletesTheEnvelope(): void
    {
        $id = $this->pushed();
        $this->assertFileExists($this->sealed($this->live, $id));
        $this->assertInstanceOf(\WP_REST_Response::class, Push::confirm(['push_id' => $id], self::KEY));
        $this->assertFileDoesNotExist($this->sealed($this->live, $id));
        $this->assertFileExists($this->work($this->live) . '/' . $id . '/content/before.json', 'das Vorher-Abbild bleibt für die Rücknahme über den Agent');
        $pushed          = $this->liveDb->data;
        $this->connected = 0;
        $this->assertSame([409, ['ok' => false, 'error' => 'confirmed']], $this->rescueDb($id));
        $this->assertSame(0, $this->connected);
        $this->assertSame($pushed, $this->liveDb->data);
    }

    /** D24 nach R7: confirm nimmt die Inhalte an, wie sie stehen – der Umschlag geht mit dem Arbeitsordner. */
    public function testConfirmAfterKeptContentRemovesTheEnvelopeWithTheFolder(): void
    {
        $id = $this->pushed();
        $this->liveDb->data['posts']['219']['post_title'] = 'nach dem Push geändert';
        $this->rescued($id);
        $done = Push::confirm(['push_id' => $id], self::KEY);
        $this->assertSame(['ok' => true, 'status' => 'rolled_back', 'warnings' => ['content_kept']], $done->data);
        $this->assertDirectoryDoesNotExist($this->work($this->live) . '/' . $id);
    }

    /** Eine Zeile liess sich nach einem Verbindungsverlust nicht zurücksetzen: der Arbeitsordner bleibt, der Umschlag nicht. */
    public function testAFailedCommitWithAnUnrestoredRowForgetsTheEnvelope(): void
    {
        $this->liveDb->loseAtWrite = 1;
        $this->liveDb->failWrite   = 2;
        list($id, $commit)         = $this->push($this->stage($this->rows()), 'new');
        $this->assertRefused('wpsync_content_content_failed', 500, $commit);
        $this->assertFileExists($this->work($this->live) . '/' . $id . '/content/before.json');
        $this->assertFileDoesNotExist($this->sealed($this->live, $id));
    }

    /** §8.1: der Marker eines Pushs nach Staging liegt in der Kopie; ihn übernimmt der nächste REST-Aufruf (push/list). */
    public function testAStagingPushIsCaughtUpByTheNextRestCall(): void
    {
        $id = $this->pushed('staging');
        $this->rescued($id);
        $this->assertFileExists($this->marker($this->staging));
        $this->assertFileDoesNotExist($this->marker($this->live));
        Push::catchUp();
        $this->assertSame('committed', Store::getPush($id)['status'], 'init sieht nur in den Arbeitsordner von Live');

        $list = Push::index();
        $this->assertSame('rolled_back', $list->data['pushes'][0]['status']);
        $unit = $this->contentUnit($id);
        $this->assertSame('rescue', $unit['via']);
        $this->assertSame(['rewrite_rules'], array_column($unit['post_actions'], 'step'), 'die Nacharbeiten der Kopie');
        $this->assertSame([], $GLOBALS['wpsync_post_actions'], 'keine WordPress-Funktion von Live für die Kopie');
        $this->assertFileDoesNotExist($this->marker($this->staging));
        $this->assertDirectoryDoesNotExist($this->work($this->staging) . '/' . $id);
    }

    /** R15: was an eingefügten Objekten stehen blieb, steht im Protokoll des Pushs. */
    public function testWhatWasLeftIsNotedInThePushLog(): void
    {
        $id = $this->pushed();
        $this->liveDb->data['postmeta']["1000001\0farbe"] = ['values' => ['rot']];
        $body = $this->rescued($id);
        $this->assertSame(['content_left_extra'], $body['warnings']);
        Push::catchUp();
        $unit = $this->contentUnit($id);
        $this->assertSame([[['table' => 'postmeta', 'key' => "1000001\0farbe"]], 1], [$unit['left'], $unit['left_total']]);
        $this->assertStringContainsString('1 fremde Stelle(n) an eingefügten Objekten blieben stehen', Admin::unitLine($unit));
    }

    /** R14: auch in der Kopie verfällt der Umschlag; ein frischer bleibt. */
    public function testEnvelopesExpireOnBothTargets(): void
    {
        $live = $this->pushed();
        Push::confirm(['push_id' => $live], self::KEY); // sonst blockierte er den nächsten Push
        $stg = $this->pushed('staging');
        $this->assertFileExists($this->sealed($this->staging, $stg));
        Push::maintain();
        $this->assertFileExists($this->sealed($this->staging, $stg));
        touch($this->sealed($this->staging, $stg), time() - Push::RESCUE_DB_TTL - 60);
        Push::maintain();
        $this->assertFileDoesNotExist($this->sealed($this->staging, $stg));
        $this->assertFileExists($this->work($this->staging) . '/' . $stg . '/content/before.json');
        // Ohne Umschlag bleibt der Notfallweg der von P2.
        $this->assertSame(['state' => 'kept', 'error' => ['code' => 'rescue_db_unavailable']], $this->rescued($stg)['content']);
    }
}
