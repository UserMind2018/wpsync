<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\TestCase;
use WpSync\Canon;
use WpSync\ContentException;
use WpSync\ContentImage;
use WpSync\ContentOrigin;
use WpSync\ContentRollback;
use WpSync\ContentSql;
use WpSync\ContentState;
use WpSync\ContentTarget;
use WpSync\RescueDb;

require_once __DIR__ . '/FakeWpdb.php';
require_once __DIR__ . '/FakeRescueLink.php';
require_once __DIR__ . '/ContentFixtures.php';

/**
 * ContentSql über RescueDb (Spec Content-Push P3 R3, R5; AC-172): dieselben Anweisungen wie über
 * wpdb – es gibt nur eine SQL-Implementierung –, und eine verlorene Verbindung mitten in der
 * Transaktion endet wie beim Agent: content_failed ohne unrestored, nichts geschrieben.
 */
final class ContentSqlRescueTest extends TestCase
{
    private string $root = '';

    protected function tearDown(): void
    {
        ContentImage::$keys    = null;
        ContentImage::$encrypt = null;
        if ($this->root !== '') {
            exec('rm -rf ' . escapeshellarg($this->root));
        }
    }

    /** @return array<string, string> */
    private static function tables(): array
    {
        $out = [];
        foreach (Canon::TABLES as $name) {
            $out[$name] = 'wp_' . $name;
        }
        return $out;
    }

    /** Dieselben Antworten für beide Attrappen. */
    private static function script(object $fake): void
    {
        $mark = null;
        $fake->answer('/^SET @wpsync_tx = \'([a-f0-9]{16})\'/', static function (string $sql) use (&$mark) {
            $mark = substr($sql, 18, 16);
            return null;
        });
        $fake->answer('/^SELECT @wpsync_tx/', static function () use (&$mark) {
            return $mark;
        });
        $fake->answer('/^SHOW TABLE STATUS/', [['Engine' => 'InnoDB', 'Auto_increment' => '1000']]);
        $fake->answer('/^SELECT MAX/', 800);
        $fake->answer('/^SELECT \* FROM `wp_posts`/', [['ID' => '219', 'post_title' => 'A']]);
        $fake->answer('/^SELECT \* FROM `wp_options`/', [['option_id' => '4', 'option_name' => 'blogname', 'option_value' => 'Kunde', 'autoload' => 'yes']]);
        $fake->answer('/^SELECT `ID` FROM `wp_posts`/', [['ID' => '219']], []);
        $fake->answer('/AS v FROM `wp_postmeta`/', [['o' => '219', 'k' => '_x', 'v' => 'b']]);
        $fake->answer('/^SELECT `term_id`, `taxonomy`/', [['term_id' => '5', 'taxonomy' => 'category']]);
    }

    /** Jede Art von Anweisung, die der Kanal kennt – auch die, die nur die Rücknahme braucht. */
    private static function exercise(ContentSql $sql): void
    {
        $evil = "x'; DROP TABLE wp_posts; -- %s \\";
        $sql->engines(Canon::TABLES);
        $sql->idMax('posts');
        $sql->read('posts', ['219', '220'], false);
        $sql->read('options', ['blogname', $evil], false);
        $sql->read('postmeta', ["219\0_x", "219\0" . $evil], false);
        $sql->read('term_relationships', ["219\0category"], false);
        $sql->aliases('options', ['blogname']);
        $sql->aliases('postmeta', ["219\0_x"]);
        $sql->taxonomies(['5', '6']);
        $sql->relations(['219'], false);
        $sql->attached('posts', ['1000001'], false);
        $sql->attached('terms', ['1000001'], false);
        $sql->attached('term_taxonomy', ['1000002'], false);
        $sql->transaction(static function () use ($sql, $evil): void {
            $sql->read('posts', ['219'], true);
            $sql->read('postmeta', ["219\0_x"], true);
            $sql->read('term_relationships', ["219\0category"], true);
            $sql->relations(['219'], true);
            $sql->attached('posts', ['1000001'], true);
            $sql->write('posts', '219', ['post_title' => $evil, 'post_excerpt' => null, 'post_date_gmt' => '0000-00-00 00:00:00']); // UPDATE
            $sql->write('posts', '1000001', ['post_title' => 'neu']);                                                              // INSERT
            $sql->write('posts', '220', null);
            $sql->write('options', 'blogname', ['option_id' => '4', 'option_value' => $evil, 'autoload' => 'yes']);
            $sql->write('options', 'weg', null);
            $sql->write('postmeta', "219\0_x", ['values' => ['a', null, $evil]]);
            $sql->write('termmeta', "5\0farbe", null);
            $sql->write('term_relationships', "219\0category", ['values' => ['5:0', '7:2']]);
            $sql->write('term_relationships', "219\0post_tag", null);
            $sql->purge('posts', '1000001');
            $sql->purge('terms', '1000001');
            $sql->purge('term_taxonomy', '1000002');
            $sql->recount(['5', '7']);
            $sql->dropMeta('_elementor_css');
            $sql->alive();
        });
    }

    /** Marke und Kommentar sind Zufall je Lauf. */
    private static function stable(array $queries): array
    {
        return array_map(static function (string $sql): string {
            return (string) preg_replace(['/\'[a-f0-9]{16}\'/', '#/\* [a-f0-9]{16} \*/#'], ["'<mark>'", '/* <n> */'], $sql);
        }, $queries);
    }

