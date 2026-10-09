<?php
namespace WpSync;

defined('ABSPATH') || defined('WPSYNC_RESCUE') || exit;

/**
 * Der versiegelte Umschlag eines Pushs mit Inhalten (Spec Content-Push P3 §5.1): was rescue.php
 * für die Rücknahme der Datenbank braucht, verschlüsselt und authentisiert mit einem Schlüssel,
 * der aus den 32 Byte des Rescue-Keys des Pushs abgeleitet ist (secret()). Auf dem Server liegt
 * nur sha256() dieses Schlüssels – daraus lässt sich der Umschlag nicht öffnen. Zwei Verfahren, die beide ohne
 * WordPress (und damit ohne sodium_compat) auskommen:
 *   wpsync-rescue:v1:sodium   XSalsa20-Poly1305 mit der Erweiterung sodium
 *   wpsync-rescue:v1:gcm      AES-256-GCM mit openssl, die Push-ID als zusätzliche Daten
 * seal() und open() werfen nie; open() liefert null bei falschem Schlüssel, Veränderung, fremdem
 * Format, fremder Push-ID – und für jeden Umschlag, der älter ist als MAX_AGE.
 */
final class RescueSeal
{
    public const NAME   = 'rescue.sealed';
    public const SODIUM = "wpsync-rescue:v1:sodium\n";
    public const GCM    = "wpsync-rescue:v1:gcm\n";

    /** Der Rescue-Key, wie die CLI ihn schickt: HMAC-SHA256 als 64 kleine Hex-Zeichen. */
    private const KEY          = '/^[a-f0-9]{64}\z/';
    private const LABEL        = "wpsync-rescue-envelope-v1\0";
    private const CIPHER       = 'aes-256-gcm';
    private const SODIUM_NONCE = 24;
    private const SODIUM_MAC   = 16;
    private const GCM_NONCE    = 12;
    private const GCM_TAG      = 16;
    /**
     * Harte Altersgrenze (Security-Review P3, N3): älter als sieben Tage öffnet ein Umschlag nie,
     * für niemanden – gemessen an created, das authentisiert in ihm steht, nicht an der Uhr der
     * Datei. Unabhängig vom Verfall nach 24 Stunden (Push::RESCUE_DB_TTL, R14): den räumt der Cron
     * von WordPress, und der läuft gerade dann nicht, wenn die Site unten ist.
     */
    public const MAX_AGE = 604800;
    /**
     * So weit darf created vor der Uhr des Servers liegen (eine Uhr, die zwischen Begin und Rücknahme
     * nachgestellt wurde). Weiter in der Zukunft ist ein Umschlag keiner: ein negatives Alter gälte
     * sonst für immer als jung, und MAX_AGE griffe nie.
     */
    public const MAX_SKEW = 300;

    /** Mehr trägt kein Umschlag; eine grössere Datei ist keiner. */
    private const MAX_BYTES    = 65536;

    /** Das Verfahren, das diese PHP-Installation ohne WordPress beherrscht: sodium, gcm oder null. */
    public static function method(): ?string
    {
        if (extension_loaded('sodium') && function_exists('sodium_crypto_secretbox')) {
            return 'sodium';
        }
        if (function_exists('openssl_encrypt') && function_exists('openssl_get_cipher_methods') && in_array(self::CIPHER, openssl_get_cipher_methods(), true)) {
            return 'gcm';
        }
        return null;
    }

    public static function file(string $workDir, string $pushId): string
    {
        return $workDir . '/' . $pushId . '/' . self::NAME;
    }

    /**
     * @param array<string, mixed> $data
     * @param string               $key    der Rescue-Key, wie die CLI ihn schickt: 64 kleine Hex-Zeichen
     * @param string|null          $method für Tests: das Verfahren anstelle von method()
     * @return string|null der Inhalt der Datei; null, wenn sich nicht versiegeln lässt
     */
    public static function seal(array $data, string $key, string $pushId, ?string $method = null): ?string
    {
        try {
            $method = $method ?? self::method();
            $secret = self::secret($key, $pushId);
            $json   = json_encode(['push_id' => $pushId] + $data, JSON_UNESCAPED_SLASHES);
            // Ohne lesbares Alter liesse sich der Umschlag nie öffnen (MAX_AGE): dann gar keiner.
            if ($secret === null || !is_string($json) || !is_int($data['created'] ?? null) || $data['created'] < 0) {
                return null;
            }
            if ($method === 'sodium' && function_exists('sodium_crypto_secretbox')) {
                $nonce = random_bytes(self::SODIUM_NONCE);
                return self::SODIUM . $nonce . sodium_crypto_secretbox($json, $nonce, $secret);
            }
            if ($method === 'gcm' && function_exists('openssl_encrypt')) {
                $nonce  = random_bytes(self::GCM_NONCE);
                $tag    = '';
                $cipher = openssl_encrypt($json, self::CIPHER, $secret, OPENSSL_RAW_DATA, $nonce, $tag, $pushId, self::GCM_TAG);
                return is_string($cipher) && is_string($tag) && strlen($tag) === self::GCM_TAG ? self::GCM . $nonce . $tag . $cipher : null;
            }
        } catch (\Throwable $e) {
            // kein Zufall, kein Verfahren: kein Umschlag
        }
        return null;
    }

