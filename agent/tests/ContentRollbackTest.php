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
}
