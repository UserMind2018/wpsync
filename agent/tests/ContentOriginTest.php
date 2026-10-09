<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\TestCase;
use WpSync\ContentOrigin;
use WpSync\StagingReplace;

final class ContentOriginTest extends TestCase
{
    private const TAIL = '/wpsync-staging-0123456789ab';

    /** Spec §5.2: je Variante ein eigener Platzhalter */
    public function testNormalizesTheThreeVariants(): void
    {
        $o = new ContentOrigin('https://kunde.de/');
        $this->assertSame('<a href="' . ContentOrigin::PLAIN . '/kontakt">', $o->normalize('<a href="https://kunde.de/kontakt">'));
        $this->assertSame('{"u":"' . ContentOrigin::ESC1 . '\/a"}', $o->normalize('{"u":"https:\/\/kunde.de\/a"}'));
        $this->assertSame('"' . ContentOrigin::ESC2 . '\\\\\\/a"', $o->normalize('"https:\\\\\\/\\\\\\/kunde.de\\\\\\/a"'));
        $this->assertSame('kein Treffer: http://kunde.de, https://www.kunde.de', $o->normalize('kein Treffer: http://kunde.de, https://www.kunde.de'));
    }

    /** C4: reiner Teilstring wie wp search-replace */
    public function testIsAPlainSubstringReplace(): void
    {
        $o = new ContentOrigin('https://kunde.de');
        $this->assertSame(ContentOrigin::PLAIN . '.evil.org', $o->normalize('https://kunde.de.evil.org'));
        $this->assertSame('HTTPS://KUNDE.DE', $o->normalize('HTTPS://KUNDE.DE'));
    }

    /** AC-147: derselbe Inhalt ergibt auf Live und in der Arbeitskopie dieselbe normalisierte Form */
    public function testLiveAndLocalNormalizeToTheSameValue(): void
    {
        $live  = new ContentOrigin('https://kunde.de');
        $local = new ContentOrigin('https://kunde.ddev.site');
        foreach ([
            '<img src="%s/wp-content/uploads/a.jpg">',
            serialize(['url' => '%s/x', 'deep' => ['%s']]),
        ] as $template) {
            $onLive  = str_replace('%s', 'https://kunde.de', $template);
            $onLocal = str_replace('%s', 'https://kunde.ddev.site', $template);
            if ($template[0] === 'a') { // serialisiert: die Längen der Arbeitskopie sind andere
                $onLive  = serialize(['url' => 'https://kunde.de/x', 'deep' => ['https://kunde.de']]);
                $onLocal = serialize(['url' => 'https://kunde.ddev.site/x', 'deep' => ['https://kunde.ddev.site']]);
            }
            $this->assertSame($live->normalize($onLive), $local->normalize($onLocal));
        }
        $json = '[{"url":"%s\/kontakt"}]';
        $this->assertSame(
            $live->normalize(str_replace('%s', 'https:\/\/kunde.de', $json)),
            $local->normalize(str_replace('%s', 'https:\/\/kunde.ddev.site', $json))
        );
    }

    /** AC-147: die Staging-Kopie normalisiert auf dieselbe Form wie Live */
    public function testStagingNormalizesLikeLive(): void
    {
        $replace = new StagingReplace('https://kunde.de', self::TAIL);
        $live    = new ContentOrigin('https://kunde.de');
        $staging = new ContentOrigin('https://kunde.de', $replace);
        foreach ([
            '<a href="https://kunde.de/kontakt">',
            '{"u":"https:\/\/kunde.de\/a"}',
            '"https:\\\\\\/\\\\\\/kunde.de\\\\\\/a"',
            serialize(['url' => 'https://kunde.de/x']),
        ] as $value) {
            $this->assertSame($live->normalize($value), $staging->normalize($replace->value($value)), $value);
        }
    }

    public function testInsertIsTheInverse(): void
    {
        $replace = new StagingReplace('https://kunde.de', self::TAIL);
        $live    = new ContentOrigin('https://kunde.de');
        $staging = new ContentOrigin('https://kunde.de', $replace);
        foreach ([
            '<a href="https://kunde.de/kontakt">',
            '{"u":"https:\/\/kunde.de\/a"}',
            '"https:\\\\\\/\\\\\\/kunde.de\\\\\\/a"',
            serialize(['url' => 'https://kunde.de/x', 'n' => 3]),
            'ohne url',
        ] as $value) {
            $normal = (string) $live->normalize($value);
            $this->assertSame($value, $live->insert($normal), $value);
            $this->assertSame($replace->value($value), $staging->insert($normal), $value);
        }
    }

