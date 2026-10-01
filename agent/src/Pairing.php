<?php
namespace WpSync;

defined('ABSPATH') || exit;

/**
 * Pairing-Code: 8 Zeichen, 10 min gültig, einmalig, nach 5 Fehlversuchen verbrannt (AC-3).
 */
final class Pairing
{
    public const CODE_TTL     = 600;
    public const MAX_ATTEMPTS = 5;

    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public static function newCode(): string
    {
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }
        return $code;
    }

    public static function newKeyId(): string
    {
        return bin2hex(random_bytes(8));
    }

    public static function newSecret(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function hashCode(string $code): string
    {
        return hash('sha256', strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code)));
    }

    /**
     * @return array{hash: string, expires: int, attempts: int}
     */
    public static function stored(string $code, int $now): array
    {
        return ['hash' => self::hashCode($code), 'expires' => $now + self::CODE_TTL, 'attempts' => 0];
    }

    /**
     * @param array{hash: string, expires: int, attempts: int}|null $stored
     * @return array{0: bool, 1: array{hash: string, expires: int, attempts: int}|null} [gültig, neuer gespeicherter Zustand]
     */
    public static function redeem(?array $stored, string $code, int $now): array
    {
        if ($stored === null || $now > $stored['expires']) {
            return [false, null];
        }
        if (hash_equals($stored['hash'], self::hashCode($code))) {
            return [true, null];
        }
        $stored['attempts']++;
        return [false, $stored['attempts'] >= self::MAX_ATTEMPTS ? null : $stored];
    }

    /** Gerätename für die Spalte VARCHAR(100): nach Zeichen kürzen, nie mitten im Mehrbyte-Zeichen (CR-01). */
    public static function device(string $name): string
    {
        $name = mb_substr($name, 0, 100, 'UTF-8');
        return $name !== '' ? $name : 'unbekannt';
    }
}
