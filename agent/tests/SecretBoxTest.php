<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\TestCase;
use WpSync\SecretBox;

final class SecretBoxTest extends TestCase
{
    private const PLAIN = 'abababababababababababababababababababababababababababababababab';

    private static function key(string $seed = 'a'): string
    {
        return hash('sha256', $seed, true);
    }

    public function testSodiumIsAvailable(): void
    {
        $this->assertTrue(SecretBox::available(), 'PHPUnit braucht sodium (ext oder sodium_compat)');
    }

    public function testRoundtrip(): void
    {
        $sealed = SecretBox::seal(self::PLAIN, self::key());
        $this->assertStringStartsWith('v1:', $sealed);
        $this->assertStringNotContainsString(self::PLAIN, $sealed);
        $this->assertSame(self::PLAIN, SecretBox::open($sealed, self::key()));
    }

    public function testSealedValueFitsColumn(): void
    {
        $this->assertLessThanOrEqual(255, strlen(SecretBox::seal(self::PLAIN, self::key())));
    }

    public function testNonceIsFreshPerSeal(): void
    {
        $this->assertNotSame(SecretBox::seal(self::PLAIN, self::key()), SecretBox::seal(self::PLAIN, self::key()));
    }

    public function testWrongKeyGivesNull(): void
    {
        $this->assertNull(SecretBox::open(SecretBox::seal(self::PLAIN, self::key()), self::key('b')));
    }

    public function testTamperedCiphertextGivesNull(): void
    {
        $sealed = SecretBox::seal(self::PLAIN, self::key());
        $raw    = (string) base64_decode(substr($sealed, 3), true);
        $raw[30] = chr(ord($raw[30]) ^ 1);
        $this->assertNull(SecretBox::open('v1:' . base64_encode($raw), self::key()));
    }

    public function testTruncatedValueGivesNull(): void
    {
        $sealed = SecretBox::seal(self::PLAIN, self::key());
        $this->assertNull(SecretBox::open(substr($sealed, 0, 20), self::key()));
        $this->assertNull(SecretBox::open('v1:', self::key()));
        $this->assertNull(SecretBox::open('v1:' . base64_encode(str_repeat("\0", 39)), self::key()));
    }

    public function testForeignFormatGivesNull(): void
    {
        $sealed = SecretBox::seal(self::PLAIN, self::key());
        $this->assertNull(SecretBox::open(self::PLAIN, self::key()), 'Klartext ist kein versiegelter Wert');
        $this->assertNull(SecretBox::open('v2:' . substr($sealed, 3), self::key()));
        $this->assertNull(SecretBox::open('v1:###kein base64###', self::key()));
        $this->assertNull(SecretBox::open('', self::key()));
    }

    public function testInvalidKeyLengthGivesNullOnOpen(): void
    {
        $sealed = SecretBox::seal(self::PLAIN, self::key());
        $this->assertNull(SecretBox::open($sealed, 'zu kurz'));
    }

    public function testInvalidKeyLengthRejectedOnSeal(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SecretBox::seal(self::PLAIN, 'zu kurz');
    }

    public function testIsSealedRecognisesFormat(): void
    {
        $this->assertTrue(SecretBox::isSealed(SecretBox::seal(self::PLAIN, self::key())));
        $this->assertFalse(SecretBox::isSealed(self::PLAIN));
        $this->assertFalse(SecretBox::isSealed(''));
        $this->assertFalse(SecretBox::isSealed('v2:abc'));
    }
}
