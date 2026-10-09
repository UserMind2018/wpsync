<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use WpSync\ContentException;
use WpSync\ContentImage;
use WpSync\ContentRollback;

require_once __DIR__ . '/ContentRollbackPluginsCase.php';

/**
 * Was die Rücknahme tut, wenn zwischen Push und Rücknahme jemand anders an der Liste war (Spec
 * Content-Push P4 A8, A21, §8.3, §14 Nr. 7–9; AC-185, AC-204) – und wenn nicht feststeht, ob der
 * COMMIT des Pushs überhaupt ankam (Annahme V1 des Plans).
 */
final class ContentRollbackPluginsChangedTest extends ContentRollbackPluginsCase
{
    private const OPTION_KEY = [['table' => 'options', 'key' => 'active_plugins']];

    /** AC-185: ein Plugin, das seit dem Push im WP-Admin eingeschaltet wurde, bleibt eingeschaltet. */
    public function testAForeignActivationStays(): void
    {
        $this->push(null, ['kunde/kunde.php'], []);
        $this->adminActivates('fremd/fremd.php');
        $back = $this->back();
        $this->assertSame(ContentRollback::DONE, $back['state']);
        $this->assertSame(['deactivated' => ['kunde/kunde.php'], 'reactivated' => []], $back['plugins']);
        $this->assertSame(['akismet/akismet.php', 'fremd/fremd.php', 'old/old.php'], $this->active());
    }

    /** AC-185: nach einem deactivate_plugins() im WP-Admin hat die Liste Lücken in den Schlüsseln – die Rücknahme gelingt trotzdem. */
    public function testAForeignDeactivationWithGapsStays(): void
    {
        $this->push(null, ['kunde/kunde.php'], []);
        $this->adminDeactivates('akismet/akismet.php');
        $this->assertSame('a:2:{i:1;s:15:"kunde/kunde.php";i:2;s:11:"old/old.php";}', $this->value(), 'so hinterlässt es der Core');
        $back = $this->back();
        $this->assertSame(['deactivated' => ['kunde/kunde.php'], 'reactivated' => []], $back['plugins']);
        $this->assertSame(['old/old.php'], $this->active(), 'das fremd abgeschaltete Plugin bleibt aus');
    }

    /** A21, AC-204: hat jemand selbst schon zurückgeschaltet, ist nichts zu tun – kein Byte der Liste ändert sich. */
    public function testWhatSomeoneSwitchedBackIsLeftAlone(): void
    {
        $this->push(null, ['kunde/kunde.php'], []);
        $this->adminDeactivates('kunde/kunde.php');
        $gaps             = $this->value();
        $this->store->log = [];
        $this->assertSame(['state' => ContentRollback::NOTHING, 'changes' => null, 'plugins' => ['deactivated' => [], 'reactivated' => []]], $this->back());
        $this->assertSame($gaps, $this->value());
        $this->assertSame([], preg_grep('/^write/', $this->store->log));

        $two = $this->otherDir('zwei');
        $this->push(null, [], ['old'], $two);
        $this->adminActivates('old/old.php');
        $this->assertSame(ContentRollback::NOTHING, $this->back(false, true, $two)['state']);
        $this->assertSame(['akismet/akismet.php', 'old/old.php'], $this->active());
    }

    /** A21: ein anderes Plugin wurde geschaltet, das eigene Delta ist noch offen – beides bleibt, wie es gemeint ist. */
    public function testADeactivationGoesBackNextToAForeignChange(): void
    {
        $this->push(null, [], ['old']);
        $this->adminActivates('fremd/fremd.php');
        $this->adminDeactivates('akismet/akismet.php');
        $back = $this->back();
        $this->assertSame(['deactivated' => [], 'reactivated' => ['old/old.php']], $back['plugins']);
        $this->assertSame(['fremd/fremd.php', 'old/old.php'], $this->active());
    }

