<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\TestCase;
use WpSync\RescueDb;

require_once __DIR__ . '/FakeRescueLink.php';

/**
 * RescueDb (Spec Content-Push P3 §6): die wpdb-Fläche für ContentSql auf einer eigenen
 * Verbindung – prepare in einem Durchgang, Sitzung wie WordPress, ein Reconnect wie wpdb, kein
 * Fehlertext. Gegen eine Attrappe der Verbindung; eine echte Datenbank sieht MysqliLink im E2E.
 */
final class RescueDbTest extends TestCase
{
    private const DB = [
        'host' => 'db.internal', 'port' => 3306, 'socket' => null, 'user' => 'wp_user', 'password' => 'geh3im!', 'name' => 'wordpress',
        'flags' => 0, 'charset' => 'utf8mb4', 'collate' => 'utf8mb4_unicode_520_ci', 'sql_mode' => 'NO_ENGINE_SUBSTITUTION',
    ];

    /** @var list<FakeRescueLink> jede Verbindung, die connect() geöffnet hat */
    private array $links = [];
    /** @var list<array<string, mixed>> womit sie geöffnet wurde */
    private array $opened = [];
    /** @var (callable(FakeRescueLink, int): void)|null richtet die n-te Verbindung ein (0 = die erste) */
    private $prepare = null;
    /** @var int|null ab der wievielten Verbindung der Aufbau scheitert */
    private ?int $refuseFrom = null;

    private function connect(array $over = []): ?RescueDb
    {
        return RescueDb::connect($over + self::DB, function (array $db): ?FakeRescueLink {
            $this->opened[] = $db;
            if ($this->refuseFrom !== null && count($this->links) >= $this->refuseFrom) {
                return null;
            }
            $link = new FakeRescueLink();
            if ($this->prepare !== null) {
                ($this->prepare)($link, count($this->links));
            }
            return $this->links[] = $link;
        });
    }

    public function testPrepareSetsValuesInOnePass(): void
    {
        $db = new RescueDb(new FakeRescueLink());
        $this->assertSame("SELECT 1 WHERE a = 'x' AND b = 7", $db->prepare('SELECT 1 WHERE a = %s AND b = %d', 'x', 7));
        $this->assertSame("a = 'O\\'Brien \\\\ \\\"'", $db->prepare('a = %s', "O'Brien \\ \""));
        // Ein Wert, der selbst einen Platzhalter enthält, wird nicht noch einmal gedeutet.
        $this->assertSame("a = '%s %d %%' AND b = 'zwei'", $db->prepare('a = %s AND b = %s', '%s %d %%', 'zwei'));
        $this->assertSame('ID IN (219,-5,9223372036854775807)', $db->prepare('ID IN (%d,%d,%d)', '219', -5, '9223372036854775807'));
        $this->assertSame("x = ''", $db->prepare('x = %s', ''));
        $this->assertSame('SELECT 1', $db->prepare('SELECT 1'));
    }

    /** @return array<string, array{0: string, 1: list<mixed>}> */
    public static function refused(): array
    {
        return [
            'keine Zahl'               => ['ID = %d', ['1 OR 1=1']],
            'Zahl mit Leerzeichen'     => ['ID = %d', [' 5']],
            'Zahl mit Zeilenvorschub'  => ['ID = %d', ["5\n"]],
            'leere Zahl'               => ['ID = %d', ['']],
            'zu lange Zahl'            => ['ID = %d', ['12345678901234567890']],
            'Kommazahl'                => ['ID = %d', ['1.5']],
            'hexadezimal'              => ['ID = %d', ['0x1A']],
            'anderer Platzhalter'      => ['a = %f', [1]],
            'Bezeichner-Platzhalter'   => ['SELECT %i', ['spalte']],
            'nummerierter Platzhalter' => ['a = %1$s', ['x']],
            'Prozent am Ende'          => ['a LIKE 100%', []],
            'doppeltes Prozent'        => ['a = %%s', ['x']],
            'zu wenige Werte'          => ['a = %s AND b = %s', ['x']],
            'zu viele Werte'           => ['a = %s', ['x', 'y']],
            'NULL als Wert'            => ['a = %s', [null]],
            'Liste als Wert'           => ['a = %s', [['x']]],
            'Wahrheitswert'            => ['a = %d', [true]],
            'Kommazahl als Wert'       => ['a = %d', [1.0]],
        ];
    }

