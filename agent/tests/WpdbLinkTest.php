<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\TestCase;
use WpSync\WpdbLink;

final class WpdbLinkTest extends TestCase
{
    public function testReturnsRowsAndFalseOnError(): void
    {
        if (!defined('ARRAY_A')) {
            define('ARRAY_A', 'ARRAY_A');
        }
        $wpdb = new FakeWpdb();
        $wpdb->answer('/^SHOW KEYS/', [['Column_name' => 'ID']]);
        $wpdb->fail('/^SELECT nope/', 'Unknown column');
        $link = new WpdbLink($wpdb);
        $this->assertSame([['Column_name' => 'ID']], $link->query("SHOW KEYS FROM `wp_users` WHERE Key_name = 'PRIMARY'"));
        $this->assertFalse($link->query('SELECT nope'));
        $this->assertSame(1, $link->errno());
        $this->assertSame("a\\'b", $link->escape("a'b"));
    }
}
