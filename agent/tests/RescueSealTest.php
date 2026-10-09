<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WpSync\PushRescue;
use WpSync\RescueSeal;

/**
 * Der versiegelte Umschlag (Spec Content-Push P3 §5.1): nur der Rescue-Key des Pushs öffnet ihn,
 * er gilt nur für diesen Push, und jede Veränderung fällt auf – in beiden Verfahren.
 */
final class RescueSealTest extends TestCase
{
    private const ID    = 'p_20261009_0123456789ab';
    private const OTHER = 'p_20261009_ba9876543210';
    private const DATA  = ['v' => 1, 'db' => ['user' => 'wp', 'password' => "geh\"eim\0'", 'name' => 'wordpress'], 'prefix' => 'wp_'];

    private string $key;

    protected function setUp(): void
    {
        $this->key = PushRescue::key(str_repeat('ab', 32), self::ID, 'salt');
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function methods(): array
    {
        return ['sodium' => ['sodium', RescueSeal::SODIUM], 'gcm' => ['gcm', RescueSeal::GCM]];
    }

    #[DataProvider('methods')]
    public function testSealsAndOpens(string $method, string $head): void
    {
        $sealed = RescueSeal::seal(self::DATA, $this->key, self::ID, $method);
        $this->assertIsString($sealed);
        $this->assertStringStartsWith($head, $sealed);
        $this->assertStringNotContainsString('geh', $sealed);
        $this->assertStringNotContainsString('wordpress', $sealed);
        $this->assertSame(['push_id' => self::ID] + self::DATA, RescueSeal::open($sealed, $this->key, self::ID));
        $this->assertNotSame($sealed, RescueSeal::seal(self::DATA, $this->key, self::ID, $method), 'jede Versiegelung mit eigener Nonce');
    }

    #[DataProvider('methods')]
    public function testAWrongKeyOpensNothing(string $method): void
    {
        $sealed = (string) RescueSeal::seal(self::DATA, $this->key, self::ID, $method);
        $this->assertNull(RescueSeal::open($sealed, PushRescue::key(str_repeat('ab', 32), self::ID, 'anderes salt'), self::ID));
        $this->assertNull(RescueSeal::open($sealed, '', self::ID));
        // Der Hash, der auf dem Server liegt, öffnet nichts.
        $this->assertNull(RescueSeal::open($sealed, hash('sha256', $this->key), self::ID));
    }

    /** Jedes einzelne Byte zählt: Kopfzeile, Nonce, Chiffrat, Prüfsumme. */
    #[DataProvider('methods')]
    public function testAChangedFileOpensNothing(string $method, string $head): void
    {
        $sealed = (string) RescueSeal::seal(self::DATA, $this->key, self::ID, $method);
        for ($i = strlen($head); $i < strlen($sealed); $i += 7) {
            $changed     = $sealed;
            $changed[$i] = chr(ord($changed[$i]) ^ 1);
            $this->assertNull(RescueSeal::open($changed, $this->key, self::ID), 'Byte ' . $i);
        }
        $this->assertNull(RescueSeal::open(substr($sealed, 0, -1), $this->key, self::ID));
        $this->assertNull(RescueSeal::open($head, $this->key, self::ID));
        $this->assertNull(RescueSeal::open('', $this->key, self::ID));
        $this->assertNull(RescueSeal::open((string) json_encode(self::DATA), $this->key, self::ID), 'Klartext ist kein Umschlag');
    }

    /** Ein Umschlag gilt nur für seinen Push – auch wenn derselbe Schlüssel ihn versiegelt hätte. */
    #[DataProvider('methods')]
    public function testAnEnvelopeOfAnotherPushOpensNothing(string $method): void
    {
        $sealed = (string) RescueSeal::seal(self::DATA, $this->key, self::ID, $method);
        $this->assertNull(RescueSeal::open($sealed, $this->key, self::OTHER));
        // Mit dem Schlüssel des anderen Pushs für den anderen Push versiegelt, unter dieser ID gelesen.
        $foreign = (string) RescueSeal::seal(self::DATA, $this->key, self::OTHER, $method);
        $this->assertNull(RescueSeal::open($foreign, $this->key, self::ID));
        $this->assertNull(RescueSeal::open($sealed, $this->key, 'kein-push'));
    }

    /** Die Verfahren sind nicht austauschbar: die Kopfzeile gehört zum Umschlag. */
    public function testTheHeadDecidesTheMethod(): void
    {
        $sodium = (string) RescueSeal::seal(self::DATA, $this->key, self::ID, 'sodium');
        $this->assertNull(RescueSeal::open(RescueSeal::GCM . substr($sodium, strlen(RescueSeal::SODIUM)), $this->key, self::ID));
        $gcm = (string) RescueSeal::seal(self::DATA, $this->key, self::ID, 'gcm');
        $this->assertNull(RescueSeal::open(RescueSeal::SODIUM . substr($gcm, strlen(RescueSeal::GCM)), $this->key, self::ID));
    }

    public function testMethodPrefersSodiumAndNamesNoneForAnUnknownOne(): void
    {
        $this->assertSame('sodium', RescueSeal::method());
        $this->assertNull(RescueSeal::seal(self::DATA, $this->key, self::ID, 'rot13'));
        $this->assertNull(RescueSeal::seal(self::DATA, '', self::ID), 'ohne Schlüssel kein Umschlag');
        $this->assertNull(RescueSeal::seal(self::DATA, $this->key, 'kein-push'));
        $this->assertNull(RescueSeal::seal(['x' => "\xff"], $this->key, self::ID), 'was sich nicht als JSON schreiben lässt');
    }

    public function testPutWritesOnlyForTheOwnerAndReadGivesItBack(): void
    {
        $dir    = sys_get_temp_dir() . '/wpsync-seal-' . bin2hex(random_bytes(4));
        $sealed = (string) RescueSeal::seal(self::DATA, $this->key, self::ID);
        mkdir($dir . '/' . self::ID, 0777, true);
        try {
            $file = RescueSeal::file($dir, self::ID);
            $this->assertSame($dir . '/' . self::ID . '/rescue.sealed', $file);
            $this->assertNull(RescueSeal::read($dir, self::ID));
            $this->assertTrue(RescueSeal::put($dir, self::ID, $sealed));
            $this->assertSame('0600', substr(sprintf('%o', fileperms($file)), -4));
            $this->assertSame($sealed, RescueSeal::read($dir, self::ID));
            $this->assertFileDoesNotExist($file . '.tmp');

            // Ein Symlink an der Stelle wird weder gelesen noch überschrieben.
            unlink($file);
            file_put_contents($dir . '/fremd', 'x');
            symlink($dir . '/fremd', $file);
            $this->assertNull(RescueSeal::read($dir, self::ID));
            RescueSeal::forget($dir, self::ID);
            $this->assertFalse(is_link($file));
            $this->assertSame('x', file_get_contents($dir . '/fremd'));
            $this->assertFalse(RescueSeal::put($dir, 'kein-push', $sealed));
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }
}
