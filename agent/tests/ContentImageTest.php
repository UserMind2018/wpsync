<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\TestCase;
use WpSync\ContentException;
use WpSync\ContentImage;

/**
 * N2: before.json und after.json eines Pushs liegen verschlüsselt und authentisiert im
 * Arbeitsordner – oder, ohne natives sodium, mit einem HMAC. Wer dort Dateien schreiben kann,
 * bringt die Rücknahme nicht dazu, etwas anderes zurückzuschreiben als das Vorher-Abbild.
 */
final class ContentImageTest extends TestCase
{
    private const JSON = '{"keys":[{"t":"options","k":"blogname","state":{"option_value":"S3VuZGU="}}]}';

    private string $dir;
    /** @var list<string> */
    private array $keys;

    protected function setUp(): void
    {
        $this->dir  = sys_get_temp_dir() . '/wpsync-image-' . bin2hex(random_bytes(4)) . '/p_20261009_aaaaaaaaaaaa/content';
        $this->keys = [hash('sha256', 'erster', true), hash('sha256', 'zweiter', true)];
    }

    protected function tearDown(): void
    {
        ContentImage::$keys    = null;
        ContentImage::$encrypt = null;
        $root = dirname($this->dir, 2);
        exec('chmod -R u+w ' . escapeshellarg($root) . ' 2>/dev/null; rm -rf ' . escapeshellarg($root));
    }

    public function testSealedImagesHideAndProtectTheirContent(): void
    {
        if (!extension_loaded('sodium')) {
            $this->markTestSkipped('needs ext-sodium');
        }
        $sealed = ContentImage::pack(self::JSON, $this->keys, 'p1/before.json', true);
        $this->assertStringStartsWith(ContentImage::SEALED, $sealed);
        $this->assertStringNotContainsString('blogname', $sealed);
        $this->assertStringNotContainsString('S3VuZGU', $sealed);
        $this->assertNotSame($sealed, ContentImage::pack(self::JSON, $this->keys, 'p1/before.json', true), 'jedes Mal eine neue Nonce');
        $this->assertSame(self::JSON, ContentImage::unpack($sealed, $this->keys, 'p1/before.json'));
        $this->assertSame(self::JSON, ContentImage::unpack($sealed, array_reverse($this->keys), 'p1/before.json'), 'jeder Schlüssel der Installation wird versucht');

        $this->assertNull(ContentImage::unpack($sealed, [hash('sha256', 'fremd', true)], 'p1/before.json'), 'anderer Schlüssel');
        $this->assertNull(ContentImage::unpack($sealed, $this->keys, 'p1/after.json'), 'eine Datei gilt nur unter ihrem Namen');
        $this->assertNull(ContentImage::unpack($sealed, $this->keys, 'p2/before.json'), 'und nur für ihren Push');
        $this->assertNull(ContentImage::unpack($sealed, [], 'p1/before.json'), 'ohne Schlüssel nicht zu öffnen');
        $flipped                       = $sealed;
        $flipped[strlen($flipped) - 5] = $flipped[strlen($flipped) - 5] === 'x' ? 'y' : 'x';
        $this->assertNull(ContentImage::unpack($flipped, $this->keys, 'p1/before.json'), 'ein verändertes Byte');
        $this->assertNull(ContentImage::unpack(substr($sealed, 0, 20), $this->keys, 'p1/before.json'), 'abgeschnitten');
    }

    public function testSignedImagesProtectTheirContentWithoutSodium(): void
    {
        $signed = ContentImage::pack(self::JSON, $this->keys, 'p1/before.json', false);
        $this->assertStringStartsWith(ContentImage::SIGNED, $signed);
        $this->assertStringEndsWith("\n" . self::JSON, $signed, 'lesbar, aber nicht veränderbar');
        $this->assertSame(self::JSON, ContentImage::unpack($signed, $this->keys, 'p1/before.json'));
        $this->assertSame(self::JSON, ContentImage::unpack($signed, [$this->keys[1], $this->keys[0]], 'p1/before.json'));
        $this->assertNull(ContentImage::unpack(str_replace('blogname', 'siteurl_', $signed), $this->keys, 'p1/before.json'));
        $this->assertNull(ContentImage::unpack($signed, [hash('sha256', 'fremd', true)], 'p1/before.json'));
        $this->assertNull(ContentImage::unpack($signed, $this->keys, 'p1/after.json'));
        $this->assertNull(ContentImage::unpack($signed, [], 'p1/before.json'));
        $this->assertNull(ContentImage::unpack(ContentImage::SIGNED . 'kein-hmac', $this->keys, 'p1/before.json'));
    }

