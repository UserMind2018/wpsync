<?php
namespace WpSync;

defined('ABSPATH') || exit;

/**
 * Schlüssel für SecretBox (SEC-006). Er darf nie aus der Datenbank stammen – sonst liest ihn,
 * wer die Datenbank liest, gleich mit. Deshalb nur Konstanten aus wp-config.php und kein
 * wp_salt(): das fällt bei fehlenden Konstanten auf Salts in wp_options zurück.
 *
 * Reihenfolge: WPSYNC_KEY (falls gesetzt), dann AUTH_KEY + SECURE_AUTH_KEY. Der zweite bleibt
 * als Rückfall gültig, damit das spätere Setzen von WPSYNC_KEY keine Kopplung bricht.
 */
final class SecretKey
{
    public const MIN_LENGTH = 32;

    private const DEFAULT_PHRASE = 'put your unique phrase here';
    private const LABEL          = 'wpsync-secretbox-v1';

    public static function usableSalt(?string $value): bool
    {
        return $value !== null && strlen($value) >= self::MIN_LENGTH && $value !== self::DEFAULT_PHRASE;
    }

    /**
     * Mögliche Schlüssel, der zum Versiegeln zuerst.
     *
     * @return list<string>
     */
    public static function candidates(?string $own, ?string $authKey, ?string $secureAuthKey): array
    {
        $keys = [];
        if ($own !== null && strlen($own) >= self::MIN_LENGTH) {
            $keys[] = hash_hmac('sha256', self::LABEL, $own, true);
        }
        if (self::usableSalt($authKey) && self::usableSalt($secureAuthKey)) {
            $keys[] = hash_hmac('sha256', self::LABEL, (string) $authKey . (string) $secureAuthKey, true);
        }
        return array_values(array_unique($keys));
    }

    /** @return list<string> aus den Konstanten der Installation; leer ohne sodium */
    public static function fromConfig(): array
    {
        if (!SecretBox::available()) {
            return [];
        }
        return self::candidates(self::constant('WPSYNC_KEY'), self::constant('AUTH_KEY'), self::constant('SECURE_AUTH_KEY'));
    }

    /** Schlüssel zum Versiegeln; null heisst: Secrets bleiben im Klartext. */
    public static function current(): ?string
    {
        return self::fromConfig()[0] ?? null;
    }

    /** WPSYNC_KEY ist gesetzt, aber zu kurz und wird deshalb ignoriert. */
    public static function ownKeyTooShort(): bool
    {
        $own = self::constant('WPSYNC_KEY');
        return $own !== null && strlen($own) < self::MIN_LENGTH;
    }

    public static function usesOwnKey(): bool
    {
        $own = self::constant('WPSYNC_KEY');
        return $own !== null && strlen($own) >= self::MIN_LENGTH && SecretBox::available();
    }

    private static function constant(string $name): ?string
    {
        if (!defined($name)) {
            return null;
        }
        $value = constant($name);
        return is_string($value) ? $value : null;
    }
}
