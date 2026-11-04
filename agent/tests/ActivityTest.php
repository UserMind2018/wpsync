<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use WpSync\Activity;
use WpSync\ActivityProbe;

final class FakeActivityProbe implements ActivityProbe
{
    /** @var array<string, mixed> */
    public $snap;
    /** @var int */
    public $snapshots = 0;

    /** @param array<string, mixed> $snap */
    public function __construct(array $snap)
    {
        $this->snap = $snap;
    }

    public function snapshot(): array
    {
        $this->snapshots++;
        return $this->snap;
    }

    public function userLogin(int $id): ?string
    {
        return $id === 3 ? 'redaktion' : null;
    }

    public function autoUpdates(): array
    {
        return ['plugins' => ['akismet'], 'themes' => []];
    }
}

/** Abgleich und Bericht: Activity läuft echt gegen FakeWpdb. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ActivityTest extends TestCase
{
    private const OLD = '{"core":"6.8.1","theme":"tt5","plugins":{"a":{"v":"1.0","a":true,"n":"A"}},"themes":{"tt5":{"v":"1.0","n":"TT5"}}}';

    private function boot(bool $lock = true): FakeWpdb
    {
        require_once __DIR__ . '/AgentHarness.php';
        require_once __DIR__ . '/FakeWpdb.php';
        define('WPSYNC_VERSION', '0.12.0');
        define('DB_NAME', 'db');
        $db = new FakeWpdb();
        $db->answer('/^SELECT GET_LOCK/', $lock ? '1' : '0');
        $GLOBALS['wpdb'] = $db;
        return $db;
    }

    /** @return array<string, mixed> */
    private function newSnap(string $version): array
    {
        return ['core' => '6.8.1', 'theme' => 'tt5', 'plugins' => ['a' => ['v' => $version, 'a' => true, 'n' => 'A']], 'themes' => ['tt5' => ['v' => '1.0', 'n' => 'TT5']]];
    }

    public function testFirstReconcileOnlyStoresTheBaseline(): void
    {
        $db = $this->boot();
        $this->assertSame(0, Activity::reconcile(new FakeActivityProbe($this->newSnap('1.0')), ['detected', null, ''], 1000));
        $writes = $db->writes();
        $this->assertCount(2, $writes);
        $this->assertStringStartsWith('REPLACE INTO `wp_wpsync_state` {"name":"activity_snapshot"', $writes[0]);
        $this->assertSame('REPLACE INTO `wp_wpsync_state` {"name":"activity_since","value":"{\"at\":1000}"}', $writes[1]);
    }

    public function testChangesAreLoggedBeforeTheSnapshotMoves(): void
    {
        $db = $this->boot();
        $db->answer("/^SELECT value FROM `wp_wpsync_state` WHERE name = 'activity_snapshot'/", self::OLD);
        $this->assertSame(1, Activity::reconcile(new FakeActivityProbe($this->newSnap('1.1')), ['user', 3, ''], 2000));
        $writes = array_values(array_filter($db->writes(), static function (string $sql): bool {
            return strpos($sql, 'INSERT') === 0 || strpos($sql, 'REPLACE') === 0;
        }));
        $this->assertSame("INSERT INTO `wp_wpsync_activity` (at, kind, type, slug, name, from_version, to_version, source, user_id, detail) VALUES (2000, 'updated', 'plugin', 'a', 'A', '1.0', '1.1', 'user', 3, NULL)", $writes[0]);
        $this->assertStringStartsWith('REPLACE INTO `wp_wpsync_state` {"name":"activity_snapshot"', $writes[1]);
        $this->assertCount(2, $writes);
    }

    public function testNoChangeWritesNothing(): void
    {
        $db = $this->boot();
        $db->answer("/^SELECT value FROM `wp_wpsync_state` WHERE name = 'activity_snapshot'/", self::OLD);
        $this->assertSame(0, Activity::reconcile(new FakeActivityProbe($this->newSnap('1.0')), ['detected', null, ''], 2000));
        $this->assertSame([], array_values(array_filter($db->writes(), static function (string $sql): bool {
            return strpos($sql, 'INSERT') === 0 || strpos($sql, 'REPLACE') === 0;
        })));
    }

    public function testABusyLockSkipsWithoutReading(): void
    {
        $this->boot(false);
        $probe = new FakeActivityProbe($this->newSnap('1.1'));
        $this->assertSame(0, Activity::reconcile($probe, ['detected', null, ''], 2000));
        $this->assertSame(0, $probe->snapshots);
    }

    public function testReportRejectsRangesOutsideTheLimits(): void
    {
        $this->boot();
        $probe = new FakeActivityProbe($this->newSnap('1.0'));
        foreach ([['since' => 10, 'until' => 5], ['since' => 0, 'until' => 32 * 86400], ['since' => 1, 'until' => 2, 'limit' => 501], ['since' => 1, 'until' => 2, 'limit' => 0], ['since' => -1, 'until' => 2]] as $params) {
            $error = Activity::report($params, $probe, 40 * 86400);
            $this->assertInstanceOf(\WP_Error::class, $error, json_encode($params));
            $this->assertSame('wpsync_activity_range', $error->code);
            $this->assertSame(400, $error->status);
        }
        $this->assertSame(0, $probe->snapshots, 'kein Abgleich bei falschem Aufruf');
    }

    public function testReportReconcilesThenReadsAndNamesUsers(): void
    {
        $db = $this->boot();
        $db->answer("/^SELECT value FROM `wp_wpsync_state` WHERE name = 'activity_snapshot'/", self::OLD);
        $db->answer("/^SELECT value FROM `wp_wpsync_state` WHERE name = 'activity_since'/", '{"at":500}');
        $db->answer('/^SELECT id, at, kind/', [
            ['id' => '1', 'at' => '1500', 'kind' => 'activated', 'type' => 'plugin', 'slug' => 'b', 'name' => 'B', 'from_version' => null, 'to_version' => null, 'source' => 'user', 'user_id' => '3', 'detail' => null],
            ['id' => '2', 'at' => '1600', 'kind' => 'deactivated', 'type' => 'plugin', 'slug' => 'b', 'name' => 'B', 'from_version' => null, 'to_version' => null, 'source' => 'user', 'user_id' => '9', 'detail' => null],
            ['id' => '3', 'at' => '1700', 'kind' => 'updated', 'type' => 'plugin', 'slug' => 'a', 'name' => 'A', 'from_version' => '1.0', 'to_version' => '1.1', 'source' => 'detected', 'user_id' => null, 'detail' => null],
        ]);
        $probe  = new FakeActivityProbe($this->newSnap('1.1'));
        $report = Activity::report(['since' => 1000, 'until' => 9999, 'limit' => 2], $probe, 2000);
        $this->assertIsArray($report);
        $this->assertSame(1, $probe->snapshots, 'ein Abgleich vor dem Lesen');
        $this->assertContains('SELECT id, at, kind, type, slug, name, from_version, to_version, source, user_id, detail FROM `wp_wpsync_activity` WHERE at >= 1000 AND at <= 2000 ORDER BY at ASC, id ASC LIMIT 3', $db->queries, 'until nie nach jetzt, limit + 1');
        $this->assertSame(['since' => 1000, 'until' => 2000, 'checked' => 2000, 'log_since' => 500, 'agent_version' => '0.12.0', 'core' => '6.8.1'], array_intersect_key($report, array_flip(['since', 'until', 'checked', 'log_since', 'agent_version', 'core'])));
        $this->assertTrue($report['truncated']);
        $this->assertSame(['plugins' => ['akismet'], 'themes' => []], $report['auto_updates']);
        $this->assertSame([
            ['at' => 1500, 'kind' => 'activated', 'type' => 'plugin', 'slug' => 'b', 'name' => 'B', 'from' => null, 'to' => null, 'source' => 'user', 'user' => ['id' => 3, 'login' => 'redaktion'], 'detail' => null],
            ['at' => 1600, 'kind' => 'deactivated', 'type' => 'plugin', 'slug' => 'b', 'name' => 'B', 'from' => null, 'to' => null, 'source' => 'user', 'user' => ['id' => 9], 'detail' => null],
        ], $report['events']);
    }

    public function testReportWithoutLogHasNoLogSince(): void
    {
        $this->boot();
        $report = Activity::report([], new FakeActivityProbe($this->newSnap('1.0')), 100000);
        $this->assertIsArray($report);
        $this->assertSame(100000 - 86400, $report['since']);
        $this->assertNull($report['log_since'], 'FakeWpdb liest den eben geschriebenen Zustand nicht zurück');
        $this->assertSame([], $report['events']);
    }

    public function testNormalizeReadsTheAutoUpdaterResult(): void
    {
        $this->boot();
        $error = new \WP_Error('download_failed', 'x');
        $this->assertSame([
            ['type' => 'core', 'slug' => 'wordpress', 'name' => 'WordPress 6.8.3', 'ok' => true, 'error' => ''],
            ['type' => 'plugin', 'slug' => 'woo', 'name' => 'Woo', 'ok' => false, 'error' => 'failed'],
            ['type' => 'translation', 'slug' => 'akismet', 'name' => 'de_DE', 'ok' => true, 'error' => ''],
        ], Activity::normalize([
            'core'        => [(object) ['item' => (object) ['current' => '6.8.3'], 'result' => '6.8.3', 'name' => 'WordPress 6.8.3']],
            'plugin'      => [(object) ['item' => (object) ['slug' => 'woo'], 'result' => $error, 'name' => 'Woo'], 'kein Objekt'],
            'translation' => [(object) ['item' => (object) ['slug' => 'akismet', 'language' => 'de_DE'], 'result' => true, 'name' => 'de_DE']],
        ]));
    }

    public function testSourceDuringAPushIsWpsync(): void
    {
        $this->boot(false); // der Abgleich nach dem Push findet die Sperre belegt und tut nichts
        $seen = Activity::attributed('p_1', static function (): array {
            return Activity::source();
        });
        $this->assertSame(['wpsync', null, 'push:p_1'], $seen);
        $this->assertSame(['other', null, ''], Activity::source(), 'danach wieder ohne Push');
        $this->assertSame(['wpsync', null, ''], Activity::attributed('bad id!', static function (): array {
            return Activity::source();
        }));
    }

    public function testOnAutoUpdatesLogsFailuresAsAuto(): void
    {
        $db = $this->boot(false); // Abgleich übersprungen, Fehlschläge trotzdem ins Protokoll
        Activity::onAutoUpdates(['plugin' => [(object) ['item' => (object) ['slug' => 'woo'], 'result' => false, 'name' => 'Woo']]]);
        $inserts = array_values(array_filter($db->writes(), static function (string $sql): bool {
            return strpos($sql, 'INSERT') === 0;
        }));
        $this->assertCount(1, $inserts);
        $this->assertStringContainsString("'update_failed', 'plugin', 'woo', 'Woo', NULL, NULL, 'auto', NULL, 'failed')", $inserts[0]);
    }

    /** Das Update des Plugins per ZIP aktiviert es nicht neu: die Tabelle muss der erste Hook anlegen. */
    public function testAHookCreatesTheMissingTableBeforeLogging(): void
    {
        $db = $this->boot();
        update_option('wpsync_schema', '0.11.0'); // Stand vor dem ZIP-Update
        $log = tempnam(sys_get_temp_dir(), 'wpsync-log');
        ini_set('error_log', (string) $log); // die Sonde braucht WordPress: der Hook meldet "übersprungen", nur die Tabelle zählt
        Activity::onChange();
        unlink((string) $log);
        $creates = array_values(array_filter($db->writes(), static function (string $sql): bool {
            return strpos($sql, 'CREATE TABLE IF NOT EXISTS `wp_wpsync_activity`') === 0;
        }));
        $this->assertCount(1, $creates);
    }

    /** Fällt jeder INSERT aus, wartet der Schnappschuss: der nächste Abgleich holt die Ereignisse nach. */
    public function testSnapshotStaysWhenNothingCouldBeWritten(): void
    {
        $db = $this->boot();
        $db->answer("/^SELECT value FROM `wp_wpsync_state` WHERE name = 'activity_snapshot'/", self::OLD);
        $db->fail('/^INSERT INTO `wp_wpsync_activity`/', 'table is gone');
        $this->assertSame(0, Activity::reconcile(new FakeActivityProbe($this->newSnap('1.1')), ['detected', null, ''], 2000));
        foreach ($db->writes() as $sql) {
            $this->assertStringNotContainsString('REPLACE INTO', $sql);
        }
    }

    /** Eine Push-ID, die kein Text ist, trägt nichts ins Protokoll und löst keine Meldung aus. */
    public function testAPushIdThatIsNotTextIsUnusable(): void
    {
        $this->boot(false);
        $this->assertSame(['wpsync', null, ''], Activity::attributed(['x'], static function (): array {
            return Activity::source();
        }));
    }

    /** Eine Ausnahme aus Commit oder Rücknahme erreicht den Aufrufer unverändert; die Zuordnung endet trotzdem. */
    public function testAttributedLetsExceptionsThroughAndResets(): void
    {
        $this->boot(false);
        $boom = new \RuntimeException('commit failed');
        try {
            Activity::attributed('p_2', static function () use ($boom): void {
                throw $boom;
            });
            $this->fail('exception expected');
        } catch (\RuntimeException $e) {
            $this->assertSame($boom, $e);
        }
        $this->assertSame(['other', null, ''], Activity::source());
    }

    /** Nach dem Deaktivieren (Marke gelöscht) legt ein später feuernder Hook nichts wieder an. */
    public function testAHookAfterDeactivationCreatesNothing(): void
    {
        $db  = $this->boot();
        $log = tempnam(sys_get_temp_dir(), 'wpsync-log');
        ini_set('error_log', (string) $log);
        Activity::onChange();
        unlink((string) $log);
        $this->assertSame([], array_values(array_filter($db->writes(), static function (string $sql): bool {
            return strpos($sql, 'CREATE TABLE') !== false;
        })));
    }
}
