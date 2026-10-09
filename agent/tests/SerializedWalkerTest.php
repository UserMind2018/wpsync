<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\TestCase;
use WpSync\SerializedWalker;

final class SerializedWalkerTest extends TestCase
{
    public static function upper(string $text): string
    {
        return str_replace('abc', 'ABCDEF', $text);
    }

    public function testRewritesEveryStringAndResetsLengths(): void
    {
        $value = serialize(['abc' => 'x abc y', 'n' => 5, 'deep' => ['abc', 1.5, false, null]]);
        $new   = SerializedWalker::rewrite($value, [self::class, 'upper']);
        $this->assertSame(['ABCDEF' => 'x ABCDEF y', 'n' => 5, 'deep' => ['ABCDEF', 1.5, false, null]], unserialize((string) $new));
    }

    public function testDoubleSerializedStrings(): void
    {
        $value = serialize(serialize(['u' => 'abc']));
        $new   = SerializedWalker::rewrite($value, [self::class, 'upper']);
        $this->assertSame(['u' => 'ABCDEF'], unserialize((string) unserialize((string) $new)));
    }

    public function testObjectsKeepTheirShapeWithoutTheClass(): void
    {
        $value = 'O:3:"Cfg":1:{s:3:"url";s:3:"abc";}';
        $new   = (string) SerializedWalker::rewrite($value, [self::class, 'upper']);
        $this->assertSame('O:3:"Cfg":1:{s:3:"url";s:6:"ABCDEF";}', $new);
    }

    public function testUnreadableValuesGiveNull(): void
    {
        $this->assertNull(SerializedWalker::rewrite('s:99:"abc";', [self::class, 'upper']));
        $this->assertNull(SerializedWalker::rewrite('C:3:"Cfg":3:{abc}', [self::class, 'upper']));
        $this->assertNull(SerializedWalker::rewrite('a:1:{i:0;s:3:"abc";}trailing', [self::class, 'upper']));
    }

    public function testLooksSerialized(): void
    {
        foreach (['N;', 'b:0;', 'i:-3;', 's:1:"a";', 'a:0:{}', 'O:1:"A":0:{}', 'C:1:"A":0:{}'] as $value) {
            $this->assertTrue(SerializedWalker::looksSerialized($value), $value);
        }
        foreach (['', 'abc', 'a:b', '{"a":1}', 's:1:"a"'] as $value) {
            $this->assertFalse(SerializedWalker::looksSerialized($value), $value);
        }
    }

    public function testUnchangedValuesComeBackByteForByte(): void
    {
        $value = 'a:2:{s:1:"f";d:0.1;s:1:"e";E:7:"Foo:Bar";}';
        $this->assertSame($value, SerializedWalker::rewrite($value, [self::class, 'upper']));
    }

    /** @return list<array{0: string}> Längen und Anzahlen, die als int überlaufen (Security-Review M2) */
    public static function overflowing(): array
    {
        return [
            ['s:9223372036854775807:"abc";'],
            ['E:9223372036854775807:"Foo:Bar";'],
            ['a:9223372036854775807:{i:0;s:3:"abc";}'],
            ['a:4611686018427387904:{i:0;s:3:"abc";}'],
            ['O:9223372036854775807:"Cfg":1:{s:1:"u";s:3:"abc";}'],
            ['O:3:"Cfg":9223372036854775807:{s:1:"u";s:3:"abc";}'],
            ['a:1:{i:0;s:99999999999999999999:"abc";}'],
            ['s:18446744073709551619:"abc";'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('overflowing')]
    public function testOverflowingLengthsAreUnreadableNotAnError(string $value): void
    {
        $this->assertTrue(SerializedWalker::looksSerialized($value));
        $this->assertNull(SerializedWalker::rewrite($value, [self::class, 'upper']));
    }

    public function testCountsBeyondTheValueAreUnreadable(): void
    {
        $this->assertNull(SerializedWalker::rewrite('a:2000000000:{i:0;s:3:"abc";}', [self::class, 'upper']));
        $this->assertNull(SerializedWalker::rewrite('s:2000000000:"abc";', [self::class, 'upper']));
    }
}
