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
        ContentImage::$keys     = null;
        ContentImage::$encrypt  = null;
        ContentImage::$fileKeys = null;
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

    /**
     * P3 R2: der Umschlag des Pushs trägt die schon abgeleiteten Dateischlüssel. Mit ihnen – und
     * ohne den Schlüssel der Installation – öffnet get() genau die Abbilder dieses Pushs.
     */
    #[\PHPUnit\Framework\Attributes\TestWith([true])]
    #[\PHPUnit\Framework\Attributes\TestWith([false])]
    public function testFileKeysOpenTheImagesOfTheirPushWithoutTheInstallationKey(bool $encrypt): void
    {
        if ($encrypt && !extension_loaded('sodium')) {
            $this->markTestSkipped('needs ext-sodium');
        }
        ContentImage::$keys    = $this->keys;
        ContentImage::$encrypt = $encrypt;
        ContentImage::put($this->dir, ContentImage::BEFORE, ['keys' => ['vorher']]);
        ContentImage::put($this->dir, ContentImage::AFTER, ['keys' => ['nachher']]);
        $before = ContentImage::fileKeys('p_20261009_aaaaaaaaaaaa', ContentImage::BEFORE);
        $after  = ContentImage::fileKeys('p_20261009_aaaaaaaaaaaa', ContentImage::AFTER);
        $this->assertCount(2, $before);
        $this->assertSame([32, 32], array_map('strlen', $before));
        $this->assertNotSame($before, $after, 'je Datei eigene Schlüssel');
        $this->assertNotContains($this->keys[0], $before, 'nie der Schlüssel der Installation selbst');

        // Ab hier gibt es den Schlüssel der Installation nicht mehr – wie in rescue.php.
        ContentImage::$keys     = [];
        ContentImage::$fileKeys = [ContentImage::BEFORE => $before, ContentImage::AFTER => [$after[0]]];
        $this->assertSame(['keys' => ['vorher']], ContentImage::get($this->dir, ContentImage::BEFORE));
        $this->assertSame(['keys' => ['nachher']], ContentImage::get($this->dir, ContentImage::AFTER));

        // Die Schlüssel eines anderen Pushs oder der anderen Datei öffnen nichts.
        foreach ([
            'anderer Push'  => ContentImage::fileKeys('p_20261009_bbbbbbbbbbbb', ContentImage::BEFORE),
            'andere Datei'  => $after,
            'kein Schlüssel' => [],
        ] as $why => $keys) {
            ContentImage::$keys     = $this->keys; // fileKeys() rechnet mit ihm; get() darf ihn nicht benutzen
            ContentImage::$fileKeys = [ContentImage::BEFORE => $keys];
            try {
                ContentImage::get($this->dir, ContentImage::BEFORE);
                $this->fail('opened with ' . $why);
            } catch (ContentException $e) {
                $this->assertSame(ContentException::IMAGE, $e->reason(), $why);
            }
        }
    }

    /** Mit Schlüsseln aus dem Umschlag gilt ein Klartext-Abbild nie – auch wenn für die Datei keiner genannt ist. */
    public function testFileKeysNeverAcceptPlaintextAndNeverWrite(): void
    {
        ContentImage::$keys = [];
        ContentImage::put($this->dir, ContentImage::BEFORE, ['keys' => []]);
        $this->assertSame(['keys' => []], ContentImage::get($this->dir, ContentImage::BEFORE), 'ohne Schlüssel der Installation: Klartext');
        $this->assertSame([], ContentImage::fileKeys('p_20261009_aaaaaaaaaaaa', ContentImage::BEFORE));

        foreach ([[], [ContentImage::BEFORE => []], [ContentImage::AFTER => [str_repeat('k', 32)]], [ContentImage::BEFORE => [str_repeat('k', 32)]]] as $fileKeys) {
            ContentImage::$fileKeys = $fileKeys;
            try {
                ContentImage::get($this->dir, ContentImage::BEFORE);
                $this->fail('accepted plaintext');
            } catch (ContentException $e) {
                $this->assertSame(ContentException::IMAGE, $e->reason());
            }
        }
        $this->assertNull(ContentImage::get($this->dir, ContentImage::AFTER), 'eine Datei, die es nicht gibt, bleibt null');
        try {
            ContentImage::put($this->dir, ContentImage::AFTER, ['keys' => []]);
            $this->fail('wrote an image');
        } catch (ContentException $e) {
            $this->assertSame(ContentException::FAILED, $e->reason());
        }
        $this->assertFileDoesNotExist($this->dir . '/after.json');
    }

    public function testTheNamesOfTheImagesBelongToContentImage(): void
    {
        $this->assertSame(['before.json', 'after.json'], [ContentImage::BEFORE, ContentImage::AFTER]);
        $this->assertSame([ContentImage::BEFORE, ContentImage::AFTER], [\WpSync\ContentApply::BEFORE, \WpSync\ContentApply::AFTER]);
    }

    /**
     * Was rescue.php vor der Schlüsselprüfung lädt, kennt weder die Abbilder noch einen Schlüssel
     * noch die Datenbank (P3 §4.1 Nr. 2): das alles liegt hinter RescueContent, das PushRescue erst
     * mit dem richtigen Schlüssel lädt.
     */
    public function testWhatRescueLoadsBeforeTheKeyCheckKnowsNeitherImagesNorKeysNorTheDatabase(): void
    {
        foreach (['rescue.php', 'src/PushRescue.php', 'src/PushSwap.php'] as $file) {
            // Der Code ohne seine Kommentare: die dürfen sagen, was hier nie geschieht.
            $source = '';
            foreach (token_get_all((string) file_get_contents(__DIR__ . '/../' . $file)) as $token) {
                if (!is_array($token)) {
                    $source .= $token;
                } elseif ($token[0] !== T_COMMENT && $token[0] !== T_DOC_COMMENT) {
                    $source .= $token[1];
                }
            }
            foreach (['ContentImage', 'ContentApply', 'ContentRollback', 'ContentSql', 'SecretKey', 'SecretBox', 'RescueSeal::', 'RescueDb', 'MysqliLink', 'sodium_', 'openssl_', 'mysqli', 'DB_PASSWORD', 'wp-config'] as $word) {
                $this->assertStringNotContainsString($word, $source, $file . ': ' . $word);
            }
        }
        $rescue = (string) file_get_contents(__DIR__ . '/../src/PushRescue.php');
        $this->assertSame(1, substr_count($rescue, 'RescueContent::run('), 'ein Weg zur Datenbank, hinter der Schlüsselprüfung');
        $this->assertSame(1, substr_count($rescue, "require_once __DIR__ . '/RescueContent.php';"));
    }
}