    /**
     * @param int|null $now für Tests: die Zeit anstelle von time()
     * @return array<string, mixed>|null null auch, wenn der Umschlag älter ist als MAX_AGE, mehr als MAX_SKEW in
     *         der Zukunft liegt oder sein Alter nicht nennt
     */
    public static function open(string $raw, string $key, string $pushId, ?int $now = null): ?array
    {
        try {
            $secret = self::secret($key, $pushId);
            if ($secret === null || strlen($raw) > self::MAX_BYTES) {
                return null;
            }
            $json = null;
            if (strncmp($raw, self::SODIUM, strlen(self::SODIUM)) === 0) {
                $body = (string) substr($raw, strlen(self::SODIUM));
                if (strlen($body) < self::SODIUM_NONCE + self::SODIUM_MAC || !function_exists('sodium_crypto_secretbox_open')) {
                    return null;
                }
                $json = sodium_crypto_secretbox_open((string) substr($body, self::SODIUM_NONCE), (string) substr($body, 0, self::SODIUM_NONCE), $secret);
            } elseif (strncmp($raw, self::GCM, strlen(self::GCM)) === 0) {
                $body = (string) substr($raw, strlen(self::GCM));
                if (strlen($body) <= self::GCM_NONCE + self::GCM_TAG || !function_exists('openssl_decrypt')) {
                    return null;
                }
                $json = openssl_decrypt(
                    (string) substr($body, self::GCM_NONCE + self::GCM_TAG),
                    self::CIPHER,
                    $secret,
                    OPENSSL_RAW_DATA,
                    (string) substr($body, 0, self::GCM_NONCE),
                    (string) substr($body, self::GCM_NONCE, self::GCM_TAG),
                    $pushId
                );
            }
            $data = is_string($json) ? json_decode($json, true) : null;
            if (!is_array($data) || ($data['push_id'] ?? null) !== $pushId) {
                return null;
            }
            $created = $data['created'] ?? null;
            if (!is_int($created) || $created < 0) {
                return null;
            }
            $age = ($now ?? time()) - $created;
            return $age <= self::MAX_AGE && $age >= -self::MAX_SKEW ? $data : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Schreibt den Umschlag vollständig oder gar nicht – erst die Rechte, dann der Inhalt. */
    public static function put(string $workDir, string $pushId, string $sealed): bool
    {
        if (preg_match(PushRescue::ID, $pushId) !== 1) {
            return false;
        }
        $file = self::file($workDir, $pushId);
        $tmp  = $file . '.tmp';
        $ok   = !is_link($tmp) && !is_link($file) && @touch($tmp) && @chmod($tmp, 0600)
            && @file_put_contents($tmp, $sealed) === strlen($sealed) && @rename($tmp, $file);
        if (!$ok && !is_link($tmp)) {
            @unlink($tmp);
        }
        return $ok;
    }

    /** Der Inhalt der Datei; null, wenn es sie nicht gibt, sie ein Symlink ist oder zu gross. */
    public static function read(string $workDir, string $pushId): ?string
    {
        if (preg_match(PushRescue::ID, $pushId) !== 1) {
            return null;
        }
        $file = self::file($workDir, $pushId);
        clearstatcache(true, $file);
        if (is_link($file) || !is_file($file) || (int) @filesize($file) > self::MAX_BYTES) {
            return null;
        }
        $raw = @file_get_contents($file);
        return is_string($raw) ? $raw : null;
    }

    /** Löscht den Umschlag (R14) – auch einen Symlink an seiner Stelle, nie dessen Ziel. */
    public static function forget(string $workDir, string $pushId): void
    {
        if (preg_match(PushRescue::ID, $pushId) !== 1) {
            return;
        }
        $file = self::file($workDir, $pushId);
        if (is_link($file) || is_file($file)) {
            @unlink($file);
        }
        if (is_file($file . '.tmp') && !is_link($file . '.tmp')) {
            @unlink($file . '.tmp');
        }
    }

    /**
     * E = HMAC-SHA256(Schlüssel = die 32 Byte von K, Nachricht = Label ‖ Push-ID), roh 32 Byte. K ist
     * der Rescue-Key in genau der Form, in der PushRescue::key() und die CLI ihn bilden: 64 kleine
     * Hex-Zeichen. Alles andere ist kein Schlüssel – null, wie ohne gültige Push-ID.
     */
    private static function secret(string $key, string $pushId): ?string
    {
        if (preg_match(self::KEY, $key) !== 1 || preg_match(PushRescue::ID, $pushId) !== 1) {
            return null;
        }
        $bytes = hex2bin($key);
        return is_string($bytes) && strlen($bytes) === 32 ? hash_hmac('sha256', self::LABEL . $pushId, $bytes, true) : null;
    }
}
