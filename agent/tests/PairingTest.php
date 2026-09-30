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
}
