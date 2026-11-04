<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\TestCase;
use WpSync\Scope;
use WpSync\SnapshotDump;
use WpSync\SnapshotException;
use WpSync\SnapshotSink;
use WpSync\TableDump;

final class SnapshotDumpTest extends TestCase
{
    private const CORE = [
        'posts' => 'wp_posts', 'postmeta' => 'wp_postmeta',
        'term_relationships' => 'wp_term_relationships', 'comments' => 'wp_comments',
    ];

    private function sink(): SnapshotSink
    {
        return new class implements SnapshotSink {
            /** @var string */
            public $data = '';

            public function write(string $data): void
            {
                $this->data .= $data;
            }
        };
    }

    /** FakeRescueLink nimmt die erste passende Antwort: ein fail() muss vor der answer() für dasselbe Muster stehen. */
    private function link(bool $selectFails = false): FakeRescueLink
    {
        $link = new FakeRescueLink();
        if ($selectFails) {
            $link->fail('/^SELECT \* FROM `wp_options`/', 1412);
        }
        $link->answer('/^SHOW CREATE TABLE `wp_options`/', [['Table' => 'wp_options', 'Create Table' => 'CREATE TABLE `wp_options` (x)']]);
        $link->answer('/^SHOW CREATE TABLE `wp_users`/', [['Table' => 'wp_users', 'Create Table' => 'CREATE TABLE `wp_users` (y)']]);
        $link->answer('/^SHOW KEYS FROM `wp_options`/', [['Column_name' => 'option_id']]);
        $link->answer(
            '/^SELECT \* FROM `wp_options`/',
            [['option_id' => '1', 'option_name' => 'a', 'option_value' => 'x', 'autoload' => 'no'], ['option_id' => '2', 'option_name' => 'b', 'option_value' => 'y', 'autoload' => 'no']],
            [['option_id' => '3', 'option_name' => 'c', 'option_value' => 'z', 'autoload' => 'no']]
        );
        return $link;
    }

    /** @return list<array{name: string, mode: string}> */
    private function tables(): array
    {
        return [['name' => 'wp_users', 'mode' => Scope::STRUCTURE], ['name' => 'wp_options', 'mode' => Scope::FULL]];
    }

    private function dump(FakeRescueLink $link): TableDump
    {
        return new TableDump($link, Scope::fromArray(['tables' => ['wp_users' => 'structure']]), self::CORE, 'wp_options', null);
    }

    public function testReadsEverythingInOneTransactionAndEndsIt(): void
    {
        $link     = $this->link();
        $sink     = $this->sink();
        $progress = [];
        $rows     = SnapshotDump::run($link, $this->dump($link), $this->tables(), $sink, INF, static function (string $t, int $done) use (&$progress): void {
            $progress[] = $t . ':' . $done;
        }, static function (): float {
            return 0.0;
        }, 2);

        $this->assertSame(['wp_users' => 0, 'wp_options' => 3], $rows);
        $this->assertSame('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ', $link->queries[0]);
        $this->assertSame('START TRANSACTION WITH CONSISTENT SNAPSHOT', $link->queries[1]);
        $this->assertSame('ROLLBACK', end($link->queries));
        foreach ($link->queries as $sql) {
            $this->assertStringNotContainsString('FROM `wp_users`', $sql, 'structure-only table must not be read');
        }
        $this->assertSame(['wp_users:1', 'wp_options:1', 'wp_options:1', 'wp_options:2'], $progress);
        $this->assertStringStartsWith("T wp_users\t0\t", $sink->data);
        $this->assertSame(2, substr_count($sink->data, "T wp_options\t"), 'one frame per chunk');
        $this->assertStringContainsString("T wp_options\t1\t", $sink->data);
        $this->assertStringEndsWith("E\n", $sink->data);
    }

    public function testReadErrorEndsTheTransactionToo(): void
    {
        $link = $this->link(true);
        try {
            SnapshotDump::run($link, $this->dump($link), $this->tables(), $this->sink(), INF, static function (): void {
            }, static function (): float {
                return 0.0;
            }, 2);
            $this->fail('want SnapshotException');
        } catch (SnapshotException $e) {
            $this->assertSame('read_failed', $e->reason);
            $this->assertStringContainsString('wp_options', $e->getMessage());
            $this->assertStringContainsString('1412', $e->getMessage());
        }
        $this->assertSame('ROLLBACK', end($link->queries));
    }

    public function testDeadlineEndsWithTimeout(): void
    {
        $link = $this->link();
        $this->expectException(SnapshotException::class);
        $this->expectExceptionMessage('time');
        SnapshotDump::run($link, $this->dump($link), $this->tables(), $this->sink(), 10.0, static function (): void {
        }, static function (): float {
            return 11.0;
        }, 2);
    }

    public function testStartFailureIsReported(): void
    {
        $link = $this->link();
        $link->fail('/^START TRANSACTION/', 1227);
        try {
            SnapshotDump::run($link, $this->dump($link), $this->tables(), $this->sink(), INF, static function (): void {
            }, static function (): float {
                return 0.0;
            });
            $this->fail('want SnapshotException');
        } catch (SnapshotException $e) {
            $this->assertSame('begin_failed', $e->reason);
        }
    }

    public function testNonTransactionalTablesAreNamed(): void
    {
        $link = new FakeRescueLink();
        $link->answer('/information_schema\.TABLES/', [
            ['name' => 'wp_options', 'engine' => 'InnoDB'],
            ['name' => 'wp_old', 'engine' => 'MyISAM'],
            ['name' => 'wp_aria', 'engine' => 'Aria'],
            ['name' => 'wp_other', 'engine' => 'MyISAM'],
        ]);
        $this->assertSame(['wp_old', 'wp_aria'], SnapshotDump::nonTransactional($link, ['wp_options', 'wp_old', 'wp_aria']));
    }
}
