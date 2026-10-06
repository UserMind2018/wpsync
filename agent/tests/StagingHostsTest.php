<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\TestCase;
use WpSync\StagingHosts;

final class StagingHostsTest extends TestCase
{
    private const LIVE    = 'https://example.com';
    private const PATH    = '/wpsync-staging-0123456789ab';
    private const UPLOADS = 'https://example.com/wp-content/uploads';

    private function blocked(string $url, string $uploads = self::UPLOADS): ?string
    {
        return StagingHosts::blocked($url, self::LIVE, self::PATH, $uploads);
    }

    /** V5: dieselben Mail-Hosts und dieselbe Sink-Adresse wie der lokale Riegel */
    public function testMailHostsMatchTheLocalMailguard(): void
    {
        $guard = (string) file_get_contents(__DIR__ . '/../../cli/internal/mailguard/00-local-mailguard.php');
        $this->assertSame(1, preg_match('/\$blocked = \[(.*?)\];/s', $guard, $m));
        preg_match_all("/'([a-z0-9.-]+)'/", $m[1], $hosts);
        $this->assertNotEmpty($hosts[1]);
        $this->assertSame($hosts[1], StagingHosts::MAIL);
        $this->assertStringContainsString("'" . StagingHosts::SINK . "'", $guard);
    }

    /** AC-94, Anhang B */
    public function testBlocksMailPaymentAndNewsletterServices(): void
    {
        $this->assertSame('mail', $this->blocked('https://api.sendgrid.com/v3/mail/send'));
        $this->assertSame('mail', $this->blocked('https://email.eu-central-1.amazonaws.com/'));
        $this->assertSame('mail', $this->blocked('https://us21.api.mailchimp.com/3.0/'));
        $this->assertSame('payment', $this->blocked('https://api.stripe.com/v1/charges'));
        $this->assertSame('payment', $this->blocked('https://API-M.PAYPAL.COM/v2/checkout/orders'));
        $this->assertSame('payment', $this->blocked('https://secure.pay1.payone.com/'));
        $this->assertSame('newsletter', $this->blocked('https://acme.api-us1.com/api/3/contacts'));
        $this->assertSame('newsletter', $this->blocked('https://api.hubapi.com/crm/v3'));
    }

    /** Leitplanke 7: die Kopie spricht nie mit Live, ausser für Uploads */
    public function testBlocksLiveButNotUploadsOrItself(): void
    {
        $this->assertSame('live', $this->blocked('https://example.com/wp-json/wc/v3/orders'));
        $this->assertSame('live', $this->blocked('http://example.com/'));
        $this->assertNull($this->blocked('https://example.com/wp-content/uploads/2024/01/a.jpg'));
        $this->assertNull($this->blocked('https://example.com/wpsync-staging-0123456789ab/wp-admin/admin-ajax.php'));
        $this->assertNull($this->blocked('https://cdn.example.net/uploads/a.jpg', 'https://cdn.example.net/uploads'));
        $this->assertSame('live', $this->blocked('https://example.com/wpsync-staging-0123456789abX/'));
    }

    public function testLeavesOtherHostsAlone(): void
    {
        foreach (['https://api.wordpress.org/plugins/info/1.2/', 'https://fonts.googleapis.com/css', 'https://stripe.com.example.org/', 'relative/path', ''] as $url) {
            $this->assertNull($this->blocked($url), $url);
        }
    }
}
