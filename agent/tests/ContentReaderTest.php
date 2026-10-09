<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\TestCase;
use WpSync\Canon;
use WpSync\ContentOrigin;
use WpSync\ContentReader;

final class ContentReaderTest extends TestCase
{
    private function reader(FakeWpdb $db, array $excluded = []): ContentReader
    {
        return new ContentReader($db, 'wp_', new ContentOrigin('https://kunde.de'), $excluded);
    }

    /** @return array<string, string|null> */
    private function post(string $id, array $over = []): array
    {
        return $over + [
            'ID' => $id, 'post_date' => '2026-01-01 10:00:00', 'post_date_gmt' => '2026-01-01 09:00:00',
            'post_content' => '<a href="https://kunde.de/x">', 'post_title' => 'Start', 'post_excerpt' => '',
            'post_status' => 'publish', 'comment_status' => 'closed', 'ping_status' => 'closed', 'post_password' => '',
            'post_name' => 'start', 'post_parent' => '0', 'menu_order' => '0', 'post_type' => 'page',
            'post_mime_type' => '', 'post_content_filtered' => '',
        ];
    }

    /** @return list<array<string, mixed>> */
    private function all(ContentReader $reader, bool $rows, ?array $tables = null): array
    {
        $out    = [];
        $cursor = null;
        do {
            $cursor = $reader->read($cursor, microtime(true) + 60, $rows, static function (array $record) use (&$out): void {
                $out[] = $record;
            }, $tables);
        } while ($cursor !== null);
        return $out;
    }

    public function testPostsAreNormalizedAndHashed(): void
    {
        $db = new FakeWpdb();
        $db->answer('/FROM `wp_posts`/', [$this->post('219')], []);
        $records = $this->all($this->reader($db), true, ['posts']);
        $this->assertCount(1, $records);
        $normal = $this->post('219', ['post_content' => '<a href="' . ContentOrigin::PLAIN . '/x">']);
        $this->assertSame('posts', $records[0]['t']);
        $this->assertSame('219', $records[0]['k']);
        $this->assertSame(Canon::hash('posts', '219', Canon::columns(Canon::POSTS, $normal)), $records[0]['h']);
        $this->assertSame(base64_encode('<a href="' . ContentOrigin::PLAIN . '/x">'), $records[0]['row']['post_content']);
        $this->assertSame(Canon::POSTS, array_keys($records[0]['row']));
        $this->assertStringContainsString('WHERE `ID` > 0', $db->queries[0]);
        $this->assertStringContainsString('ORDER BY `ID` LIMIT ' . ContentReader::POSTS_PER_STEP, $db->queries[0]);
    }

    public function testWithoutRowsOnlyFingerprintsLeave(): void
    {
        $db = new FakeWpdb();
        $db->answer('/FROM `wp_posts`/', [$this->post('1')], []);
        $records = $this->all($this->reader($db), false, ['posts']);
        $this->assertSame(['t', 'k', 'h'], array_keys($records[0]));
    }

    public function testNullStaysNull(): void
    {
        $db = new FakeWpdb();
        $db->answer('/FROM `wp_posts`/', [$this->post('1', ['post_excerpt' => null])], []);
        $records = $this->all($this->reader($db), true, ['posts']);
        $this->assertNull($records[0]['row']['post_excerpt']);
        $this->assertNotSame(
            Canon::hash('posts', '1', Canon::columns(Canon::POSTS, $this->post('1', ['post_content' => '<a href="' . ContentOrigin::PLAIN . '/x">']))),
            $records[0]['h']
        );
    }

    /** Spec §4.2: Zeilen, die sich nicht normalisieren lassen, tragen h: null */
    public function testUnnormalizableRowsCarryNoFingerprint(): void
    {
        $db = new FakeWpdb();
        $db->answer('/FROM `wp_posts`/', [$this->post('5', ['post_content' => 'schon ' . ContentOrigin::PLAIN])], []);
        $records = $this->all($this->reader($db), true, ['posts']);
        $this->assertSame(['t' => 'posts', 'k' => '5', 'h' => null, 'why' => 'unnormalizable'], $records[0]);
    }

    public function testExcludedPostTypesStayOnTheServer(): void
    {
        $db = new FakeWpdb();
        $this->all($this->reader($db, ['shop_order', 'revision']), false, ['posts', 'postmeta', 'term_relationships']);
        $sql = implode("\n", $db->queries);
        $this->assertStringContainsString("`post_type` NOT IN ('shop_order','revision')", $sql);
        $this->assertStringContainsString('JOIN `wp_posts` p ON p.`ID` = m.`post_id`', $sql);
        $this->assertStringContainsString('LEFT JOIN `wp_posts` p ON p.`ID` = r.`object_id`', $sql);
    }

