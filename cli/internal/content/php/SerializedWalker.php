<?php
namespace WpSync;

defined('ABSPATH') || exit;

/**
 * Läuft strukturerhaltend durch einen serialisierten PHP-Wert und gibt jeden String an eine
 * Funktion; jede String-Länge wird neu gesetzt. Doppelt serialisierte Strings werden betreten,
 * Objekte ohne Laden der Klasse gelesen. C: und Unlesbares ergeben null (Spec Stufe 2b 5.6,
 * Spec Content-Push §5.3).
 */
final class SerializedWalker
{
    private const MAX_DEPTH = 64;

    /** Ziffern einer Länge oder Anzahl: mehr passt in keinen Wert, und als int liefe die Zahl über. */
    private const MAX_DIGITS = 10;

    public static function looksSerialized(string $value): bool
    {
        return preg_match('/^(N;|b:[01];|i:-?\d+;|d:[^;]+;|s:\d+:"|a:\d+:\{|O:\d+:"|C:\d+:"|E:\d+:")/', $value) === 1
            && in_array(substr($value, -1), [';', '}'], true);
    }

    /**
     * @param callable(string): string $text bekommt den Inhalt jedes Strings, der nicht selbst serialisiert ist
     * @return string|null der neu geschriebene Wert; null, wenn er sich nicht vollständig lesen liess
     */
    public static function rewrite(string $value, callable $text): ?string
    {
        $pos = 0;
        $new = self::parse($value, $pos, 0, $text);
        if ($new === null || $pos !== strlen($value) || ($new !== $value && !self::valid($new))) {
            return null;
        }
        return $new;
    }

    private static function valid(string $value): bool
    {
        return $value === 'b:0;' || @unserialize($value, ['allowed_classes' => false]) !== false;
    }

    /** Inhalt eines Strings: selbst serialisiert (doppelt serialisiert) oder Text. */
    private static function inner(string $value, callable $text): string
    {
        if (self::looksSerialized($value)) {
            $pos = 0;
            $new = self::parse($value, $pos, 0, $text);
            if ($new !== null && $pos === strlen($value)) {
                return $new;
            }
        }
        return (string) $text($value);
    }

    /** @return string|null neu geschrieben ab $pos; null, wenn dort kein gültiger Wert steht */
    private static function parse(string $s, int &$pos, int $depth, callable $text): ?string
    {
        if ($depth > self::MAX_DEPTH || !isset($s[$pos])) {
            return null;
        }
        switch ($s[$pos]) {
            case 'N':
                if (substr($s, $pos, 2) !== 'N;') {
                    return null;
                }
                $pos += 2;
                return 'N;';
            case 'b':
            case 'i':
            case 'd':
            case 'r':
            case 'R':
                if (preg_match('/\G[bidrR]:[^;:{}"]*;/', $s, $m, 0, $pos) !== 1) {
                    return null;
                }
                $pos += strlen($m[0]);
                return $m[0];
            case 's':
                if (preg_match('/\Gs:(\d{1,' . self::MAX_DIGITS . '}):"/', $s, $m, 0, $pos) !== 1) {
                    return null;
                }
                $start = $pos + strlen($m[0]);
                $len   = (int) $m[1];
                if (!self::fits($s, $start, $len) || substr($s, $start + $len, 2) !== '";') {
                    return null;
                }
                $pos = $start + $len + 2;
                $new = self::inner(substr($s, $start, $len), $text);
                return 's:' . strlen($new) . ':"' . $new . '";';
            case 'E':
                if (preg_match('/\GE:(\d{1,' . self::MAX_DIGITS . '}):"/', $s, $m, 0, $pos) !== 1) {
                    return null;
                }
                $start = $pos + strlen($m[0]);
                $len   = (int) $m[1];
                if (!self::fits($s, $start, $len) || substr($s, $start + $len, 2) !== '";') {
                    return null;
                }
                $out = substr($s, $pos, $start + $len + 2 - $pos);
                $pos = $start + $len + 2;
                return $out;
            case 'a':
                if (preg_match('/\Ga:(\d{1,' . self::MAX_DIGITS . '}):\{/', $s, $m, 0, $pos) !== 1) {
                    return null;
                }
                $pos += strlen($m[0]);
                if (!self::fits($s, $pos, (int) $m[1])) {
                    return null;
                }
                return self::members($s, $pos, $depth, 2 * (int) $m[1], $m[0], $text);
            case 'O':
                $digits = '(\d{1,' . self::MAX_DIGITS . '})';
                if (preg_match('/\GO:' . $digits . ':"([^"]*)":' . $digits . ':\{/', $s, $m, 0, $pos) !== 1 || strlen($m[2]) !== (int) $m[1]) {
                    return null;
                }
                $pos += strlen($m[0]);
                if (!self::fits($s, $pos, (int) $m[3])) {
                    return null;
                }
                return self::members($s, $pos, $depth, 2 * (int) $m[3], $m[0], $text);
        }
        return null; // C: und Unbekanntes lassen sich ohne die Klasse nicht lesen
    }

    /**
     * Passen ab $from noch $n Bytes bzw. Einträge in den Wert? Geprüft, bevor mit $n gerechnet wird –
     * jeder Eintrag belegt mindestens ein Byte.
     */
    private static function fits(string $s, int $from, int $n): bool
    {
        return $n <= strlen($s) - $from;
    }

    private static function members(string $s, int &$pos, int $depth, int $count, string $head, callable $text): ?string
    {
        $out = $head;
        for ($i = 0; $i < $count; $i++) {
            $part = self::parse($s, $pos, $depth + 1, $text);
            if ($part === null) {
                return null;
            }
            $out .= $part;
        }
        if (($s[$pos] ?? '') !== '}') {
            return null;
        }
        $pos++;
        return $out . '}';
    }
}
