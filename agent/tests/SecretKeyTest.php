<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\TestCase;
use WpSync\SecretKey;

final class SecretKeyTest extends TestCase
{
    private const AUTH   = 'auth-key-0123456789-0123456789-0123456789-0123456789';
    private const SECURE = 'secure-auth-key-0123456789-0123456789-0123456789-01';
    private const OWN    = 'own-wpsync-key-0123456789-0123456789';

    public function testDefaultPhraseIsNotUsable(): void
    {
        $this->assertFalse(SecretKey::usableSalt('put your unique phrase here'));
        $this->assertFalse(SecretKey::usableSalt(null));
        $this->assertFalse(SecretKey::usableSalt(''));
        $this->assertFalse(SecretKey::usableSalt('kurz'), 'unter 32 Zeichen');
        $this->assertTrue(SecretKey::usableSalt(self::AUTH));
    }

    public function testSaltsDeriveKey(): void
    {
        $keys = SecretKey::candidates(null, self::AUTH, self::SECURE);
        $this->assertCount(1, $keys);
        $this->assertSame(32, strlen($keys[0]));
        $this->assertSame(hash_hmac('sha256', 'wpsync-secretbox-v1', self::AUTH . self::SECURE, true), $keys[0]);
    }

    public function testDerivationIsStable(): void
    {
        $this->assertSame(SecretKey::candidates(null, self::AUTH, self::SECURE), SecretKey::candidates(null, self::AUTH, self::SECURE));
    }

    public function testRotatedSaltGivesOtherKey(): void
    {
        $this->assertNotSame(
            SecretKey::candidates(null, self::AUTH, self::SECURE),
            SecretKey::candidates(null, self::AUTH, self::SECURE . 'x')
        );
    }

    public function testBothSaltsRequired(): void
    {
        $this->assertSame([], SecretKey::candidates(null, self::AUTH, null));
        $this->assertSame([], SecretKey::candidates(null, null, self::SECURE));
        $this->assertSame([], SecretKey::candidates(null, self::AUTH, 'put your unique phrase here'));
        $this->assertSame([], SecretKey::candidates(null, 'put your unique phrase here', self::SECURE));
    }

    public function testOwnKeyComesFirstAndSaltsStayAsFallback(): void
    {
        $keys = SecretKey::candidates(self::OWN, self::AUTH, self::SECURE);
        $this->assertCount(2, $keys);
        $this->assertSame(hash_hmac('sha256', 'wpsync-secretbox-v1', self::OWN, true), $keys[0]);
        $this->assertSame(SecretKey::candidates(null, self::AUTH, self::SECURE)[0], $keys[1]);
    }

    public function testOwnKeyWorksWithoutSalts(): void
    {
        $this->assertCount(1, SecretKey::candidates(self::OWN, null, null));
    }

    public function testShortOwnKeyIsIgnored(): void
    {
        $this->assertSame(SecretKey::candidates(null, self::AUTH, self::SECURE), SecretKey::candidates('zu-kurz', self::AUTH, self::SECURE));
        $this->assertSame([], SecretKey::candidates(str_repeat('x', 31), null, null));
        $this->assertCount(1, SecretKey::candidates(str_repeat('x', 32), null, null));
    }
}
