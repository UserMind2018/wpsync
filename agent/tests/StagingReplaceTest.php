<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\TestCase;
use WpSync\StagingReplace;

final class StagingReplaceTest extends TestCase
{
    private const TAIL = '/wpsync-staging-0123456789ab';

    private function r(string $home = 'https://example.com'): StagingReplace
    {
        return new StagingReplace($home, self::TAIL);
    }

    /** AC-87 */
    public function testReplacesEveryFormOfTheLiveUrl(): void
    {
        $r = $this->r();
        $this->assertSame('<a href="https://example.com/wpsync-staging-0123456789ab/shop/">', $r->text('<a href="https://example.com/shop/">'));
        $this->assertSame('http://example.com/wpsync-staging-0123456789ab', $r->text('http://example.com'));
        $this->assertSame('src="//EXAMPLE.com/wpsync-staging-0123456789ab/x.js"', $r->text('src="//EXAMPLE.com/x.js"'));
        $this->assertSame('{"url":"https:\/\/example.com\/wpsync-staging-0123456789ab\/kontakt"}', $r->text('{"url":"https:\/\/example.com\/kontakt"}'));
        $this->assertSame('https://example.com/wpsync-staging-0123456789ab?p=1', $r->text('https://example.com?p=1'));
        $this->assertSame('example.com', $r->host());
    }

    public function testLeavesOtherHostsAlone(): void
    {
        $r = $this->r();
        foreach ([
            'https://example.com.evil.org/',
            'https://example.community/',
            'https://www.example.com/',
            'mailto:info@example.com',
            'example.com/ohne-schema',
            'https://example.com:8443/',
        ] as $value) {
            $this->assertSame($value, $r->text($value));
        }
    }

    public function testIsIdempotent(): void
    {
        $r    = $this->r();
        $once = $r->text('https://example.com/a "https:\/\/example.com"');
        $this->assertSame($once, $r->text($once));
        $this->assertSame($once, $r->value($once));
    }

    public function testHomeWithAPath(): void
    {
        $r = $this->r('https://example.com/blog');
        $this->assertSame('https://example.com/blog/wpsync-staging-0123456789ab/x', $r->text('https://example.com/blog/x'));
        $this->assertSame('https://example.com/other', $r->text('https://example.com/other'));
        $this->assertSame('https://example.com/blogger', $r->text('https://example.com/blogger'));
    }

    /** AC-87 */
    public function testSerializedValuesStayValid(): void
    {
        $r    = $this->r();
        $data = ['url' => 'https://example.com/x', 'https://example.com/key' => ['deep' => 'http://example.com'], 'n' => 5, 'f' => 1.5, 'b' => false, 'null' => null];
        $back = unserialize($r->value(serialize($data)), ['allowed_classes' => false]);
        $this->assertSame('https://example.com/wpsync-staging-0123456789ab/x', $back['url']);
        $this->assertSame(['deep' => 'http://example.com/wpsync-staging-0123456789ab'], $back['https://example.com/wpsync-staging-0123456789ab/key']);
        $this->assertSame([5, 1.5, false, null], [$back['n'], $back['f'], $back['b'], $back['null']]);
        $this->assertSame(0, $r->skipped());
    }

    /** AC-87: Objekte unbekannter Klassen, private Eigenschaften */
    public function testObjectsOfUnknownClassesKeepTheirShape(): void
    {
        $value = 'O:13:"Elementor_Cfg":2:{s:3:"url";s:21:"https://example.com/x";s:8:"' . "\0*\0" . 'inner";a:1:{i:0;s:19:"https://example.com";}}';
        $this->assertNotFalse(unserialize($value, ['allowed_classes' => false]), 'fixture must be valid');
        $object = unserialize($this->r()->value($value), ['allowed_classes' => false]);
        $this->assertInstanceOf(\__PHP_Incomplete_Class::class, $object);
        $props = (array) $object;
        $this->assertSame('https://example.com/wpsync-staging-0123456789ab/x', $props['url']);
        $this->assertSame(['https://example.com/wpsync-staging-0123456789ab'], $props["\0*\0inner"]);
    }

    public function testDoubleSerializedValues(): void
    {
        $inner = serialize(['u' => 'https://example.com/y']);
        $new   = $this->r()->value(serialize($inner));
        $this->assertSame(['u' => 'https://example.com/wpsync-staging-0123456789ab/y'], unserialize((string) unserialize($new)));
    }

    public function testBrokenSerializedValuesStayAndAreCounted(): void
    {
        $r      = $this->r();
        $broken = 's:99:"https://example.com/x";';
        $this->assertSame($broken, $r->value($broken));
        $this->assertSame(1, $r->skipped());
        $this->assertSame('b:0;', $r->value('b:0;'));
        $this->assertSame(1, $r->skipped());
    }

    /** AC-87: Elementor speichert JSON mit escaped Slashes */
    public function testElementorJsonStaysDecodable(): void
    {
        $json = (string) json_encode([['settings' => ['link' => ['url' => 'https://example.com/kontakt'], 'image' => ['url' => 'https://example.com/wp-content/uploads/a.jpg']]]]);
        $data = json_decode($this->r()->value($json), true);
        $this->assertIsArray($data);
        $this->assertSame('https://example.com/wpsync-staging-0123456789ab/kontakt', $data[0]['settings']['link']['url']);
        $this->assertSame('https://example.com/wpsync-staging-0123456789ab/wp-content/uploads/a.jpg', $data[0]['settings']['image']['url']);
    }

    public function testRejectsBadArguments(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new StagingReplace('kein-url', self::TAIL);
    }
}
