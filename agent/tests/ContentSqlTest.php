<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\TestCase;
use WpSync\Canon;
use WpSync\ContentException;
use WpSync\ContentSql;

require_once __DIR__ . '/FakeWpdb.php';

/**
 * Form der Abfragen von ContentSql (Spec Content-Push §7.3, §11): jeder Wert über prepare(),
 * Paare bytegenau, gesperrtes Lesen, Grenzen der Transaktion. Was die Datenbank daraus macht,
 * prüft scripts/e2e-content-push.sh.
 */
final class ContentSqlTest extends TestCase
{
    private FakeWpdb $db;

    protected function setUp(): void
    {
        $this->db = new FakeWpdb();
    }

    /** @return array<string, string> */
    private function tables(string $prefix = 'wp_'): array
    {
        $out = [];
        foreach (Canon::TABLES as $name) {
            $out[$name] = $prefix . $name;
        }
        return $out;
    }

    private function sql(string $prefix = 'wp_'): ContentSql
    {
        return new ContentSql($this->db, $this->tables($prefix));
    }

    public function testRefusesNamesThatAreNoIdentifiers(): void
    {
        foreach ([['posts' => 'wp_posts` WHERE 1; --'], ['options' => ''], ['terms' => null]] as $over) {
            try {
                new ContentSql($this->db, $over + $this->tables());
                $this->fail('accepted ' . json_encode($over));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('invalid content table', $e->getMessage());
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->sql()->read('users', ['1'], false);
    }

    public function testReadsRowsByKeyWithAndWithoutLock(): void
    {
        $this->db->answer('/FROM `wp_posts`/', [['ID' => '219', 'post_title' => 'A'], ['ID' => '220', 'post_title' => 'B']]);
        $rows = $this->sql()->read('posts', ['219', '220', '999'], false);
        $this->assertSame('A', $rows['219']['post_title']);
        $this->assertSame('B', $rows['220']['post_title']);
        $this->assertNull($rows['999']);
        $this->assertSame('SELECT * FROM `wp_posts` WHERE `ID` IN (219,220,999)', $this->db->queries[0]);

        $this->db = new FakeWpdb();
        $this->sql()->read('posts', ['219'], true);
        $this->assertSame('SELECT * FROM `wp_posts` WHERE `ID` IN (219) FOR UPDATE', $this->db->queries[0]);
    }

    /** Gelesen wird bytegenau: was die Datenbank grosszügiger gleichsetzt, ist nicht der gefragte Schlüssel. */
    public function testOptionsAreReadBytewise(): void
    {
        $this->db->answer('/FROM `wp_options`/', [
            ['option_id' => '3', 'option_name' => 'Blogname', 'option_value' => 'Zwilling', 'autoload' => 'yes'],
            ['option_id' => '4', 'option_name' => 'page_on_front', 'option_value' => '5', 'autoload' => 'yes'],
        ]);
        $rows = $this->sql()->read('options', ['blogname', 'page_on_front'], true);
        $this->assertNull($rows['blogname']);
        $this->assertSame('5', $rows['page_on_front']['option_value']);
        $this->assertSame("SELECT * FROM `wp_options` WHERE `option_name` IN ('blogname','page_on_front') FOR UPDATE", $this->db->queries[0]);
    }

    /** Härtung S4: ein Zwilling – gleich für die Datenbank, andere Bytes – wird gemeldet, nie getroffen. */
    public function testAliasesNameKeysWithATwinOnTheTarget(): void
    {
        $this->db->answer('/FROM `wp_postmeta`/', [['o' => '219', 'k' => '_Elementor_Data']]);
        $this->db->answer('/FROM `wp_options`/', [['k' => 'Blogname']]);
        $sql = $this->sql();
        $this->assertSame(["219\0_elementor_data"], $sql->aliases('postmeta', ["219\0_elementor_data", "219\0_thumbnail_id", "220\0_elementor_data"]));
        $this->assertSame(
            "SELECT DISTINCT `post_id` AS o, `meta_key` AS k FROM `wp_postmeta` WHERE (`post_id` = 219 AND `meta_key` = '_elementor_data' AND BINARY `meta_key` <> '_elementor_data')"
            . " OR (`post_id` = 219 AND `meta_key` = '_thumbnail_id' AND BINARY `meta_key` <> '_thumbnail_id')"
            . " OR (`post_id` = 220 AND `meta_key` = '_elementor_data' AND BINARY `meta_key` <> '_elementor_data')",
            $this->db->queries[0]
        );
        $this->assertSame(['blogname'], $sql->aliases('options', ['blogname', 'page_on_front']));
        $this->assertSame("SELECT `option_name` AS k FROM `wp_options` WHERE (`option_name` = 'blogname' AND BINARY `option_name` <> 'blogname') OR (`option_name` = 'page_on_front' AND BINARY `option_name` <> 'page_on_front')", $this->db->queries[1]);
        $this->assertSame([], $sql->aliases('posts', ['219']));
        $this->assertCount(2, $this->db->queries);
    }

    public function testReadsMetaPairsBytewise(): void
    {
        $this->db->answer('/FROM `wp_postmeta`/', [
            ['o' => '219', 'k' => '_multi', 'v' => 'b'],
            ['o' => '219', 'k' => '_Multi', 'v' => 'fremd'],
            ['o' => '219', 'k' => '_multi', 'v' => null],
        ]);
        $rows = $this->sql()->read('postmeta', ["219\0_multi", "219\0_fehlt"], true);
        $this->assertSame(['values' => ['b', null]], $rows["219\0_multi"]);
        $this->assertNull($rows["219\0_fehlt"]);
        $this->assertSame(
            "SELECT `post_id` AS o, `meta_key` AS k, `meta_value` AS v FROM `wp_postmeta` WHERE (`post_id` = 219 AND BINARY `meta_key` = '_multi')"
            . " OR (`post_id` = 219 AND BINARY `meta_key` = '_fehlt') ORDER BY `meta_id` FOR UPDATE",
            $this->db->queries[0]
        );
        $this->sql()->read('termmeta', ["7\0farbe"], false);
        $this->assertStringContainsString('FROM `wp_termmeta` WHERE (`term_id` = 7 AND BINARY `meta_key` = \'farbe\') ORDER BY `meta_id`', $this->db->queries[1]);
        $this->assertStringNotContainsString('FOR UPDATE', $this->db->queries[1]);
    }

    /** Zuordnungen je Taxonomie (S2): fremde Taxonomien desselben Beitrags gehören nicht dazu. */
    public function testReadsRelationshipsPerTaxonomy(): void
    {
        $this->db->answer('/FROM `wp_term_relationships` r/', [
            ['o' => '219', 'tt' => '3', 'ord' => '0', 'tax' => 'category'],
            ['o' => '219', 'tt' => '9', 'ord' => '0', 'tax' => 'language'],
            ['o' => '219', 'tt' => '7', 'ord' => '2', 'tax' => 'category'],
        ]);
        $rows = $this->sql()->read('term_relationships', ["219\0category", "219\0post_tag"], true);
        $this->assertSame(['values' => ['3:0', '7:2']], $rows["219\0category"]);
        $this->assertNull($rows["219\0post_tag"]);
        $this->assertStringContainsString('JOIN `wp_term_taxonomy` x ON x.`term_taxonomy_id` = r.`term_taxonomy_id` WHERE r.`object_id` IN (219)', $this->db->queries[0]);
        $this->assertStringEndsWith(' FOR UPDATE', $this->db->queries[0]);
    }

    public function testWritesARowAsInsertOrUpdate(): void
    {
        $evil = "x'; DROP TABLE wp_posts; --";
        $this->sql()->write('posts', '1000001', ['ID' => '5', 'post_title' => $evil, 'post_excerpt' => null]);
        $this->assertSame('SELECT `ID` FROM `wp_posts` WHERE `ID` = 1000001', $this->db->queries[0]);
        $this->assertSame(
            "INSERT INTO `wp_posts` (`ID`, `post_title`, `post_excerpt`) VALUES ('1000001', 'x\\'; DROP TABLE wp_posts; --', NULL)",
            $this->db->queries[1],
            'der Schlüssel gewinnt gegen eine ID im Zustand; der Wert steht escapt in Anführungszeichen'
        );
        $this->assertContains($evil, $this->db->prepared);

        $this->db->queries = [];
        $this->db->answer('/^SELECT `ID` FROM `wp_posts`/', [['ID' => '219']]);
        $this->sql()->write('posts', '219', ['ID' => '219', 'post_title' => 'Neu', 'post_status' => 'trash']);
        $this->assertSame("UPDATE `wp_posts` SET `post_title` = 'Neu', `post_status` = 'trash' WHERE `ID` = 219", $this->db->queries[1]);
    }

    public function testWritesOptionsWithoutTheirId(): void
    {
        $this->sql()->write('options', 'blogname', ['option_id' => '3', 'option_name' => 'egal', 'option_value' => 'Kunde', 'autoload' => 'auto']);
        $where = "WHERE `option_name` = 'blogname' AND BINARY `option_name` = 'blogname'";
        $this->assertSame('SELECT `option_name` FROM `wp_options` ' . $where, $this->db->queries[0]);
        $this->assertSame("INSERT INTO `wp_options` (`option_name`, `option_value`, `autoload`) VALUES ('blogname', 'Kunde', 'auto')", $this->db->queries[1]);
        $this->sql()->write('options', 'blogname', null);
        $this->assertSame('DELETE FROM `wp_options` ' . $where, $this->db->queries[2], 'bytegenau: nie der Zwilling in anderer Schreibweise');
    }

    public function testAColumnNameFromOutsideIsNeverSql(): void
    {
        try {
            $this->sql()->write('posts', '219', ['post_title` = 1, `post_status' => 'x']);
            $this->fail('accepted a column name with a backtick');
        } catch (ContentException $e) {
            $this->assertSame(ContentException::FAILED, $e->reason());
        }
        $this->assertSame([], $this->db->writes());
    }

    public function testReplacesAMetaPairAsAWhole(): void
    {
        $this->sql()->write('postmeta', "219\0_e'vil", ['values' => ['a', null, "b'c"]]);
        $this->assertSame([
            "DELETE FROM `wp_postmeta` WHERE `post_id` = 219 AND BINARY `meta_key` = '_e\\'vil'",
            "INSERT INTO `wp_postmeta` (`post_id`, `meta_key`, `meta_value`) VALUES (219, '_e\\'vil', 'a')",
            "INSERT INTO `wp_postmeta` (`post_id`, `meta_key`, `meta_value`) VALUES (219, '_e\\'vil', NULL)",
            "INSERT INTO `wp_postmeta` (`post_id`, `meta_key`, `meta_value`) VALUES (219, '_e\\'vil', 'b\\'c')",
        ], $this->db->queries);

        $this->db->queries = [];
        $this->sql()->write('termmeta', "7\0farbe", null);
        $this->assertSame(["DELETE FROM `wp_termmeta` WHERE `term_id` = 7 AND BINARY `meta_key` = 'farbe'"], $this->db->queries);
    }

    public function testReplacesTheRelationshipsOfOneTaxonomy(): void
    {
        $this->sql()->write('term_relationships', "219\0category", ['values' => ['3:0', '7:2']]);
        $this->assertSame([
            "DELETE r FROM `wp_term_relationships` r JOIN `wp_term_taxonomy` x ON x.`term_taxonomy_id` = r.`term_taxonomy_id` WHERE r.`object_id` = 219 AND BINARY x.`taxonomy` = 'category'",
            'INSERT INTO `wp_term_relationships` (`object_id`, `term_taxonomy_id`, `term_order`) VALUES (219, 3, 0)',
            'INSERT INTO `wp_term_relationships` (`object_id`, `term_taxonomy_id`, `term_order`) VALUES (219, 7, 2)',
        ], $this->db->queries);
    }

    public function testTransactionCommitsOrRollsBack(): void
    {
        $sql = $this->sql();
        $this->assertSame('fertig', $sql->transaction(function () use ($sql): string {
            $sql->write('options', 'blogname', null);
            return 'fertig';
        }));
        $this->assertSame(['START TRANSACTION', "DELETE FROM `wp_options` WHERE `option_name` = 'blogname' AND BINARY `option_name` = 'blogname'", 'COMMIT'], $this->db->queries);

        $this->db->queries = [];
        try {
            $sql->transaction(static function (): void {
                throw new \RuntimeException('boom');
            });
            $this->fail('no exception');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }
        $this->assertSame(['START TRANSACTION', 'ROLLBACK'], $this->db->queries);
    }

    public function testAFailedWriteOrCommitEndsInRollback(): void
    {
        $sql = $this->sql();
        $this->db->fail('/^DELETE FROM `wp_options`/', 'Lock wait timeout exceeded');
        try {
            $sql->transaction(static function () use ($sql): void {
                $sql->write('options', 'blogname', null);
            });
            $this->fail('no exception');
        } catch (ContentException $e) {
            $this->assertSame(ContentException::FAILED, $e->reason());
            $this->assertStringNotContainsString('Lock wait', $e->getMessage(), 'was die Datenbank meldet, bleibt auf dem Server');
        }
        $this->assertSame('ROLLBACK', $this->db->queries[count($this->db->queries) - 1]);

        $db2 = new FakeWpdb();
        $db2->fail('/^COMMIT/', 'gone away');
        try {
            (new ContentSql($db2, $this->tables()))->transaction(static function (): void {
            });
            $this->fail('no exception');
        } catch (ContentException $e) {
            $this->assertSame(['START TRANSACTION', 'COMMIT', 'ROLLBACK'], $db2->queries);
        }

        $db3 = new FakeWpdb();
        $db3->fail('/^START TRANSACTION/', 'gone away');
        $ran = false;
        try {
            (new ContentSql($db3, $this->tables()))->transaction(static function () use (&$ran): void {
                $ran = true;
            });
            $this->fail('no exception');
        } catch (ContentException $e) {
            $this->assertFalse($ran, 'ohne Transaktion wird nichts geschrieben');
        }
    }

    public function testAFailedReadIsNotAnEmptyResult(): void
    {
        $this->db->fail('/FROM `wp_posts`/', 'Table is marked as crashed');
        $this->expectException(ContentException::class);
        $this->sql()->read('posts', ['219'], false);
    }

    public function testEnginesTaxonomiesPurgeRecountDropMeta(): void
    {
        $this->db->answer('/^SHOW TABLE STATUS LIKE \'wp\\\\\\\\_options\'/', [['Engine' => 'MyISAM']]);
        $this->db->answer('/^SHOW TABLE STATUS/', [['Engine' => 'InnoDB']]);
        $this->assertSame(['posts' => 'InnoDB', 'options' => 'MyISAM'], $this->sql()->engines(['posts', 'options']));

        $this->db->answer('/SELECT `term_id`, `taxonomy`/', [['term_id' => '5', 'taxonomy' => 'category'], ['term_id' => '5', 'taxonomy' => 'post_tag']]);
        $this->assertSame(['5' => ['category', 'post_tag']], $this->sql()->taxonomies(['5', '6']));

        $this->db->queries = [];
        $sql               = $this->sql();
        $sql->purge('posts', '1000001');
        $sql->purge('terms', '1000002');
        $sql->purge('term_taxonomy', '1000003');
        $sql->recount(['3', '7']);
        $sql->dropMeta('_elementor_css');
        $this->assertSame([
            'DELETE FROM `wp_postmeta` WHERE `post_id` = 1000001',
            'DELETE FROM `wp_term_relationships` WHERE `object_id` = 1000001',
            'DELETE FROM `wp_termmeta` WHERE `term_id` = 1000002',
            'DELETE FROM `wp_term_relationships` WHERE `term_taxonomy_id` = 1000003',
            'UPDATE `wp_term_taxonomy` x SET x.`count` = (SELECT COUNT(*) FROM `wp_term_relationships` r WHERE r.`term_taxonomy_id` = x.`term_taxonomy_id`) WHERE x.`term_taxonomy_id` IN (3,7)',
            "DELETE FROM `wp_postmeta` WHERE `meta_key` = '_elementor_css' AND BINARY `meta_key` = '_elementor_css'",
        ], $this->db->queries);
    }

    /** §7.8: auf Staging nur die Tabellen der Kopie – der Store kennt keine anderen Namen. */
    public function testUsesExactlyTheGivenTables(): void
    {
        $sql = $this->sql('stgabcdef_');
        $sql->write('postmeta', "219\0_x", ['values' => ['a']]);
        $sql->read('posts', ['219'], false);
        foreach ($this->db->queries as $query) {
            $this->assertStringNotContainsString('`wp_', $query);
            $this->assertStringContainsString('`stgabcdef_', $query);
        }
    }
}
