<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\TestCase;
use WpSync\Protection;

final class ProtectionTest extends TestCase
{
    private const KEY = '0123456789abcdef';
    private const SIG = 'c797bbaa5e3decdcf65b9846271bc15ed06bedda7234591ba9793984c05f4d0d';

    private function check(?string $getRoute, string $uri, bool $postRoute = false, string $key = self::KEY, string $sig = self::SIG, string $home = ''): bool
    {
        return Protection::matches($getRoute, $postRoute, $uri, $home, 'wp-json', $key, $sig);
    }

    public function testQueryStringNeverEnablesBypass(): void
    {
        $this->assertFalse($this->check(null, '/?x=/wp-json/wpsync/v1'));
        $this->assertFalse($this->check(null, '/2026/09/29/hello-world/?x=/wp-json/wpsync/v1'));
        $this->assertFalse($this->check(null, '/wp-json/wp/v2/users?x=/wp-json/wpsync/v1'));
    }

    public function testRestRouteMustMatchAtSegmentBoundary(): void
    {
        $this->assertTrue($this->check('/wpsync/v1/ping', '/?rest_route=/wpsync/v1/ping'));
        $this->assertTrue($this->check('/wpsync/v1/infosheet/refresh', '/'));
        $this->assertTrue($this->check('/wpsync/v1/content/stage', '/'), 'zwei Segmente, nur Kleinbuchstaben: auch hinter „Password Protected“ erreichbar');
        $this->assertFalse($this->check('/wpsync/v1evil', '/'));
        $this->assertFalse($this->check('/wpsync/v1/../wp/v2/users', '/'));
        $this->assertFalse($this->check('/wp/v2/users', '/wp-json/wpsync/v1/ping'), 'rest_route in GET wins over the path');
        $this->assertFalse($this->check('', '/wp-json/wpsync/v1/ping'), 'empty rest_route is not a REST request');
    }

    public function testPrettyPermalinkPath(): void
    {
        $this->assertTrue($this->check(null, '/wp-json/wpsync/v1/ping'));
        $this->assertTrue($this->check(null, '/wp-json/wpsync/v1/ping?x=1'));
        $this->assertTrue($this->check(null, '/blog/wp-json/wpsync/v1/delta', false, self::KEY, self::SIG, '/blog'));
        $this->assertFalse($this->check(null, '/hello-world/wp-json/wpsync/v1/ping'), 'must start at the home path');
        $this->assertFalse($this->check(null, '//evil/wp-json/wpsync/v1/ping'));
        $this->assertFalse($this->check(null, '/wp-json/wpsync/v1evil'));
    }

    public function testRestRouteInPostBodyDisablesBypass(): void
    {
        $this->assertFalse($this->check(null, '/wp-json/wpsync/v1/ping', true), 'POST value beats the path in WP::parse_request');
    }

    public function testUnsignedRequestsOnlyReachIndexAndPair(): void
    {
        $this->assertTrue($this->check('/wpsync/v1', '/', false, '', ''));
        $this->assertTrue($this->check('/wpsync/v1/', '/', false, '', ''));
        $this->assertTrue($this->check('/wpsync/v1/pair', '/', false, '', ''));
        $this->assertFalse($this->check('/wpsync/v1/ping', '/', false, '', ''));
        $this->assertFalse($this->check('/wpsync/v1/ping', '/', false, self::KEY, 'x'));
        $this->assertFalse($this->check('/wpsync/v1/ping', '/', false, self::KEY . "\n", self::SIG));
    }
}
