<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\TestCase;
use WpSync\SnapshotJob;

final class SnapshotJobTest extends TestCase
{
    private const ID = '0123456789abcdef0123456789abcdef';

    /** @return array<string, mixed> */
    private function job(int $now = 1000): array
    {
        return SnapshotJob::start(self::ID, 'k1', '/srv/wpsync-snap-x/wpsync-snap-a.bin', str_repeat('s', 32), 3, $now);
    }

    public function testStartIsRunningAndViewHidesKeyAndPath(): void
    {
        $view = SnapshotJob::view($this->job(), 1005);
        $this->assertSame(SnapshotJob::RUNNING, $view['state']);
        $this->assertSame(3, $view['tables_total']);
        $this->assertSame(5.0, $view['seconds']);
        $encoded = (string) json_encode($view);
        $this->assertStringNotContainsString('wpsync-snap-a.bin', $encoded);
        $this->assertStringNotContainsString(base64_encode(str_repeat('s', 32)), $encoded);
        $this->assertArrayNotHasKey('key', $view);
        $this->assertArrayNotHasKey('file', $view);
        $this->assertSame(1048576, $view['block_size']);
    }

    public function testProgressAndDone(): void
    {
        $job = SnapshotJob::progress($this->job(), 'wp_posts', 2, 4096, 1010);
        $this->assertSame(2, $job['tables_done']);
        $this->assertSame('wp_posts', $job['table']);
        $this->assertSame(1010, $job['heartbeat']);
        $job  = SnapshotJob::done($job, ['blocks' => 2, 'size' => 1500000, 'sha256' => str_repeat('a', 64), 'plain' => 9000000], ['wp_posts' => 5], ['wp_old'], 1020);
        $view = SnapshotJob::view($job, 1030);
        $this->assertSame(SnapshotJob::DONE, $view['state']);
        $this->assertSame(3, $view['tables_done']);
        $this->assertSame(2, $view['blocks']);
        $this->assertSame(9000000, $view['bytes_plain']);
        $this->assertSame(['wp_old'], $view['inconsistent']);
        $this->assertSame(20.0, $view['seconds']);
    }

    public function testStalledOnlyWithFreeLockAndOldHeartbeat(): void
    {
        $job = $this->job(1000);
        $this->assertFalse(SnapshotJob::stalled($job, true, 1000 + SnapshotJob::STALL_SECONDS));
        $this->assertFalse(SnapshotJob::stalled($job, false, 1000 + SnapshotJob::STALL_SECONDS + 1));
        $this->assertTrue(SnapshotJob::stalled($job, true, 1000 + SnapshotJob::STALL_SECONDS + 1));
        $done = SnapshotJob::done($job, ['blocks' => 1, 'size' => 1, 'sha256' => '', 'plain' => 1], [], [], 1001);
        $this->assertFalse(SnapshotJob::stalled($done, true, 99999), 'only running jobs stall');
    }

    public function testExpiry(): void
    {
        $job = $this->job(1000);
        $this->assertFalse(SnapshotJob::expired($job, false, 1000 + SnapshotJob::MAX_AGE));
        $this->assertTrue(SnapshotJob::expired($job, false, 1000 + SnapshotJob::MAX_AGE + 1), 'any job ends after MAX_AGE');
        $this->assertTrue(SnapshotJob::expired($job, true, 1000 + SnapshotJob::STALL_SECONDS + 1), 'stalled');
        $failed = SnapshotJob::failed($job, 'timeout', 'time limit reached at wp_posts', 1001);
        $this->assertTrue(SnapshotJob::expired($failed, false, 1001));
        $this->assertSame('timeout', SnapshotJob::view($failed, 1001)['code']);
        $done = SnapshotJob::done($job, ['blocks' => 1, 'size' => 1, 'sha256' => '', 'plain' => 1], [], [], 2000);
        $this->assertFalse(SnapshotJob::expired($done, true, 2000 + SnapshotJob::DONE_TTL));
        $this->assertTrue(SnapshotJob::expired($done, true, 2000 + SnapshotJob::DONE_TTL + 1));
    }

    public function testIdPattern(): void
    {
        $this->assertSame(1, preg_match(SnapshotJob::ID, self::ID));
        $this->assertSame(0, preg_match(SnapshotJob::ID, strtoupper(self::ID)));
        $this->assertSame(0, preg_match(SnapshotJob::ID, self::ID . "\n"));
    }
}
