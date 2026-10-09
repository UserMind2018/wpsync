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
}
