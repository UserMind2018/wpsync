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

    /**
     * Eine Datenbank, die die Sitzungsmarke hält wie eine Verbindung: SELECT @wpsync_tx liefert, was
     * SET zuletzt gesetzt hat. $lose: mit dieser Abfrage geht die Verbindung verloren – die neue
     * kennt keine Marke.
     */
    private function connection(?string $lose = null): FakeWpdb
    {
        $db   = new FakeWpdb();
        $mark = null;
        $db->observer = static function (string $sql) use (&$mark, $lose): void {
            if ($lose !== null && preg_match($lose, $sql) === 1) {
                $mark = null;
            }
            if (preg_match("/^SET @wpsync_tx = (?:'([a-f0-9]+)'|NULL)\\z/", $sql, $m) === 1) {
                $mark = $m[1] ?? null;
            }
        };
        $db->answer('/^SELECT @wpsync_tx/', static function () use (&$mark) {
            return $mark;
        });
        return $db;
    }

    private function mark(FakeWpdb $db): string
    {
        $this->assertSame(1, preg_match("/^SET @wpsync_tx = '([a-f0-9]{16})'\\z/", $db->queries[0], $m), $db->queries[0]);
        return $m[1];
    }

    public function testTransactionCommitsOrRollsBack(): void
    {
        $this->db = $this->connection();
        $sql      = $this->sql();
        $this->assertFalse($sql->alive(), 'ausserhalb einer Transaktion lebt keine');
        $this->db->queries = [];
        $this->assertSame('fertig', $sql->transaction(function () use ($sql): string {
            $sql->write('options', 'blogname', null);
            $this->assertTrue($sql->alive());
            return 'fertig';
        }));
        $mark = $this->mark($this->db);
        $this->assertSame([
            "SET @wpsync_tx = '" . $mark . "'",
            'START TRANSACTION',
            "DELETE FROM `wp_options` WHERE `option_name` = 'blogname' AND BINARY `option_name` = 'blogname' AND @wpsync_tx = '" . $mark . "'",
            'SELECT @wpsync_tx',
            'SELECT @wpsync_tx',
            'COMMIT',
            'SELECT @wpsync_tx',
            'SET @wpsync_tx = NULL',
        ], $this->db->queries, 'erst die Marke, dann die Transaktion; vor und nach dem COMMIT wird sie geprüft');
        $this->assertFalse($sql->alive());

        $this->db->queries = [];
        try {
            $sql->transaction(static function (): void {
                throw new \RuntimeException('boom');
            });
            $this->fail('no exception');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }
        $this->assertSame(['START TRANSACTION', 'ROLLBACK', 'SET @wpsync_tx = NULL'], array_slice($this->db->queries, 1));
    }

    /** Jede schreibende Anweisung einer Transaktion trägt die Marke als Bedingung: auf einer neuen Verbindung schreibt sie nichts. */
    public function testEveryWriteInATransactionIsBoundToItsConnection(): void
    {
        $this->db = $this->connection();
        $this->db->answer('/^SELECT `ID` FROM `wp_posts` WHERE `ID` = 219/', [['ID' => '219']]);
        $sql = $this->sql();
        $sql->transaction(static function () use ($sql): void {
            $sql->write('posts', '1000001', ['post_title' => 'Neu', 'post_excerpt' => null]);
            $sql->write('posts', '219', ['post_title' => 'Alt']);
            $sql->write('postmeta', "219\0_x", ['values' => ['a']]);
            $sql->write('term_relationships', "219\0category", ['values' => ['3:0']]);
            $sql->purge('posts', '1000001');
            $sql->purge('terms', '7');
            $sql->purge('term_taxonomy', '8');
            $sql->recount(['3']);
            $sql->dropMeta('_elementor_css');
        });
        $mark   = $this->mark($this->db);
        $guard  = "@wpsync_tx = '" . $mark . "'";
        $writes = array_values(array_filter($this->db->writes(), static function (string $sql): bool {
            return preg_match('/^(SET|START|COMMIT)/', $sql) !== 1;
        }));
        $this->assertSame([
            "INSERT INTO `wp_posts` (`post_title`, `post_excerpt`, `ID`) SELECT 'Neu', NULL, '1000001' FROM DUAL WHERE " . $guard,
            "UPDATE `wp_posts` SET `post_title` = 'Alt' WHERE `ID` = 219 AND " . $guard,
            "DELETE FROM `wp_postmeta` WHERE `post_id` = 219 AND BINARY `meta_key` = '_x' AND " . $guard,
            "INSERT INTO `wp_postmeta` (`post_id`, `meta_key`, `meta_value`) SELECT 219, '_x', 'a' FROM DUAL WHERE " . $guard,
            "DELETE r FROM `wp_term_relationships` r JOIN `wp_term_taxonomy` x ON x.`term_taxonomy_id` = r.`term_taxonomy_id` WHERE r.`object_id` = 219 AND BINARY x.`taxonomy` = 'category' AND " . $guard,
            'INSERT INTO `wp_term_relationships` (`object_id`, `term_taxonomy_id`, `term_order`) SELECT 219, 3, 0 FROM DUAL WHERE ' . $guard,
            'DELETE FROM `wp_postmeta` WHERE `post_id` = 1000001 AND ' . $guard,
            'DELETE FROM `wp_term_relationships` WHERE `object_id` = 1000001 AND ' . $guard,
            'DELETE FROM `wp_termmeta` WHERE `term_id` = 7 AND ' . $guard,
            'DELETE FROM `wp_term_relationships` WHERE `term_taxonomy_id` = 8 AND ' . $guard,
            'UPDATE `wp_term_taxonomy` x SET x.`count` = (SELECT COUNT(*) FROM `wp_term_relationships` r WHERE r.`term_taxonomy_id` = x.`term_taxonomy_id`) WHERE x.`term_taxonomy_id` IN (3) AND ' . $guard,
            "DELETE FROM `wp_postmeta` WHERE `meta_key` = '_elementor_css' AND BINARY `meta_key` = '_elementor_css' AND " . $guard,
        ], $writes);
    }

    /**
     * $wpdb baut eine verlorene Verbindung neu auf und wiederholt die Abfrage: die neue Verbindung
     * kennt die Marke nicht. Dann gibt es keinen COMMIT.
     */
    public function testATransactionThatLostItsConnectionNeverCommits(): void
    {
        $this->db = $this->connection('/^DELETE FROM `wp_options`/');
        $sql      = $this->sql();
        $alive    = null;
        try {
            $sql->transaction(static function () use ($sql, &$alive): void {
                $sql->write('options', 'blogname', null);
                $alive = $sql->alive();
            });
            $this->fail('no exception');
        } catch (ContentException $e) {
            $this->assertSame(ContentException::FAILED, $e->reason());
        }
        $this->assertFalse($alive);
        $this->assertNotContains('COMMIT', $this->db->queries);
        $this->assertSame(['ROLLBACK', 'SET @wpsync_tx = NULL'], array_slice($this->db->queries, -2));

        // Zwischen Marke und START TRANSACTION verloren: die neue Verbindung hat eine Transaktion, aber keine Marke.
        $this->db = $this->connection('/^START TRANSACTION/');
        $sql      = $this->sql();
        try {
            $sql->transaction(static function (): void {
            });
            $this->fail('no exception');
        } catch (ContentException $e) {
            $this->assertSame(ContentException::FAILED, $e->reason());
        }
        $this->assertNotContains('COMMIT', $this->db->queries);
    }

    /** Geht die Verbindung im COMMIT verloren, ist offen, ob er ankam – das sagt die Transaktion, statt zu raten. */
    public function testALostConnectionAtCommitIsUnclear(): void
    {
        $this->db = $this->connection('/^COMMIT/');
        try {
            $this->sql()->transaction(static function (): string {
                return 'fertig';
            });
            $this->fail('no exception');
        } catch (ContentException $e) {
            $this->assertSame(ContentException::UNCLEAR, $e->reason());
        }
        $this->assertSame(['COMMIT', 'SELECT @wpsync_tx', 'SET @wpsync_tx = NULL'], array_slice($this->db->queries, -3));
    }

    public function testAFailedWriteOrCommitEndsInRollback(): void
    {
        $this->db = $this->connection();
        $sql      = $this->sql();
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
        $this->assertSame('ROLLBACK', $this->db->queries[count($this->db->queries) - 2]);

        $db2 = $this->connection();
        $db2->fail('/^COMMIT/', 'gone away');
        try {
            (new ContentSql($db2, $this->tables()))->transaction(static function (): void {
            });
            $this->fail('no exception');
        } catch (ContentException $e) {
            $this->assertSame(['START TRANSACTION', 'SELECT @wpsync_tx', 'COMMIT', 'ROLLBACK', 'SET @wpsync_tx = NULL'], array_slice($db2->queries, 1));
        }

        foreach (['/^START TRANSACTION/', '/^SET @wpsync_tx = \'/'] as $failing) {
            $db3 = $this->connection();
            $db3->fail($failing, 'gone away');
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
    }

    public function testADatabaseErrorIsNeverPrintedIntoTheAnswer(): void
    {
        // WP_DEBUG mit WP_DEBUG_DISPLAY: $wpdb gibt einen Fehler samt Abfrage – und damit samt der
        // Werte des Pakets – als HTML aus, vor dem JSON der Antwort.
        $this->db = $this->connection();
        $this->db->show_errors();
        $this->db->fail('/^INSERT INTO `wp_postmeta`/', 'e2e boom');
        $sql = $this->sql();
        try {
            $sql->transaction(static function () use ($sql): void {
                $sql->write('postmeta', "5\0_e2e", ['values' => ['ein Wert des Pakets']]);
            });
            $this->fail('no exception');
        } catch (ContentException $e) {
            $this->assertSame(ContentException::FAILED, $e->reason());
        }
        $this->assertSame([], $this->db->shown, 'kein Schreibfehler steht in der Antwort');
        $this->assertTrue($this->db->show_errors, 'danach gilt wieder die Einstellung der Site');

        $this->db->fail('/FROM `wp_posts`/', 'Table is marked as crashed');
        try {
            $sql->read('posts', ['219'], false);
            $this->fail('no exception');
        } catch (ContentException $e) {
            $this->assertSame([], $this->db->shown, 'kein Lesefehler steht in der Antwort');
        }
        $this->assertTrue($this->db->show_errors);
    }

    public function testAFailedReadIsNotAnEmptyResult(): void
    {
        $this->db->fail('/FROM `wp_posts`/', 'Table is marked as crashed');
        $this->expectException(ContentException::class);
        $this->sql()->read('posts', ['219'], false);
    }

    /**
     * M2: hide_errors() unterdrückt nur die Ausgabe – wpdb::print_error() schriebe die ganze Abfrage
     * samt der Werte des Pakets per error_log() ins Protokoll des Servers. Jede Abfrage des Kanals
     * läuft deshalb mit suppress_errors(true); danach gilt wieder die Einstellung der Site, und
     * last_error bleibt lesbar.
     */
    public function testNoQueryOfTheChannelReachesTheErrorLog(): void
    {
        $secret = 'geheimer-wert-des-pakets';
        $this->db->fail('/^(INSERT|UPDATE|DELETE)/', 'Deadlock found');
        try {
            $sql = $this->sql();
            $sql->transaction(static function () use ($sql, $secret): void {
                $sql->write('postmeta', "219\0_x", ['values' => [$secret]]);
            });
            $this->fail('no exception');
        } catch (ContentException $e) {
            $this->assertSame('content_failed', $e->reason());
        }
        $this->assertSame([], $this->db->logged, 'kein Schreibfehler steht im Fehlerprotokoll');
        $this->assertSame([], $this->db->unsuppressed, 'auch SET, START TRANSACTION und ROLLBACK laufen unterdrückt');
        $this->assertNotSame([], $this->db->suppressCalls);
        $this->assertFalse($this->db->suppress_errors, 'danach gilt wieder die Einstellung der Site');

        // Lesen – auch unter Sperre –, Zähler, Zwillinge, Zuordnungen, Engines.
        $this->db = new FakeWpdb();
        $this->db->suppress_errors(true); // die Site unterdrückt schon selbst
        $this->db->suppressCalls = [];
        $this->db->fail('/^(SELECT|SHOW)/', 'Table is marked as crashed');
        $asks = [
            function (): void { $this->sql()->read('posts', ['219'], true); },
            function (): void { $this->sql()->read('postmeta', ["219\0_x"], true); },
            function (): void { $this->sql()->read('term_relationships', ["219\0category"], false); },
            function (): void { $this->sql()->aliases('options', ['blogname']); },
            function (): void { $this->sql()->relations(['219'], true); },
            function (): void { $this->sql()->taxonomies(['5']); },
            function (): void { $this->sql()->engines(['posts']); },
            function (): void { $this->sql()->idMax('posts'); },
        ];
        foreach ($asks as $i => $ask) {
            try {
                $ask();
                $this->fail('no exception in ask ' . $i);
            } catch (ContentException $e) {
                $this->assertSame('content_failed', $e->reason(), 'last_error wird weiter gelesen (ask ' . $i . ')');
            }
        }
        $this->assertSame([], $this->db->logged);
        $this->assertSame([], $this->db->unsuppressed);
        $this->assertTrue($this->db->suppress_errors, 'die Einstellung der Site bleibt, wie sie war');
        $this->assertFalse($this->sql()->alive());
        $this->assertSame([], $this->db->unsuppressed);
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

    /** M1: höchste vergebene ID einer Zähler-Tabelle – dieselbe Rechnung wie id_max im Manifest-Kopf. */
    public function testIdMaxIsTheHigherOfMaxAndAutoIncrement(): void
    {
        $this->db->answer('/^SHOW TABLE STATUS LIKE \'stg\\\\\\\\_posts\'/', [['Engine' => 'InnoDB', 'Auto_increment' => '1205']]);
        $this->db->answer('/^SHOW TABLE STATUS/', [['Engine' => 'InnoDB', 'Auto_increment' => '47']]);
        $this->db->answer('/^SELECT MAX\(`ID`\) FROM `stg_posts`/', '900');
        $this->db->answer('/^SELECT MAX\(`term_id`\) FROM `stg_terms`/', '50');
        $this->db->answer('/^SELECT MAX\(`term_taxonomy_id`\) FROM `stg_term_taxonomy`/', null);
        $sql = $this->sql('stg_');
        $this->assertSame(1204, $sql->idMax('posts'), 'AUTO_INCREMENT − 1 gewinnt');
        $this->assertSame(50, $sql->idMax('terms'), 'MAX(id) gewinnt');
        $this->assertSame(46, $sql->idMax('term_taxonomy'), 'leere Tabelle');
        foreach ($this->db->queries as $query) {
            $this->assertStringNotContainsString('wp_', $query, 'nur die Tabellen des Ziels');
        }
        try {
            $sql->idMax('options');
            $this->fail('accepted a table without a counter');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('options', $e->getMessage());
        }

        $this->db = new FakeWpdb();
        $this->db->fail('/^SELECT MAX/', 'Table is marked as crashed');
        $this->expectException(ContentException::class);
        $this->sql()->idMax('posts');
    }

    public function testReadsTheRawRelationshipsOfAnObject(): void
    {
        $this->db->answer('/FROM `wp_term_relationships`/', [['o' => '219', 'tt' => '3'], ['o' => '219', 'tt' => '77'], ['o' => '220', 'tt' => '3']]);
        $this->assertSame(['219' => ['3', '77'], '220' => ['3']], $this->sql()->relations(['219', '220', '219'], true));
        $this->assertSame('SELECT `object_id` AS o, `term_taxonomy_id` AS tt FROM `wp_term_relationships` WHERE `object_id` IN (219,220) FOR UPDATE', $this->db->queries[0], 'ohne JOIN: auch Zuordnungen ohne term_taxonomy-Zeile');
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
