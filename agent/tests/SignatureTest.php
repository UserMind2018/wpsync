<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\TestCase;
use WpSync\Signature;

final class SignatureTest extends TestCase
{
    private const SECRET = 'test-secret-0123456789abcdef0123456789abcdef';
    private const NONCE  = '0123456789abcdef0123456789abcdef';
    private const ROUTE  = '/wpsync/v1/ping';

    public function testKnownVectorMatchesGoImplementation(): void
    {
        $payload = Signature::payload('POST', self::ROUTE, 1790000000, self::NONCE, '{}');
        $this->assertSame(
            'c797bbaa5e3decdcf65b9846271bc15ed06bedda7234591ba9793984c05f4d0d',
            Signature::sign(self::SECRET, $payload)
        );
    }

    public function testTimestampHeaderMustBePlainDigits(): void
    {
        $this->assertSame(1790000000, Signature::timestamp('1790000000'));
        $this->assertSame(0, Signature::timestamp('0'));
        foreach (['1790000000abc', ' 1790000000', '1790000000 ', '+1790000000', '-1', '1.5', '0x1F', '', '17900000000', "1790000000\n"] as $bad) {
            $this->assertNull(Signature::timestamp($bad), var_export($bad, true));
        }
    }

    public function testValidSignaturePasses(): void
    {
        $sig = $this->sign(1000, '{}');
        $this->assertNull(Signature::check(self::SECRET, 'POST', self::ROUTE, 1000, self::NONCE, '{}', $sig, 1100));
    }

    public function testTamperedBodyFails(): void
    {
        $sig = $this->sign(1000, '{}');
        $this->assertSame('signature', Signature::check(self::SECRET, 'POST', self::ROUTE, 1000, self::NONCE, '{"a":1}', $sig, 1000));
    }

    public function testOldTimestampFails(): void
    {
        $sig = $this->sign(1000, '{}');
        $this->assertSame('timestamp', Signature::check(self::SECRET, 'POST', self::ROUTE, 1000, self::NONCE, '{}', $sig, 1301));
    }

    public function testMalformedNonceFails(): void
    {
        $this->assertSame('nonce', Signature::check(self::SECRET, 'POST', self::ROUTE, 1000, 'xyz', '{}', 'x', 1000));
    }

    public function testNonceWithTrailingNewlineFails(): void
    {
        $this->assertSame('nonce', Signature::check(self::SECRET, 'POST', self::ROUTE, 1000, self::NONCE . "\n", '{}', 'x', 1000));
    }

    private function sign(int $timestamp, string $body): string
    {
        return Signature::sign(self::SECRET, Signature::payload('POST', self::ROUTE, $timestamp, self::NONCE, $body));
    }
}
