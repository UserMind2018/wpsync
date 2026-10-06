<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\TestCase;
use WpSync\PushWindow;

final class PushWindowTest extends TestCase
{
    /** P3: nur die drei angebotenen Dauern. */
    public function testUntilAcceptsOnlyOfferedDurations(): void
    {
        $this->assertSame(1900, PushWindow::until(900, 1000));
        $this->assertSame(4600, PushWindow::until(3600, 1000));
        $this->assertSame(29800, PushWindow::until(28800, 1000));
        $this->expectException(\InvalidArgumentException::class);
        PushWindow::until(86400, 1000);
    }

    /** AC-50 */
    public function testOpenAndRemaining(): void
    {
        $this->assertFalse(PushWindow::open(0, 1000), 'never opened');
        $this->assertTrue(PushWindow::open(1001, 1000));
        $this->assertFalse(PushWindow::open(1000, 1000), 'closes exactly at the end');
        $this->assertFalse(PushWindow::open(999, 1000));
        $this->assertSame(60, PushWindow::remaining(1060, 1000));
        $this->assertSame(0, PushWindow::remaining(900, 1000));
    }

    public function testLabel(): void
    {
        $this->assertSame('15 Minuten', PushWindow::DURATIONS[900]);
        $this->assertSame('geschlossen', PushWindow::label(0, 1000));
        $this->assertSame('offen, noch 1 Min', PushWindow::label(1030, 1000));
        $this->assertSame('offen, noch 90 Min', PushWindow::label(6400, 1000));
    }
}
