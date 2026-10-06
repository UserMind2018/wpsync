<?php
namespace WpSync;

defined('ABSPATH') || exit;

/**
 * Wohin die Staging-Kopie keine Anfrage schicken darf (Spec Stufe 2b 5.5 Nr. 3, Anhang B,
 * Leitplanke 7). Wird unverändert in die Kopie kopiert (mu-plugins/wpsync-staging/) und darf
 * nichts anderes aus dem Agent nutzen. Die Liste ist eine Untergrenze – Ergänzungen mit Test.
 */
final class StagingHosts
{
    public const SINK = 'blocked@mailguard.invalid';

    /** Wie cli/internal/mailguard/00-local-mailguard.php – StagingHostsTest prüft die Gleichheit. */
    public const MAIL = [
        'api.sendgrid.com',
        'api.brevo.com',
        'api.sendinblue.com',
        'api.mailgun.net',
        'api.eu.mailgun.net',
        'api.postmarkapp.com',
        'api.sparkpost.com',
        'api.mailjet.com',
        'api.resend.com',
        'api.smtp2go.com',
        'mandrillapp.com',
        'api.mailchimp.com',
        'email.us-east-1.amazonaws.com',
        'api.elasticemail.com',
        'api.mailersend.com',
    ];

    /** Jeweils samt Subdomains. */
    public const PAYMENT = ['api.stripe.com', 'api-m.paypal.com', 'api.paypal.com', 'api.mollie.com', 'api.klarna.com', 'checkout-live.adyen.com', 'api.sumup.com', 'payone.com'];
    public const NEWSLETTER = ['api.cleverreach.com', 'api.klaviyo.com', 'api-us1.com', 'api.hubapi.com', 'rest.clicksend.com'];

    /**
     * Grund der Sperre (mail, payment, newsletter, live) oder null. Live selbst ist gesperrt bis
     * auf die Uploads; Anfragen an die Kopie selbst sind erlaubt.
     */
    public static function blocked(string $url, string $liveUrl, string $stagingPath, string $uploadsUrl): ?string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return null;
        }
        foreach (['mail' => self::MAIL, 'payment' => self::PAYMENT, 'newsletter' => self::NEWSLETTER] as $why => $hosts) {
            foreach ($hosts as $needle) {
                if ($host === $needle || substr($host, -strlen($needle) - 1) === '.' . $needle) {
                    return $why;
                }
            }
        }
        if (preg_match('/^email(-smtp)?\.[a-z0-9-]+\.amazonaws\.com\z/', $host) === 1) {
            return 'mail'; // Amazon SES in jeder Region
        }
        if (self::origin($url) !== self::origin($liveUrl)) {
            return null;
        }
        $path    = (string) parse_url($url, PHP_URL_PATH);
        $allowed = [rtrim($stagingPath, '/')];
        if (self::origin($uploadsUrl) === self::origin($liveUrl)) {
            $allowed[] = rtrim((string) parse_url($uploadsUrl, PHP_URL_PATH), '/');
        }
        foreach ($allowed as $prefix) {
            if ($prefix !== '' && ($path === $prefix || strpos($path, $prefix . '/') === 0)) {
                return null;
            }
        }
        return 'live';
    }

    /** Host mit Port, ohne Schema: http und https von Live sind dasselbe Ziel. */
    private static function origin(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['host'] ?? '') === '') {
            return '';
        }
        return strtolower((string) $parts['host']) . ':' . ($parts['port'] ?? '');
    }
}