    /** AC-185: fremde Änderungen an der Liste sperren die Rücknahme der Paketzeilen nie. */
    public function testHandChangesToTheListNeverBlockTheRows(): void
    {
        $rows = $this->rows();
        $old  = $this->store->data;
        $this->push($rows, ['kunde/kunde.php'], []);
        $this->adminActivates('fremd/fremd.php');

        $this->assertSame(ContentRollback::DONE, $this->back()['state']);
        $now = $this->store->data;
        unset($old['options']['active_plugins'], $now['options']['active_plugins']);
        $this->assertSame(self::sorted($old), self::sorted($now), 'jede Zeile des Pakets steht wie vor dem Push');
        $this->assertSame(['akismet/akismet.php', 'fremd/fremd.php', 'old/old.php'], $this->active());
    }

    /** §8.3 Nr. 2, §14 Nr. 8: ist eine Paketzeile geändert, bleibt der Satz ganz – auch der Plugin-Zustand. */
    public function testAChangedRowOfThePackageKeepsThePluginStateToo(): void
    {
        $this->push($this->rows(), ['kunde/kunde.php'], ['old']);
        $this->store->data['posts']['219']['post_title'] = 'nach dem Push geändert';
        $e = $this->refused();
        $this->assertSame(ContentException::CHANGED, $e->reason());
        $this->assertSame([['table' => 'posts', 'key' => '219']], $e->keys(), 'genannt wird die Zeile, nicht die Liste');
        $this->assertSame(['akismet/akismet.php', 'kunde/kunde.php'], $this->active());
    }

    /** @return array<string, array{0: list<string>}> */
    public static function orders(): array
    {
        return ['der spätere zuerst' => [['zwei', 'eins']], 'der frühere zuerst' => [['eins', 'zwei']]];
    }

    /** AC-185: zwei Pushes mit je einer Aktivierung lassen sich in jeder Reihenfolge zurücknehmen. */
    #[DataProvider('orders')]
    public function testTwoPushesGoBackInAnyOrder(array $order): void
    {
        $old  = $this->store->data;
        $dirs = ['eins' => $this->otherDir('eins'), 'zwei' => $this->otherDir('zwei')];
        $this->push(null, ['a/a.php'], [], $dirs['eins']);
        $this->push(null, ['b/b.php'], [], $dirs['zwei']);
        $this->assertSame(['a/a.php', 'akismet/akismet.php', 'b/b.php', 'old/old.php'], $this->active());

        $this->assertSame(ContentRollback::DONE, $this->back(false, true, $dirs[$order[0]])['state']);
        $this->assertCount(3, $this->active());
        $this->assertSame(ContentRollback::DONE, $this->back(false, true, $dirs[$order[1]])['state']);
        $this->assertSame($old, $this->store->data);
    }

    /** V3: stehen die Zeilen schon im Vorher-Zustand, die Liste aber nicht, geht nur die Liste zurück – und umgekehrt. */
    public function testOnlyWhatIsStillOpenIsWritten(): void
    {
        $row = ContentFixtures::row('update', 'options', 'blogname', $this->h('options', 'blogname'), ['option_value' => 'Kunde GmbH']);
        $this->push([$row], ['kunde/kunde.php'], []);
        $this->store->data['options']['blogname']['option_value'] = 'Kunde'; // von Hand zurückgestellt
        $this->store->log = [];
        $back = $this->back();
        $this->assertSame(ContentRollback::DONE, $back['state']);
        $this->assertSame(['deactivated' => ['kunde/kunde.php'], 'reactivated' => []], $back['plugins']);
        $this->assertSame(['begin', 'write options:active_plugins', 'commit'], $this->store->log, 'die Zeile, die schon stimmt, wird nicht angefasst');

        $two = $this->otherDir('zwei');
        $row = ContentFixtures::row('update', 'options', 'blogname', $this->h('options', 'blogname'), ['option_value' => 'Kunde AG']);
        $this->push([$row], ['kunde/kunde.php'], [], $two);
        $this->adminDeactivates('kunde/kunde.php'); // die Liste steht schon wie vor dem Push
        $this->store->log = [];
        $back = $this->back(false, true, $two);
        $this->assertSame(ContentRollback::DONE, $back['state']);
        $this->assertSame(['deactivated' => [], 'reactivated' => []], $back['plugins']);
        $this->assertSame('Kunde', $this->store->data['options']['blogname']['option_value']);
        $this->assertNotContains('write options:active_plugins', $this->store->log);
    }

