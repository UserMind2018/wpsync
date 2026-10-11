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

    /** Ein Secret im Klartext, wie newSecret() es liefert (Altbestand vor Agent 0.4.1). */
    public static function isSecret(string $value): bool
    {
        return preg_match('/^[a-f0-9]{64}\z/', $value) === 1;
    }

    public static function hashCode(string $code): string
    {
        return hash('sha256', strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code)));
    }

    /**
     * @param string $device Gerätename, den der Administrator beim Erzeugen vergeben hat (Spec 0.9.1 G1); '' ohne.
     *                       Er liegt beim Code und verfällt mit ihm
     * @return array{hash: string, expires: int, attempts: int, device?: string}
     */
    public static function stored(string $code, int $now, string $device = ''): array
    {
        $stored = ['hash' => self::hashCode($code), 'expires' => $now + self::CODE_TTL, 'attempts' => 0];
        return $device === '' ? $stored : $stored + ['device' => self::device($device)];
    }

    /**
     * Der Name, der bei einem Code liegt; '' ohne.
     *
     * @param array<string, mixed>|null $stored
     */
    public static function named(?array $stored): string
    {
        return is_array($stored) && is_string($stored['device'] ?? null) ? $stored['device'] : '';
    }

    /**
     * Unter welchem Namen eine Kopplung gespeichert wird (G2): --device der CLI > Name aus dem WP-Admin >
     * Rechnername. Eine CLI vor 0.9.1 sendet device_given nicht – ihr device ist dann meist der Rechnername,
     * und der Name aus dem Admin gewinnt.
     *
     * @param string                    $cli    device der Anfrage, schon bereinigt
     * @param bool                      $given  die Anfrage sagt: der Name wurde mit --device gesetzt
     * @param array<string, mixed>|null $stored der Zustand des eingelösten Codes
     */
    public static function choose(string $cli, bool $given, ?array $stored): string
    {
        $admin = self::named($stored);
        return self::device(($given && $cli !== '') || $admin === '' ? $cli : $admin);
    }

    /**
     * @param array{hash: string, expires: int, attempts: int, device?: string}|null $stored
     * @return array{0: bool, 1: array{hash: string, expires: int, attempts: int, device?: string}|null} [gültig, neuer gespeicherter Zustand]
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