    /** @param list<mixed> $args */
    #[\PHPUnit\Framework\Attributes\DataProvider('refused')]
    public function testPrepareRefusesWhatItDoesNotKnow(string $sql, array $args): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new RescueDb(new FakeRescueLink()))->prepare($sql, ...$args);
    }

    public function testEscLikeIsTheOneOfWpdb(): void
    {
        $db = new RescueDb(new FakeRescueLink());
        $this->assertSame('wp\\_posts 100\\% a\\\\b', $db->esc_like('wp_posts 100% a\\b'));
        $this->assertSame("SHOW TABLES LIKE 'wp\\\\_posts'", $db->prepare('SHOW TABLES LIKE %s', $db->esc_like('wp_posts')));
    }

    public function testResultsVarAndQuery(): void
    {
        $link = new FakeRescueLink();
        $link->answer('/FROM t/', [['a' => '1', 'b' => null], ['a' => '2', 'b' => 'x']]);
        $link->answer('/SELECT @@x/', [['@@x' => null]]);
        $link->answer('/SELECT MAX/', 41);
        $db = new RescueDb($link);
        $this->assertSame([['a' => '1', 'b' => null], ['a' => '2', 'b' => 'x']], $db->get_results('SELECT * FROM t', 'ARRAY_A'));
        $this->assertSame('1', $db->get_var('SELECT a FROM t'));
        $this->assertNull($db->get_var('SELECT @@x'), 'NULL bleibt null');
        $this->assertSame('41', $db->get_var('SELECT MAX(id)'));
        $this->assertNull($db->get_var('SELECT nichts'));
        $this->assertSame([], $db->get_results('SELECT nichts'));
        $this->assertTrue($db->query('UPDATE t SET a = 1'));
        $this->assertSame(2, $db->query('SELECT * FROM t'));
        $this->assertSame('', $db->last_error);
    }

    /** §6.1: kein Text des Servers, keine Ausgabe – nur 'error'; der nächste Erfolg löscht ihn. */
    public function testAnErrorIsSilentAndCarriesNoText(): void
    {
        $link = new FakeRescueLink();
        $link->fail('/kaputt/', 1062);
        $db = new RescueDb($link);
        ob_start();
        $this->assertFalse($db->query("INSERT INTO kaputt VALUES ('geheimer wert')"));
        $this->assertSame('error', $db->last_error);
        $this->assertNull($db->get_results('SELECT * FROM kaputt'));
        $this->assertSame('error', $db->last_error);
        $this->assertNull($db->get_var('SELECT kaputt'));
        $this->assertSame('', (string) ob_get_clean());
        $this->assertTrue($db->query('SET @x = 1'));
        $this->assertSame('', $db->last_error);
        $this->assertNull($db->dbh, 'ohne mysqli nennt auch ContentSql::note() nichts');
        $this->assertCount(4, $link->queries, 'ein gewöhnlicher Fehler wird nicht wiederholt');
        // Die Schalter von wpdb gibt es, sie bewirken nichts.
        $this->assertFalse($db->hide_errors());
        $this->assertFalse($db->show_errors());
        $db->suppress_errors(true);
    }

    /** §6.2: Zeichensatz, SET NAMES mit Kollation, sql_mode, Datenbank – in dieser Reihenfolge. */
    public function testConnectBuildsTheSessionOfWordPress(): void
    {
        $db = $this->connect();
        $this->assertNotNull($db);
        $this->assertSame([
            'charset:utf8mb4',
            "query:SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_520_ci'",
            "query:SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'",
            'select:wordpress',
        ], $this->links[0]->calls);
        $this->assertSame('db.internal', $this->opened[0]['host']);

        $this->links = [];
        $this->assertNotNull($this->connect(['collate' => '', 'sql_mode' => '']));
        $this->assertSame(['charset:utf8mb4', "query:SET NAMES 'utf8mb4'", "query:SET SESSION sql_mode = ''", 'select:wordpress'], $this->links[0]->calls);

        // Ohne Zeichensatz setzt auch wpdb keinen.
        $this->links = [];
        $this->assertNotNull($this->connect(['charset' => '', 'collate' => 'utf8mb4_bin']));
        $this->assertSame(["query:SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'", 'select:wordpress'], $this->links[0]->calls);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: (callable(FakeRescueLink): void)|null}> */
    public static function noSession(): array
    {
        return [
            'Zeichensatz scheitert'    => [[], static function (FakeRescueLink $l): void {
                $l->failCharset = true;
            }],
            'SET NAMES scheitert'      => [[], static function (FakeRescueLink $l): void {
                $l->fail('/^SET NAMES/', 1115);
            }],
            'sql_mode scheitert'       => [[], static function (FakeRescueLink $l): void {
                $l->fail('/sql_mode/', 1231);
            }],
            'Datenbank scheitert'      => [[], static function (FakeRescueLink $l): void {
                $l->failSelect = true;
            }],
            'Zeichensatz mit Anführungszeichen' => [['charset' => "utf8' --"], null],
            'Kollation mit Leerzeichen'         => [['collate' => 'utf8mb4_bin x'], null],
            'sql_mode mit Anführungszeichen'    => [['sql_mode' => "ANSI'; DROP TABLE x; --"], null],
            'sql_mode klein geschrieben'        => [['sql_mode' => 'ansi'], null],
            'sql_mode fehlt'                    => [['sql_mode' => null], null],
            'ohne Datenbank'                    => [['name' => ''], null],
        ];
    }

    /** @param array<string, mixed> $over */
    #[\PHPUnit\Framework\Attributes\DataProvider('noSession')]
    public function testWithoutACompleteSessionThereIsNoConnection(array $over, ?callable $break): void
    {
        $this->prepare = $break === null ? null : static function (FakeRescueLink $link) use ($break): void {
            $break($link);
        };
        $this->assertNull($this->connect($over));
        $this->assertCount(1, $this->links);
        $this->assertTrue($this->links[0]->closed, 'eine halbe Sitzung wird geschlossen');
        $this->assertSame([], preg_grep('/DROP|--/', $this->links[0]->queries));
    }

    public function testNoLinkNoConnection(): void
    {
        $this->refuseFrom = 0;
        $this->assertNull($this->connect());
        $this->assertNull(RescueDb::connect(self::DB, static function (): void {
            throw new \RuntimeException("Access denied for user 'wp_user'@'db.internal'");
        }));
        $this->assertNull(RescueDb::connect(self::DB, static function (): string {
            return 'keine Verbindung';
        }));
    }

    /** R5: Fehler 2006 und 2013 – einmal neu verbinden, Sitzung neu aufbauen, Abfrage einmal wiederholen. */
    #[\PHPUnit\Framework\Attributes\TestWith([2006])]
    #[\PHPUnit\Framework\Attributes\TestWith([2013])]
    public function testALostConnectionIsRebuiltOnceAndTheQueryRepeated(int $errno): void
    {
        $this->prepare = static function (FakeRescueLink $link, int $n) use ($errno): void {
            if ($n === 0) {
                $link->fail('/FROM t/', $errno);
            } else {
                $link->answer('/FROM t/', [['a' => '1']]);
            }
        };
        $db = $this->connect();
        $this->assertSame([['a' => '1']], $db->get_results('SELECT a FROM t'));
        $this->assertSame('', $db->last_error);
        $this->assertCount(2, $this->links);
        $this->assertTrue($this->links[0]->closed);
        $this->assertSame(['charset:utf8mb4', "query:SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_520_ci'", "query:SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'", 'select:wordpress', 'query:SELECT a FROM t'], $this->links[1]->calls);
        // Die nächste Abfrage läuft auf der neuen Verbindung.
        $db->query('SET @x = 1');
        $this->assertSame('SET @x = 1', $this->links[1]->queries[count($this->links[1]->queries) - 1]);
    }

    public function testTheRepeatedQueryIsNotRepeatedAgain(): void
    {
        $this->prepare = static function (FakeRescueLink $link): void {
            $link->fail('/FROM t/', 2006);
        };
        $db = $this->connect();
        $this->assertNull($db->get_results('SELECT a FROM t'));
        $this->assertSame('error', $db->last_error);
        $this->assertCount(2, $this->links, 'ein Reconnect je Abfrage, nicht mehr');
        $this->assertSame(1, count(preg_grep('/FROM t/', $this->links[1]->queries)));
    }

    public function testAFailedReconnectKeepsTheError(): void
    {
        $this->prepare = static function (FakeRescueLink $link): void {
            $link->fail('/FROM t/', 2006);
        };
        $this->refuseFrom = 1;
        $db               = $this->connect();
        $this->assertNull($db->get_results('SELECT a FROM t'));
        $this->assertSame('error', $db->last_error);
        $this->assertCount(1, $this->links);
        $this->assertCount(2, $this->opened);
    }

    public function testOtherErrorsAndAPlainLinkNeverReconnect(): void
    {
        $this->prepare = static function (FakeRescueLink $link): void {
            $link->fail('/FROM t/', 1205); // Lock wait timeout
        };
        $db = $this->connect();
        $this->assertNull($db->get_results('SELECT a FROM t FOR UPDATE'));
        $this->assertCount(1, $this->links);

        $link = new FakeRescueLink();
        $link->fail('/FROM t/', 2006);
        $plain = new RescueDb($link);
        $this->assertFalse($plain->query('DELETE FROM t'));
        $this->assertCount(1, $link->queries);
    }

    public function testCloseClosesTheLink(): void
    {
        $db = $this->connect();
        $db->close();
        $this->assertTrue($this->links[0]->closed);
    }
}