    /** Hat die Installation einen Schlüssel, gilt eine Klartext-Datei nicht – sonst liesse sich jede geschützte einfach ersetzen. */
    public function testPlainImagesOnlyWithoutAnyKey(): void
    {
        $this->assertSame(self::JSON, ContentImage::pack(self::JSON, [], 'p1/before.json', true), 'ohne Schlüssel bleibt es beim Klartext');
        $this->assertSame(self::JSON, ContentImage::unpack(self::JSON, [], 'p1/before.json'));
        $this->assertNull(ContentImage::unpack(self::JSON, $this->keys, 'p1/before.json'));
        $this->assertNull(ContentImage::unpack('', [], 'p1/before.json'));
    }

    public function testPutAndGetKeepTheFilesPrivate(): void
    {
        ContentImage::$keys = $this->keys;
        $data               = json_decode(self::JSON, true);
        ContentImage::put($this->dir, 'before.json', $data);
        $file = $this->dir . '/before.json';
        $this->assertSame('0600', substr(sprintf('%o', fileperms($file)), -4));
        $this->assertSame('0700', substr(sprintf('%o', fileperms($this->dir)), -4));
        $this->assertStringNotContainsString('blogname', (string) file_get_contents($file));
        $this->assertSame($data, ContentImage::get($this->dir, 'before.json'));
        $this->assertNull(ContentImage::get($this->dir, 'after.json'), 'fehlt die Datei, ist das kein Fehler');
        $this->assertSame([], glob($this->dir . '/*.tmp'));

        // Unter einem anderen Namen oder in einem anderen Push gilt die Datei nicht.
        copy($file, $this->dir . '/after.json');
        foreach (['after.json' => $this->dir, 'before.json' => dirname($this->dir, 2) . '/p_20261009_bbbbbbbbbbbb/content'] as $name => $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0700, true);
                copy($file, $dir . '/' . $name);
            }
            try {
                ContentImage::get($dir, $name);
                $this->fail('accepted ' . $name);
            } catch (ContentException $e) {
                $this->assertSame('before_image_invalid', $e->reason());
                $this->assertSame(409, $e->status());
                $this->assertStringNotContainsString($this->dir, $e->getMessage(), 'kein Pfad in der Meldung');
            }
        }
        // Ein Symlink ist keine Datei des Pushs.
        unlink($this->dir . '/after.json');
        symlink($file, $this->dir . '/after.json');
        $this->expectException(ContentException::class);
        ContentImage::get($this->dir, 'after.json');
    }

    /** Scheitert das Schreiben, gibt es eine Ablehnung – keine PHP-Warnung mit dem Pfad im Fehlerprotokoll. */
    public function testAFailedWriteIsARefusalWithoutAWarning(): void
    {
        mkdir(dirname($this->dir), 0700, true);
        file_put_contents($this->dir, 'eine Datei, wo der Ordner sein müsste');
        try {
            ContentImage::put($this->dir, 'before.json', ['keys' => []]);
            $this->fail('no exception');
        } catch (ContentException $e) {
            $this->assertSame(ContentException::FAILED, $e->reason());
            $this->assertStringNotContainsString($this->dir, $e->getMessage());
        }
    }

    /** rescue.php nimmt nie Inhalte zurück: es braucht weder das Vorher-Abbild noch einen Schlüssel. */
    public function testRescueNeverTouchesTheImages(): void
    {
        foreach (['rescue.php', 'src/PushRescue.php', 'src/PushSwap.php'] as $file) {
            $source = (string) file_get_contents(__DIR__ . '/../' . $file);
            foreach (['ContentImage', 'ContentApply', 'before.json', 'SecretKey', 'SecretBox', 'sodium_'] as $word) {
                $this->assertStringNotContainsString($word, $source, $file . ': ' . $word);
            }
        }
    }
}