    /** Die Arbeitskopie baut das Paket, Live setzt ein: aus der lokalen URL wird die von Live. */
    public function testLocalValueArrivesWithTheLiveOrigin(): void
    {
        $local = new ContentOrigin('https://kunde.ddev.site');
        $live  = new ContentOrigin('https://kunde.de');
        $value = serialize(['url' => 'https://kunde.ddev.site/neu', 'json' => '{"u":"https:\/\/kunde.ddev.site\/a"}']);
        $this->assertSame(
            serialize(['url' => 'https://kunde.de/neu', 'json' => '{"u":"https:\/\/kunde.de\/a"}']),
            $live->insert((string) $local->normalize($value))
        );
    }

    /** Spec §5.2, §5.3: Platzhalter im Rohwert, C: und Unlesbares mit Origin lassen sich nicht normalisieren */
    public function testUnnormalizableValues(): void
    {
        $o = new ContentOrigin('https://kunde.de');
        $this->assertNull($o->normalize('Text mit ' . ContentOrigin::PLAIN));
        $this->assertNull($o->normalize('Text mit ' . ContentOrigin::ESC2));
        $this->assertNull($o->normalize('C:3:"Cfg":16:{https://kunde.de}'));
        $this->assertNull($o->normalize('s:99:"https://kunde.de";'));
        $this->assertSame('C:3:"Cfg":3:{abc}', $o->normalize('C:3:"Cfg":3:{abc}'), 'ohne Origin bleibt der Wert, wie er ist');
    }

    public function testInsertRefusesUnknownPlaceholdersAndUnreadableValues(): void
    {
        $o = new ContentOrigin('https://kunde.de');
        $this->assertNull($o->insert("\u{27E6}wpsync:origin:esc9\u{27E7}/x"));
        $this->assertNull($o->insert('s:99:"' . ContentOrigin::PLAIN . '";'));
    }

    public function testVariantsAndBadHome(): void
    {
        $this->assertSame(
            ['plain' => 'https://a.de/b', 'esc1' => 'https:\/\/a.de\/b', 'esc2' => 'https:\\\\\\/\\\\\\/a.de\\\\\\/b'],
            ContentOrigin::variants('https://a.de/b')
        );
        $this->expectException(\InvalidArgumentException::class);
        new ContentOrigin('kunde.de');
    }

