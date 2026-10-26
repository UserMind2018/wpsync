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

    /** Spec 0.9.1 G1: der Name aus dem WP-Admin liegt beim Code – ohne Namen bleibt der Zustand, wie er war. */
    public function testACodeCarriesTheNameGivenInTheAdmin(): void
    {
        $this->assertSame(['hash', 'expires', 'attempts'], array_keys(Pairing::stored('ABCDEFGH', 1000)));
        $this->assertSame(['hash', 'expires', 'attempts'], array_keys(Pairing::stored('ABCDEFGH', 1000, '')));
        $stored = Pairing::stored('ABCDEFGH', 1000, 'Studio Büro');
        $this->assertSame('Studio Büro', $stored['device']);
        $this->assertSame('Studio Büro', Pairing::named($stored));
        $this->assertSame(100, mb_strlen(Pairing::stored('ABCDEFGH', 1000, str_repeat('ä', 150))['device'], 'UTF-8'));
        $this->assertSame('', Pairing::named(Pairing::stored('ABCDEFGH', 1000)));
        $this->assertSame('', Pairing::named(null));
        $this->assertSame('', Pairing::named(['device' => ['kein', 'Text']] + Pairing::stored('ABCDEFGH', 1000)));
    }

    /** AC-249: ein Fehlversuch behält den Namen; ein abgelaufener oder verbrannter Code nimmt ihn mit. */
    public function testTheNameGoesWithItsCode(): void
    {
        $stored = Pairing::stored('ABCDEFGH', 1000, 'Studio Büro');
        [$ok, $next] = Pairing::redeem($stored, 'WRONGWRO', 1001);
        $this->assertFalse($ok);
        $this->assertSame('Studio Büro', Pairing::named($next));
        for ($i = 2; $i <= Pairing::MAX_ATTEMPTS; $i++) {
            [, $next] = Pairing::redeem($next, 'WRONGWRO', 1001);
        }
        $this->assertNull($next, 'verbrannt: kein Name bleibt liegen');
        [, $next] = Pairing::redeem($stored, 'ABCDEFGH', 1000 + Pairing::CODE_TTL + 1);
        $this->assertNull($next, 'abgelaufen');
        [$ok, $next] = Pairing::redeem($stored, 'ABCDEFGH', 1001);
        $this->assertTrue($ok);
        $this->assertNull($next, 'eingelöst');
        // Der nächste Code beginnt ohne Namen.
        $this->assertSame('', Pairing::named(Pairing::stored('JKMNPQRS', 2000)));
    }

    /** G2, AC-244–AC-246: --device der CLI > Name aus dem Admin > Rechnername. */
    public function testWhichNameAPairingGets(): void
    {
        $named   = Pairing::stored('ABCDEFGH', 1000, 'Studio Büro');
        $unnamed = Pairing::stored('ABCDEFGH', 1000);
        $this->assertSame('Studio Büro', Pairing::choose('Mac.fritz.box', false, $named), 'AC-244: ohne --device der Name aus dem Admin');
        $this->assertSame('Laptop', Pairing::choose('Laptop', true, $named), 'AC-245: --device gewinnt');
        $this->assertSame('Mac.fritz.box', Pairing::choose('Mac.fritz.box', false, $unnamed), 'AC-246: wie bisher');
        $this->assertSame('Laptop', Pairing::choose('Laptop', true, $unnamed));
        // Eine alte CLI sendet device_given nicht: der Name aus dem Admin gewinnt über den Rechnernamen.
        $this->assertSame('Studio Büro', Pairing::choose('Mac.fritz.box', false, $named));
        // device_given ohne Namen ist kein Name.
        $this->assertSame('Studio Büro', Pairing::choose('', true, $named));
        $this->assertSame('unbekannt', Pairing::choose('', true, $unnamed));
        $this->assertSame('unbekannt', Pairing::choose('', false, null));
        $this->assertSame(100, mb_strlen(Pairing::choose(str_repeat('ä', 150), true, $named), 'UTF-8'));
    }
}
