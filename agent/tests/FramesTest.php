<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\TestCase;
use WpSync\Frames;

final class FramesTest extends TestCase
{
    public function testHeaders(): void
    {
        $this->assertSame("F wp-content/a.css\t12\t1700000000\n", Frames::file('wp-content/a.css', 12, 1700000000));
        $this->assertSame("M wp-content/gone.css\n", Frames::missing('wp-content/gone.css'));
        $this->assertSame("T wp_posts\t3\t120\n", Frames::table('wp_posts', 3, 120));
        $this->assertSame("E\n", Frames::end());
    }
}