    /** @return list<array{0: string}> Security-Review M2: Längen und Anzahlen, die als int überlaufen */
    public static function overflowing(): array
    {
        return [
            ['s:9223372036854775807:"%s";'],
            ['E:9223372036854775807:"%s";'],
            ['a:9223372036854775807:{i:0;s:16:"%s";}'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('overflowing')]
    public function testOverflowingLengthsAreUnnormalizableNotAnError(string $template): void
    {
        $o = new ContentOrigin('https://kunde.de');
        $this->assertNull($o->normalize(sprintf($template, 'https://kunde.de')));
        $this->assertNull($o->insert(sprintf($template, ContentOrigin::PLAIN)));
    }

    /** Security-Review M3: tausende Ebenen serialisierter Strings sind unlesbar – kein Speicherfehler */
    public function testDeeplyNestedStringsAreUnnormalizable(): void
    {
        $o      = new ContentOrigin('https://kunde.de');
        $before = memory_get_peak_usage();
        $this->assertNull($o->normalize(SerializedWalkerTest::nested('https://kunde.de', 5000)));
        $this->assertNull($o->insert(SerializedWalkerTest::nested(ContentOrigin::PLAIN, 5000)));
        $this->assertLessThan(32 * 1024 * 1024, memory_get_peak_usage() - $before);
        $this->assertSame(
            SerializedWalkerTest::nested(ContentOrigin::PLAIN . '/x', 3),
            $o->normalize(SerializedWalkerTest::nested('https://kunde.de/x', 3))
        );
    }

    /**
     * Werte, deren Längenangaben erst mit einer anders langen Origin stimmen (Security-Review H1.2,
     * H1.3): 24 = strlen('https://staging.kunde.de'), 44 = mit Staging-Pfad hinter https://kunde.de.
     *
     * @return array<string, array{0: string}>
     */
    public static function crafted(): array
    {
        $out = [];
        foreach ([24, 44] as $len) {
            $core = 'a:2:{i:0;s:' . $len . ':"https://kunde.de";i:1;O:8:"stdClass":0:{}}';
            $out += [
                "$len ohne Leerraum"      => [$core],
                "$len Leerzeichen davor"  => [' ' . $core],
                "$len Zeilenende danach"  => [$core . "\n"],
                "$len Leerraum beidseits" => ["\t" . $core . " \0"],
                "$len als String"         => ['s:' . strlen($core) . ':"' . $core . '";'],
                "$len als String, Leerraum" => ['s:' . (strlen($core) + 1) . ':" ' . $core . '";'],
                "$len im Array"           => [serialize(['v' => $core, 'u' => 'https://kunde.de/x'])],
                "$len im Array, Leerraum" => [serialize(['v' => "\n" . $core . ' '])],
                "$len drei Ebenen"        => [serialize(serialize(['v' => ' ' . $core]))],
                "$len escaped"            => [' a:1:{i:0;s:' . ($len + 2) . ':"https:\/\/kunde.de";}'],
            ];
        }
        return $out;
    }

    /**
     * Wie viele Strings in diesem Wert WordPress deserialisieren könnte – maybe_unserialize() auf
     * den Wert selbst und auf jeden String darin.
     */
    private static function readable(string $value): int
    {
        $data = @unserialize(trim($value), ['allowed_classes' => false]);
        if ($data === false) {
            return 0;
        }
        $count = 1;
        $todo  = [$data];
        while ($todo !== []) {
            $item = array_pop($todo);
            if (is_string($item)) {
                $count += self::readable($item);
            } elseif (is_array($item) || is_object($item)) {
                foreach ((array) $item as $member) {
                    $todo[] = $member;
                }
            }
        }
        return $count;
    }

    /**
     * Invariante: Was sich vorher nicht deserialisieren liess, lässt sich auch nach
     * normalize → insert mit einer anders langen Origin nicht deserialisieren.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('crafted')]
    public function testAnInvalidSerializedValueNeverBecomesValid(string $value): void
    {
        $live    = new ContentOrigin('https://kunde.de');
        $targets = [
            new ContentOrigin('https://staging.kunde.de'),
            new ContentOrigin('https://kunde.de', new StagingReplace('https://kunde.de', self::TAIL)),
        ];
        $normal = $live->normalize($value);
        foreach ($targets as $target) {
            $inserted = $normal === null ? null : $target->insert($normal);
            $this->assertTrue($inserted === null || self::readable($inserted) <= self::readable($value), 'readable after insert: ' . json_encode($inserted));
        }
        $this->assertNull($normal, 'the whole value is unreadable');

        // Dasselbe, wenn der Wert schon mit Platzhalter ankommt – etwa aus einem Paket.
        foreach ([ContentOrigin::PLAIN, ContentOrigin::ESC1] as $mark) {
            $marked = str_replace(['https://kunde.de', 'https:\/\/kunde.de'], $mark, $value);
            foreach ($targets as $target) {
                $inserted = $target->insert($marked);
                $this->assertTrue($inserted === null || self::readable($inserted) <= self::readable($marked), 'readable after insert: ' . json_encode($inserted));
            }
        }
    }

    public function testWhitespaceAroundASerializedValueSurvivesNormalizeAndInsert(): void
    {
        $live   = new ContentOrigin('https://kunde.de');
        $target = new ContentOrigin('https://staging.kunde.de');
        $value  = " a:1:{i:0;s:16:\"https://kunde.de\";}\n";
        $normal = $live->normalize($value);
        $this->assertSame(' ' . serialize([ContentOrigin::PLAIN]) . "\n", $normal);
        $this->assertSame($value, $live->insert((string) $normal));
        $this->assertSame(' ' . serialize(['https://staging.kunde.de']) . "\n", $target->insert((string) $normal));
    }
}