    /** §6.1: Meta als sortierte Multimenge je (post_id, meta_key) */
    public function testMetaPairsAreMultisets(): void
    {
        $db = new FakeWpdb();
        $db->answer('/SELECT DISTINCT m\.`post_id`/', ['7', '9'], []);
        $db->answer('/AS o, m\.`meta_key` AS k/', [
            ['o' => '7', 'k' => '_menu_item_classes', 'v' => 'b'],
            ['o' => '7', 'k' => '_menu_item_classes', 'v' => 'a'],
            ['o' => '7', 'k' => '_elementor_data', 'v' => '[{"u":"https:\/\/kunde.de\/a"}]'],
            ['o' => '9', 'k' => '_thumbnail_id', 'v' => null],
        ]);
        $records = $this->all($this->reader($db), true, ['postmeta']);
        $this->assertSame(["7\0_menu_item_classes", "7\0_elementor_data", "9\0_thumbnail_id"], array_column($records, 'k'));
        $this->assertSame(Canon::hash('postmeta', "7\0_menu_item_classes", Canon::set(['a', 'b'])), $records[0]['h']);
        $this->assertSame(['values' => [base64_encode('a'), base64_encode('b')]], $records[0]['row']);
        $this->assertSame(Canon::hash('postmeta', "7\0_elementor_data", Canon::set(['[{"u":"' . ContentOrigin::ESC1 . '\/a"}]'])), $records[1]['h']);
        $this->assertSame(['values' => [null]], $records[2]['row']);
        $this->assertStringContainsString('m.`post_id` > 0 AND m.`post_id` <= 9', $db->queries[1]);
    }

    public function testNumericMetaKeysStayStrings(): void
    {
        $db = new FakeWpdb();
        $db->answer('/SELECT DISTINCT m\.`term_id`/', ['3'], []);
        $db->answer('/AS o, m\.`meta_key` AS k/', [['o' => '3', 'k' => '123', 'v' => 'x']]);
        $records = $this->all($this->reader($db), false, ['termmeta']);
        $this->assertSame("3\0" . '123', $records[0]['k']);
        $this->assertSame('termmeta', $records[0]['t']);
    }

    /** §6.1, S2: Zuordnungen je Taxonomie */
    public function testRelationshipsAreKeyedByTaxonomy(): void
    {
        $db = new FakeWpdb();
        $db->answer('/SELECT DISTINCT r\.`object_id`/', ['11'], []);
        $db->answer('/x\.`taxonomy` AS tax/', [
            ['o' => '11', 'tt' => '5', 'ord' => '0', 'tax' => 'category'],
            ['o' => '11', 'tt' => '3', 'ord' => '0', 'tax' => 'category'],
            ['o' => '11', 'tt' => '8', 'ord' => '2', 'tax' => 'language'],
        ]);
        $records = $this->all($this->reader($db), true, ['term_relationships']);
        $this->assertSame(["11\0category", "11\0language"], array_column($records, 'k'));
        $this->assertSame(Canon::hash('term_relationships', "11\0category", Canon::set(['3:0', '5:0'])), $records[0]['h']);
        $this->assertSame(['values' => [base64_encode('3:0'), base64_encode('5:0')]], $records[0]['row']);
    }

    public function testTermsTaxonomiesAndOptions(): void
    {
        $db = new FakeWpdb();
        $db->answer('/FROM `wp_terms`/', [['term_id' => '4', 'name' => 'Menü', 'slug' => 'menue', 'term_group' => '0']], []);
        $db->answer('/FROM `wp_term_taxonomy`/', [['term_taxonomy_id' => '6', 'term_id' => '4', 'taxonomy' => 'nav_menu', 'description' => '', 'parent' => '0']], []);
        $db->answer('/FROM `wp_options`/', [['option_id' => '2', 'option_name' => 'blogname', 'option_value' => 'Kunde']], []);
        $records = $this->all($this->reader($db), true, ['terms', 'term_taxonomy', 'options']);
        $this->assertSame([['terms', '4'], ['term_taxonomy', '6'], ['options', 'blogname']], array_map(static function (array $r): array {
            return [$r['t'], $r['k']];
        }, $records));
        $this->assertSame(Canon::hash('options', 'blogname', Canon::columns(['option_value'], ['option_value' => 'Kunde'])), $records[2]['h']);
        $this->assertSame(['option_value' => base64_encode('Kunde')], $records[2]['row']);
        $options = implode("\n", preg_grep('/wp_options/', $db->queries));
        foreach (["'wpsync\\\\_%'", "'\\\\_transient\\\\_%'", "'\\\\_site\\\\_transient\\\\_%'"] as $like) {
            $this->assertStringContainsString('NOT LIKE ' . $like, $options);
        }
    }

