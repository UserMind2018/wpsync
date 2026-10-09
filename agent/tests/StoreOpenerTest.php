<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use WpSync\Store;

/**
 * Wer das Push-Fenster geöffnet hat (Spec Content-Push §9, AC-145): Spalten, Migration, Lesen und
 * Schreiben. Store läuft echt gegen FakeWpdb – ein Prozess pro Test (Spalten-Cache, Konstanten).
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class StoreOpenerTest extends TestCase
{
    private const PAIRINGS = [
        ['Field' => 'key_id', 'Type' => 'char(16)'],
        ['Field' => 'secret', 'Type' => 'varchar(255)'],
        ['Field' => 'device', 'Type' => 'varchar(100)'],
        ['Field' => 'created', 'Type' => 'int(10) unsigned'],
        ['Field' => 'last_used', 'Type' => 'int(10) unsigned'],
        ['Field' => 'push_until', 'Type' => 'int(10) unsigned'],
    ];

    /** @var FakeWpdb */
    private $db;

    /** @param bool $migrated die Tabellen haben die Spalten schon (Agent ≥ 0.6.0) */
    private function boot(bool $migrated): void
    {
        require_once __DIR__ . '/AgentHarness.php';
        require_once __DIR__ . '/FakeWpdb.php';
        define('WPSYNC_VERSION', 'test');
        $this->db = new FakeWpdb();
        $pairings = self::PAIRINGS;
        $pushes   = [['Field' => 'push_id', 'Type' => 'varchar(32)']];
        if ($migrated) {
            $pairings[] = ['Field' => 'push_opened_by', 'Type' => 'bigint(20) unsigned'];
            $pushes[]   = ['Field' => 'opened_by', 'Type' => 'bigint(20) unsigned'];
        }
        $this->db->answer('/^SHOW COLUMNS FROM `wp_wpsync_pairings`/', $pairings);
        $this->db->answer('/^SHOW COLUMNS FROM `wp_wpsync_pushes`/', $pushes);
        $GLOBALS['wpdb'] = $this->db;
    }

    /** AC-145: Öffnen merkt sich den Benutzer, Schliessen löscht ihn, ohne Benutzer bleibt er leer. */
    public function testOpeningStoresTheUserAndClosingClearsIt(): void
    {
        $this->boot(true);
        Store::setPushUntil('k1', 1800000900, 7);
        Store::setPushUntil('k1', 0, 7);
        Store::setPushUntil('k1', 1800000900);
        $this->assertSame([
            'UPDATE `wp_wpsync_pairings` SET {"push_until":1800000900,"push_opened_by":7} WHERE {"key_id":"k1"}',
            'UPDATE `wp_wpsync_pairings` SET {"push_until":0,"push_opened_by":null} WHERE {"key_id":"k1"}',
            'UPDATE `wp_wpsync_pairings` SET {"push_until":1800000900,"push_opened_by":null} WHERE {"key_id":"k1"}',
        ], $this->db->writes());
    }

    /** AC-145: abgelaufen zählt wie geschlossen – dann gibt es keinen Öffner. */
    public function testTheOpenerCountsOnlyWhileTheWindowIsOpen(): void
    {
        $this->boot(true);
        $now = time();
        $this->db->answer(
            '/^SELECT push_until, push_opened_by FROM `wp_wpsync_pairings`/',
            [['push_until' => (string) ($now + 600), 'push_opened_by' => '7']],
            [['push_until' => (string) ($now - 1), 'push_opened_by' => '7']],
            [['push_until' => (string) ($now + 600), 'push_opened_by' => null]],
            []
        );
        $this->assertSame(7, Store::pushOpener('k1'));
        $this->assertNull(Store::pushOpener('k1'), 'expired');
        $this->assertNull(Store::pushOpener('k1'), 'opened without a user (WP-CLI)');
        $this->assertNull(Store::pushOpener('k1'), 'unknown pairing');
        $this->assertStringContainsString("WHERE key_id = 'k1'", $this->db->queries[1]);
    }

    /** A11: hat ein ALTER nicht geklappt, bleiben Fenster und Pushs benutzbar. */
    public function testWithoutTheColumnNothingBreaks(): void
    {
        $this->boot(false);
        Store::setPushUntil('k1', 1800000900, 7);
        $this->assertSame(['UPDATE `wp_wpsync_pairings` SET {"push_until":1800000900} WHERE {"key_id":"k1"}'], $this->db->writes());
        $this->assertNull(Store::pushOpener('k1'));
        foreach ($this->db->queries as $sql) {
            $this->assertStringNotContainsString('push_opened_by FROM', $sql);
        }
    }

    public function testInstallAddsTheColumnsToOlderTables(): void
    {
        $this->boot(false);
        Store::install();
        $this->assertContains('ALTER TABLE `wp_wpsync_pairings` ADD COLUMN push_opened_by BIGINT UNSIGNED NULL', $this->db->queries);
        $this->assertContains('ALTER TABLE `wp_wpsync_pushes` ADD COLUMN opened_by BIGINT UNSIGNED NULL', $this->db->queries);
        $this->assertSame('test', $GLOBALS['wpsync_options'][Store::SCHEMA_OPTION]);
    }

    public function testAFreshInstallHasTheColumnsAndNeedsNoAlter(): void
    {
        $this->boot(true);
        Store::install();
        $create = implode("\n", array_filter($this->db->queries, static function (string $sql): bool {
            return strpos($sql, 'CREATE TABLE') === 0;
        }));
        $this->assertStringContainsString('push_opened_by BIGINT UNSIGNED NULL', $create);
        $this->assertStringContainsString('opened_by BIGINT UNSIGNED NULL,', $create);
        foreach ($this->db->queries as $sql) {
            $this->assertStringNotContainsString('ADD COLUMN', $sql);
        }
    }
}
