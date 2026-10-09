<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\TestCase;
use WpSync\ContentException;
use WpSync\ContentOrigin;
use WpSync\ContentPackage;

require_once __DIR__ . '/ContentFixtures.php';

/** Form, Prüfsumme und Grenzen eines Pakets (Spec Content-Push §7.1, §7.2 Nr. 1 und 2, §7.5). */
final class ContentPackageTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        array_map('unlink', array_filter($this->files, 'is_file'));
    }

    private function write(string $text): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'wpsync-pkg-');
        file_put_contents($file, $text);
        $this->files[] = $file;
        return $file;
    }

    /** @param list<array<string, mixed>> $rows */
    private function read(array $rows, array $head = []): ContentPackage
    {
        return ContentPackage::read($this->write(ContentFixtures::text($rows, $head)));
    }

    private function assertRefused(string $reason, string $text, string $what = ''): ContentException
    {
        try {
            ContentPackage::read($this->write($text));
        } catch (ContentException $e) {
            $this->assertSame($reason, $e->reason(), $what . ': ' . $e->getMessage());
            return $e;
        }
        $this->fail('accepted: ' . $what);
    }

    /** @return list<array<string, mixed>> */
    private function sample(): array
    {
        return [
            ContentFixtures::row('update', 'posts', '219', str_repeat('a', 64), ContentFixtures::postRow('219')),
            ContentFixtures::row('insert', 'postmeta', "219\0_elementor_data", 'absent', ['values' => ['[{"url":"' . ContentOrigin::ESC1 . '\/x"}]', null]]),
            ContentFixtures::row('update', 'postmeta', "219\0_thumbnail_id", str_repeat('b', 64), ['values' => []]),
            ContentFixtures::row('trash', 'posts', '220', str_repeat('c', 64)),
            ContentFixtures::row('insert', 'term_relationships', "219\0category", 'absent', ['values' => ['3:0']]),
            ContentFixtures::row('update', 'options', 'blogname', str_repeat('d', 64), ['option_value' => 'Kunde']),
            ContentFixtures::row('insert', 'terms', '1000001', 'absent', ['name' => 'Neu', 'slug' => 'neu', 'term_group' => '0']),
            ContentFixtures::row('insert', 'term_taxonomy', '1000001', 'absent', ['term_id' => '1000001', 'taxonomy' => 'category', 'description' => '', 'parent' => '0']),
        ];
    }

    public function testReadsHeadAndDecodedRows(): void
    {
        $package = $this->read($this->sample());
        $head    = $package->head();
        $this->assertSame(8, $head['rows']);
        $this->assertSame(ContentFixtures::HOME, $head['home']);
        $this->assertSame(ContentFixtures::LOCAL, $head['local_host']);
        $this->assertSame([1000001, 1999999], $head['corridor']['posts']);
        $this->assertSame(['post_types' => [], 'taxonomies' => [], 'meta_exceptions' => []], $head['extensions']);

        $rows = $package->rows();
        $this->assertCount(8, $rows);
        $this->assertSame('Seite 219', $rows[0]['row']['post_title']);
        $this->assertSame(['[{"url":"' . ContentOrigin::ESC1 . '\/x"}]', null], $rows[1]['row']['values']);
        $this->assertSame([], $rows[2]['row']['values'], 'leere Menge: das Paar wird gelöscht');
        $this->assertNull($rows[3]['row']);
        $this->assertSame(['3:0'], $rows[4]['row']['values']);
        $this->assertSame("219\0category", $rows[4]['key']);
        $this->assertSame(['posts' => 2, 'postmeta' => 2, 'term_relationships' => 1, 'options' => 1, 'terms' => 1, 'term_taxonomy' => 1], $package->counts());
        $this->assertGreaterThan(100, $package->bytes());
    }

    public function testMissingFile(): void
    {
        try {
            ContentPackage::read(sys_get_temp_dir() . '/wpsync-gibt-es-nicht.jsonl');
            $this->fail('read a missing file');
        } catch (ContentException $e) {
            $this->assertSame(ContentException::MISSING, $e->reason());
        }
    }

    public function testChecksumAndRowCount(): void
    {
        $rows = $this->sample();
        $this->assertRefused('package_invalid', ContentFixtures::text($rows, ['sha256' => str_repeat('0', 64)]), 'falsche Prüfsumme');
        $this->assertRefused('package_invalid', ContentFixtures::text($rows, ['rows' => 9]), 'weniger Zeilen als genannt');
        $this->assertRefused('package_invalid', ContentFixtures::text($rows, ['rows' => 7]), 'mehr Zeilen als genannt');
        $this->assertRefused('package_invalid', ContentFixtures::text($rows, ['rows' => 0]), 'leeres Paket');
        $text = ContentFixtures::text($rows);
        $this->assertRefused('package_invalid', substr($text, 0, -1), 'letzte Zeile ohne Zeilenende');
        $this->assertRefused('package_invalid', str_replace("\n", "\r\n", $text), 'CRLF');
        $this->assertRefused('package_invalid', "kein json\n", 'kein Kopf');
        $this->assertRefused('package_invalid', '{"head":{}}' . "\n", 'leerer Kopf');
        $this->assertRefused('package_invalid', str_repeat('x', 70000) . "\n", 'Kopf zu lang');
    }

    public function testHeadFields(): void
    {
        $rows = $this->sample();
        $this->assertRefused('baseline_outdated', ContentFixtures::text($rows, ['canon_version' => 2]), 'andere kanonische Form');
        $this->assertRefused('baseline_outdated', ContentFixtures::text($rows, ['variants' => ['plain', 'esc1']]), 'Pull vor esc2');
        $this->assertRefused('package_invalid', ContentFixtures::text($rows, ['canon_version' => '1']), 'Version als Text');
        $this->assertRefused('package_invalid', ContentFixtures::text($rows, ['extensions' => ['options' => ['x']]]), 'Optionen lassen sich nicht freigeben');
        $this->assertRefused('package_invalid', ContentFixtures::text($rows, ['extensions' => ['post_types' => ['shop_order']]]), 'pseudonymisierter Beitragstyp');
        $this->assertRefused('package_invalid', ContentFixtures::text($rows, ['corridor' => ['offset' => 1, 'posts' => [5, 4], 'terms' => [1, 2], 'term_taxonomy' => [1, 2]]]), 'Korridor verkehrt');
        $this->assertRefused('package_invalid', ContentFixtures::text($rows, ['corridor' => ['offset' => 1, 'posts' => [1, 2]]]), 'Korridor unvollständig');
        $this->assertRefused('package_invalid', ContentFixtures::text($rows, ['home' => 'kunde.de']), 'home ohne Schema');
        $this->assertRefused('package_invalid', ContentFixtures::text($rows, ['home' => 'https://kunde.de/']), 'home mit Schrägstrich am Ende');
        $this->assertRefused('package_invalid', ContentFixtures::text($rows, ['local_host' => 'Kunde.ddev.site/x']), 'local_host mit Pfad');
        $this->assertRefused('package_invalid', ContentFixtures::text($rows, ['map_id' => 'kurz']), 'map_id');
        $this->assertRefused('package_invalid', ContentFixtures::text($rows, ['zusatz' => 1]), 'unbekanntes Feld');
        $ok = $this->read($rows, ['extensions' => ['post_types' => ['referenz'], 'taxonomies' => [], 'meta_exceptions' => ['design_token']], 'home' => 'https://kunde.de/blog']);
        $this->assertSame(['referenz'], $ok->head()['extensions']['post_types']);
    }

    /** §7.5: Grenzen stehen in der Ablehnung, damit der Aufrufer sie zeigen kann. */
    public function testLimits(): void
    {
        $e = $this->assertRefused('package_too_large', ContentFixtures::text($this->sample(), ['rows' => ContentPackage::MAX_ROWS + 1]), 'zu viele Zeilen');
        $this->assertSame(ContentPackage::MAX_ROWS, $e->toArray()['limits']['max_rows']);
        $this->assertSame(ContentPackage::MAX_BYTES, $e->toArray()['limits']['max_bytes']);
        $this->assertGreaterThanOrEqual(2, $e->toArray()['limits']['budget_seconds']);
        $this->assertSame(\WpSync\ContentCheck::ID_HEADROOM, $e->toArray()['limits']['id_headroom'], 'M1: so weit über der höchsten ID des Ziels dürfen neue Objekte liegen');
        $this->assertSame(413, $e->status());

        $big = ContentFixtures::row('update', 'options', 'blogname', str_repeat('d', 64), ['option_value' => str_repeat('x', 6400000)]);
        $e   = $this->assertRefused('package_too_large', ContentFixtures::text([$big]), 'zu viele Bytes');
        $this->assertGreaterThan(ContentPackage::MAX_BYTES, $e->toArray()['bytes']);
    }

    public function testRowShapes(): void
    {
        $h   = str_repeat('a', 64);
        $bad = [
            'unbekannte Tabelle'              => ContentFixtures::row('update', 'users', '1', $h, ['user_login' => 'x']),
            'unbekanntes op'                  => ContentFixtures::row('delete', 'posts', '1', $h, ContentFixtures::postRow('1')),
            'insert mit Abdruck'              => ContentFixtures::row('insert', 'posts', '1000001', $h, ContentFixtures::postRow('1000001')),
            'update mit absent'               => ContentFixtures::row('update', 'posts', '1', 'absent', ContentFixtures::postRow('1')),
            'Schlüssel 0'                     => ContentFixtures::row('update', 'posts', '0', $h, ContentFixtures::postRow('0')),
            'Schlüssel mit SQL'               => ContentFixtures::row('update', 'posts', '1 OR 1=1', $h, ContentFixtures::postRow('1')),
            'Paar ohne Namen'                 => ContentFixtures::row('update', 'postmeta', '219', $h, ['values' => ['x']]),
            'Taxonomie mit Sonderzeichen'     => ContentFixtures::row('update', 'term_relationships', "219\0cat`egory", $h, ['values' => ['3:0']]),
            'Zuordnung ohne Zahl'             => ContentFixtures::row('update', 'term_relationships', "219\0category", $h, ['values' => ['3']]),
            'Zuordnung NULL'                  => ContentFixtures::row('update', 'term_relationships', "219\0category", $h, ['values' => [null]]),
            'trash für Meta'                  => ContentFixtures::row('trash', 'postmeta', "219\0_x", $h),
            'trash mit row'                   => ContentFixtures::row('trash', 'posts', '219', $h, ContentFixtures::postRow('219')),
            'trash mit einer Datumsspalte'    => ContentFixtures::row('trash', 'posts', '219', $h, ['post_date' => '2026-10-09 12:00:00']),
            'trash mit fremder Spalte'        => ContentFixtures::row('trash', 'posts', '219', $h, ['post_date' => '2026-10-09 12:00:00', 'post_date_gmt' => '2026-10-09 10:00:00', 'post_title' => 'x']),
            'trash mit falschem Datum'        => ContentFixtures::row('trash', 'posts', '219', $h, ['post_date' => '2026-10-09 12:00:00', 'post_date_gmt' => 'gestern']),
            'trash mit Datum ohne Zeit'       => ContentFixtures::row('trash', 'posts', '219', $h, ['post_date' => '2026-10-09', 'post_date_gmt' => '2026-10-09 10:00:00']),
            'trash mit leerem row'            => ['op' => 'trash', 'table' => 'posts', 'key' => '219', 'expected' => $h, 'row' => []],
            'update ohne row'                 => ContentFixtures::row('update', 'posts', '219', $h),
            'Spalte fehlt'                    => ContentFixtures::row('update', 'posts', '219', $h, array_diff_key(ContentFixtures::postRow('219'), ['post_title' => 1])),
            'fremde Spalte'                   => ContentFixtures::row('update', 'posts', '219', $h, ['post_author' => '1'] + ContentFixtures::postRow('219')),
            'Datum'                           => ContentFixtures::row('update', 'posts', '219', $h, ContentFixtures::postRow('219', ['post_date' => 'gestern'])),
            'post_parent'                     => ContentFixtures::row('update', 'posts', '219', $h, ContentFixtures::postRow('219', ['post_parent' => '-1'])),
            'neuer Beitrag im Papierkorb'     => ContentFixtures::row('insert', 'posts', '1000001', 'absent', ContentFixtures::postRow('1000001', ['post_status' => 'trash'])),
            'leeres neues Paar'               => ContentFixtures::row('insert', 'postmeta', "219\0_x", 'absent', ['values' => []]),
            'Option ohne Wert'                => ContentFixtures::row('update', 'options', 'blogname', $h, ['option_value' => null]),
            'Optionsname leer'                => ContentFixtures::row('update', 'options', '', $h, ['option_value' => 'x']),
            'term_taxonomy ohne Term'         => ContentFixtures::row('insert', 'term_taxonomy', '1000001', 'absent', ['term_id' => '0', 'taxonomy' => 'category', 'description' => '', 'parent' => '0']),
        ];
        foreach ($bad as $what => $row) {
            $this->assertRefused('package_invalid', ContentFixtures::text([$row]), $what);
        }
        $double = [ContentFixtures::row('trash', 'posts', '219', $h), ContentFixtures::row('update', 'posts', '219', $h, ContentFixtures::postRow('219'))];
        $this->assertRefused('package_invalid', ContentFixtures::text($double), 'doppelter Schlüssel');
        // Was der Papierkorb an einem Beitrag hinterlässt, schreibt der Agent selbst – eine zweite Quelle gibt es nicht.
        foreach (['_wp_trash_meta_status', '_wp_trash_meta_time', '_wp_desired_post_slug'] as $meta) {
            $e = $this->assertRefused('package_invalid', ContentFixtures::text([
                ContentFixtures::row('insert', 'postmeta', "219\0" . $meta, 'absent', ['values' => ['x']]),
                ContentFixtures::row('trash', 'posts', '219', $h),
            ]), $meta);
            $this->assertStringContainsString('Papierkorb', $e->getMessage());
        }
        ContentPackage::read($this->write(ContentFixtures::text([
            ContentFixtures::row('insert', 'postmeta', "220\0_wp_desired_post_slug", 'absent', ['values' => ['x']]),
            ContentFixtures::row('trash', 'posts', '219', $h),
        ]))); // an einem anderen Beitrag ist es eine Zeile wie jede andere
        $raw = ContentFixtures::text([ContentFixtures::row('update', 'options', 'blogname', $h, ['option_value' => 'x'])]);
        $this->assertRefused('package_invalid', str_replace('"eA=="', '"kein base64!"', $raw), 'kein base64');
        $this->assertRefused('package_invalid', str_replace('"op":"update"', '"op":"update","zusatz":1', $raw), 'unbekanntes Feld in der Zeile');
    }

    /**
     * op trash darf row mit genau post_date und post_date_gmt tragen: WordPress gibt einem nie
     * veröffentlichten Entwurf beim Weg in den Papierkorb das Datum des Verschiebens.
     */
    public function testTrashMayCarryTheTwoDates(): void
    {
        $h    = str_repeat('a', 64);
        $rows = ContentPackage::read($this->write(ContentFixtures::text([
            ContentFixtures::row('trash', 'posts', '219', $h, ['post_date' => '2026-10-09 12:00:00', 'post_date_gmt' => '2026-10-09 10:00:00']),
            ContentFixtures::row('trash', 'posts', '220', $h, ['post_date' => '0000-00-00 00:00:00', 'post_date_gmt' => '0000-00-00 00:00:00']),
            ContentFixtures::row('trash', 'posts', '221', $h),
        ])))->rows();
        $this->assertNull($rows[0]['row'], 'row bleibt den ganzen Zeilen vorbehalten');
        $this->assertSame(['post_date' => '2026-10-09 12:00:00', 'post_date_gmt' => '2026-10-09 10:00:00'], $rows[0]['dates']);
        $this->assertSame(['post_date' => '0000-00-00 00:00:00', 'post_date_gmt' => '0000-00-00 00:00:00'], $rows[1]['dates']);
        $this->assertNull($rows[2]['dates']);
    }

    /** Die Meldung nennt die Zeile, nie einen Wert. */
    public function testMessagesCarryNoValues(): void
    {
        $row = ContentFixtures::row('update', 'posts', '219', str_repeat('a', 64), ContentFixtures::postRow('219', ['post_date' => 'geheimer-wert']));
        $e   = $this->assertRefused('package_invalid', ContentFixtures::text([$row]), 'Datum');
        $this->assertStringContainsString('Zeile 1', $e->getMessage());
        $this->assertStringNotContainsString('geheimer-wert', $e->getMessage());
    }
}