    /** Schrittgrenze bei Meta: jedes Objekt genau einmal, auch das letzte eines Schritts mit mehreren Zeilen */
    public function testMetaStepsNeitherRepeatNorSkipAnObject(): void
    {
        $count = ContentReader::OBJECTS_PER_STEP * 2 + 1;
        $db    = new FakeWpdb();
        $db->answer('/SELECT DISTINCT m\.`post_id`/', static function (string $sql) use ($count): array {
            preg_match('/> (\d+) ORDER BY .* LIMIT (\d+)$/', $sql, $m);
            $ids = [];
            for ($id = (int) $m[1] + 1; $id <= $count && count($ids) < (int) $m[2]; $id++) {
                $ids[] = (string) $id;
            }
            return $ids;
        });
        $db->answer('/AS o, m\.`meta_key` AS k/', static function (string $sql): array {
            preg_match('/> (\d+) AND m\.`post_id` <= (\d+)/', $sql, $m);
            $found = [];
            for ($id = (int) $m[1] + 1; $id <= (int) $m[2]; $id++) {
                $found[] = ['o' => (string) $id, 'k' => '_a', 'v' => 'x'];
                $found[] = ['o' => (string) $id, 'k' => '_b', 'v' => 'y'];
            }
            return $found;
        });
        $reader = $this->reader($db);
        $keys   = [];
        $emit   = static function (array $record) use (&$keys): void {
            $keys[] = $record['k'];
        };
        // Budget abgelaufen: jeder Aufruf liest genau einen Schritt.
        $cursor = $reader->read(null, microtime(true) - 1, false, $emit, ['postmeta']);
        $this->assertSame(['t' => 1, 'a' => (string) ContentReader::OBJECTS_PER_STEP], $cursor);
        $this->assertCount(ContentReader::OBJECTS_PER_STEP * 2, $keys);
        $cursor = $reader->read($cursor, microtime(true) - 1, false, $emit, ['postmeta']);
        $this->assertSame(['t' => 1, 'a' => (string) (ContentReader::OBJECTS_PER_STEP * 2)], $cursor);
        $this->assertNull($reader->read($cursor, microtime(true) - 1, false, $emit, ['postmeta']));
        $expected = [];
        for ($id = 1; $id <= $count; $id++) {
            $expected[] = $id . "\0_a";
            $expected[] = $id . "\0_b";
        }
        $this->assertSame($expected, $keys);
    }

    /** options: der Schlüssel ist option_name, weitergelesen wird nach option_id */
    public function testOptionsResumeByIdNotByName(): void
    {
        $db   = new FakeWpdb();
        $page = [];
        for ($i = 1; $i <= ContentReader::ROWS_PER_STEP; $i++) {
            $page[] = ['option_id' => (string) ($i * 2), 'option_name' => 'opt_' . (1000 - $i), 'option_value' => 'v'];
        }
        $last = (string) (ContentReader::ROWS_PER_STEP * 2);
        $db->answer('/FROM `wp_options`/', $page, [['option_id' => '4711', 'option_name' => 'aaa', 'option_value' => 'v']], []);
        $keys   = [];
        $reader = $this->reader($db);
        $emit   = static function (array $record) use (&$keys): void {
            $keys[] = $record['k'];
        };
        $cursor = $reader->read(null, microtime(true) - 1, false, $emit, ['options']);
        $this->assertSame(['t' => 6, 'a' => $last], $cursor);
        $this->assertNull($reader->read($cursor, microtime(true) + 60, false, $emit, ['options']));
        $this->assertStringContainsString('WHERE `option_id` > ' . $last . ' AND', $db->queries[1]);
        $this->assertCount(ContentReader::ROWS_PER_STEP + 1, $keys);
        $this->assertSame('aaa', $keys[ContentReader::ROWS_PER_STEP]);
        $this->assertSame($keys, array_values(array_unique($keys)));
    }