    /** AC-172: über RescueDb entstehen dieselben Anweisungen wie über wpdb. */
    public function testTheSameStatementsAsThroughWpdb(): void
    {
        $wpdb = new FakeWpdb();
        self::script($wpdb);
        self::exercise(new ContentSql($wpdb, self::tables(), 'wp_comments'));

        $link = new FakeRescueLink();
        self::script($link);
        self::exercise(new ContentSql(new RescueDb($link), self::tables(), 'wp_comments'));

        $this->assertGreaterThan(50, count($wpdb->queries));
        $this->assertSame(self::stable($wpdb->queries), self::stable($link->queries));
        $this->assertContains('START TRANSACTION', $link->queries);
        $this->assertContains('COMMIT', $link->queries);
        // Jede schreibende Anweisung der Transaktion trägt die Sitzungsmarke.
        $inside = array_slice($link->queries, (int) array_search('START TRANSACTION', $link->queries, true) + 1, -3);
        foreach (preg_grep('/^(INSERT|UPDATE|DELETE)/', $inside) as $write) {
            $this->assertMatchesRegularExpression('/@wpsync_tx = \'[a-f0-9]{16}\'$/', $write);
        }
    }

    /** Ein Wert, der keine Zahl ist, erreicht die Datenbank nicht als Zahl – RescueDb lehnt ab, wo wpdb 0 einsetzt. */
    public function testAKeyThatIsNoNumberNeverBecomesSql(): void
    {
        $link = new FakeRescueLink();
        $sql  = new ContentSql(new RescueDb($link), self::tables());
        try {
            $sql->read('posts', ['1 OR 1=1'], false);
            $this->fail('no exception');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame([], $link->queries);
        }
    }

    /**
     * AC-172, R5: die Verbindung geht beim ersten Schreibzugriff der Rücknahme verloren. RescueDb
     * verbindet neu und wiederholt ihn – auf der neuen Verbindung trägt er die Marke der alten und
     * schreibt nichts. Die Rücknahme endet mit content_failed ohne unrestored; kein COMMIT.
     */
    public function testALostConnectionInTheTransactionFailsWithoutAnUnrestoredRow(): void
    {
        $this->root = sys_get_temp_dir() . '/wpsync-sqlrescue-' . bin2hex(random_bytes(4));
        $dir        = $this->root . '/p_20261009_0123456789ab/content';
        ContentImage::$keys    = [str_repeat('k', 32)];
        ContentImage::$encrypt = false;
        $before = ContentFixtures::option('blogname', 'Kunde');
        $pushed = ContentFixtures::option('blogname', 'Kunde GmbH');
        ContentImage::put($dir, 'before.json', ['keys' => [['t' => 'options', 'k' => 'blogname', 'state' => ContentState::encode('options', $before)]]]);
        ContentImage::put($dir, 'after.json', ['keys' => [['t' => 'options', 'k' => 'blogname', 'h' => ContentFixtures::hash('options', 'blogname', $pushed)]], 'changes' => []]);

        /** @var list<FakeRescueLink> $links */
        $links = [];
        $db    = RescueDb::connect(
            ['name' => 'wordpress', 'charset' => 'utf8mb4', 'collate' => '', 'sql_mode' => ''],
            static function () use (&$links, $pushed): FakeRescueLink {
                $link = new FakeRescueLink();
                $mark = null;
                $link->answer('/^SET @wpsync_tx = \'/', static function (string $sql) use (&$mark) {
                    $mark = substr($sql, 18, 16);
                    return null;
                });
                // Die neue Verbindung kennt die Marke nicht: @wpsync_tx ist dort NULL.
                $link->answer('/^SELECT @wpsync_tx/', static function () use (&$mark) {
                    return $mark === null ? [['@wpsync_tx' => null]] : $mark;
                });
                $link->answer('/^SHOW TABLE STATUS/', [['Engine' => 'InnoDB']]);
                $link->answer('/^SELECT \* FROM `wp_options`/', [$pushed]);
                $link->answer('/^SELECT `option_name` FROM `wp_options`/', [['option_name' => 'blogname']]);
                if ($links === []) {
                    $link->fail('/^UPDATE `wp_options`/', 2006);
                }
                return $links[] = $link;
            }
        );
        $this->assertNotNull($db);
        $target = new ContentTarget('live', new ContentSql($db, self::tables()), new ContentOrigin(ContentFixtures::HOME), ContentFixtures::HOME, ContentFixtures::HOME, ContentFixtures::HOME, 'wp_', '', '');
        try {
            ContentRollback::run($target, $dir);
            $this->fail('no exception');
        } catch (ContentException $e) {
            $this->assertSame(ContentException::FAILED, $e->reason());
            $this->assertArrayNotHasKey('unrestored', $e->toArray());
            $this->assertSame([], $e->keys());
        }
        $this->assertCount(2, $links, 'einmal neu verbunden');
        $this->assertNotContains('COMMIT', array_merge($links[0]->queries, $links[1]->queries));
        // Was die neue Verbindung an Schreibzugriffen sah, war an die Marke der alten gebunden.
        $writes = preg_grep('/^(INSERT|UPDATE|DELETE)/', $links[1]->queries);
        $this->assertCount(1, $writes);
        foreach ($writes as $write) {
            $this->assertMatchesRegularExpression('/ AND @wpsync_tx = \'[a-f0-9]{16}\'$/', $write);
        }
        $this->assertStringStartsWith("UPDATE `wp_options` SET `option_value` = 'Kunde'", (string) array_values($writes)[0]);
    }
}
