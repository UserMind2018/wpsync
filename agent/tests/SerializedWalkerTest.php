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

    /** $levels-mal serialisiert: s:N:"s:M:"…";"; */
    public static function nested(string $leaf, int $levels): string
    {
        for ($i = 0; $i < $levels; $i++) {
            $leaf = serialize($leaf);
        }
        return $leaf;
    }

    /** @param mixed $leaf */
    private static function arrays($leaf, int $levels): string
    {
        for ($i = 0; $i < $levels; $i++) {
            $leaf = [$leaf];
        }
        return serialize($leaf);
    }

    /** Security-Review M3: die Tiefe zählt über verschachtelte serialisierte Strings hinweg */
    public function testNestedStringsCountTowardsTheDepth(): void
    {
        $value  = self::nested('abc', 5000);
        $before = memory_get_peak_usage();
        $start  = microtime(true);
        $this->assertNull(SerializedWalker::rewrite($value, [self::class, 'upper']));
        $this->assertLessThan(32 * 1024 * 1024, memory_get_peak_usage() - $before);
        $this->assertLessThan(2.0, microtime(true) - $start);
    }

    public function testDepthLimitCountsStructureAndStringsTogether(): void
    {
        $this->assertSame(self::nested('ABCDEF', 3), SerializedWalker::rewrite(self::nested('abc', 3), [self::class, 'upper']));
        $this->assertSame(self::nested('ABCDEF', 64), SerializedWalker::rewrite(self::nested('abc', 64), [self::class, 'upper']));
        $this->assertNull(SerializedWalker::rewrite(self::nested('abc', 66), [self::class, 'upper']));
        $this->assertSame(self::arrays('ABCDEF', 64), SerializedWalker::rewrite(self::arrays('abc', 64), [self::class, 'upper']));
        $this->assertNull(SerializedWalker::rewrite(self::arrays('abc', 65), [self::class, 'upper']));
        // 40 Ebenen Struktur, darin ein String mit weiteren 40: zusammen zu tief.
        $mixed = self::arrays(self::arrays('abc', 40), 40);
        $this->assertNull(SerializedWalker::rewrite($mixed, [self::class, 'upper']));
        $fits = self::arrays(self::arrays('abc', 30), 30);
        $this->assertSame(self::arrays(self::arrays('ABCDEF', 30), 30), SerializedWalker::rewrite($fits, [self::class, 'upper']));
    }

    /** Ein verschachtelter String wird an Ort und Stelle gelesen, nicht je Ebene kopiert. */
    public function testALargeDoubleSerializedValueIsNotCopiedPerLevel(): void
    {
        $value  = self::nested(str_repeat('x', 4 * 1024 * 1024) . 'abc', 40);
        $before = memory_get_peak_usage();
        $new    = SerializedWalker::rewrite($value, [self::class, 'upper']);
        $this->assertLessThan(40 * 1024 * 1024, memory_get_peak_usage() - $before);
        $this->assertSame(self::nested(str_repeat('x', 4 * 1024 * 1024) . 'ABCDEF', 40), $new);
    }

    /** Security-Review H1.2: WordPress beurteilt einen Wert nach trim() – is_serialized() */
    public function testLooksSerializedJudgesTheTrimmedValue(): void
    {
        foreach ([' a:0:{}', "a:0:{}\n", "\t\r\n s:1:\"a\"; \0\x0B", ' N; ', ' i:5;'] as $value) {
            $this->assertTrue(SerializedWalker::looksSerialized($value), json_encode($value));
        }
        foreach ([' ', "\n\n", ' abc ', ' s:1:"a" ', "a:0:{}\u{00A0}"] as $value) {
            $this->assertFalse(SerializedWalker::looksSerialized($value), json_encode($value));
        }
    }

    public function testWhitespaceAroundAValueStaysWhereItIs(): void
    {
        $this->assertSame(" a:1:{i:0;s:6:\"ABCDEF\";}\n", SerializedWalker::rewrite(" a:1:{i:0;s:3:\"abc\";}\n", [self::class, 'upper']));
        $this->assertSame("\ta:0:{} ", SerializedWalker::rewrite("\ta:0:{} ", [self::class, 'upper']));
        $inner = " a:1:{i:0;s:3:\"abc\";}\r\n";
        $this->assertSame(
            serialize(['v' => " a:1:{i:0;s:6:\"ABCDEF\";}\r\n"]),
            SerializedWalker::rewrite(serialize(['v' => $inner]), [self::class, 'upper'])
        );
        $this->assertNull(SerializedWalker::rewrite(' s:99:"abc";', [self::class, 'upper']));
    }

    /** Security-Review H1.3: ein verschachtelter Wert, der serialisiert aussieht und es nicht ist, wird nie als Text ersetzt */
    public function testAnUnreadableNestedValueWithAHitMakesTheWholeValueUnreadable(): void
    {
        foreach (['s:99:"abc";', ' s:99:"abc";', 'C:3:"Cfg":3:{abc}', 'a:1:{i:0;s:9:"abc";}'] as $broken) {
            $this->assertNull(SerializedWalker::rewrite(serialize(['v' => $broken]), [self::class, 'upper']), $broken);
            $this->assertNull(SerializedWalker::rewrite(serialize(['v' => serialize(['w' => $broken])]), [self::class, 'upper']), $broken);
        }
    }

    public function testAnUnreadableNestedValueWithoutAHitStaysAsItIs(): void
    {
        $value = serialize(['v' => 's:99:"xyz";', 'w' => 'abc', 'c' => 'C:3:"Cfg":3:{xyz}']);
        $this->assertSame(
            serialize(['v' => 's:99:"xyz";', 'w' => 'ABCDEF', 'c' => 'C:3:"Cfg":3:{xyz}']),
            SerializedWalker::rewrite($value, [self::class, 'upper'])
        );
    }

    /** Härtung S1: Objekte, eigene Serialisierung und Enums an struktureller Stelle – auch verschachtelt. */
    public function testHasObject(): void
    {
        $object = 'O:8:"stdClass":1:{s:1:"a";i:1;}';
        foreach ([
            $object,
            "  " . $object . "\n",
            serialize(['x' => [1, new \stdClass()]]),
            'a:1:{i:0;' . $object . '}',
            serialize(serialize(['deep' => new \stdClass()])),
            'E:11:"Suit:Hearts";',
            'a:1:{s:1:"e";E:11:"Suit:Hearts";}',
        ] as $value) {
            $this->assertTrue(SerializedWalker::hasObject($value), $value);
        }
        foreach (['', 'Text', 'O:8:"stdClass" im Fliesstext', serialize(['a' => 'O:8:"stdClass":0:{}x', 'b' => [1, 2.5, null, true]]), serialize('nur Text'), '[{"json":"O:1"}]'] as $value) {
            $this->assertFalse(SerializedWalker::hasObject($value), $value);
        }
        foreach (['C:11:"ArrayObject":21:{x:i:0;a:0:{};m:a:0:{}}', 'a:2:{i:0;i:1;}', 's:99:"zu kurz";', 'a:1:{i:0;s:14:"a:2:{i:0;i:1;}";}', 'O:8:"stdClass":1:{s:1:"a";'] as $value) {
            $this->assertNull(SerializedWalker::hasObject($value), $value);
        }
        // Auf dem gehärteten Parser: zu tief ist unlesbar, Leerraum am Rand zählt nicht – auch nicht in einem inneren String.
        $this->assertNull(SerializedWalker::hasObject(str_repeat('a:1:{i:0;', 70) . 'i:1;' . str_repeat('}', 70)));
        $this->assertNull(SerializedWalker::hasObject('a:1:{i:0;s:99999999999999999999:"x";}'));
        $this->assertTrue(SerializedWalker::hasObject(serialize(['x' => " \n" . $object . "\t"])));
        $this->assertTrue(SerializedWalker::hasObject("\0" . $object . "\v"));
    }
}
