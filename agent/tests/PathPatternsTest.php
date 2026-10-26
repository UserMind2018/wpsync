<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\TestCase;
use WpSync\PathPatterns;

final class PathPatternsTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function fixture(): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/fixtures/pathmatch.json'), true);
    }

    public function testValid(): void
    {
        foreach (self::fixture()['valid'] as $c) {
            $this->assertSame($c['out'], PathPatterns::normalize($c['in']), json_encode($c['in']));
        }
    }

    public function testInvalid(): void
    {
        foreach (self::fixture()['invalid'] as $in) {
            try {
                PathPatterns::normalize([$in]);
                $this->fail('accepted: ' . json_encode($in));
            } catch (\InvalidArgumentException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testTooMany(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PathPatterns::normalize(array_map(static function (int $i): string {
            return 'uploads/d' . $i . '/';
        }, range(1, PathPatterns::MAX_ENTRIES + 1)));
    }

    public function testMatch(): void
    {
        foreach (self::fixture()['match'] as $c) {
            $set = PathPatterns::fromList($c['p']);
            $this->assertSame($c['want'], $set->matches($c['path'], $c['dir']), json_encode($c));
        }
    }
}