    /** Meta-Schlüssel bytegenau: "123" und "0123" sind zwei Paare, Gross- und Kleinschreibung zählt */
    public function testMetaKeysAreGroupedByteExact(): void
    {
        $db = new FakeWpdb();
        $db->answer('/SELECT DISTINCT m\.`post_id`/', ['7'], []);
        $db->answer('/AS o, m\.`meta_key` AS k/', [
            ['o' => '7', 'k' => '123', 'v' => 'a'],
            ['o' => '7', 'k' => '0123', 'v' => 'b'],
            ['o' => '7', 'k' => 'Key', 'v' => 'c'],
            ['o' => '7', 'k' => '123', 'v' => null],
            ['o' => '7', 'k' => 'key', 'v' => 'd'],
        ]);
        $records = $this->all($this->reader($db), true, ['postmeta']);
        $this->assertSame(["7\0" . '123', "7\0" . '0123', "7\0Key", "7\0key"], array_column($records, 'k'));
        $this->assertSame(['values' => [null, base64_encode('a')]], $records[0]['row']);
        $this->assertSame(Canon::hash('postmeta', "7\0" . '123', Canon::set(['a', null])), $records[0]['h']);
    }

    public function testCursorResumesWhereTheBudgetEnded(): void
    {
        $db   = new FakeWpdb();
        $page = [];
        for ($i = 1; $i <= ContentReader::POSTS_PER_STEP; $i++) {
            $page[] = $this->post((string) $i);
        }
        $db->answer('/FROM `wp_posts`/', $page, [$this->post('500')], []);
        $seen   = 0;
        $reader = $this->reader($db);
        $cursor = $reader->read(null, microtime(true) - 1, false, static function () use (&$seen): void {
            $seen++;
        }, ['posts']);
        $this->assertSame(['t' => 0, 'a' => (string) ContentReader::POSTS_PER_STEP], $cursor);
        $this->assertSame(ContentReader::POSTS_PER_STEP, $seen);
        $this->assertNull($reader->read($cursor, microtime(true) + 60, false, static function () use (&$seen): void {
            $seen++;
        }, ['posts']));
        $this->assertSame(ContentReader::POSTS_PER_STEP + 1, $seen);
        $this->assertStringContainsString('WHERE `ID` > ' . ContentReader::POSTS_PER_STEP, $db->queries[1]);
    }

    /** Ein Lesefehler sähe sonst aus wie „fertig“ */
    public function testADatabaseErrorIsNotTheEnd(): void
    {
        $db = new FakeWpdb();
        $db->fail('/FROM `wp_posts`/', 'gone away');
        $this->expectException(\RuntimeException::class);
        $this->all($this->reader($db), false, ['posts']);
    }

    public function testKeysThatAreNotUtf8CarryNoFingerprint(): void
    {
        $db = new FakeWpdb();
        $db->answer('/FROM `wp_options`/', [['option_id' => '2', 'option_name' => "kaputt\xFF", 'option_value' => 'x']], []);
        $records = $this->all($this->reader($db), true, ['options']);
        $this->assertNull($records[0]['h']);
        $this->assertSame('key_encoding', $records[0]['why']);
    }

    /** Security-Review M2: scheitert die Normalisierung einer Zeile, fehlt nur ihr Abdruck – die Seite bleibt */
    public function testAValueThatBreaksTheNormalizerCostsOnlyItsRow(): void
    {
        $db = new FakeWpdb();
        $db->answer('/FROM `wp_posts`/', [
            $this->post('1', ['post_content' => 's:9223372036854775807:"https://kunde.de";']),
            $this->post('2'),
        ], []);
        $db->answer('/SELECT DISTINCT m\.`post_id`/', ['7'], []);
        $db->answer('/FROM `wp_postmeta` m WHERE m\.`post_id` > 0 AND/', [
            ['o' => '7', 'k' => '_a', 'v' => 'a:9223372036854775807:{i:0;s:16:"https://kunde.de";}'],
            ['o' => '7', 'k' => '_b', 'v' => 'ok'],
        ]);
        $records = $this->all($this->reader($db), true, ['posts', 'postmeta']);
        $this->assertSame(['t' => 'posts', 'k' => '1', 'h' => null, 'why' => 'unnormalizable'], $records[0]);
        $this->assertNotNull($records[1]['h']);
        $this->assertSame(['t' => 'postmeta', 'k' => "7\0_a", 'h' => null, 'why' => 'unnormalizable'], $records[2]);
        $this->assertNotNull($records[3]['h']);
    }

    private function hiding(FakeWpdb $db): ContentReader
    {
        return new ContentReader($db, 'wp_', new ContentOrigin('https://kunde.de'), [], true);
    }

