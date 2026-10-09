<?php
namespace WpSync;

defined('ABSPATH') || exit;

/**
 * Läuft strukturerhaltend durch einen serialisierten PHP-Wert und gibt jeden String an eine
 * Funktion; jede String-Länge wird neu gesetzt. Doppelt serialisierte Strings werden betreten,
 * Objekte ohne Laden der Klasse gelesen. C: und Unlesbares ergeben null (Spec Stufe 2b 5.6,
 * Spec Content-Push §5.3). Gelesen wird an Ort und Stelle: ein verschachtelter String ist ein
 * Abschnitt [$pos, $end) desselben Werts, keine Kopie je Ebene.
 */
final class SerializedWalker
{
    /** Tiefe insgesamt: Arrays, Objekte und in Strings verschachtelte serialisierte Werte zählen zusammen. */
    private const MAX_DEPTH = 64;

    /** Ziffern einer Länge oder Anzahl: mehr passt in keinen Wert, und als int liefe die Zahl über. */
    private const MAX_DIGITS = 10;

    public static function looksSerialized(string $value): bool
    {
        return self::looksAt($value, 0, strlen($value));
    }

    /** looksSerialized() für den Abschnitt [$from, $to) von $s. */
    private static function looksAt(string $s, int $from, int $to): bool
    {
        return $to > $from
            && preg_match('/\G(?:N;|b:[01];|i:-?\d+;|d:[^;]+;|s:\d+:"|a:\d+:\{|O:\d+:"|C:\d+:"|E:\d+:")/', $s, $m, 0, $from) === 1
            && $from + strlen($m[0]) <= $to
            && in_array($s[$to - 1], [';', '}'], true);
    }

    /**
     * @param callable(string): string $text bekommt den Inhalt jedes Strings, der nicht selbst serialisiert ist
     * @return string|null der neu geschriebene Wert; null, wenn er sich nicht vollständig lesen liess
     */
    public static function rewrite(string $value, callable $text): ?string
    {
        $pos = 0;
        $end = strlen($value);
        try {
            $new = self::parse($value, $pos, $end, 0, $text);
        } catch (\OverflowException $e) {
            return null; // tiefer als MAX_DEPTH
        }
        if ($new === null || $pos !== $end || ($new !== $value && !self::valid($new))) {
            return null;
        }
        return $new;
    }

    private static function valid(string $value): bool
    {
        // max_depth zählt nur Arrays und Objekte; MAX_DEPTH lässt davon eine Ebene mehr zu, als es Tiefen gibt.
        return $value === 'b:0;' || @unserialize($value, ['allowed_classes' => false, 'max_depth' => self::MAX_DEPTH + 1]) !== false;
    }

    /**
     * Inhalt eines Strings, der Abschnitt [$from, $to) von $s: selbst serialisiert (doppelt
     * serialisiert) oder Text.
     *
     * @throws \OverflowException wenn die Verschachtelung MAX_DEPTH übersteigt
     */
    private static function inner(string $s, int $from, int $to, int $depth, callable $text): string
    {
        if (self::looksAt($s, $from, $to)) {
            $pos = $from;
            $new = self::parse($s, $pos, $to, $depth + 1, $text);
            if ($new !== null && $pos === $to) {
                return $new;
            }
        }
        return (string) $text(substr($s, $from, $to - $from));
    }

    /**
     * @param int $end Ende des Abschnitts, in dem der Wert stehen muss
     * @return string|null neu geschrieben ab $pos; null, wenn dort kein gültiger Wert steht
     * @throws \OverflowException wenn die Verschachtelung MAX_DEPTH übersteigt – der ganze Wert ist dann unlesbar
     */
    private static function parse(string $s, int &$pos, int $end, int $depth, callable $text): ?string
    {
        if ($depth > self::MAX_DEPTH) {
            throw new \OverflowException('nested too deep');
        }
        if ($pos >= $end) {
            return null;
        }
        switch ($s[$pos]) {
            case 'N':
                if ($end - $pos < 2 || $s[$pos + 1] !== ';') {
                    return null;
                }
                $pos += 2;
                return 'N;';
            case 'b':
            case 'i':
            case 'd':
            case 'r':
            case 'R':
                if (preg_match('/\G[bidrR]:[^;:{}"]*;/', $s, $m, 0, $pos) !== 1 || strlen($m[0]) > $end - $pos) {
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
                if (!self::fits($start, $end, $len, 2) || $s[$start + $len] !== '"' || $s[$start + $len + 1] !== ';') {
                    return null;
                }
                $pos = $start + $len + 2;
                $new = self::inner($s, $start, $start + $len, $depth, $text);
                return 's:' . strlen($new) . ':"' . $new . '";';
            case 'E':
                if (preg_match('/\GE:(\d{1,' . self::MAX_DIGITS . '}):"/', $s, $m, 0, $pos) !== 1) {
                    return null;
                }
                $start = $pos + strlen($m[0]);
                $len   = (int) $m[1];
                if (!self::fits($start, $end, $len, 2) || $s[$start + $len] !== '"' || $s[$start + $len + 1] !== ';') {
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
                if (!self::fits($pos, $end, (int) $m[1], 0)) {
                    return null;
                }
                return self::members($s, $pos, $end, $depth, 2 * (int) $m[1], $m[0], $text);
            case 'O':
                $digits = '(\d{1,' . self::MAX_DIGITS . '})';
                if (preg_match('/\GO:' . $digits . ':"([^"]*)":' . $digits . ':\{/', $s, $m, 0, $pos) !== 1 || strlen($m[2]) !== (int) $m[1]) {
                    return null;
                }
                $pos += strlen($m[0]);
                if (!self::fits($pos, $end, (int) $m[3], 0)) {
                    return null;
                }
                return self::members($s, $pos, $end, $depth, 2 * (int) $m[3], $m[0], $text);
        }
        return null; // C: und Unbekanntes lassen sich ohne die Klasse nicht lesen
    }

    /**
     * Passen zwischen $from und $end noch $n Bytes bzw. Einträge und dahinter $more Bytes? Geprüft,
     * bevor mit $n gerechnet wird – jeder Eintrag belegt mindestens ein Byte.
     */
    private static function fits(int $from, int $end, int $n, int $more): bool
    {
        return $from <= $end && $n <= $end - $from - $more;
    }

    private static function members(string $s, int &$pos, int $end, int $depth, int $count, string $head, callable $text): ?string
    {
        $out = $head;
        for ($i = 0; $i < $count; $i++) {
            $part = self::parse($s, $pos, $end, $depth + 1, $text);
            if ($part === null) {
                return null;
            }
            $out .= $part;
        }
        if ($pos >= $end || $s[$pos] !== '}') {
            return null;
        }
        $pos++;
        return $out . '}';
    }
}
