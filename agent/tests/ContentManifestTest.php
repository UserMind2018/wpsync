<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use WpSync\Anonymizer;
use WpSync\Canon;
use WpSync\ContentLists;
use WpSync\ContentManifest;
use WpSync\Scope;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ContentManifestTest extends TestCase
{
    private FakeWpdb $db;

    protected function setUp(): void
    {
        require_once __DIR__ . '/ContentHarness.php';
        require_once __DIR__ . '/FakeWpdb.php';
        $GLOBALS['wpsync_options'] = ['home' => 'https://kunde.de', 'siteurl' => 'https://kunde.de'];
        $this->db                  = new FakeWpdb();
        $GLOBALS['wpdb']           = $this->db;
        $this->db->answer('/^SHOW TABLE STATUS LIKE \'wp\\\\\\\\_posts\'/', [['Engine' => 'InnoDB', 'Auto_increment' => '1205']]);
        $this->db->answer('/^SHOW TABLE STATUS LIKE \'wp\\\\\\\\_options\'/', [['Engine' => 'MyISAM', 'Auto_increment' => '900']]);
        $this->db->answer('/^SHOW TABLE STATUS/', [['Engine' => 'InnoDB', 'Auto_increment' => '47']]);
        $this->db->answer('/^SELECT MAX\(`ID`\) FROM `wp_posts`/', '1127');
        $this->db->answer('/^SELECT MAX\(/', '50');
    }

    /** @return list<array<string, mixed>> */
    private function page(?array $cursor, ?Scope $scope = null, float $budget = 60.0): array
    {
        $lines = [];
        ContentManifest::page($cursor, $scope ?? Scope::fromArray(null), static function (string $line) use (&$lines): void {
            $lines[] = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        }, microtime(true) + $budget);
        return $lines;
    }

    /** @return list<string> die Zeilen, wie sie der Endpunkt schreibt */
    private function sent(?array $cursor): array
    {
        $lines = [];
        ContentManifest::send($cursor, Scope::fromArray(null), static function (string $line) use (&$lines): void {
            $lines[] = $line;
        }, microtime(true) + 60.0);
        return $lines;
    }

    /** Spec §4.2: Kopf mit allem, was Studio und CLI brauchen */
    public function testHead(): void
    {
        $head = $this->page(null)[0]['head'];
        $this->assertSame(Canon::VERSION, $head['canon_version']);
        $this->assertSame(ContentLists::VERSION, $head['list_version']);
        $this->assertSame(['plain', 'esc1', 'esc2'], $head['variants']);
        $this->assertSame(['home' => 'https://kunde.de', 'siteurl' => 'https://kunde.de'], $head['origins']);
        $this->assertSame(['posts' => 1204, 'terms' => 50, 'term_taxonomy' => 50], $head['id_max'], 'AUTO_INCREMENT - 1 gewinnt über MAX(ID)');
        $this->assertSame('InnoDB', $head['engines']['posts']);
        $this->assertSame('MyISAM', $head['engines']['options']);
        $this->assertCount(7, $head['engines']);
        $this->assertSame(Canon::TABLES, $head['tables']);
        $this->assertSame(Anonymizer::patterns(), $head['pseudonym']);
        $this->assertSame('wp_', $head['prefix']);
        $this->assertSame('utf8mb4', $head['charset']);
        $this->assertTrue($head['pushable']);
        $this->assertArrayNotHasKey('why', $head);
        $this->assertSame(ContentLists::export(), $head['lists']);
    }

    /** C8: andere Origin für siteurl → kein Inhalts-Push; home/wp bleibt erlaubt */
    public function testOriginMismatch(): void
    {
        $GLOBALS['wpsync_options']['siteurl'] = 'https://kunde.de/wp';
        $this->assertTrue($this->page(null)[0]['head']['pushable']);
        $GLOBALS['wpsync_options']['siteurl'] = 'https://wp.kunde.de';
        $head = $this->page(null)[0]['head'];
        $this->assertFalse($head['pushable']);
        $this->assertSame('origin_mismatch', $head['why']);
        $GLOBALS['wpsync_options']['siteurl'] = 'http://kunde.de';
        $this->assertSame('origin_mismatch', $this->page(null)[0]['head']['why']);
        $GLOBALS['wpsync_options']['siteurl'] = 'https://KUNDE.de:443';
        $this->assertTrue($this->page(null)[0]['head']['pushable'], 'Host gross/klein und der Standard-Port zählen nicht');
    }

    public function testFirstPageCarriesHeadRowsAndNext(): void
    {
        $this->db->answer('/FROM `wp_options`/', [['option_id' => '2', 'option_name' => 'blogname', 'option_value' => 'Kunde']], []);
        $lines = $this->page(null);
        $this->assertArrayHasKey('head', $lines[0]);
        $this->assertSame(['t' => 'options', 'k' => 'blogname', 'h' => Canon::hash('options', 'blogname', Canon::columns(['option_value'], ['option_value' => 'Kunde']))], $lines[1]);
        $this->assertSame(['next' => null], $lines[2]);
        $this->assertCount(3, $lines);
    }

    /** Nur Fingerabdrücke: kein Wert einer Zeile steht in der Antwort. */
    public function testNoValueLeavesTheServer(): void
    {
        $this->db->answer('/FROM `wp_options`/', [['option_id' => '2', 'option_name' => 'blogname', 'option_value' => 'Streng geheim']], []);
        $sent = implode("\n", $this->sent(null));
        $this->assertStringContainsString('"k":"blogname"', $sent);
        $this->assertStringNotContainsString('"row"', $sent);
        $this->assertStringNotContainsString('Streng geheim', $sent);
        $this->assertStringNotContainsString(base64_encode('Streng geheim'), $sent);
    }

    public function testLaterPagesHaveNoHeadAndResume(): void
    {
        $lines = $this->page(['t' => 6, 'a' => '40']);
        $this->assertSame([['next' => null]], $lines);
        $options = array_values(preg_grep('/FROM `wp_options`/', $this->db->queries));
        $this->assertStringContainsString('`option_id` > 40', $options[0]);
        $this->assertSame([], preg_grep('/FROM `wp_posts`/', $this->db->queries));
    }

    public function testBudgetEndsThePageWithACursor(): void
    {
        $page = [];
        for ($i = 1; $i <= 500; $i++) {
            $page[] = ['term_id' => (string) $i, 'name' => 'n', 'slug' => 's', 'term_group' => '0'];
        }
        $this->db->answer('/FROM `wp_terms`/', $page);
        $lines = $this->page(['t' => 2, 'a' => ''], null, -1.0);
        $next  = $lines[count($lines) - 1];
        $this->assertSame(['next' => ['t' => 2, 'a' => '500']], $next);
        $this->assertSame($next['next'], ContentManifest::cursor($next['next']), 'der Cursor des Agents ist der der nächsten Anfrage');
    }

    /** Wie /db: Tabellen ausserhalb des Profils verlassen den Server nicht */
    public function testScopeLeavesTablesAndPostTypesOut(): void
    {
        $scope = Scope::fromArray(['tables' => ['wp_termmeta' => 'skip', 'wp_options' => 'structure'], 'exclude_post_types' => ['shop_order']]);
        $lines = $this->page(null, $scope);
        $this->assertSame(['posts', 'postmeta', 'terms', 'term_taxonomy', 'term_relationships'], $lines[0]['head']['tables']);
        $sql = implode("\n", $this->db->queries);
        $this->assertStringNotContainsString('FROM `wp_termmeta`', $sql);
        $this->assertStringNotContainsString('FROM `wp_options`', $sql);
        $this->assertStringContainsString("NOT IN ('shop_order')", $sql);
    }

    public function testMultisiteIsNotPushable(): void
    {
        $GLOBALS['wpsync_test_multisite'] = true;
        $this->assertSame('multisite', $this->page(null)[0]['head']['why']);
    }

    public function testCursorAcceptsNullAndWhatTheAgentHandsOut(): void
    {
        $this->assertNull(ContentManifest::cursor(null));
        $this->assertSame(['t' => 0, 'a' => ''], ContentManifest::cursor(['t' => 0, 'a' => '']));
        $this->assertSame(['t' => 6, 'a' => '18446744073709551615'], ContentManifest::cursor(['a' => '18446744073709551615', 't' => 6, 'x' => 'y']));
    }

    /** @return array<string, array{0: mixed}> */
    public static function invalidCursors(): array
    {
        return [
            'text'              => ['x'],
            'zahl'              => [5],
            'false'             => [false],
            'leer'              => [[]],
            'liste'             => [[2, '5']],
            'ohne a'            => [['t' => 2]],
            'ohne t'            => [['a' => '5']],
            't zu gross'        => [['t' => 7, 'a' => '']],
            't negativ'         => [['t' => -1, 'a' => '']],
            't als text'        => [['t' => '2', 'a' => '']],
            't als bruch'       => [['t' => 2.0, 'a' => '']],
            't als liste'       => [['t' => [2], 'a' => '']],
            'a als zahl'        => [['t' => 2, 'a' => 5]],
            'a als liste'       => [['t' => 2, 'a' => ['5']]],
            'a null'            => [['t' => 2, 'a' => null]],
            'a mit sql'         => [['t' => 2, 'a' => '5 OR 1=1']],
            'a negativ'         => [['t' => 2, 'a' => '-5']],
            'a mit zeilenende'  => [['t' => 2, 'a' => "5\n"]],
            'a zu lang'         => [['t' => 2, 'a' => str_repeat('9', 21)]],
        ];
    }

    /**
     * ContentReader castet nur – alles andere als null oder {t: 0…6, a: Ziffern} lehnt der Endpunkt ab.
     *
     * @param mixed $raw
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidCursors')]
    public function testCursorRejectsEverythingElse($raw): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ContentManifest::cursor($raw);
    }

    /** Mitten im Stream gibt es keinen Statuscode mehr – und keine Details der Datenbank. */
    public function testAReadErrorEndsThePageWithoutDetails(): void
    {
        $this->db->fail('/FROM `wp_posts`/', "Table 'kunde_live.wp_posts' doesn't exist");
        $sent = $this->sent(null);
        $this->assertStringStartsWith('{"head":', $sent[0]);
        $this->assertSame('{"error":"read_failed"}', $sent[count($sent) - 1]);
        $this->assertStringNotContainsString('kunde_live', implode("\n", $sent));
        $this->assertStringNotContainsString('"next"', implode("\n", $sent));
    }

    /** Ein id_max aus einer gescheiterten Abfrage läge unter dem, was Live schon vergeben hat (B8). */
    public function testAnUnreadableHeadIsAnErrorNotAHead(): void
    {
        $this->db        = new FakeWpdb();
        $GLOBALS['wpdb'] = $this->db;
        $this->db->fail('/^SHOW TABLE STATUS/', "SHOW command denied to user 'kunde'@'localhost'");
        $this->assertSame(['{"error":"read_failed"}'], $this->sent(null));
    }

    public function testAnInvalidHomeIsAnErrorLine(): void
    {
        $GLOBALS['wpsync_options']['home'] = 'kunde.de';
        $sent = $this->sent(['t' => 0, 'a' => '']);
        $this->assertSame(['{"error":"read_failed"}'], $sent);
    }
}