    /**
     * Security-Review M1, analog AC-148: Für alles, was der Pull pseudonymisiert, verlässt kein
     * Abdruck des echten Werts den Server – je Regel des Anonymizers, die eine Inhaltstabelle trifft.
     */
    public function testWhatThePullPseudonymizesCarriesNoFingerprint(): void
    {
        $covered = 0;
        foreach (\WpSync\Anonymizer::postTypes() as $i => $type) {
            $db = new FakeWpdb();
            $db->answer('/FROM `wp_posts`/', [
                $this->post((string) (10 + $i), ['post_type' => $type, 'post_password' => 'wc_order_AbC', 'post_excerpt' => 'Bitte klingeln']),
                $this->post('99'),
            ], []);
            $hidden = $this->all($this->hiding($db), false, ['posts']);
            $this->assertSame(['t' => 'posts', 'k' => (string) (10 + $i), 'h' => null, 'why' => 'pseudonymized'], $hidden[0], $type);
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}\z/', (string) $hidden[1]['h'], 'andere Beitragstypen behalten ihren Abdruck');
            $db = new FakeWpdb();
            $db->answer('/FROM `wp_posts`/', [$this->post((string) (10 + $i), ['post_type' => $type])], []);
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}\z/', (string) $this->all($this->reader($db), false, ['posts'])[0]['h'], 'ohne Schalter: ' . $type);
            $covered++;
        }
        foreach (['postmeta' => 'post_id', 'termmeta' => 'term_id'] as $table => $column) {
            foreach (\WpSync\Anonymizer::metaKeys($table) as $key) {
                $rows = [['o' => '7', 'k' => $key, 'v' => '203.0.113.7'], ['o' => '7', 'k' => '_e2e_plain', 'v' => 'x']];
                $db   = new FakeWpdb();
                $db->answer('/SELECT DISTINCT m\.`' . $column . '`/', ['7'], []);
                $db->answer('/AS o, m\.`meta_key` AS k/', $rows);
                $hidden = $this->all($this->hiding($db), false, [$table]);
                $this->assertSame(['t' => $table, 'k' => "7\0" . $key, 'h' => null, 'why' => 'pseudonymized'], $hidden[0], $key);
                $this->assertNotNull($hidden[1]['h']);
                $db = new FakeWpdb();
                $db->answer('/SELECT DISTINCT m\.`' . $column . '`/', ['7'], []);
                $db->answer('/AS o, m\.`meta_key` AS k/', $rows);
                $this->assertSame(Canon::hash($table, "7\0" . $key, Canon::set(['203.0.113.7'])), $this->all($this->reader($db), false, [$table])[0]['h'], 'ohne Schalter: ' . $key);
                $covered++;
            }
        }
        foreach (\WpSync\Anonymizer::metaKeys('options') as $name) {
            $rows = [['option_id' => '1', 'option_name' => $name, 'option_value' => 'chef@kunde.de'], ['option_id' => '2', 'option_name' => 'blogname', 'option_value' => 'Kunde']];
            $db   = new FakeWpdb();
            $db->answer('/FROM `wp_options`/', $rows, []);
            $hidden = $this->all($this->hiding($db), false, ['options']);
            $this->assertSame(['t' => 'options', 'k' => $name, 'h' => null, 'why' => 'pseudonymized'], $hidden[0]);
            $this->assertNotNull($hidden[1]['h']);
            $db = new FakeWpdb();
            $db->answer('/FROM `wp_options`/', $rows, []);
            $this->assertSame(Canon::hash('options', $name, Canon::columns(['option_value'], ['option_value' => 'chef@kunde.de'])), $this->all($this->reader($db), false, ['options'])[0]['h']);
            $covered++;
        }
        $this->assertGreaterThan(20, $covered);
        // Tabellen, für die es heute keine Regel gibt: bekommt eine davon eine, muss dieser Test sie abdecken.
        foreach (['terms', 'term_taxonomy', 'term_relationships'] as $table) {
            $this->assertFalse(\WpSync\Anonymizer::changes('wp_' . $table, 'wp_'), $table);
        }
        $this->assertSame([], \WpSync\Anonymizer::columns('posts', true), 'posts: nur Regeln mit when');
        $this->assertSame([], \WpSync\Anonymizer::columns('postmeta', false), 'postmeta: nur meta-Regeln');
        $this->assertSame([], \WpSync\Anonymizer::columns('options', false), 'options: nur meta-Regeln');
    }

    /** Auch mit Werten (rows) verlässt eine pseudonymisierte Zeile den Leser ohne row. */
    public function testAPseudonymizedRowNeverCarriesItsValues(): void
    {
        $db = new FakeWpdb();
        $db->answer('/FROM `wp_options`/', [['option_id' => '1', 'option_name' => 'admin_email', 'option_value' => 'chef@kunde.de']], []);
        $this->assertSame([['t' => 'options', 'k' => 'admin_email', 'h' => null, 'why' => 'pseudonymized']], $this->all($this->hiding($db), true, ['options']));
    }
}
