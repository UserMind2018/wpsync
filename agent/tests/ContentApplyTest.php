<?php
declare(strict_types=1);

namespace WpSync\Tests;

use WpSync\ContentApply;
use WpSync\ContentException;
use WpSync\ContentRollback;
use WpSync\ContentOrigin;
use WpSync\ContentPackage;
use WpSync\ContentState;

require_once __DIR__ . '/ContentApplyCase.php';

/** Anwenden in einer Transaktion (Spec Content-Push §7.3; AC-150, AC-151) gegen den Store im Speicher. */
final class ContentApplyTest extends ContentApplyCase
{
    /** AC-150: update, insert mit fester ID und trash in einer Transaktion. */
    public function testAppliesTheWholeSet(): void
    {
        $result = $this->apply($this->rows());
        $data   = $this->store->data;

        $this->assertSame('Neu', $data['posts']['219']['post_title']);
        $this->assertSame('Link: https://kunde.de/neu', $data['posts']['219']['post_content'], 'die Origin des Ziels ist eingesetzt');
        $this->assertSame('2026-10-09 14:13:20', $data['posts']['219']['post_modified']);
        $this->assertSame(gmdate('Y-m-d H:i:s', self::NOW), $data['posts']['219']['post_modified_gmt']);
        $this->assertSame('7', $data['posts']['219']['post_author'], 'post_author eines bestehenden Beitrags bleibt');
        $this->assertSame('https://kunde.de/?p=219', $data['posts']['219']['guid'], 'guid eines bestehenden Beitrags bleibt');
        $this->assertSame(['[{"url":"https:\/\/kunde.de\/neu"}]'], $data['postmeta']["219\0_elementor_data"]['values']);
        $this->assertArrayNotHasKey("219\0_thumbnail_id", $data['postmeta'], 'leere Menge löscht das Paar');
        $this->assertSame(['bleibt'], $data['postmeta']["219\0_fremd"]['values'], 'was nicht im Paket steht, bleibt');

        $this->assertSame('trash', $data['posts']['220']['post_status']);
        $this->assertSame(['draft'], $data['postmeta']["220\0_wp_trash_meta_status"]['values']);
        $this->assertSame([(string) self::NOW], $data['postmeta']["220\0_wp_trash_meta_time"]['values']);

        $new = $data['posts']['1000001'];
        $this->assertSame('Ganz neu', $new['post_title']);
        $this->assertSame('42', $this->applyAuthor(), 'post_author neuer Beiträge ist der Öffner des Fensters');
        $this->assertSame('7', $new['post_author']);
        $this->assertSame('https://kunde.de/?p=1000001', $new['guid']);
        $this->assertSame(['', '', '0'], [$new['to_ping'], $new['pinged'], $new['comment_count']]);
        $this->assertSame('0', $data['term_taxonomy']['1000002']['count']);
        $this->assertSame(['5:0', '1000002:0'], $data['term_relationships']["219\0category"]['values']);
        $this->assertSame('Kunde GmbH', $data['options']['blogname']['option_value']);
        $this->assertSame('yes', $data['options']['blogname']['autoload'], 'autoload einer bestehenden Option bleibt');
        $this->assertSame(['option_value' => '1000001', 'autoload' => 'auto'], $data['options']['page_on_front']);

        $this->assertSame(12, $result['rows']);
        $this->assertSame(['begin', 'commit'], [$this->store->log[0], $this->store->log[count($this->store->log) - 1]]);
        $this->assertSame([219, 220, 1000001], $result['changes']['posts']);
        $this->assertSame([219], $result['changes']['revisions'], 'nur geänderte Beiträge, nicht neue und nicht die im Papierkorb');
        $this->assertSame([1000001], $result['changes']['terms']);
        $this->assertSame([1000002, 5], $result['changes']['term_taxonomy']);
        $this->assertSame(['blogname', 'page_on_front'], $result['changes']['options']);
        $this->assertTrue($result['changes']['rewrite']);
    }

    /** N1: sind die Vorher-Zustände zu gross, wird nichts geschrieben – auch kein before.json. */
    public function testNothingIsWrittenWhenTheStatesAreTooLarge(): void
    {
        $old = $this->store->data;
        \WpSync\ContentCheck::$maxStateBytes = 100;
        try {
            $this->apply($this->rows());
            $this->fail('no exception');
        } catch (\WpSync\ContentException $e) {
            $this->assertSame('package_too_large', $e->reason());
        } finally {
            \WpSync\ContentCheck::$maxStateBytes = \WpSync\ContentCheck::MAX_STATE_BYTES;
        }
        $this->assertSame($old, $this->store->data);
        $this->assertSame([], preg_grep('/^(write|delete|purge|commit)/', $this->store->log));
        $this->assertFileDoesNotExist($this->dir . '/before.json');
    }

