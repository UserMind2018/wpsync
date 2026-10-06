<?php
namespace WpSync;

defined('ABSPATH') || exit;

/**
 * Versiegelt kurze Geheimnisse für die Datenbank (SEC-006): XSalsa20-Poly1305 über
 * sodium_crypto_secretbox – in WordPress immer vorhanden, notfalls über sodium_compat.
 *
 * Format: „v1:“ + base64(nonce | ciphertext). open() liefert bei falschem Schlüssel,
 * Manipulation oder fremdem Format null und wirft nie.
 */
final class SecretBox
{
    public const PREFIX = 'v1:';

    private const KEY_BYTES   = 32;
    private const NONCE_BYTES = 24;
    private const MAC_BYTES   = 16;

    public static function available(): bool
    {
        return function_exists('sodium_crypto_secretbox') && function_exists('sodium_crypto_secretbox_open');
    }

    public static function isSealed(string $value): bool
    {
        return strncmp($value, self::PREFIX, strlen(self::PREFIX)) === 0;
    }

    /** @throws \InvalidArgumentException bei einem Schlüssel, der nicht 32 Byte lang ist */
    public static function seal(string $plain, string $key): string
    {
        if (strlen($key) !== self::KEY_BYTES) {
            throw new \InvalidArgumentException('secretbox key must be 32 bytes');
        }
        $nonce = random_bytes(self::NONCE_BYTES);
        return self::PREFIX . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $key));
    }

    public static function open(string $sealed, string $key): ?string
    {
        if (!self::isSealed($sealed) || strlen($key) !== self::KEY_BYTES) {
            return null;
        }
        $raw = base64_decode(substr($sealed, strlen(self::PREFIX)), true);
        if (!is_string($raw) || strlen($raw) < self::NONCE_BYTES + self::MAC_BYTES) {
            return null;
        }
        try {
            $plain = sodium_crypto_secretbox_open(substr($raw, self::NONCE_BYTES), substr($raw, 0, self::NONCE_BYTES), $key);
        } catch (\Throwable $e) {
            return null;
        }
        return is_string($plain) ? $plain : null;
    }
}
