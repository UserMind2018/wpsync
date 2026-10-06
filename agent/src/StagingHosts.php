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
     * Grund der Sperre (mail, payment, newsletter, live, invalid) oder null. Live selbst ist
     * gesperrt bis auf die Uploads; Anfragen an die Kopie selbst sind erlaubt. Der Riegel fragt
     * nur, ob null zurückkommt – im Zweifel also einen Grund liefern.
     */
    public static function blocked(string $url, string $liveUrl, string $stagingPath, string $uploadsUrl): ?string
    {
        $url = trim($url);
        // Steuerzeichen, Leerraum, Backslash: parse_url und der HTTP-Client lesen das verschieden.
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1) {
            return 'invalid';
        }
        $host = self::host($url);
        if ($host === '') {
            // Ohne http(s) und ohne // schickt WordPress nichts ab (relative Pfade, leer).
            return preg_match('#^(https?:|//)#i', $url) === 1 ? 'invalid' : null;
        }
        foreach (['mail' => self::MAIL, 'payment' => self::PAYMENT, 'newsletter' => self::NEWSLETTER] as $why => $hosts) {
            foreach ($hosts as $needle) {
                if ($host === $needle || substr($host, -strlen($needle) - 1) === '.' . $needle) {
                    return $why;
                }
            }
        }
        // Amazon SES in jeder Region: auch SMTP, FIPS, China und die Dual-Stack-Endpunkte.
        if (preg_match('/^email(-smtp)?(-fips)?\.[a-z0-9-]+\.(amazonaws\.com(\.cn)?|api\.aws)\z/', $host) === 1) {
            return 'mail';
        }
        $live = self::origin($liveUrl);
        if ($live === '') {
            return 'live'; // ohne lesbare Live-URL ist nicht zu sagen, was Live ist
        }
        if (self::origin($url) !== $live) {
            return null;
        }
        $path = rawurldecode((string) parse_url($url, PHP_URL_PATH));
        // Doppelt kodiert, NUL, Backslash oder ein ..-Segment: der Pfad bleibt nicht, wo er anfängt.
        if (preg_match('/%[0-9a-f]{2}|[\x00\\\\]|(^|\/)\.\.(\/|\z)/i', $path) === 1) {
            return 'live';
        }
        $allowed = [rtrim($stagingPath, '/')];
        if (self::origin($uploadsUrl) === $live) {
            $allowed[] = rtrim((string) parse_url(trim($uploadsUrl), PHP_URL_PATH), '/');
        }
        foreach ($allowed as $prefix) {
            if ($prefix !== '' && ($path === $prefix || strpos($path, $prefix . '/') === 0)) {
                return null;
            }
        }
        return 'live';
    }

    /** Host in Kleinbuchstaben, ohne abschliessenden Punkt; '' wenn keiner zu lesen ist. */
    private static function host(string $url): string
    {
        return rtrim(strtolower((string) parse_url($url, PHP_URL_HOST)), '.');
    }

    /**
     * Host mit Port, ohne Schema: http und https von Live sind dasselbe Ziel, ebenso mit und
     * ohne www. und mit oder ohne den Standardport des Schemas.
     */
    private static function origin(string $url): string
    {
        $url  = trim($url);
        $host = self::host($url);
        if ($host === '') {
            return '';
        }
        if (strpos($host, 'www.') === 0) {
            $host = substr($host, 4);
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $port   = (string) parse_url($url, PHP_URL_PORT);
        if (($scheme === 'http' && $port === '80') || ($scheme === 'https' && $port === '443')) {
            $port = '';
        }
        return $host . ':' . $port;
    }
}