    /** Hilfsprüfung: ein anderer Öffner landet im neuen Beitrag. */
    private function applyAuthor(): string
    {
        $store         = new ContentMemory(['options' => ['stylesheet' => ContentFixtures::option('stylesheet', 'x')]]);
        $store->counters = ['posts' => 5]; // M1: neue IDs höchstens ID_HEADROOM über der höchsten des Ziels
        $this->files[] = $file = ContentFixtures::file([ContentFixtures::row('insert', 'posts', '1000005', 'absent', ContentFixtures::postRow('1000005'))]);
        ContentApply::run(ContentPackage::read($file), ContentFixtures::live($store), $this->dir . '-author', 42, self::NOW, '2026-10-09 14:13:20');
        exec('rm -rf ' . escapeshellarg($this->dir . '-author'));
        return (string) $store->data['posts']['1000005']['post_author'];
    }

    /** §7.3 Nr. 3 und 5: Vorher-Abbild roh, Nachher-Abdrücke – beides im Arbeitsordner des Pushs. */
    public function testWritesBeforeAndAfter(): void
    {
        $old    = $this->store->data;
        $result = $this->apply($this->rows());
        $before = json_decode((string) file_get_contents($this->dir . '/before.json'), true);
        $after  = json_decode((string) file_get_contents($this->dir . '/after.json'), true);

        $this->assertCount(15, $before['keys'], '12 Zeilen und die drei Meta des Papierkorbs');
        $this->assertSame(['t' => 'posts', 'k' => '219'], array_slice($before['keys'][0], 0, 2));
        $this->assertSame($old['posts']['219'], ContentState::decode('posts', $before['keys'][0]['state']), 'roh, nicht normalisiert');
        $byKey = [];
        foreach ($before['keys'] as $entry) {
            $byKey[$entry['t'] . ':' . $entry['k']] = $entry['state'];
        }
        $this->assertNull($byKey['posts:1000001'], 'eingefügte Schlüssel: vorher gab es sie nicht');
        $this->assertNull($byKey["postmeta:220\0_wp_trash_meta_status"]);
        $this->assertSame(['values' => [base64_encode('300')]], $byKey["postmeta:219\0_thumbnail_id"]);

        $this->assertSame($result['after'], $after['keys']);
        $this->assertSame($result['changes'], $after['changes']);
        $hashes = [];
        foreach ($after['keys'] as $entry) {
            $hashes[$entry['t'] . ':' . $entry['k']] = $entry['h'];
        }
        $this->assertSame($this->h('posts', '219'), $hashes['posts:219'], 'der Abdruck danach ist der des neuen Zustands');
        $this->assertSame($this->h('posts', '1000001'), $hashes['posts:1000001']);
        $this->assertNull($hashes["postmeta:219\0_thumbnail_id"], 'gelöschtes Paar: kein Abdruck');
        $this->assertSame(ContentState::desired('posts', '1000001', ContentFixtures::postRow('1000001', ['post_title' => 'Ganz neu'])), $hashes['posts:1000001'], 'derselbe Abdruck, den der Export der Arbeitskopie nennt');
    }

    /** AC-150: ein Fehler mittendrin hinterlässt keine Zeile. */
    public function testAFailureInTheMiddleLeavesNothing(): void
    {
        $old                    = $this->store->data;
        $this->store->failWrite = 5;
        try {
            $this->apply($this->rows());
            $this->fail('no exception');
        } catch (ContentException $e) {
            $this->assertSame(ContentException::FAILED, $e->reason());
        }
        $this->assertSame($old, $this->store->data);
        $this->assertSame('rollback', $this->store->log[count($this->store->log) - 1]);
        $this->assertFileExists($this->dir . '/before.json');
        $this->assertFileDoesNotExist($this->dir . '/after.json');
    }

