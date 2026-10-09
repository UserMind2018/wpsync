<?php
declare(strict_types=1);

namespace WpSync\Tests;

use WpSync\ContentApply;
use WpSync\ContentException;
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

    /** Hilfsprüfung: ein anderer Öffner landet im neuen Beitrag. */
    private function applyAuthor(): string
    {
        $store         = new ContentMemory(['options' => ['stylesheet' => ContentFixtures::option('stylesheet', 'x')]]);
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

        $this->assertCount(14, $before['keys'], '12 Zeilen und die beiden Papierkorb-Meta');
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
}
