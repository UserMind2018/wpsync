<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\TestCase;
use WpSync\Canon;
use WpSync\ContentException;
use WpSync\ContentOrigin;
use WpSync\ContentReader;
use WpSync\ContentState;

require_once __DIR__ . '/ContentMemory.php';

final class ContentStateTest extends TestCase
{
    private function reader(): ContentReader
    {
        return new ContentReader(null, '', new ContentOrigin('https://kunde.de'));
    }

    /** @return array<string, string> */
    private function post(array $over = []): array
    {
        return $over + [
            'ID' => '219', 'post_author' => '7', 'post_date' => '2026-01-01 10:00:00', 'post_date_gmt' => '2026-01-01 09:00:00',
            'post_content' => '<a href="https://kunde.de/x">', 'post_title' => 'Start', 'post_excerpt' => '', 'post_status' => 'publish',
            'comment_status' => 'closed', 'ping_status' => 'closed', 'post_password' => '', 'post_name' => 'start', 'to_ping' => '',
            'pinged' => '', 'post_modified' => '2026-02-02 10:00:00', 'post_modified_gmt' => '2026-02-02 09:00:00',
            'post_content_filtered' => '', 'post_parent' => '0', 'guid' => 'https://kunde.de/?p=219', 'menu_order' => '0',
            'post_type' => 'page', 'post_mime_type' => '', 'comment_count' => '0',
        ];
    }

    /** Der Abdruck des Rohzustands ist der des Manifests: normalisiert, ohne post_author, post_modified, guid. */
    public function testRecordOfARowMatchesTheManifest(): void
    {
        $normal                 = $this->post();
        $normal['post_content'] = '<a href="' . ContentOrigin::PLAIN . '/x">';
        $want                   = Canon::hash('posts', '219', Canon::columns(Canon::POSTS, $normal));

        $record = ContentState::record($this->reader(), 'posts', '219', $this->post());
        $this->assertSame(['t' => 'posts', 'k' => '219', 'h' => $want], $record);
        $later = ContentState::record($this->reader(), 'posts', '219', $this->post(['post_modified' => '2027-01-01 00:00:00', 'post_author' => '1', 'guid' => 'x']));
        $this->assertSame($want, $later['h']);
        $this->assertSame($want, ContentState::desired('posts', '219', $normal), 'die Zeile des Pakets ergibt denselben Abdruck');
    }

    public function testRecordOfSetsAndAbsentKeys(): void
    {
        $reader = $this->reader();
        $this->assertNull(ContentState::record($reader, 'postmeta', "219\0_x", null));

        $meta = ContentState::record($reader, 'postmeta', "219\0_x", ['values' => ['b', 'https://kunde.de/a', null]]);
        $this->assertSame(Canon::hash('postmeta', "219\0_x", Canon::set(['b', ContentOrigin::PLAIN . '/a', null])), $meta['h']);
        $this->assertSame($meta['h'], ContentState::desired('postmeta', "219\0_x", ['values' => [null, ContentOrigin::PLAIN . '/a', 'b']]), 'Reihenfolge zählt nicht');

        $rel = ContentState::record($reader, 'term_relationships', "219\0category", ['values' => ['7:0', '3:0']]);
        $this->assertSame(Canon::hash('term_relationships', "219\0category", Canon::set(['3:0', '7:0'])), $rel['h']);

        $option = ContentState::record($reader, 'options', 'blogname', ['option_id' => '3', 'option_name' => 'blogname', 'option_value' => 'Kunde', 'autoload' => 'yes']);
        $this->assertSame(Canon::hash('options', 'blogname', Canon::columns(['option_value'], ['option_value' => 'Kunde'])), $option['h']);
    }

    /** Ein Platzhalter im Rohwert lässt sich nicht normalisieren – der Datensatz hat keinen Abdruck. */
    public function testRecordWithoutFingerprint(): void
    {
        $record = ContentState::record($this->reader(), 'options', 'blogname', ['option_value' => 'x ' . ContentOrigin::PLAIN]);
        $this->assertNull($record['h']);
        $this->assertSame('unnormalizable', $record['why']);
    }