    /** V1: ist der COMMIT nicht quittiert, die Liste aber bytegleich die des Pushs, geht alles zurück. */
    public function testAnUnacknowledgedCommitInExactlyThePushedStateGoesBack(): void
    {
        $old = $this->store->data;
        $this->push(null, ['kunde/kunde.php'], ['old']);
        $back = $this->back(false, false);
        $this->assertSame(ContentRollback::DONE, $back['state']);
        $this->assertSame(['deactivated' => ['kunde/kunde.php'], 'reactivated' => ['old/old.php']], $back['plugins']);
        $this->assertSame($old, $this->store->data);
    }

    /** V1: ist der COMMIT nicht quittiert und die Liste seither anders, zählt wie bei jeder Zeile der Abdruck. */
    public function testAnUnacknowledgedCommitWithAForeignChangeIsRefused(): void
    {
        $this->push(null, ['kunde/kunde.php'], []);
        $this->adminActivates('fremd/fremd.php');
        $e = $this->refused(false, false);
        $this->assertSame(ContentException::CHANGED, $e->reason());
        $this->assertSame(self::OPTION_KEY, $e->keys());
        // Sobald feststeht, dass der Push ankam (content.state = applied), gilt das Delta.
        $this->assertSame(ContentRollback::DONE, $this->back(false, true)['state']);
        $this->assertSame(['akismet/akismet.php', 'fremd/fremd.php', 'old/old.php'], $this->active());
    }

    /**
     * V1, der Fall, für den es die Regel gibt: die Transaktion des Pushs kam nie an (ROLLBACK an der
     * Naht, die Abbilder liegen schon). Schaltet danach ein Administrator dasselbe Plugin selbst ein,
     * nimmt ihm die Rücknahme dieses Pushs nichts weg.
     */
    public function testATransactionThatNeverArrivedTakesNothingFromAnAdministrator(): void
    {
        try {
            $this->push(null, ['kunde/kunde.php'], [], null, static function (): bool {
                return false; // rescue.php kam dazwischen: ROLLBACK
            });
            $this->fail('die Naht hat nicht abgelehnt');
        } catch (ContentException $e) {
            $this->assertSame(ContentException::FAILED, $e->reason());
        }
        $this->assertNotNull(ContentImage::get($this->dir, ContentImage::AFTER), 'beide Abbilder liegen, obwohl nichts festgeschrieben ist');
        $this->assertSame(self::ACTIVE, $this->active());
        $this->assertSame(ContentRollback::NOTHING, $this->back(false, false)['state'], 'unberührt: nichts zu tun');

        $this->adminActivates('kunde/kunde.php');
        $this->adminActivates('fremd/fremd.php');
        $e = $this->refused(false, false);
        $this->assertSame([ContentException::CHANGED, self::OPTION_KEY], [$e->reason(), $e->keys()]);
        $this->assertSame(['akismet/akismet.php', 'fremd/fremd.php', 'kunde/kunde.php', 'old/old.php'], $this->active(), 'sein Plugin bleibt eingeschaltet');
    }

