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
    /** created weit in der Zukunft: dieser Umschlag gilt in keinem Testlauf als zu alt. */
    private const DATA  = ['v' => 1, 'created' => 4102444800, 'db' => ['user' => 'wp', 'password' => "geh\"eim\0'", 'name' => 'wordpress'], 'prefix' => 'wp_'];

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

    /**
     * Security-Review P3, N1: der Rescue-Key ist genau 64 kleine Hex-Zeichen (HMAC-SHA256, wie
     * PushRescue::key() und die CLI ihn bilden) – alles andere versiegelt und öffnet nichts.
     */
    #[DataProvider('methods')]
    public function testOnlyAKeyOfSixtyFourLowerHexDigitsSealsOrOpens(string $method): void
    {
        $sealed = (string) RescueSeal::seal(self::DATA, $this->key, self::ID, $method);
        $this->assertSame(1, preg_match('/^[a-f0-9]{64}\z/', $this->key));
        foreach (['k', 'geheim', substr($this->key, 0, 63), $this->key . 'a', strtoupper($this->key), $this->key . "\n", ' ' . $this->key, substr($this->key, 0, 63) . 'g', str_repeat("\0", 64)] as $bad) {
            $this->assertNull(RescueSeal::seal(self::DATA, $bad, self::ID, $method), 'seal: ' . bin2hex($bad));
            $this->assertNull(RescueSeal::open($sealed, $bad, self::ID), 'open: ' . bin2hex($bad));
        }
    }

    /**
     * N1: der Schlüssel des Umschlags entsteht aus den 32 Byte des Rescue-Keys, nicht aus seiner
     * Hex-Schreibweise: E = HMAC-SHA256(hex2bin(K), "wpsync-rescue-envelope-v1\0" + push_id).
     */
    public function testTheEnvelopeKeyIsDerivedFromTheBytesOfTheRescueKey(): void
    {
        $sealed = (string) RescueSeal::seal(self::DATA, $this->key, self::ID, 'gcm');
        $body   = substr($sealed, strlen(RescueSeal::GCM));
        $open   = static function (string $secret) use ($body) {
            return openssl_decrypt(substr($body, 28), 'aes-256-gcm', $secret, OPENSSL_RAW_DATA, substr($body, 0, 12), substr($body, 12, 16), self::ID);
        };
        $label = "wpsync-rescue-envelope-v1\0" . self::ID;
        $this->assertSame(json_encode(['push_id' => self::ID] + self::DATA, JSON_UNESCAPED_SLASHES), $open(hash_hmac('sha256', $label, (string) hex2bin($this->key), true)));
        $this->assertFalse($open(hash_hmac('sha256', $label, $this->key, true)), 'nicht aus der Hex-Zeichenkette');
    }

    /**
     * Security-Review P3, N3: älter als sieben Tage öffnet ein Umschlag nie – gleich, wer fragt und
     * was die Uhr der Datei (mtime, R14) sagt. Das Alter steht authentisiert im Umschlag (created).
     */
    #[DataProvider('methods')]
    public function testAnEnvelopeOlderThanSevenDaysOpensNothing(string $method): void
    {
        $this->assertSame(7 * 86400, RescueSeal::MAX_AGE);
        $born   = 1790000000;
        $sealed = (string) RescueSeal::seal(['created' => $born] + self::DATA, $this->key, self::ID, $method);
        $this->assertSame($born, RescueSeal::open($sealed, $this->key, self::ID, $born)['created'] ?? null);
        $this->assertNotNull(RescueSeal::open($sealed, $this->key, self::ID, $born + RescueSeal::MAX_AGE), 'genau sieben Tage: noch');
        $this->assertNull(RescueSeal::open($sealed, $this->key, self::ID, $born + RescueSeal::MAX_AGE + 1));
        $this->assertNull(RescueSeal::open($sealed, $this->key, self::ID), 'ohne Angabe gilt die Uhr des Servers');
        $this->assertNotNull(RescueSeal::open($sealed, $this->key, self::ID, $born - 3600), 'eine nachgehende Uhr macht ihn nicht alt');
        // Ohne lesbares Alter gibt es keinen Umschlag: weder versiegelt noch geöffnet.
        foreach ([null, '1790000000', 1.5, -1, true] as $bad) {
            $data = ['created' => $bad] + self::DATA;
            if ($bad === null) {
                unset($data['created']);
            }
            $this->assertNull(RescueSeal::seal($data, $this->key, self::ID, $method), var_export($bad, true));
        }
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
