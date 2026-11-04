<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use WpSync\ActivityLog;
use WpSync\Store;

/** Protokoll des Nachtberichts: eigene Tabelle, nur über prepare(). */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ActivityLogTest extends TestCase
{
    private function boot(): FakeWpdb
    {
        require_once __DIR__ . '/AgentHarness.php';
        require_once __DIR__ . '/FakeWpdb.php';
        define('WPSYNC_VERSION', '0.12.0');
        $db              = new FakeWpdb();
        $GLOBALS['wpdb'] = $db;
        return $db;
    }

    public function testInstallCreatesTheTableAndItIsOwn(): void
    {
        $db = $this->boot();
        Store::install();
        $create = array_values(array_filter($db->queries, static function (string $sql): bool {
            return strpos($sql, 'CREATE TABLE IF NOT EXISTS `wp_wpsync_activity`') === 0;
        }));
        $this->assertCount(1, $create);
        $this->assertStringContainsString('id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY', $create[0]);
        $this->assertStringContainsString('KEY at (at)', $create[0]);
        $this->assertContains('wp_wpsync_activity', Store::ownTables());
    }

    public function testAddWritesOneRowPerKnownEvent(): void
    {
        $db = $this->boot();
        $n  = ActivityLog::add([
            ['kind' => 'updated', 'type' => 'plugin', 'slug' => 'a', 'name' => "A'x", 'from' => '1.0', 'to' => '1.1', 'detail' => null],
            ['kind' => 'update_failed', 'type' => 'plugin', 'slug' => 'w', 'name' => 'W', 'from' => null, 'to' => null, 'detail' => 'download_failed'],
            ['kind' => 'bogus', 'type' => 'plugin', 'slug' => 'x', 'name' => 'X', 'from' => null, 'to' => null, 'detail' => null],
        ], 1000, 'user', 7, 'push:p1');
        $this->assertSame(2, $n);
        $this->assertSame([
            "INSERT INTO `wp_wpsync_activity` (at, kind, type, slug, name, from_version, to_version, source, user_id, detail) VALUES (1000, 'updated', 'plugin', 'a', 'A\\'x', '1.0', '1.1', 'user', 7, 'push:p1')",
            "INSERT INTO `wp_wpsync_activity` (at, kind, type, slug, name, from_version, to_version, source, user_id, detail) VALUES (1000, 'update_failed', 'plugin', 'w', 'W', NULL, NULL, 'user', 7, 'download_failed')",
        ], $db->writes());
    }

    public function testUnknownSourceWritesNothing(): void
    {
        $db = $this->boot();
        $this->assertSame(0, ActivityLog::add([['kind' => 'updated', 'type' => 'plugin', 'slug' => 'a', 'name' => 'A', 'from' => '1', 'to' => '2', 'detail' => null]], 1, 'hacker', null, ''));
        $this->assertSame([], $db->writes());
    }

    public function testBetweenReadsInOrderWithTypes(): void
    {
        $db = $this->boot();
        $db->answer('/^SELECT id, at, kind, type, slug, name, from_version, to_version, source, user_id, detail FROM `wp_wpsync_activity` WHERE at >= 100 AND at <= 200 ORDER BY at ASC, id ASC LIMIT 11$/', [
            ['id' => '4', 'at' => '150', 'kind' => 'updated', 'type' => 'plugin', 'slug' => 'a', 'name' => 'A', 'from_version' => '1', 'to_version' => '2', 'source' => 'auto', 'user_id' => null, 'detail' => null],
            ['id' => '5', 'at' => '160', 'kind' => 'activated', 'type' => 'plugin', 'slug' => 'b', 'name' => 'B', 'from_version' => null, 'to_version' => null, 'source' => 'user', 'user_id' => '3', 'detail' => null],
        ]);
        $this->assertSame([
            ['at' => 150, 'kind' => 'updated', 'type' => 'plugin', 'slug' => 'a', 'name' => 'A', 'from' => '1', 'to' => '2', 'source' => 'auto', 'user_id' => null, 'detail' => null],
            ['at' => 160, 'kind' => 'activated', 'type' => 'plugin', 'slug' => 'b', 'name' => 'B', 'from' => null, 'to' => null, 'source' => 'user', 'user_id' => 3, 'detail' => null],
        ], ActivityLog::between(100, 200, 11));
    }

    public function testPruneDeletesOldRows(): void
    {
        $db = $this->boot();
        ActivityLog::prune(5000);
        $this->assertSame(['DELETE FROM `wp_wpsync_activity` WHERE at < 5000'], $db->writes());
    }
}