    /** V1, D10: ohne Nachher-Abbild lässt sich nicht beweisen, dass die Änderung der Liste vom Push stammt. */
    public function testWithoutAnAfterImageAnUnacknowledgedDeltaIsRefused(): void
    {
        $this->push(null, ['kunde/kunde.php'], []);
        unlink($this->dir . '/' . ContentImage::AFTER);
        $e = $this->refused(false, false);
        $this->assertSame([ContentException::CHANGED, self::OPTION_KEY], [$e->reason(), $e->keys()]);
        // Quittiert geht das Delta auch ohne Nachher-Abbild zurück – nur die Nacharbeiten kennt dann niemand.
        $this->assertSame(['state' => ContentRollback::DONE, 'changes' => null, 'plugins' => ['deactivated' => ['kunde/kunde.php'], 'reactivated' => []]], $this->back(false, true));
    }

    /** Geht die Verbindung beim Schreiben der Liste verloren, steht danach wieder der gepushte Stand – der Satz bleibt ganz. */
    public function testALostConnectionAtTheListKeepsThePushedState(): void
    {
        $this->push(null, ['kunde/kunde.php'], ['old']);
        $this->store              = new ContentMemory($this->store->data); // Zähler von vorn
        $pushed                   = $this->store->data;
        $this->store->loseAtWrite = 1;
        try {
            $this->back();
            $this->fail('nicht gescheitert');
        } catch (ContentException $e) {
            $this->assertSame(ContentException::FAILED, $e->reason());
            $this->assertArrayNotHasKey('unrestored', $e->toArray());
        }
        $this->assertSame($pushed, $this->store->data);
    }

    /** Geht die Verbindung im COMMIT der Rücknahme verloren, entscheidet die Liste, ob er ankam. */
    public function testAnUnclearCommitOfTheRollbackIsDecidedByTheList(): void
    {
        $old = $this->store->data;
        $this->push(null, ['kunde/kunde.php'], ['old']);
        $pushed = $this->store->data;

        $this->store               = new ContentMemory($pushed);
        $this->store->loseAtCommit = 'landed';
        $back                      = $this->back();
        $this->assertSame(ContentRollback::DONE, $back['state']);
        $this->assertSame(['deactivated' => ['kunde/kunde.php'], 'reactivated' => ['old/old.php']], $back['plugins']);
        $this->assertSame($old, $this->store->data);

        $this->store               = new ContentMemory($pushed);
        $this->store->loseAtCommit = 'discarded';
        try {
            $this->back();
            $this->fail('ein verworfener COMMIT gilt nicht als Rücknahme');
        } catch (ContentException $e) {
            $this->assertSame(ContentException::FAILED, $e->reason());
        }
        $this->assertSame($pushed, $this->store->data);
    }

    /** R15 mit Plugin-Zustand: rescue.php lässt Fremdes an eingefügten Objekten stehen und schaltet die Liste trotzdem zurück. */
    public function testTheEmergencyWayLeavesWhatGrewAndStillSwitchesTheList(): void
    {
        $this->push($this->rows(), ['kunde/kunde.php'], ['old']);
        $this->store->data['postmeta']["1000001\0farbe"] = ['values' => ['rot']];

        $e = $this->refused(); // über den Agent: nichts wird zurückgenommen – auch die Liste nicht
        $this->assertSame(ContentException::CHANGED, $e->reason());
        $this->assertSame(['akismet/akismet.php', 'kunde/kunde.php'], $this->active());

        $back = $this->back(true);
        $this->assertSame(ContentRollback::DONE, $back['state']);
        $this->assertSame([['table' => 'postmeta', 'key' => "1000001\0farbe"]], $back['left']);
        $this->assertSame(['deactivated' => ['kunde/kunde.php'], 'reactivated' => ['old/old.php']], $back['plugins']);
        $this->assertSame(self::ACTIVE, $this->active());
        $this->assertArrayHasKey("1000001\0farbe", $this->store->data['postmeta'], 'das Fremde steht noch');
    }