    public function testSplit(): void
    {
        $this->assertSame(['219', '_elementor_data'], ContentState::split("219\0_elementor_data"));
        $this->assertSame(['219', "a\0b"], ContentState::split("219\0a\0b"));
        $this->assertSame(['219', ''], ContentState::split('219'));
        $this->assertTrue(ContentState::isSet('termmeta'));
        $this->assertFalse(ContentState::isSet('options'));
    }

    /** before.json: auch Binäres und NULL überstehen die Ablage. */
    public function testEncodeAndDecodeAreInverse(): void
    {
        $row = ['ID' => '5', 'post_title' => "bin\xff\x00är", 'post_excerpt' => ''];
        $this->assertSame($row, ContentState::decode('posts', json_decode((string) json_encode(ContentState::encode('posts', $row)), true)));
        $set = ['values' => ["a\xff", null, '']];
        $this->assertSame($set, ContentState::decode('postmeta', json_decode((string) json_encode(ContentState::encode('postmeta', $set)), true)));
        $this->assertNull(ContentState::encode('posts', null));
        $this->assertNull(ContentState::decode('posts', null));
    }

    public function testDecodeRefusesAnythingElse(): void
    {
        foreach ([['posts', 'x'], ['posts', ['ID' => '***']], ['posts', ['bad column' => 'eA==']], ['postmeta', ['values' => 'x']], ['postmeta', ['ID' => 'eA==']]] as $case) {
            try {
                ContentState::decode($case[0], $case[1]);
                $this->fail('decoded ' . json_encode($case));
            } catch (ContentException $e) {
                $this->assertSame(ContentException::FAILED, $e->reason());
            }
        }
    }

    public function testExceptionAsArray(): void
    {
        $keys = [];
        for ($i = 1; $i <= 250; $i++) {
            $keys[] = ContentException::key('posts', (string) $i);
        }
        $e   = new ContentException(ContentException::CONFLICT, 'Seit dem Pull geändert.', $keys);
        $out = $e->toArray();
        $this->assertSame('conflict', $out['code']);
        $this->assertSame('Seit dem Pull geändert.', $out['message']);
        $this->assertCount(ContentException::MAX_KEYS, $out['keys']);
        $this->assertSame(250, $out['total']);
        $this->assertSame(['table' => 'posts', 'key' => '1'], $out['keys'][0]);
        $this->assertSame(409, $e->status());

        $plain = (new ContentException(ContentException::UPLOAD_MISSING, 'Dateien fehlen.', [], ['paths' => ['2026/10/a.jpg']]))->toArray();
        $this->assertSame(['code' => 'upload_missing', 'message' => 'Dateien fehlen.', 'paths' => ['2026/10/a.jpg']], $plain);
        $this->assertSame(['table' => 'postmeta', 'key' => "5\0_x", 'pattern' => 'email'], ContentException::key('postmeta', "5\0_x", 'email'));
        $this->assertSame(400, (new ContentException(ContentException::INVALID, 'x'))->status());
        $this->assertSame(413, (new ContentException(ContentException::TOO_LARGE, 'x'))->status());
        $this->assertSame(500, (new ContentException(ContentException::FAILED, 'x'))->status());
    }

    /** Die Attrappe verhält sich wie eine Transaktion: bei einer Exception ist alles wie vorher. */
    public function testMemoryStoreRollsBack(): void
    {
        $store = new ContentMemory(['options' => ['blogname' => ['option_value' => 'alt']]]);
        try {
            $store->transaction(static function () use ($store): void {
                $store->write('options', 'blogname', ['option_value' => 'neu']);
                $store->write('options', 'weg', null);
                throw new \RuntimeException('boom');
            });
            $this->fail('no exception');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }
        $this->assertSame('alt', $store->data['options']['blogname']['option_value']);
        $this->assertSame(['begin', 'write options:blogname', 'delete options:weg', 'rollback'], $store->log);
        $this->assertSame(['blogname' => ['option_value' => 'alt'], 'fehlt' => null], $store->read('options', ['blogname', 'fehlt'], false));
    }
}
