<?php
declare(strict_types=1);

namespace WpSync\Tests;

use WpSync\ContentException;
use WpSync\ContentRollback;

require_once __DIR__ . '/ContentApplyCase.php';

/** Rücknahme des DB-Anteils (Spec Content-Push §7.6; AC-153) gegen den Store im Speicher. */
final class ContentRollbackTest extends ContentApplyCase
{
    /** AC-153: die Rücknahme stellt das Vorher-Abbild her, löscht Eingefügtes samt Anhang, holt aus dem Papierkorb. */
    public function testRollbackRestoresEverything(): void
    {
        $old    = $this->store->data;
        $result = $this->apply($this->rows());
        // Nach dem Push hängt WordPress etwas an den neuen Beitrag – das geht mit ihm.
        $this->store->data['postmeta']["1000001\0_edit_lock"] = ['values' => ['1:1']];
        $this->store->log = [];

        $back = ContentRollback::run(ContentFixtures::live($this->store), $this->dir);
        $this->assertSame(ContentRollback::DONE, $back['state']);
        $this->assertSame($result['changes'], $back['changes']);
        $this->assertSame(self::sorted($old), self::sorted($this->store->data));
        $this->assertContains('purge posts:1000001', $this->store->log);
        $this->assertContains('posts:219', $this->store->locked);
        $this->assertSame('commit', $this->store->log[count($this->store->log) - 1]);

        $again = ContentRollback::run(ContentFixtures::live($this->store), $this->dir);
        $this->assertSame(ContentRollback::NOTHING, $again['state'], 'ein zweiter Lauf findet alles im Vorher-Zustand');
    }

    /** AC-153: nach einer Änderung seit dem Push wird nichts zurückgenommen – alle geänderten Schlüssel genannt. */
    public function testChangedSincePush(): void
    {
        $this->apply($this->rows());
        $this->store->data['posts']['219']['post_title'] = 'nach dem Push im WP-Admin geändert';
        unset($this->store->data['posts']['1000001']);
        $pushed = $this->store->data;
        try {
            ContentRollback::run(ContentFixtures::live($this->store), $this->dir);
            $this->fail('no exception');
        } catch (ContentException $e) {
            $this->assertSame(ContentException::CHANGED, $e->reason());
            $this->assertSame([['table' => 'posts', 'key' => '219'], ['table' => 'posts', 'key' => '1000001']], $e->keys());
        }
        $this->assertSame($pushed, $this->store->data);
    }

    /** Was sich im Abdruck nicht zeigt (post_modified, Autor), hindert die Rücknahme nicht. */
    public function testRollbackIgnoresNoise(): void
    {
        $old = $this->store->data;
        $this->apply($this->rows());
        $this->store->data['posts']['219']['post_modified'] = '2027-01-01 00:00:00';
        $this->assertSame(ContentRollback::DONE, ContentRollback::run(ContentFixtures::live($this->store), $this->dir)['state']);
        $this->assertSame(self::sorted($old), self::sorted($this->store->data));
    }

    /** Kam die Transaktion nie an, ist nichts zu tun – mit und ohne Vorher-Abbild. */
    public function testRollbackOfAPushThatNeverArrived(): void
    {
        $this->assertSame(ContentRollback::NOTHING, ContentRollback::run(ContentFixtures::live($this->store), $this->dir)['state']);

        $old                    = $this->store->data;
        $this->store->failWrite = 5;
        try {
            $this->apply($this->rows());
        } catch (ContentException $e) {
            // before.json liegt, geschrieben wurde nichts
        }
        $this->store->log = [];
        $this->assertSame(ContentRollback::NOTHING, ContentRollback::run(ContentFixtures::live($this->store), $this->dir)['state']);
        $this->assertSame($old, $this->store->data);
        $this->assertSame([], preg_grep('/^(write|delete|purge) /', $this->store->log));
    }

    public function testRollbackWithADamagedBeforeImage(): void
    {
        $this->apply($this->rows());
        $pushed = $this->store->data;
        file_put_contents($this->dir . '/before.json', '{"keys":[{"t":"users","k":"1","state":null}]}');
        try {
            ContentRollback::run(ContentFixtures::live($this->store), $this->dir);
            $this->fail('no exception');
        } catch (ContentException $e) {
            $this->assertSame(ContentException::FAILED, $e->reason());
        }
        $this->assertSame($pushed, $this->store->data);
    }

    /** Fehlt after.json, obwohl sich Zeilen geändert haben, ist nicht zu beweisen, dass es der Push war. */
    public function testRollbackWithoutAfterImage(): void
    {
        $this->apply($this->rows());
        unlink($this->dir . '/after.json');
        $this->expectException(ContentException::class);
        ContentRollback::run(ContentFixtures::live($this->store), $this->dir);
    }

    /** @return array<string, array{0: int}> */
    public static function lostWrites(): array
    {
        return ['erster' => [1], 'mittendrin' => [6], 'eingefügter Beitrag' => [13], 'letzter' => [15]];
    }

