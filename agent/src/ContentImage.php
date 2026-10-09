<?php
namespace WpSync;

defined('ABSPATH') || defined('WPSYNC_RESCUE') || exit;

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
    /** Die beiden Abbilder eines Pushs im Ordner content seines Arbeitsordners. */
    public const BEFORE = 'before.json';
    public const AFTER  = 'after.json';

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
     * @var array<string, list<string>>|null Name der Datei → ihre schon abgeleiteten Dateischlüssel (roh,
     *      32 Byte). Gesetzt – von rescue.php aus dem Umschlag des Pushs (Spec Content-Push P3 R2) –,
     *      ersetzt es den Schlüssel der Installation samt Ableitung: SecretKey wird dann nie gefragt.
     *      Ohne Schlüssel für eine Datei gilt sie nicht (nie Klartext), und geschrieben wird so nichts.
     */
    public static $fileKeys = null;

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
        if (self::$fileKeys !== null) {
            throw $failed; // die Rücknahme ohne WordPress liest Abbilder, sie legt keine an
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
        $json = null;
        if (is_string($raw) && self::$fileKeys !== null) {
            // Schlüssel aus dem Umschlag: ohne einen für diese Datei gilt sie nicht – auch nicht als Klartext.
            $given = self::$fileKeys[$name] ?? [];
            $json  = is_array($given) && $given !== [] ? self::unpackWith($raw, array_values(array_map('strval', $given))) : null;
        } elseif (is_string($raw)) {
            $json = self::unpack($raw, self::keys(), self::context($dir, $name));
        }
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
        $derived = [];
        foreach ($keys as $key) {
            $derived[] = self::derive($key, $context);
        }
        return self::unpackWith($raw, $derived);
    }

    /**
     * Die Dateischlüssel eines Abbilds, wie get() sie ableitet – für den Umschlag des Pushs (Spec
     * Content-Push P3 R2): wer ihn öffnet, liest die Abbilder dieses einen Pushs, nicht mehr.
     *
     * @return list<string> roh, je 32 Byte, der zum Schützen zuerst; leer: die Installation hat keinen Schlüssel
     */
    public static function fileKeys(string $pushId, string $name): array
    {
        $out = [];
        foreach (self::keys() as $key) {
            $out[] = self::derive($key, $pushId . '/' . $name);
        }
        return $out;
    }

    /**
     * @param list<string> $derived Dateischlüssel, schon abgeleitet; leer: nur Klartext gilt
     * @return string|null das JSON; null: nicht zu öffnen, verändert, für einen anderen Push oder Namen
     *                     – oder Klartext, obwohl es einen Schlüssel gibt
     */
    private static function unpackWith(string $raw, array $derived): ?string
    {
        $sealed = strncmp($raw, self::SEALED, strlen(self::SEALED)) === 0;
        $signed = strncmp($raw, self::SIGNED, strlen(self::SIGNED)) === 0;
        if ($derived === []) {
            return !$sealed && !$signed && $raw !== '' ? $raw : null;
        }
        if ($sealed) {
            $body = substr($raw, strlen(self::SEALED));
            if (strlen($body) < self::NONCE_BYTES + self::MAC_BYTES || !function_exists('sodium_crypto_secretbox_open')) {
                return null;
            }
            foreach ($derived as $key) {
                try {
                    $plain = sodium_crypto_secretbox_open(substr($body, self::NONCE_BYTES), substr($body, 0, self::NONCE_BYTES), $key);
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
            foreach ($derived as $key) {
                if (hash_equals(hash_hmac('sha256', $json, $key), $mac)) {
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
