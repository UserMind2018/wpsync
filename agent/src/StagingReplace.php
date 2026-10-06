<?php
namespace WpSync;

defined('ABSPATH') || exit;

/**
 * Schreibt die Live-URL auf die Staging-URL um (Spec Stufe 2b 5.6, V4): hinter
 * //<host>[<home-pfad>] wird der Staging-Pfad eingefügt – in Klartext, protokollrelativ und in
 * JSON mit escaped Slashes. Nur vor einer Grenze, nie zweimal. Serialisierte Werte werden
 * strukturerhaltend durchlaufen; jede String-Länge wird neu gesetzt.
 */
final class StagingReplace
{
    private const MAX_DEPTH = 64;

    /** @var string */
    private $host;
    /** @var string */
    private $plain;
    /** @var string */
    private $escaped;
    /** @var string */
    private $tail;
    /** @var string */
    private $tailEscaped;
    /** @var int */
    private $skipped = 0;

    /**
     * @param string $liveHome    home_url() von Live, z. B. https://example.com/blog
     * @param string $stagingPath eingefügter Pfad, z. B. /wpsync-staging-0123456789ab
     */
    public function __construct(string $liveHome, string $stagingPath)
    {
        $parts = parse_url($liveHome);
        if (!is_array($parts) || ($parts['host'] ?? '') === '' || preg_match('#^(/[A-Za-z0-9._~-]+)+\z#', $stagingPath) !== 1) {
            throw new \InvalidArgumentException('invalid live home or staging path');
        }
        $this->host        = strtolower((string) $parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $needle            = $this->host . rtrim((string) ($parts['path'] ?? ''), '/');
        $this->tail        = $stagingPath;
        $this->tailEscaped = str_replace('/', '\\/', $stagingPath);
        $this->plain       = '#(?<=//)' . preg_quote($needle, '#') . '(?=[/"\'\\\\\s?\#<)]|\z)(?!' . preg_quote($this->tail, '#') . ')#i';
        $this->escaped     = '#(?<=\\\\/\\\\/)' . preg_quote(str_replace('/', '\\/', $needle), '#') . '(?=[\\\\"\'\s?\#<)]|\z)(?!' . preg_quote($this->tailEscaped, '#') . ')#i';
    }

    /** Host mit Port – Kandidaten-Zeilen findet der Job per LIKE darauf. */
    public function host(): string
    {
        return $this->host;
    }

    /** Werte, die serialisiert aussahen, sich aber nicht lesen liessen; sie blieben unverändert. */
    public function skipped(): int
    {
        return $this->skipped;
    }

    public function text(string $value): string
    {
        $tail = $this->tail;
        $out  = preg_replace_callback($this->plain, static function (array $m) use ($tail): string {
            return $m[0] . $tail;
        }, $value);
        if ($out === null) {
            return $value;
        }
        $escaped = $this->tailEscaped;
        $out     = preg_replace_callback($this->escaped, static function (array $m) use ($escaped): string {
            return $m[0] . $escaped;
        }, $out);
        return $out ?? $value;
    }

    /** Ein Spaltenwert: serialisiert strukturerhaltend, sonst als Text. */
    public function value(string $value): string
    {
        if (!self::looksSerialized($value)) {
            return $this->text($value);
        }
        $pos = 0;
        $new = $this->parse($value, $pos, 0);
        if ($new === null || $pos !== strlen($value) || ($new !== $value && !self::valid($new))) {
            $this->skipped++;
            return $value;
        }
        return $new;
    }

    private static function looksSerialized(string $value): bool
    {
        return preg_match('/^(N;|b:[01];|i:-?\d+;|d:[^;]+;|s:\d+:"|a:\d+:\{|O:\d+:"|C:\d+:"|E:\d+:")/', $value) === 1
            && in_array(substr($value, -1), [';', '}'], true);
    }

    private static function valid(string $value): bool
    {
        return $value === 'b:0;' || @unserialize($value, ['allowed_classes' => false]) !== false;
    }

    /** Inhalt eines Strings: selbst serialisiert (doppelt serialisiert) oder Text. */
    private function inner(string $value): string
    {
        if (self::looksSerialized($value)) {
            $pos = 0;
            $new = $this->parse($value, $pos, 0);
            if ($new !== null && $pos === strlen($value)) {
                return $new;
            }
        }
        return $this->text($value);
    }

    /** @return string|null neu geschrieben ab $pos; null, wenn dort kein gültiger Wert steht */
    private function parse(string $s, int &$pos, int $depth): ?string
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
                if (preg_match('/\Gs:(\d+):"/', $s, $m, 0, $pos) !== 1) {
                    return null;
                }
                $start = $pos + strlen($m[0]);
                $len   = (int) $m[1];
                if (substr($s, $start + $len, 2) !== '";') {
                    return null;
                }
                $pos = $start + $len + 2;
                $new = $this->inner(substr($s, $start, $len));
                return 's:' . strlen($new) . ':"' . $new . '";';
            case 'E':
                if (preg_match('/\GE:(\d+):"/', $s, $m, 0, $pos) !== 1) {
                    return null;
                }
                $start = $pos + strlen($m[0]);
                if (substr($s, $start + (int) $m[1], 2) !== '";') {
                    return null;
                }
                $out = substr($s, $pos, $start + (int) $m[1] + 2 - $pos);
                $pos = $start + (int) $m[1] + 2;
                return $out;
            case 'a':
                if (preg_match('/\Ga:(\d+):\{/', $s, $m, 0, $pos) !== 1) {
                    return null;
                }
                $pos += strlen($m[0]);
                return $this->members($s, $pos, $depth, 2 * (int) $m[1], $m[0]);
            case 'O':
                if (preg_match('/\GO:(\d+):"([^"]*)":(\d+):\{/', $s, $m, 0, $pos) !== 1 || strlen($m[2]) !== (int) $m[1]) {
                    return null;
                }
                $pos += strlen($m[0]);
                return $this->members($s, $pos, $depth, 2 * (int) $m[3], $m[0]);
        }
        return null; // C: und Unbekanntes lassen sich ohne die Klasse nicht lesen
    }

    private function members(string $s, int &$pos, int $depth, int $count, string $head): ?string
    {
        $out = $head;
        for ($i = 0; $i < $count; $i++) {
            $part = $this->parse($s, $pos, $depth + 1);
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
