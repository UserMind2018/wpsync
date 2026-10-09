<?php
namespace WpSync;

defined('ABSPATH') || exit;

/**
 * Ablage der beiden Abbilder eines Inhalts-Pushs (before.json, after.json) im Arbeitsordner des
 * Pushs. before.json trägt Inhalte der Site, und die Rücknahme schreibt zurück, was darin steht:
 * wer im Arbeitsordner Dateien lesen oder schreiben kann, soll weder das eine noch das andere
 * ausnutzen können. Mit einem Schlüssel der Installation (SecretKey – WPSYNC_KEY oder die Salts
 * aus wp-config.php, nie aus der Datenbank) liegen sie deshalb
 *   verschlüsselt und authentisiert   XSalsa20-Poly1305 wie SecretBox, mit nativem sodium, oder
 *   mit einem HMAC-SHA256             lesbar, aber nicht veränderbar – ohne natives sodium
 *                                     (sodium_compat wäre für Megabytes zu langsam).
 * Der Schlüssel jeder Datei ist an den Ordner des Pushs und an ihren Namen gebunden: ein Abbild
 * gilt nur für seinen Push und nur als das, was es ist. Die Rücknahme über die Admin-Seite
 * braucht kein Gerät – der Schlüssel kommt aus der Installation, nicht aus der Kopplung.
 * Ohne Schlüssel (keine Salts, kein WPSYNC_KEY) bleibt es beim Klartext, wie bei den
 * Pairing-Secrets. Hat die Installation einen Schlüssel, gilt eine Klartext-Datei nicht.
 */
final class ContentImage
{
    /** Datei beginnt so: Nonce und Chiffrat folgen roh. */
    public const SEALED = "wpsync-image:v1\n";
    /** Datei beginnt so: 64 Hex-Zeichen HMAC, ein Zeilenvorschub, dann das JSON. */
    public const SIGNED = 'wpsync-image:h1:';

    private const LABEL       = 'wpsync-content-image-v1';
    private const NONCE_BYTES = 24;
    private const MAC_BYTES   = 16;

    /** @var list<string>|null für Tests: Schlüssel anstelle von SecretKey::fromConfig() */
    public static $keys = null;
    /** @var bool|null für Tests: verschlüsseln (true) oder nur signieren (false); null: je nach sodium */
    public static $encrypt = null;

    /**
     * Schreibt ein Abbild vollständig oder gar nicht – nur für den Besitzer lesbar.
     *
     * @param string               $dir  Ordner content im Arbeitsordner des Pushs
     * @param array<string, mixed> $data
     * @throws ContentException content_failed – ohne Pfad; was PHP dazu meldet, bleibt aus
     */
    public static function put(string $dir, string $name, array $data): void
    {
        $failed = new ContentException(ContentException::FAILED, 'Das Abbild der betroffenen Zeilen liess sich nicht schreiben – nichts wurde übernommen.');
        $json   = json_encode($data, JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw $failed;
        }
        try {
            $packed = self::pack($json, self::keys(), self::context($dir, $name), self::$encrypt ?? extension_loaded('sodium'));
        } catch (\Throwable $e) {
            throw $failed;
        }
        unset($json);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $file = $dir . '/' . $name;
        $tmp  = $file . '.tmp';
        // Erst die Rechte, dann der Inhalt: die Datei ist zu keinem Zeitpunkt für andere lesbar.
        $ok = !is_link($tmp) && @touch($tmp) && @chmod($tmp, 0600) && @file_put_contents($tmp, $packed) === strlen($packed) && @rename($tmp, $file);
        if (!$ok) {
            @unlink($tmp);
            throw $failed;
        }
    }

    /**
     * Liest ein Abbild und prüft, dass es unverändert das ist, was put() für diesen Push unter
     * diesem Namen geschrieben hat.
     *
     * @return array<string, mixed>|null null: die Datei gibt es nicht
     * @throws ContentException before_image_invalid
     */
    public static function get(string $dir, string $name): ?array
    {
        $file = $dir . '/' . $name;
        if (!is_link($file) && !file_exists($file)) {
            return null;
        }
        $raw  = is_file($file) && !is_link($file) ? @file_get_contents($file) : false;
        $json = is_string($raw) ? self::unpack($raw, self::keys(), self::context($dir, $name)) : null;
        unset($raw);
        $data = $json === null ? null : json_decode($json, true);
        if (!is_array($data)) {
            throw self::invalid();
        }
        return $data;
    }

