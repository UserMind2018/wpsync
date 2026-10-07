<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use WpSync\Staging;
use WpSync\Store;

/**
 * Store::dataTables() ist die eine Tabellenliste für Pull, Infosheet und die Quelle einer
 * Staging-Kopie (AC-83, Leitplanke 3): Staging-Tabellen stehen nie darin. Store und Staging
 * laufen echt gegen FakeWpdb – die Liste wird pro Request gemerkt, deshalb ein Prozess pro Test.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class StoreDataTablesTest extends TestCase
{
    /** @var FakeWpdb */
    private $db;

    /**
     * @param list<string>              $tables
     * @param array<string, mixed>|null $record Datensatz der Kopie in wpsync_state
     */
    private function boot(array $tables, ?array $record, string $prefix = 'wp_'): void
    {
        require_once __DIR__ . '/AgentHarness.php';
        require_once __DIR__ . '/FakeWpdb.php';
        $this->db              = new FakeWpdb();
        $this->db->base_prefix = $prefix;
        $this->db->answer('/^SHOW FULL TABLES/', array_map(static function (string $name): array {
            return [$name, 'BASE TABLE'];
        }, $tables));
        $this->db->answer(
            "/^SELECT value FROM `" . $prefix . "wpsync_state` WHERE name = '" . Staging::STATE . "'/",
            $record === null ? null : json_encode($record)
        );
        $GLOBALS['wpdb'] = $this->db;
    }

    public function testHidesTheTablesOfTheStagingCopy(): void
    {
        $this->boot(
            ['wp_options', 'wp_posts', 'wp_stg_notes', 'stgabc123_options', 'stgabc123_users'],
            ['status' => 'ready', 'prefix' => 'stgabc123_', 'dir' => 'wpsync-staging-0123456789ab']
        );
        $this->assertSame(['stgabc123_'], Staging::hiddenPrefixes());
        $this->assertSame(['wp_options', 'wp_posts', 'wp_stg_notes'], Store::dataTables());
    }

    public function testAskedOncePerRequest(): void
    {
        $this->boot(['wp_options'], null);
        Store::dataTables();
        Store::dataTables();
        $shows = array_filter($this->db->queries, static function (string $sql): bool {
            return strpos($sql, 'SHOW FULL TABLES') === 0;
        });
        $this->assertCount(1, $shows);
    }

    /** Kein Datensatz (abgebrochenes create, verlorener Zustand): das Namensmuster genügt. */
    public function testHidesOrphanedStagingTablesWithoutARecord(): void
    {
        $this->boot(['options', 'posts', 'stgabc123_options', 'stgabc123_wc_orders'], null, '');
        $this->assertSame([], Staging::hiddenPrefixes());
        $this->assertSame(['options', 'posts'], Store::dataTables());
    }

    /** Ein beschädigter Datensatz verstellt den Filter nicht – weder versteckt er Live noch öffnet er Staging. */
    public function testATamperedRecordNeitherHidesLiveNorExposesStaging(): void
    {
        $this->boot(['options', 'posts', 'stgabc123_users'], ['status' => 'ready', 'prefix' => 'po', 'dir' => '../..'], '');
        $this->assertSame([], Staging::hiddenPrefixes());
        $this->assertSame(['options', 'posts'], Store::dataTables());
    }

    /** hiddenPrefixes() liest den Datensatz über Store::getState() – das darf dataTables() nie wieder betreten. */
    public function testReadingTheRecordDoesNotListTablesAgain(): void
    {
        $this->boot(['wp_options'], ['status' => 'ready', 'prefix' => 'stgabc123_', 'dir' => 'wpsync-staging-0123456789ab']);
        Store::dataTables();
        $this->assertSame(
            ['SHOW FULL TABLES', 'SELECT value FROM'],
            array_map(static function (string $sql): string {
                return strpos($sql, 'SHOW') === 0 ? 'SHOW FULL TABLES' : substr($sql, 0, 17);
            }, $this->db->queries)
        );
    }
}