    /**
     * Wie beim Anwenden: verliert die Rücknahme ihre Verbindung, bleibt der Satz ganz – der eine
     * Schlüssel, dessen Schreibzugriff für sich lief, geht auf den gepushten Stand zurück.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('lostWrites')]
    public function testALostConnectionWhileRollingBackKeepsThePushedState(int $n): void
    {
        $this->apply($this->rows());
        $pushed                   = $this->store->data;
        $this->store->log         = [];
        $this->store->loseAtWrite = 15 + $n; // das Anwenden hat 15-mal geschrieben
        try {
            ContentRollback::run(ContentFixtures::live($this->store), $this->dir);
            $this->fail('no exception');
        } catch (ContentException $e) {
            $this->assertSame(ContentException::FAILED, $e->reason());
            $this->assertSame([], $e->keys());
        }
        $this->assertSame(self::sorted($pushed), self::sorted($this->store->data));
        $log  = $this->store->log;
        $lost = (int) array_search('lost', $log, true);
        $this->assertSame(1, preg_match('/^(?:write|delete) (.+)\z/s', $log[$lost + 1], $m));
        $this->assertSame(['rollback', 'begin'], array_slice($log, $lost + 2, 2));
        $repair = array_slice($log, $lost + 4);
        $this->assertCount(2, $repair, implode(', ', $repair));
        $this->assertMatchesRegularExpression('/^(write|delete) ' . preg_quote($m[1], '/') . '\z/', $repair[0]);
    }

    public function testALostConnectionBeforeTheFirstWriteOfARollback(): void
    {
        $this->apply($this->rows());
        $pushed                  = $this->store->data;
        $this->store->log        = [];
        $this->store->beforeLock = static function (ContentMemory $store): void {
            $store->lose();
        };
        try {
            ContentRollback::run(ContentFixtures::live($this->store), $this->dir);
            $this->fail('no exception');
        } catch (ContentException $e) {
            $this->assertSame(ContentException::FAILED, $e->reason());
        }
        $this->assertSame($pushed, $this->store->data);
        $this->assertSame([], preg_grep('/^(write|delete|purge|commit)/', $this->store->log));
    }

    public function testALostConnectionAtTheCommitOfARollbackIsLookedUp(): void
    {
        $old = $this->store->data;
        $this->apply($this->rows());
        $pushed                    = $this->store->data;
        $this->store->loseAtCommit = 'discarded';
        try {
            ContentRollback::run(ContentFixtures::live($this->store), $this->dir);
            $this->fail('no exception');
        } catch (ContentException $e) {
            $this->assertSame(ContentException::FAILED, $e->reason());
        }
        $this->assertSame($pushed, $this->store->data);

        $this->store->loseAtCommit = 'landed';
        $this->assertSame(ContentRollback::DONE, ContentRollback::run(ContentFixtures::live($this->store), $this->dir)['state']);
        $this->assertSame(self::sorted($old), self::sorted($this->store->data));
    }

    /**
     * Die Rücknahme schreibt nur zurück, was der Push geschrieben hat: die Spalten des Abdrucks und
     * post_modified. Was sich daneben seit dem Push getan hat – ein Kommentar, ein anderer Autor,
     * autoload einer Option –, bleibt und hindert die Rücknahme nicht.
     */
    public function testRollbackLeavesWhatThePushNeverWrote(): void
    {
        $old = $this->store->data;
        $this->apply($this->rows());
        $this->store->data['posts']['219']['comment_count'] = '3';
        $this->store->data['posts']['219']['post_author']   = '9';
        $this->store->data['posts']['219']['guid']          = 'https://kunde.de/?p=219&neu';
        $this->store->data['posts']['220']['comment_count'] = '1';
        $this->store->data['options']['blogname']['autoload'] = 'off';
        $this->store->data['term_taxonomy']['5']['count']     = '7';

        $this->assertSame(ContentRollback::DONE, ContentRollback::run(ContentFixtures::live($this->store), $this->dir)['state']);
        $now = $this->store->data;
        $this->assertSame(['3', '9', 'https://kunde.de/?p=219&neu'], [$now['posts']['219']['comment_count'], $now['posts']['219']['post_author'], $now['posts']['219']['guid']]);
        $this->assertSame('1', $now['posts']['220']['comment_count'], 'auch der Beitrag aus dem Papierkorb');
        $this->assertSame('off', $now['options']['blogname']['autoload']);
        $this->assertSame($old['posts']['219']['post_title'], $now['posts']['219']['post_title']);
        $this->assertSame($old['posts']['219']['post_modified'], $now['posts']['219']['post_modified']);
        $this->assertSame('draft', $now['posts']['220']['post_status']);
        $this->assertSame('Kunde', $now['options']['blogname']['option_value']);
        $this->assertArrayNotHasKey('1000001', $now['posts'], 'eingefügte Zeilen gehen weiter ganz');
        $this->assertArrayNotHasKey('page_on_front', $now['options']);
    }
}
