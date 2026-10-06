<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\TestCase;
use WpSync\Pairing;

final class PairingTest extends TestCase
{
    public function testCodeFormat(): void
    {
        $this->assertMatchesRegularExpression('/^[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{8}$/', Pairing::newCode());
        $this->assertMatchesRegularExpression('/^[a-f0-9]{16}$/', Pairing::newKeyId());
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', Pairing::newSecret());
    }

    public function testIsSecretRecognisesPlainSecrets(): void
    {
        $this->assertTrue(Pairing::isSecret(Pairing::newSecret()));
        $this->assertFalse(Pairing::isSecret(strtoupper(Pairing::newSecret())));
        $this->assertFalse(Pairing::isSecret(substr(Pairing::newSecret(), 1)));
        $this->assertFalse(Pairing::isSecret('v1:' . Pairing::newSecret()));
        $this->assertFalse(Pairing::isSecret(''));
    }

    public function testNormalizeIgnoresCaseAndSeparators(): void
    {
        $this->assertSame(Pairing::hashCode('ABCD-EFGH'), Pairing::hashCode('abcd efgh'));
    }

    public function testRedeemValidCodeConsumesIt(): void
    {
        $stored = Pairing::stored('ABCDEFGH', 1000);
        [$ok, $next] = Pairing::redeem($stored, 'abcd-efgh', 1100);
        $this->assertTrue($ok);
        $this->assertNull($next, 'code is single use');
    }

    public function testRedeemExpiredCodeFails(): void
    {
        [$ok, $next] = Pairing::redeem(Pairing::stored('ABCDEFGH', 1000), 'ABCDEFGH', 1000 + Pairing::CODE_TTL + 1);
        $this->assertFalse($ok);
        $this->assertNull($next);
    }

    public function testWrongCodeCountsAttemptsAndBurnsCodeAfterLimit(): void
    {
        $stored = Pairing::stored('ABCDEFGH', 1000);
        for ($i = 1; $i < Pairing::MAX_ATTEMPTS; $i++) {
            [$ok, $stored] = Pairing::redeem($stored, 'WRONGWRO', 1001);
            $this->assertFalse($ok);
            $this->assertSame($i, $stored['attempts']);
        }
        [$ok, $stored] = Pairing::redeem($stored, 'WRONGWRO', 1001);
        $this->assertFalse($ok);
        $this->assertNull($stored);
        [$ok] = Pairing::redeem($stored, 'ABCDEFGH', 1001);
        $this->assertFalse($ok, 'burned code must not work anymore');
    }

    public function testNoStoredCodeFails(): void
    {
        [$ok, $next] = Pairing::redeem(null, 'ABCDEFGH', 1000);
        $this->assertFalse($ok);
        $this->assertNull($next);
    }

    public function testDeviceIsCutAtCharactersNotBytes(): void
    {
        $device = Pairing::device(str_repeat('a', 99) . 'ä' . 'zzz');
        $this->assertSame(str_repeat('a', 99) . 'ä', $device);
        $this->assertTrue(mb_check_encoding($device, 'UTF-8'), 'a multibyte character must not be cut in half (CR-01)');
        $this->assertSame(100, mb_strlen(Pairing::device(str_repeat('ä', 150)), 'UTF-8'));
    }

    public function testEmptyDeviceGetsPlaceholder(): void
    {
        $this->assertSame('unbekannt', Pairing::device(''));
        $this->assertSame('Mac.fritz.box', Pairing::device('Mac.fritz.box'));
    }
}