    /**
     * Ergänzt beim Umsetzen (nicht im Plan): steht ein Eintrag des Pushs mehrfach in der Liste – der Core
     * verhindert es nicht, ein fremdes Skript kann es hinterlassen –, geht jedes Vorkommen, und was der
     * Push strich, kommt genau einmal zurück.
     */
    public function testDuplicateEntriesInTheListAreAllTakenBack(): void
    {
        $this->push(null, ['kunde/kunde.php'], ['old']);
        $this->store->data['options']['active_plugins']['option_value'] = serialize(
            ['kunde/kunde.php', 'akismet/akismet.php', 'kunde/kunde.php', 'akismet/akismet.php']
        );
        $back = $this->back();
        $this->assertSame(['deactivated' => ['kunde/kunde.php'], 'reactivated' => ['old/old.php']], $back['plugins']);
        $this->assertSame(['akismet/akismet.php', 'akismet/akismet.php', 'old/old.php'], $this->active(), 'das fremde Doppel bleibt, wie es ist');
        $this->assertSame(1, substr_count($this->value(), 'old/old.php'));
    }

    /** Ergänzt: eine Rücknahme zweimal – mit Paketzeilen und beiden Richtungen – schreibt beim zweiten Mal nichts. */
    public function testASecondRollbackOfRowsAndListWritesNothing(): void
    {
        $old = $this->store->data;
        $this->push($this->rows(), ['kunde/kunde.php'], ['old']);
        $this->assertSame(ContentRollback::DONE, $this->back()['state']);
        $this->assertSame(self::sorted($old), self::sorted($this->store->data));
        $stands           = $this->store->data;
        $this->store->log = [];
        foreach ([true, false] as $landed) {
            $again = $this->back(false, $landed);
            $this->assertSame([ContentRollback::NOTHING, ['deactivated' => [], 'reactivated' => []]], [$again['state'], $again['plugins']]);
        }
        $this->assertSame([], preg_grep('/^(write|delete)/', $this->store->log));
        $this->assertSame($stands, $this->store->data);
    }

    /**
     * Ergänzt: nach der Rücknahme schaltet ein Administrator das Plugin des Pushs selbst wieder ein. Eine
     * Wiederholung derselben Rücknahme nähme es ihm erneut – deshalb merkt sich der Aufrufer, dass sie
     * gelaufen ist (rescue.json: content.state, Task 7); hier steht fest, was die Regel allein tut.
     */
    public function testARepeatedRollbackFollowsTheDeltaAgain(): void
    {
        $this->push(null, ['kunde/kunde.php'], []);
        $this->assertSame(ContentRollback::DONE, $this->back()['state']);
        $this->adminActivates('kunde/kunde.php');
        $back = $this->back();
        $this->assertSame(['deactivated' => ['kunde/kunde.php'], 'reactivated' => []], $back['plugins'], 'das Delta kennt keinen Urheber');
        // Unquittiert dagegen nur, wenn der Wert bytegleich der des Pushs ist – mit einer fremden Änderung daneben nicht.
        $this->adminActivates('kunde/kunde.php');
        $this->adminActivates('fremd/fremd.php');
        $this->assertSame(ContentException::CHANGED, $this->refused(false, false)->reason());
    }

    /** Ergänzt: von Hand entfernt und wieder hinzugefügt – der Eintrag steht da, also geht er; Lücken stören nicht. */
    public function testAnEntryRemovedAndAddedAgainByHandStillGoes(): void
    {
        $this->push(null, ['kunde/kunde.php'], ['old']);
        $this->adminDeactivates('kunde/kunde.php');
        $this->adminActivates('old/old.php');   // von Hand zurück: nichts mehr offen
        $this->assertSame(ContentRollback::NOTHING, $this->back()['state']);
        $this->adminActivates('kunde/kunde.php');
        $this->adminDeactivates('akismet/akismet.php');
        $back = $this->back();
        $this->assertSame(['deactivated' => ['kunde/kunde.php'], 'reactivated' => []], $back['plugins']);
        $this->assertSame(['old/old.php'], $this->active());
    }

