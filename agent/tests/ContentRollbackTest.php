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

    /**
     * Lag auf dem Ziel schon etwas an der ID eines neuen Beitrags (verwaiste Zuordnung oder Meta
     * eines früher gelöschten Beitrags) und hat der Push es überschrieben, steht es nach der
     * Rücknahme wieder da: was am eingefügten Objekt hängt, geht zuerst, dann kommt das Vorher-Abbild.
     */
    public function testRollbackRestoresWhatHungOnTheIdOfAnInsertedObject(): void
    {
        $this->store->data['term_relationships']["1000001\0category"]   = ['values' => ['5:0']];
        $this->store->data['postmeta']["1000001\0_wp_page_template"]    = ['values' => ['verwaist']];
        $old  = $this->store->data;
        $rows = $this->rows();
        foreach ($rows as $i => $row) {
            if ($row['key'] === "1000001\0category") {
                $rows[$i] = ContentFixtures::row('update', 'term_relationships', $row['key'], $this->h('term_relationships', $row['key']), ['values' => ['5:0', '1000002:0']]);
            } elseif ($row['key'] === "1000001\0_wp_page_template") {
                $rows[$i] = ContentFixtures::row('update', 'postmeta', $row['key'], $this->h('postmeta', $row['key']), ['values' => ['default']]);
            }
        }
        $this->apply($rows);

        $this->assertSame(ContentRollback::DONE, ContentRollback::run(ContentFixtures::live($this->store), $this->dir)['state']);
        $this->assertSame(self::sorted($old), self::sorted($this->store->data));
        $this->assertSame(ContentRollback::NOTHING, ContentRollback::run(ContentFixtures::live($this->store), $this->dir)['state']);
    }

    /** @return array<string, array{0: callable(ContentMemory): void, 1: list<array{table: string, key: string}>}> */
    public static function grownObjects(): array
    {
        return [
            'neue Meta am Beitrag' => [
                static function (ContentMemory $s): void {
                    $s->data['postmeta']["1000001\0_elementor_data"] = ['values' => ['[{"neu":1}]']];
                    $s->data['postmeta']["1000001\0farbe"]           = ['values' => ['rot']];
                },
                [['table' => 'postmeta', 'key' => "1000001\0_elementor_data"], ['table' => 'postmeta', 'key' => "1000001\0farbe"]],
            ],
            'Zuordnung in einer anderen Taxonomie' => [
                static function (ContentMemory $s): void {
                    $s->data['terms']['8']                               = ContentFixtures::term('8', 'Tag');
                    $s->data['term_taxonomy']['8']                       = ContentFixtures::taxonomy('8', '8', 'post_tag');
                    $s->data['term_relationships']["1000001\0post_tag"] = ['values' => ['8:0']];
                },
                [['table' => 'term_relationships', 'key' => "1000001\0post_tag"]],
            ],
            'verwaiste Zuordnung am Beitrag' => [
                static function (ContentMemory $s): void {
                    $s->orphans['1000001'] = ['77'];
                },
                [['table' => 'term_relationships', 'key' => "1000001\0"]],
            ],
            'Kommentar am Beitrag' => [
                static function (ContentMemory $s): void {
                    $s->comments['1000001'] = 2;
                },
                [['table' => 'comments', 'key' => '1000001']],
            ],
            'Revision und Kindseite' => [
                static function (ContentMemory $s): void {
                    $s->data['posts']['1000050'] = ContentFixtures::post('1000050', ['post_type' => 'revision', 'post_status' => 'inherit', 'post_parent' => '1000001']);
                    $s->data['posts']['1000051'] = ContentFixtures::post('1000051', ['post_parent' => '1000001']);
                },
                [['table' => 'posts', 'key' => '1000050'], ['table' => 'posts', 'key' => '1000051']],
            ],
            'Meta am Term' => [
                static function (ContentMemory $s): void {
                    $s->data['termmeta']["1000001\0farbe"] = ['values' => ['rot']];
                },
                [['table' => 'termmeta', 'key' => "1000001\0farbe"]],
            ],
            'der Term in einer weiteren Taxonomie' => [
                static function (ContentMemory $s): void {
                    $s->data['term_taxonomy']['1000060'] = ContentFixtures::taxonomy('1000060', '1000001', 'post_tag');
                },
                [['table' => 'term_taxonomy', 'key' => '1000060']],
            ],
            'Kind-Term' => [
                static function (ContentMemory $s): void {
                    $s->data['terms']['1000070']         = ContentFixtures::term('1000070', 'Kind');
                    $s->data['term_taxonomy']['1000070'] = ['parent' => '1000001'] + ContentFixtures::taxonomy('1000070', '1000070', 'category');
                },
                [['table' => 'term_taxonomy', 'key' => '1000070']],
            ],
            'fremder Beitrag an der eingefügten term_taxonomy' => [
                static function (ContentMemory $s): void {
                    $s->data['term_relationships']["220\0category"] = ['values' => ['1000002:0']];
                },
                [['table' => 'term_relationships', 'key' => "220\0category"]],
            ],
        ];
    }

    /**
     * M3: was nach dem Push an einem eingefügten Objekt entstand und nicht vom Push stammt, löscht
     * die Rücknahme nicht mit – sie lehnt ab und nennt die Stellen. Nichts wird zurückgenommen.
     *
     * @param callable(ContentMemory): void           $grow
     * @param list<array{table: string, key: string}> $keys
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('grownObjects')]
    public function testRollbackRefusesWhenAnInsertedObjectHasGrown(callable $grow, array $keys): void
    {
        $this->apply($this->rows());
        $grow($this->store);
        $pushed           = $this->store->data;
        $this->store->log = [];
        try {
            ContentRollback::run(ContentFixtures::live($this->store), $this->dir);
            $this->fail('no exception');
        } catch (ContentException $e) {
            $this->assertSame(ContentException::CHANGED, $e->reason());
            $this->assertSame($keys, $e->keys());
        }
        $this->assertSame($pushed, $this->store->data, 'nichts wird zurückgenommen');
        $this->assertSame([], preg_grep('/^(write|delete|purge|commit)/', $this->store->log));
    }

    /**
     * M3: Meta, die WordPress oder der Agent selbst an einem neuen Beitrag anlegt und die auf der
     * festen Sperrliste steht, ist keine Änderung – sonst gäbe es nach einem Blick in den Editor
     * keine Rücknahme mehr. Sie geht mit dem Beitrag.
     */
    public function testSystemMetaOnAnInsertedObjectDoesNotBlockTheRollback(): void
    {
        $old = $this->store->data;
        $this->apply($this->rows());
        foreach (['_edit_lock', '_edit_last', '_wp_old_slug', '_wp_trash_meta_status', '_wp_trash_meta_time', '_elementor_css', '_Elementor_CSS', '_elementor_screenshot_x', '_yoast_indexnow_last_ping'] as $name) {
            $this->store->data['postmeta']["1000001\0" . $name] = ['values' => ['x']];
        }
        $this->assertSame(ContentRollback::DONE, ContentRollback::run(ContentFixtures::live($this->store), $this->dir)['state']);
        $this->assertSame(self::sorted($old), self::sorted($this->store->data));
    }

    /** M3: was das Paket selbst an das neue Objekt gehängt hat, ist vom Push – und an bestehenden Objekten ändert sich nichts. */
    public function testWhatHangsOnExistingObjectsIsNotTheBusinessOfTheRollback(): void
    {
        $old = $this->store->data;
        $this->apply($this->rows());
        $this->store->comments['219']                      = 4;
        $this->store->data['postmeta']["219\0_neu"]        = ['values' => ['nach dem Push']];
        $this->store->data['posts']['1000090']             = ContentFixtures::post('1000090', ['post_parent' => '219']);
        $this->assertSame(ContentRollback::DONE, ContentRollback::run(ContentFixtures::live($this->store), $this->dir)['state']);
        $this->assertSame(['nach dem Push'], $this->store->data['postmeta']["219\0_neu"]['values']);
        unset($this->store->data['postmeta']["219\0_neu"], $this->store->data['posts']['1000090']);
        $this->assertSame(self::sorted($old), self::sorted($this->store->data));
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
            $this->assertSame('before_image_invalid', $e->reason());
        }
        $this->assertSame($pushed, $this->store->data);
    }

    /** @return list<string> Schlüssel einer Installation, mit der die Abbilder geschützt abgelegt werden */
    private function protect(): array
    {
        return \WpSync\ContentImage::$keys = [hash('sha256', 'schlüssel der installation', true)];
    }

    /** N2: mit einem Schlüssel der Installation liegen die Abbilder geschützt – und die Rücknahme geht wie zuvor. */
    public function testRollbackFromProtectedImages(): void
    {
        $this->protect();
        try {
            $old = $this->store->data;
            $this->apply($this->rows());
            $before = (string) file_get_contents($this->dir . '/before.json');
            $this->assertNull(json_decode($before, true), 'kein Klartext-JSON');
            $this->assertStringNotContainsString(base64_encode('Kunde'), $before);
            $this->assertNull(json_decode((string) file_get_contents($this->dir . '/after.json'), true));
            $this->assertSame(ContentRollback::DONE, ContentRollback::run(ContentFixtures::live($this->store), $this->dir)['state']);
            $this->assertSame(self::sorted($old), self::sorted($this->store->data));
        } finally {
            \WpSync\ContentImage::$keys = null;
        }
    }

    /** @return array<string, array{0: callable(string, array<string, mixed>, array<string, mixed>): void}> */
    public static function forgedImages(): array
    {
        $option = static function (string $value): array {
            return ['t' => 'options', 'k' => 'blogname', 'state' => ['option_value' => base64_encode($value)]];
        };
        return [
            'before.json durch Klartext ersetzt' => [static function (string $dir) use ($option): void {
                file_put_contents($dir . '/before.json', json_encode(['keys' => [$option('<script>')]]));
            }],
            'before.json mit einem fremden Schlüssel versiegelt' => [static function (string $dir) use ($option): void {
                file_put_contents($dir . '/before.json', \WpSync\ContentImage::pack((string) json_encode(['keys' => [$option('x')]]), [hash('sha256', 'fremd', true)], basename(dirname($dir)) . '/before.json', false));
            }],
            'before.json und after.json vertauscht' => [static function (string $dir): void {
                rename($dir . '/before.json', $dir . '/x');
                rename($dir . '/after.json', $dir . '/before.json');
                rename($dir . '/x', $dir . '/after.json');
            }],
            'after.json abgeschnitten' => [static function (string $dir): void {
                file_put_contents($dir . '/after.json', substr((string) file_get_contents($dir . '/after.json'), 0, 40));
            }],
            // Gültig geschützt, aber nicht das, was ein Push ablegt:
            'ein Schlüssel, den der Push nicht geschrieben hat' => [static function (string $dir, array $before, array $after): void {
                $before['keys'][] = ['t' => 'options', 'k' => 'siteurl', 'state' => ['option_value' => base64_encode('https://evil.example')]];
                \WpSync\ContentImage::put($dir, 'before.json', $before);
            }],
            'ein anderer Schlüssel an derselben Stelle' => [static function (string $dir, array $before, array $after): void {
                foreach ($before['keys'] as $i => $entry) {
                    if ($entry['k'] === 'blogname') {
                        $before['keys'][$i]['k'] = 'siteurl';
                    }
                }
                \WpSync\ContentImage::put($dir, 'before.json', $before);
            }],
            'ein Schlüssel, der keine ID ist' => [static function (string $dir, array $before, array $after): void {
                foreach (['before.json' => $before, 'after.json' => $after] as $name => $image) {
                    foreach ($image['keys'] as $i => $entry) {
                        if ($entry['t'] === 'posts' && $entry['k'] === '219') {
                            $image['keys'][$i]['k'] = '219 OR 1=1';
                        }
                    }
                    \WpSync\ContentImage::put($dir, $name, $image);
                }
            }],
            'eine fremde Tabelle' => [static function (string $dir, array $before, array $after): void {
                foreach (['before.json' => $before, 'after.json' => $after] as $name => $image) {
                    $image['keys'][0]['t'] = 'users';
                    \WpSync\ContentImage::put($dir, $name, $image);
                }
            }],
        ];
    }

    /**
     * N2: ein Abbild, das sich nicht öffnen lässt, verändert wurde oder nicht zu dem passt, was der
     * Push geschrieben hat, ist ein eigener Grund – und nichts wird geschrieben.
     *
     * @param callable(string, array<string, mixed>, array<string, mixed>): void $forge
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('forgedImages')]
    public function testRollbackRefusesForgedImages(callable $forge): void
    {
        $this->protect();
        try {
            $this->apply($this->rows());
            $pushed = $this->store->data;
            $forge($this->dir, (array) \WpSync\ContentImage::get($this->dir, 'before.json'), (array) \WpSync\ContentImage::get($this->dir, 'after.json'));
            $this->store->log = [];
            try {
                ContentRollback::run(ContentFixtures::live($this->store), $this->dir);
                $this->fail('no exception');
            } catch (ContentException $e) {
                $this->assertSame('before_image_invalid', $e->reason(), $e->getMessage());
                $this->assertSame(409, $e->status());
            }
            $this->assertSame($pushed, $this->store->data);
            $this->assertSame([], preg_grep('/^(write|delete|purge|commit)/', $this->store->log));
        } finally {
            \WpSync\ContentImage::$keys = null;
        }
    }

    /** N5: ist seit dem Push eine der sieben Tabellen nicht mehr InnoDB, gäbe es keine Transaktion – nichts wird zurückgenommen. */
    public function testRollbackNeedsInnoDbOnEveryContentTable(): void
    {
        $this->apply($this->rows());
        $pushed = $this->store->data;
        foreach (['termmeta', 'posts'] as $table) {
            $this->store->engines = [$table => 'MyISAM'];
            $this->store->log     = [];
            try {
                ContentRollback::run(ContentFixtures::live($this->store), $this->dir);
                $this->fail('no exception');
            } catch (ContentException $e) {
                $this->assertSame('engine_unsupported', $e->reason());
                $this->assertSame([$table], $e->toArray()['tables']);
            }
            $this->assertSame($pushed, $this->store->data);
            $this->assertSame([], $this->store->log, 'nicht einmal eine Transaktion');
        }
        $this->store->engines = [];
        $this->assertSame(ContentRollback::DONE, ContentRollback::run(ContentFixtures::live($this->store), $this->dir)['state']);
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

    /**
     * Ein Stand, der sich nicht normalisieren lässt, gleicht keinem – auch nicht einem anderen, der
     * sich nicht normalisieren lässt: dann ist nicht zu beweisen, dass sich nichts geändert hat.
     */
    public function testAStateWithoutFingerprintNeverCountsAsUnchanged(): void
    {
        $old = $this->store->data;
        $this->apply($this->rows());
        $broken = \WpSync\ContentOrigin::PLAIN . ' steht roh in der Datenbank';
        $before = json_decode((string) file_get_contents($this->dir . '/before.json'), true);
        foreach ($before['keys'] as $i => $entry) {
            if ($entry['t'] === 'postmeta' && $entry['k'] === "219\0_elementor_data") {
                $before['keys'][$i]['state'] = ['values' => [base64_encode($broken)]];
            }
        }
        file_put_contents($this->dir . '/before.json', json_encode($before));
        $this->store->data = $old; // alles wie vor dem Push – bis auf das eine Paar
        $this->store->data['postmeta']["219\0_elementor_data"] = ['values' => [$broken]];
        $now = $this->store->data;
        try {
            ContentRollback::run(ContentFixtures::live($this->store), $this->dir);
            $this->fail('no exception');
        } catch (ContentException $e) {
            $this->assertSame(ContentException::CHANGED, $e->reason());
            $this->assertContains(['table' => 'postmeta', 'key' => "219\0_elementor_data"], $e->keys());
        }
        $this->assertSame($now, $this->store->data);
    }
}