    /** §7.3 Nr. 3: ohne geschriebenes Vorher-Abbild kein Schreiben. */
    public function testWithoutBeforeImageNothingIsWritten(): void
    {
        mkdir(dirname($this->dir), 0777, true);
        file_put_contents($this->dir, 'kein Ordner'); // der Ordner content lässt sich nicht anlegen
        $old = $this->store->data;
        try {
            $this->apply($this->rows());
            $this->fail('no exception');
        } catch (ContentException $e) {
            $this->assertSame(ContentException::FAILED, $e->reason());
        }
        $this->assertSame($old, $this->store->data);
        $this->assertSame([], preg_grep('/^(write|delete) /', $this->store->log));
        unlink($this->dir);
    }

    /** AC-151: die Prüfung läuft unter Sperre noch einmal – eine Änderung zwischen Probelauf und Anwenden fällt auf. */
    public function testConflictUnderLock(): void
    {
        $rows                     = $this->rows();
        $this->store->beforeLock = static function (ContentMemory $store): void {
            $store->data['posts']['219']['post_title'] = 'inzwischen auf Live geändert';
            $store->data['options']['blogname']['option_value'] = 'inzwischen geändert';
        };
        try {
            $this->apply($rows);
            $this->fail('no exception');
        } catch (ContentException $e) {
            $this->assertSame(ContentException::CONFLICT, $e->reason());
            $this->assertSame([['table' => 'posts', 'key' => '219'], ['table' => 'options', 'key' => 'blogname']], $e->keys());
        }
        $this->assertSame([], preg_grep('/^(write|delete) /', $this->store->log));
        $this->assertContains('posts:219', $this->store->locked);
        $this->assertFileDoesNotExist($this->dir . '/before.json');
    }

    /** §9: ohne Öffner des Fensters kein neuer Beitrag – Änderungen an bestehenden gehen. */
    public function testAuthorUnknown(): void
    {
        try {
            $this->apply($this->rows(), null);
            $this->fail('no exception');
        } catch (ContentException $e) {
            $this->assertSame(ContentException::AUTHOR, $e->reason());
        }
        $this->assertSame([], preg_grep('/^(write|delete) /', $this->store->log));
        $this->apply([ContentFixtures::row('update', 'options', 'blogname', $this->h('options', 'blogname'), ['option_value' => 'Neu'])], null);
        $this->assertSame('Neu', $this->store->data['options']['blogname']['option_value']);
    }

    /** Härtung S1: nach dem Schreiben wird zurückgelesen – ein anderer Abdruck als der des Pakets nimmt alles zurück. */
    public function testAValueTheDatabaseChangedFailsThePush(): void
    {
        $old                 = $this->store->data;
        $this->store->mangle = static function (string $table, string $key, array $state): array {
            if ($table === 'posts') {
                $state['post_title'] = substr((string) $state['post_title'], 0, 2);
            }
            return $state;
        };
        try {
            $this->apply($this->rows());
            $this->fail('no exception');
        } catch (ContentException $e) {
            $this->assertSame(ContentException::MISMATCH, $e->reason());
            $this->assertSame([['table' => 'posts', 'key' => '219']], $e->keys());
        }
        $this->assertSame($old, $this->store->data);
    }

