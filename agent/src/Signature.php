<?php
namespace WpSync;

defined('ABSPATH') || exit;

/**
 * HMAC-SHA256 über "METHOD\nROUTE\nTIMESTAMP\nNONCE\nsha256(BODY)".
 * Alle Parameter stehen im Body und sind damit mitsigniert.
 */
final class Signature
{
    public const MAX_SKEW = 300;

    public static function payload(string $method, string $route, int $timestamp, string $nonce, string $body): string
    {
        return implode("\n", [$method, $route, (string) $timestamp, $nonce, hash('sha256', $body)]);
    }

    public static function sign(string $secret, string $payload): string
    {
        return hash_hmac('sha256', $payload, $secret);
    }

    /**
     * @return string|null Fehlercode ('timestamp', 'nonce', 'signature') oder null, wenn gültig
     */
    public static function check(
        string $secret,
        string $method,
        string $route,
        int $timestamp,
        string $nonce,
        string $body,
        string $signature,
        int $now
    ): ?string {
        if (abs($now - $timestamp) > self::MAX_SKEW) {
            return 'timestamp';
        }
        if (!preg_match('/^[a-f0-9]{32}\z/', $nonce)) {
            return 'nonce';
        }
        $expected = self::sign($secret, self::payload($method, $route, $timestamp, $nonce, $body));
        return hash_equals($expected, $signature) ? null : 'signature';
    }
}
