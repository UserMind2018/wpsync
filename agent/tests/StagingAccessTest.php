<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\TestCase;
use WpSync\StagingAccess;

final class StagingAccessTest extends TestCase
{
    private const NOW = 1800000000;
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/wpsync-access-' . bin2hex(random_bytes(4)) . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    private function access(): StagingAccess
    {
        $access = new StagingAccess($this->file);
        $access->init('https://example.com', 'https://example.com/wp-content/uploads', '/wpsync-staging-0123456789ab', self::NOW, self::NOW);
        return $access;
    }

    /** AC-92 */
    public function testTokenWorksExactlyOnce(): void
    {
        $access = $this->access();
        $token  = $access->issueToken(self::NOW);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}\z/', $token);
        $cookie = $access->redeemToken($token, self::NOW + 10);
        $this->assertNotNull($cookie);
        $this->assertNull($access->redeemToken($token, self::NOW + 11));
        $this->assertTrue($access->cookieValid((string) $cookie, self::NOW + 12));
    }

    /** N1: die Prüfung vor WordPress liest nur – ein offener Link bleibt für den Riegel gültig. */
    public function testAdmitsOnlyACookieOrAnOpenLinkWithoutUsingIt(): void
    {
        $access = $this->access();
        $token  = $access->issueToken(self::NOW);
        $this->assertTrue($access->admits($token, '', self::NOW + 10));
        $this->assertTrue($access->admits($token, '', self::NOW + 10), 'not used up');
        $this->assertFalse($access->admits($token, '', self::NOW + StagingAccess::TOKEN_TTL + 1), 'expired');
        $this->assertFalse($access->admits(str_repeat('a', 64), '', self::NOW));
        $this->assertFalse($access->admits('kein-token', '', self::NOW));
        $this->assertFalse($access->admits(null, '', self::NOW));
        $cookie = (string) $access->redeemToken($token, self::NOW + 10);
        $this->assertFalse($access->admits($token, '', self::NOW + 11), 'redeemed');
        $this->assertTrue($access->admits(null, $cookie, self::NOW + 11));
        $access->lock();
        $this->assertFalse($access->admits(null, $cookie, self::NOW + 12), 'locked');
    }

    /** T1: ein Link zählt nur am Einstieg der Kopie. */
    public function testLoginTokenOnlyAtTheEntryPoint(): void
    {
        $base  = '/wpsync-staging-0123456789ab';
        $query = ['wpsync_login' => 'x'];
        foreach ([$base, $base . '/', $base . '/index.php', $base . '/?a=b'] as $uri) {
            $this->assertSame('x', StagingAccess::loginToken($query, $uri, $base), $uri);
        }
        foreach ([$base . '/wp-login.php', $base . '//', '/', ''] as $uri) {
            $this->assertNull(StagingAccess::loginToken($query, $uri, $base), $uri);
        }
        $this->assertNull(StagingAccess::loginToken(['wpsync_login' => ['x']], $base . '/', $base));
        $this->assertNull(StagingAccess::loginToken([], $base . '/', $base));
        $this->assertNull(StagingAccess::loginToken($query, '/', ''));
    }

    /** AC-92 */
    public function testTokenExpiresAfterFiveMinutes(): void
    {
        $access = $this->access();
        $token  = $access->issueToken(self::NOW);
        $this->assertNull($access->redeemToken($token, self::NOW + StagingAccess::TOKEN_TTL + 1));
        $this->assertNull($access->redeemToken($token, self::NOW), 'an expired token is used up as well');
    }

    public function testCookieLastsTwelveHours(): void
    {
        $access = $this->access();
        $cookie = (string) $access->redeemToken($access->issueToken(self::NOW), self::NOW);
        $this->assertTrue($access->cookieValid($cookie, self::NOW + StagingAccess::COOKIE_TTL));
        $this->assertFalse($access->cookieValid($cookie, self::NOW + StagingAccess::COOKIE_TTL + 1));
    }

    /** AC-91 */
    public function testForgedCookiesAreRejected(): void
    {
        $access = $this->access();
        $access->redeemToken($access->issueToken(self::NOW), self::NOW);
        foreach (['', 'probe', str_repeat('a', 64), str_repeat('A', 64)] as $forged) {
            $this->assertFalse($access->cookieValid($forged, self::NOW));
        }
    }

    public function testOnlyHashesAreStored(): void
    {
        $access = $this->access();
        $token  = $access->issueToken(self::NOW);
        $cookie = (string) $access->redeemToken($access->issueToken(self::NOW), self::NOW);
        $raw    = (string) file_get_contents($this->file);
        $this->assertStringNotContainsString($token, $raw);
        $this->assertStringNotContainsString($cookie, $raw);
        $this->assertStringContainsString(hash('sha256', $token), $raw);
    }

    /** AC-102 */
    public function testLockInvalidatesEverythingAndANewLinkUnlocks(): void
    {
        $access = $this->access();
        $token  = $access->issueToken(self::NOW);
        $cookie = (string) $access->redeemToken($access->issueToken(self::NOW), self::NOW);
        $access->lock();
        $this->assertTrue($access->read()['locked']);
        $this->assertFalse($access->cookieValid($cookie, self::NOW));
        $this->assertNull($access->redeemToken($token, self::NOW));
        $fresh = $access->issueToken(self::NOW + 100);
        $this->assertFalse($access->read()['locked']);
        $this->assertSame(self::NOW + 100, $access->read()['last_used']);
        $this->assertNotNull($access->redeemToken($fresh, self::NOW + 101));
    }

    /** AC-102 */
    public function testExpiresAfterFourteenDaysWithoutUse(): void
    {
        $this->assertFalse(StagingAccess::expired(self::NOW, self::NOW + StagingAccess::EXPIRE_AFTER));
        $this->assertTrue(StagingAccess::expired(self::NOW, self::NOW + StagingAccess::EXPIRE_AFTER + 1));
    }

    public function testTouchWritesAtMostHourly(): void
    {
        $access = $this->access();
        $access->touch(self::NOW + 60);
        $this->assertSame(self::NOW, $access->read()['last_used']);
        $access->touch(self::NOW + StagingAccess::TOUCH_EVERY);
        $this->assertSame(self::NOW + StagingAccess::TOUCH_EVERY, $access->read()['last_used']);
    }

    public function testMissingOrBrokenFileIsLocked(): void
    {
        $this->assertTrue((new StagingAccess($this->file))->read()['locked']);
        file_put_contents($this->file, '{kaputt');
        $state = (new StagingAccess($this->file))->read();
        $this->assertTrue($state['locked']);
        $this->assertSame([], $state['cookies']);
    }

    public function testInitKeepsTheLiveData(): void
    {
        $state = $this->access()->read();
        $this->assertSame('https://example.com', $state['live_url']);
        $this->assertSame('/wpsync-staging-0123456789ab', $state['staging_path']);
        $this->assertSame(self::NOW, $state['copied_at']);
        $this->assertFalse($state['locked']);
    }
}