    /** W8: guid eines neuen Attachments ist die Adresse der Datei auf dem Ziel. */
    public function testGuidOfANewAttachment(): void
    {
        $dir = sys_get_temp_dir() . '/wpsync-apply-up-' . bin2hex(random_bytes(4));
        mkdir($dir . '/2026/10', 0777, true);
        file_put_contents($dir . '/2026/10/bild.jpg', 'x');
        $this->apply([
            ContentFixtures::row('insert', 'posts', '1000009', 'absent', ContentFixtures::postRow('1000009', ['post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => 'image/jpeg'])),
            ContentFixtures::row('insert', 'postmeta', "1000009\0_wp_attached_file", 'absent', ['values' => ['2026/10/bild.jpg']]),
        ], 7, ContentFixtures::live($this->store, $dir));
        $this->assertSame('https://kunde.de/wp-content/uploads/2026/10/bild.jpg', $this->store->data['posts']['1000009']['guid']);
        exec('rm -rf ' . escapeshellarg($dir));
    }

    /** §7.8: auf der Kopie stehen danach die Adressen der Kopie – in jeder Schreibweise. */
    public function testAppliesToTheStagingCopy(): void
    {
        $path   = '/' . ContentFixtures::STAGING_DIR;
        $h      = $this->h('posts', '219');
        $this->store->data['posts']['219']['post_content'] = '<a href="https://kunde.de' . $path . '/kontakt">Kontakt</a>';
        $result = $this->apply([
            ContentFixtures::row('update', 'posts', '219', $h, ContentFixtures::postRow('219', ['post_content' => ContentOrigin::PLAIN . '/a ' . ContentOrigin::ESC1 . '\/b'])),
            ContentFixtures::row('insert', 'posts', '1000001', 'absent', ContentFixtures::postRow('1000001')),
        ], 7, ContentFixtures::staging($this->store));
        $this->assertSame('https://kunde.de' . $path . '/a https:\/\/kunde.de\\' . $path . '\/b', $this->store->data['posts']['219']['post_content']);
        $this->assertSame('https://kunde.de' . $path . '/?p=1000001', $this->store->data['posts']['1000001']['guid']);
        $this->assertSame(
            ContentState::desired('posts', '219', ContentFixtures::postRow('219', ['post_content' => ContentOrigin::PLAIN . '/a ' . ContentOrigin::ESC1 . '\/b'])),
            $result['after'][0]['h'],
            'der Abdruck auf der Kopie ist der normalisierte – derselbe wie später auf Live'
        );
    }

    /** Ein Beitrag, der schon im Papierkorb liegt, wird nicht noch einmal hineingelegt. */
    public function testTrashOfATrashedPostWritesNothing(): void
    {
        $this->store->data['posts']['220']['post_status'] = 'trash';
        $result = $this->apply([ContentFixtures::row('trash', 'posts', '220', $this->h('posts', '220'))]);
        $this->assertSame([], $result['after']);
        $this->assertArrayNotHasKey("220\0_wp_trash_meta_status", $this->store->data['postmeta']);
    }

    /** @return array<string, array{0: int}> der wievielte Schreibzugriff die Verbindung verliert: update, trash, insert, Paar, Option */
    public static function lostWrites(): array
    {
        return ['update posts' => [1], 'trash' => [2], 'insert term_taxonomy' => [5], 'postmeta' => [6], 'letzter' => [15]];
    }

    /**
     * Baut $wpdb die Verbindung mitten in der Transaktion neu auf, hat der Server alles davor
     * verworfen, und der eine Schreibzugriff danach lief für sich: kein COMMIT, genau dieser
     * Schlüssel geht auf seinen Stand davor zurück, content_failed.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('lostWrites')]
    public function testALostConnectionWhileWritingLeavesNothing(int $n): void
    {
        $old                      = $this->store->data;
        $this->store->loseAtWrite = $n;
        try {
            $this->apply($this->rows());
            $this->fail('no exception');
        } catch (ContentException $e) {
            $this->assertSame(ContentException::FAILED, $e->reason());
            $this->assertSame([], $e->keys(), 'die Zeile steht wieder wie vorher');
            $this->assertStringContainsString('Verbindung', $e->getMessage());
        }
        $this->assertSame(self::sorted($old), self::sorted($this->store->data));
        $log  = $this->store->log;
        $lost = (int) array_search('lost', $log, true);
        $this->assertSame(1, preg_match('/^(?:write|delete) (.+)\z/s', $log[$lost + 1], $m), 'der Schreibzugriff, der für sich lief');
        $this->assertSame(['rollback', 'begin'], array_slice($log, $lost + 2, 2), 'kein COMMIT, kein weiterer Schreibzugriff');
        $repair = array_slice($log, $lost + 4);
        $this->assertCount(2, $repair, implode(', ', $repair));
        $this->assertMatchesRegularExpression('/^(write|delete) ' . preg_quote($m[1], '/') . '\z/', $repair[0], 'genau dieser eine Schlüssel wird zurückgeschrieben');
        $this->assertSame('commit', $repair[1]);
        $this->assertFileDoesNotExist($this->dir . '/after.json');
    }

    /** Geht die Verbindung zwischen dem Lesen unter Sperre und dem ersten Schreiben verloren, wird nichts geschrieben. */
    public function testALostConnectionBeforeTheFirstWriteWritesNothing(): void
    {
        $old                     = $this->store->data;
        $this->store->beforeLock = static function (ContentMemory $store): void {
            $store->lose();
        };
        try {
            $this->apply($this->rows());
            $this->fail('no exception');
        } catch (ContentException $e) {
            $this->assertSame(ContentException::FAILED, $e->reason());
            $this->assertSame([], $e->keys());
        }
        $this->assertSame($old, $this->store->data);
        $this->assertSame([], preg_grep('/^(write|delete|purge|commit)/', $this->store->log));
        $this->assertFileDoesNotExist($this->dir . '/before.json');
    }

    /** Lässt sich der eine Schlüssel nicht zurückschreiben, nennt die Ablehnung ihn. */
    public function testALostWriteThatCannotBeTakenBackIsNamed(): void
    {
        $this->store->loseAtWrite = 1;
        $this->store->failWrite   = 2; // das Zurückschreiben
        try {
            $this->apply($this->rows());
            $this->fail('no exception');
        } catch (ContentException $e) {
            $this->assertSame(ContentException::FAILED, $e->reason());
            $this->assertSame([['table' => 'posts', 'key' => '219']], $e->keys());
            $this->assertTrue($e->toArray()['unrestored']);
        }
        $this->assertSame('Neu', $this->store->data['posts']['219']['post_title'], 'der eine Schreibzugriff steht – und die Antwort sagt es');
        $this->assertSame('Kunde', $this->store->data['options']['blogname']['option_value']);
    }

    /** Geht die Verbindung im COMMIT verloren, sieht der Agent nach: angekommen ist Erfolg, nicht angekommen content_failed. */
    public function testALostConnectionAtCommitIsLookedUp(): void
    {
        $old                       = $this->store->data;
        $this->store->loseAtCommit = 'discarded';
        try {
            $this->apply($this->rows());
            $this->fail('no exception');
        } catch (ContentException $e) {
            $this->assertSame(ContentException::FAILED, $e->reason());
        }
        $this->assertSame($old, $this->store->data);

        $this->store->loseAtCommit = 'landed';
        $result                    = $this->apply($this->rows());
        $this->assertSame(12, $result['rows']);
        $this->assertSame('Neu', $this->store->data['posts']['219']['post_title']);
    }

    /**
     * Papierkorb wie WordPress (wp_trash_post() und wp_insert_post()): Status, __trashed am Namen,
     * der alte Name in _wp_desired_post_slug, dazu Status und Zeit des Papierkorbs – alles vom Agent,
     * nichts davon aus dem Paket. content.after nennt jeden dieser Schlüssel.
     */
    public function testTrashDoesWhatWordPressDoes(): void
    {
        $this->store->data['postmeta']["220\0_wp_desired_post_slug"] = ['values' => ['uralt']]; // WordPress hängt an, es ersetzt nicht
        $result = $this->apply([ContentFixtures::row('trash', 'posts', '220', $this->h('posts', '220'))]);
        $data   = $this->store->data;
        $this->assertSame(['trash', 'seite-220__trashed'], [$data['posts']['220']['post_status'], $data['posts']['220']['post_name']]);
        $this->assertSame(['uralt', 'seite-220'], $data['postmeta']["220\0_wp_desired_post_slug"]['values']);
        $this->assertSame(['draft'], $data['postmeta']["220\0_wp_trash_meta_status"]['values']);
        $this->assertSame([(string) self::NOW], $data['postmeta']["220\0_wp_trash_meta_time"]['values']);
        $after = [];
        foreach ($result['after'] as $entry) {
            $after[$entry['t'] . ':' . $entry['k']] = $entry['h'];
        }
        $this->assertSame(['posts:220', "postmeta:220\0_wp_trash_meta_status", "postmeta:220\0_wp_trash_meta_time", "postmeta:220\0_wp_desired_post_slug"], array_keys($after));
        $this->assertSame($this->h('posts', '220'), $after['posts:220'], 'der Abdruck der Zeile mit dem neuen Namen');
        $this->assertSame($this->h('postmeta', "220\0_wp_desired_post_slug"), $after["postmeta:220\0_wp_desired_post_slug"]);
        $this->assertTrue($result['changes']['rewrite'], 'der Name hat sich geändert');

        // Die Rücknahme macht alles rückgängig: Name, Status, die drei Meta.
        $this->assertSame(ContentRollback::DONE, ContentRollback::run(ContentFixtures::live($this->store), $this->dir)['state']);
        $data = $this->store->data;
        $this->assertSame(['draft', 'seite-220'], [$data['posts']['220']['post_status'], $data['posts']['220']['post_name']]);
        $this->assertSame(['uralt'], $data['postmeta']["220\0_wp_desired_post_slug"]['values']);
        $this->assertArrayNotHasKey("220\0_wp_trash_meta_status", $data['postmeta']);
        $this->assertArrayNotHasKey("220\0_wp_trash_meta_time", $data['postmeta']);
    }

    /**
     * Trägt op trash post_date und post_date_gmt, setzt der Agent sie mit Status und Name: nach dem
     * Push ist der Abdruck der Zeile der der Arbeitskopie. Die Rücknahme stellt die alten Daten her.
     */
    public function testTrashSetsTheDatesOfThePackage(): void
    {
        $this->store->data['posts']['220']['post_date_gmt'] = '0000-00-00 00:00:00'; // nie veröffentlicht
        $old    = $this->store->data;
        $dates  = ['post_date' => '2026-10-09 16:13:20', 'post_date_gmt' => '2026-10-09 14:13:20'];
        $result = $this->apply([ContentFixtures::row('trash', 'posts', '220', $this->h('posts', '220'), $dates)]);
        $row    = $this->store->data['posts']['220'];
        $this->assertSame(['trash', 'seite-220__trashed', '2026-10-09 16:13:20', '2026-10-09 14:13:20'], [$row['post_status'], $row['post_name'], $row['post_date'], $row['post_date_gmt']]);
        $this->assertSame($this->h('posts', '220'), $result['after'][0]['h'], 'content.after trägt den Abdruck mit den neuen Daten');
        $local = array_merge($old['posts']['220'], $dates, ['post_status' => 'trash', 'post_name' => 'seite-220__trashed']);
        $this->assertSame(ContentFixtures::hash('posts', '220', $local), $result['after'][0]['h'], 'so steht die Zeile in der Arbeitskopie');

        $this->assertSame(ContentRollback::DONE, ContentRollback::run(ContentFixtures::live($this->store), $this->dir)['state']);
        $this->assertSame(self::sorted($old), self::sorted($this->store->data));
    }

    /** Liegt der Beitrag auf dem Ziel schon im Papierkorb, schreibt op trash nichts – auch keine Daten. */
    public function testTrashWithDatesOfAPostAlreadyInTheTrashWritesNothing(): void
    {
        $this->store->data['posts']['220']['post_status'] = 'trash';
        $old = $this->store->data;
        $this->apply([ContentFixtures::row('trash', 'posts', '220', $this->h('posts', '220'), ['post_date' => '2026-10-09 16:13:20', 'post_date_gmt' => '2026-10-09 14:13:20'])]);
        $this->assertSame($old, $this->store->data);
    }

    /** Wie _truncate_post_slug( …, 191 ) und der Fall „trägt das Suffix schon“ in wp_add_trashed_suffix_to_post_name_for_post(). */
    public function testTrashedNamesFollowCore(): void
    {
        $cases = [
            'kontakt'                    => ['kontakt__trashed', true],
            ''                           => ['__trashed', true],
            'alt__trashed'               => ['alt__trashed', false],
            'endet-auf-'                 => ['endet-auf__trashed', true],
            str_repeat('a', 200)         => [str_repeat('a', 191) . '__trashed', true],
            str_repeat('a', 190) . '-b'  => [str_repeat('a', 190) . '__trashed', true],
        ];
        foreach ($cases as $name => $want) {
            $name = (string) $name;
            $this->setUp();
            $this->store->data['posts']['220']['post_name'] = $name;
            $this->apply([ContentFixtures::row('trash', 'posts', '220', $this->h('posts', '220'))]);
            $this->assertSame($want[0], $this->store->data['posts']['220']['post_name'], $name);
            $this->assertSame($want[1] ? [$name] : null, $this->store->data['postmeta']["220\0_wp_desired_post_slug"]['values'] ?? null, $name);
        }
    }

    /** Auf Live fragt der Agent WordPress nach dem eindeutigen Namen (wp_unique_post_slug), wie wp_insert_post() es tut. */
    public function testTrashAsksTheTargetForAUniqueName(): void
    {
        $target       = ContentFixtures::live($this->store);
        $seen         = null;
        $target->slug = static function (string $name, string $id, string $type, string $parent) use (&$seen): string {
            $seen = [$name, $id, $type, $parent];
            return $name . '-2';
        };
        $this->apply([ContentFixtures::row('trash', 'posts', '220', $this->h('posts', '220'))], 7, $target);
        $this->assertSame(['seite-220__trashed', '220', 'page', '0'], $seen);
        $this->assertSame('seite-220__trashed-2', $this->store->data['posts']['220']['post_name']);
        $this->assertSame(['seite-220'], $this->store->data['postmeta']["220\0_wp_desired_post_slug"]['values']);
    }
}
