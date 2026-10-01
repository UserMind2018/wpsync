<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\TestCase;
use WpSync\Infosheet;
use WpSync\Inventory;

final class InfosheetTest extends TestCase
{
    public function testJobIsStaleAfterMaxAge(): void
    {
        $job = Inventory::initial(1000.0);
        $this->assertFalse(Infosheet::stale($job, 1000.0 + Infosheet::MAX_JOB_AGE));
        $this->assertTrue(Infosheet::stale($job, 1000.0 + Infosheet::MAX_JOB_AGE + 1));
    }

    public function testJobWithoutStartIsStale(): void
    {
        $this->assertTrue(Infosheet::stale(['phase' => 'meta'], 1700000000.0));
    }
}