    /** Ergänzt (V1): unquittiert und von Hand schon zurückgeschaltet – nichts offen, also auch keine Ablehnung. */
    public function testAnUnacknowledgedCommitWithNothingOpenIsNotRefused(): void
    {
        $this->push(null, ['kunde/kunde.php'], []);
        $this->adminDeactivates('kunde/kunde.php');
        $this->adminActivates('fremd/fremd.php');
        $stands = $this->store->data;
        $this->assertSame(ContentRollback::NOTHING, $this->back(false, false)['state']);
        $this->assertSame($stands, $this->store->data);
    }

    /** Ergänzt (V1): unquittiert, mit Paketzeilen – steht alles bytegleich wie gepusht, geht der ganze Satz zurück. */
    public function testAnUnacknowledgedCommitWithRowsInThePushedStateGoesBack(): void
    {
        $old = $this->store->data;
        $this->push($this->rows(), ['kunde/kunde.php'], ['old']);
        $back = $this->back(false, false);
        $this->assertSame(ContentRollback::DONE, $back['state']);
        $this->assertSame(self::sorted($old), self::sorted($this->store->data));
    }

    /**
     * Ergänzt (V1): unquittiert, die Paketzeilen stehen wie gepusht, die Liste ist fremd geändert – der Satz
     * bleibt ganz: auch keine Paketzeile geht zurück.
     */
    public function testAnUnacknowledgedForeignListChangeKeepsTheRowsToo(): void
    {
        $this->push($this->rows(), ['kunde/kunde.php'], []);
        $this->adminActivates('fremd/fremd.php');
        $e = $this->refused(false, false);
        $this->assertSame([ContentException::CHANGED, self::OPTION_KEY], [$e->reason(), $e->keys()]);
        $this->assertSame('Neu', $this->store->data['posts']['219']['post_title']);
    }

    /** @return array<string, array{0: mixed}> */
    public static function badPrints(): array
    {
        return ['fehlt' => [null], 'keine Zeichenkette' => [7], 'kein Hash' => ['xyz'], 'zu kurz' => [str_repeat('a', 63)], 'gross' => [str_repeat('A', 64)]];
    }

    /** Ergänzt (V1): ein Abdruck in after.json, der kein sha256 ist, beweist nichts – unquittiert wird abgelehnt. */
    #[DataProvider('badPrints')]
    public function testAnUnusablePrintProvesNothing($h): void
    {
        $result = $this->push(null, ['kunde/kunde.php'], []);
        $changes = $result['changes'];
        $changes['plugins']['h'] = $h;
        ContentImage::put($this->dir, ContentImage::AFTER, ['keys' => [], 'changes' => $changes]);
        $e = $this->refused(false, false);
        $this->assertSame([ContentException::CHANGED, self::OPTION_KEY], [$e->reason(), $e->keys()]);
    }

    /** Ergänzt: geht die Verbindung im COMMIT einer Rücknahme mit Paketzeilen verloren, entscheiden Zeilen und Liste zusammen. */
    public function testAnUnclearCommitWithRowsIsDecidedByRowsAndList(): void
    {
        $old = $this->store->data;
        $this->push($this->rows(), ['kunde/kunde.php'], ['old']);
        $pushed = $this->store->data;

        $this->store               = new ContentMemory($pushed);
        $this->store->loseAtCommit = 'landed';
        $this->assertSame(ContentRollback::DONE, $this->back()['state']);
        $this->assertSame(self::sorted($old), self::sorted($this->store->data));

        $this->store               = new ContentMemory($pushed);
        $this->store->loseAtCommit = 'discarded';
        try {
            $this->back();
            $this->fail('ein verworfener COMMIT gilt nicht als Rücknahme');
        } catch (ContentException $e) {
            $this->assertSame(ContentException::FAILED, $e->reason());
        }
        $this->assertSame($pushed, $this->store->data);
    }
}
