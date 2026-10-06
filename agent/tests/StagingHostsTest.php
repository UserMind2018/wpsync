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

    /** Ein Suffix ist erst hinter einem Punkt eine Subdomain; Userinfo und Port ändern den Host nicht. */
    public function testComparesTheHostNotTheString(): void
    {
        $this->assertSame('payment', $this->blocked('https://user:pw@api.stripe.com:443/v1'));
        $this->assertSame('payment', $this->blocked('//api.stripe.com/v1'));
        foreach (['https://evilstripe.com/', 'https://evilapi.stripe.com/', 'https://notpayone.com/', 'https://api.stripe.com@evil.test/', 'https://evil.test/@api.stripe.com/'] as $url) {
            $this->assertNull($this->blocked($url), $url);
        }
    }

    public function testIgnoresATrailingDotOnTheHost(): void
    {
        $this->assertSame('payment', $this->blocked('https://api.stripe.com./v1/charges'));
        $this->assertSame('mail', $this->blocked('https://api.sendgrid.com../v3/mail/send'));
        $this->assertSame('mail', $this->blocked('https://email.eu-west-1.amazonaws.com./'));
        $this->assertSame('live', $this->blocked('https://example.com./wp-json/'));
        $this->assertSame('live', StagingHosts::blocked('https://example.com/wp-json/', 'https://example.com.', self::PATH, self::UPLOADS));
        $this->assertNull($this->blocked('https://example.com./wp-content/uploads/a.jpg'));
    }

    public function testTreatsTheDefaultPortAsNoPort(): void
    {
        $this->assertSame('live', $this->blocked('https://example.com:443/wp-json/'));
        $this->assertSame('live', $this->blocked('http://example.com:80/wp-json/'));
        $this->assertSame('live', StagingHosts::blocked('https://example.com/wp-json/', 'https://example.com:443', self::PATH, self::UPLOADS));
        $this->assertNull($this->blocked('https://example.com:443/wp-content/uploads/a.jpg'));
        $this->assertNull($this->blocked('https://example.com/wp-content/uploads/a.jpg', 'https://example.com:443/wp-content/uploads'));
    }

    /** Die Ausnahmen für Uploads und die Kopie gelten nur für Pfade, die dort auch bleiben. */
    public function testPathExceptionsCannotBeLeftAgain(): void
    {
        foreach ([
            'https://example.com/wp-content/uploads/../../wp-json/wc/v3/orders',
            'https://example.com/wp-content/uploads/..',
            'https://example.com/wp-content/uploads/%2e%2e/%2E%2e/wp-json/',
            'https://example.com/wp-content/uploads/%252e%252e/wp-json/',
            'https://example.com/wp-content/uploads/..%2fwp-json/',
            'https://example.com/wp-content/uploads/..%5c..%5cwp-json/',
            'https://example.com/wp-content/uploads/a%00.jpg',
            'https://example.com/wpsync-staging-0123456789ab/../wp-json/',
            'https://example.com/wpsync-staging-0123456789ab/%2e%2e/wp-json/',
        ] as $url) {
            $this->assertSame('live', $this->blocked($url), $url);
        }
        $this->assertNull($this->blocked('https://example.com/wp-content/uploads/2024/a%20b..c.jpg'));
        $this->assertNull($this->blocked('https://example.com/wp-content/%75ploads/a.jpg'));
    }

    /** Was sich nicht eindeutig lesen lässt, geht nicht raus. */
    public function testBlocksUrlsItCannotRead(): void
    {
        $this->assertSame('payment', $this->blocked("  https://api.stripe.com/v1\n"));
        $this->assertSame('live', $this->blocked(' https://example.com/wp-json/'));
        foreach ([
            "https://api.stripe.com\t/v1",
            'https://api.stripe.com /v1',
            "https://example.com/wp-content/uploads/a\x00.jpg",
            'https://api.stripe.com\\@evil.test/',
            'https://evil.test\\@api.stripe.com/',
            'https://example.com/wp-content/uploads\\..\\wp-json',
            'https:///wp-json/',
            'https:api.stripe.com',
            'HTTP://',
            '//',
        ] as $url) {
            $this->assertSame('invalid', $this->blocked($url), json_encode($url));
        }
    }

    public function testLiveWithAndWithoutWwwIsTheSameHost(): void
    {
        $this->assertSame('live', $this->blocked('https://www.example.com/wp-json/'));
        $this->assertSame('live', $this->blocked('https://WWW.example.com./wp-json/'));
        $this->assertSame('live', StagingHosts::blocked('https://example.com/wp-json/', 'https://www.example.com', self::PATH, 'https://www.example.com/wp-content/uploads'));
        $this->assertNull($this->blocked('https://www.example.com/wp-content/uploads/a.jpg'));
        $this->assertNull($this->blocked('https://www.example.com/wpsync-staging-0123456789ab/'));
        $this->assertNull($this->blocked('https://wwwexample.com/wp-json/'));
        $this->assertNull($this->blocked('https://shop.example.com/wp-json/'));
    }

    public function testBlocksAmazonSesInEveryPartition(): void
    {
        foreach ([
            'https://email-smtp.eu-west-1.amazonaws.com/',
            'https://email-fips.us-east-1.amazonaws.com/',
            'https://email-smtp-fips.us-gov-west-1.amazonaws.com/',
            'https://email.cn-north-1.amazonaws.com.cn/',
            'https://email.eu-central-1.api.aws/',
            'https://email-fips.us-east-2.api.aws/',
        ] as $url) {
            $this->assertSame('mail', $this->blocked($url), $url);
        }
        foreach (['https://s3.eu-central-1.amazonaws.com/bucket/a.jpg', 'https://email.eu-central-1.amazonaws.com.example.org/', 'https://email.example.aws/'] as $url) {
            $this->assertNull($this->blocked($url), $url);
        }
    }

    /** Ohne lesbare Live-URL ist nicht zu sagen, was Live ist: dann geht nichts raus. */
    public function testWithoutAReadableLiveUrlEverythingIsBlocked(): void
    {
        foreach (['', 'example.com', 'https://', '   '] as $live) {
            $this->assertSame('payment', StagingHosts::blocked('https://api.stripe.com/v1', $live, self::PATH, self::UPLOADS), $live);
            $this->assertSame('live', StagingHosts::blocked('https://example.com/wp-json/', $live, self::PATH, self::UPLOADS), $live);
            $this->assertSame('live', StagingHosts::blocked('https://example.com/wp-content/uploads/a.jpg', $live, self::PATH, self::UPLOADS), $live);
            $this->assertSame('live', StagingHosts::blocked('https://api.wordpress.org/', $live, self::PATH, self::UPLOADS), $live);
            $this->assertNull(StagingHosts::blocked('relative/path', $live, self::PATH, self::UPLOADS), $live);
        }
    }
}