    /** Die Ablehnung für ein Abbild, das sich nicht öffnen lässt, verändert wurde oder nicht zum Push passt. */
    public static function invalid(): ContentException
    {
        return new ContentException(
            ContentException::IMAGE,
            'Das Vorher-Abbild dieses Pushs lässt sich nicht öffnen, wurde verändert oder passt nicht zu dem, was der Push geschrieben hat (anderer Schlüssel in wp-config.php?) – nichts wird zurückgenommen, auch Code und Uploads nicht.'
        );
    }

    /**
     * @param list<string> $keys    Schlüssel der Installation, der zum Schützen zuerst; leer: Klartext
     * @param string       $context woran die Datei gebunden ist: <Ordner des Pushs>/<Name>
     * @param bool         $encrypt verschlüsseln; sonst nur ein HMAC
     */
    public static function pack(string $json, array $keys, string $context, bool $encrypt): string
    {
        if ($keys === []) {
            return $json;
        }
        $key = self::derive($keys[0], $context);
        if ($encrypt && function_exists('sodium_crypto_secretbox')) {
            $nonce = random_bytes(self::NONCE_BYTES);
            return self::SEALED . $nonce . sodium_crypto_secretbox($json, $nonce, $key);
        }
        return self::SIGNED . hash_hmac('sha256', $json, $key) . "\n" . $json;
    }

    /**
     * @param list<string> $keys
     * @return string|null das JSON; null: nicht zu öffnen, verändert, für einen anderen Push oder Namen
     *                     – oder Klartext, obwohl die Installation einen Schlüssel hat
     */
    public static function unpack(string $raw, array $keys, string $context): ?string
    {
        $sealed = strncmp($raw, self::SEALED, strlen(self::SEALED)) === 0;
        $signed = strncmp($raw, self::SIGNED, strlen(self::SIGNED)) === 0;
        if ($keys === []) {
            return !$sealed && !$signed && $raw !== '' ? $raw : null;
        }
        if ($sealed) {
            $body = substr($raw, strlen(self::SEALED));
            if (strlen($body) < self::NONCE_BYTES + self::MAC_BYTES || !function_exists('sodium_crypto_secretbox_open')) {
                return null;
            }
            foreach ($keys as $key) {
                try {
                    $plain = sodium_crypto_secretbox_open(substr($body, self::NONCE_BYTES), substr($body, 0, self::NONCE_BYTES), self::derive($key, $context));
                } catch (\Throwable $e) {
                    $plain = false;
                }
                if (is_string($plain)) {
                    return $plain;
                }
            }
            return null;
        }
        if ($signed) {
            $mac  = substr($raw, strlen(self::SIGNED), 64);
            $json = substr($raw, strlen(self::SIGNED) + 65);
            if (preg_match('/^[a-f0-9]{64}\z/', $mac) !== 1 || substr($raw, strlen(self::SIGNED) + 64, 1) !== "\n") {
                return null;
            }
            foreach ($keys as $key) {
                if (hash_equals(hash_hmac('sha256', $json, self::derive($key, $context)), $mac)) {
                    return $json;
                }
            }
        }
        return null;
    }

    /** Schlüssel einer Datei: aus dem der Installation, gebunden an Push und Namen. */
    private static function derive(string $key, string $context): string
    {
        return hash_hmac('sha256', self::LABEL . "\0" . $context, $key, true);
    }

    /** <Ordner des Pushs>/<Name> – der Ordner content liegt direkt im Arbeitsordner des Pushs. */
    private static function context(string $dir, string $name): string
    {
        return basename(dirname($dir)) . '/' . $name;
    }

    /** @return list<string> */
    private static function keys(): array
    {
        return self::$keys ?? SecretKey::fromConfig();
    }
}
