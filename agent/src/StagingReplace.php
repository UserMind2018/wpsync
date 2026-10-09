<?php
namespace WpSync;

defined('ABSPATH') || exit;

/**
 * Schreibt die Live-URL auf die Staging-URL um (Spec Stufe 2b 5.6, V4): hinter
 * //<host>[<home-pfad>] wird der Staging-Pfad eingefügt – in Klartext, protokollrelativ, in JSON
 * mit escaped Slashes (\/) und in doppelt escaptem JSON (\\\/). Nur vor einer Grenze, nie
 * zweimal. Serialisierte Werte durchläuft SerializedWalker. strip() ist die exakte Umkehr
 * (Spec Content-Push §5.3).
 */
final class StagingReplace
{
    /** @var string */
    private $host;
    /** @var string */
    private $tail;
    /** @var list<array{0: string, 1: string}> je Variante [Muster, einzufügender Pfad] */
    private $insert = [];
    /** @var list<string> je Variante das Muster, das den eingefügten Pfad wieder findet */
    private $remove = [];
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
        $this->host = strtolower((string) $parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $this->tail = $stagingPath;
        $needle     = $this->host . rtrim((string) ($parts['path'] ?? ''), '/');
        $quote      = static function (string $text): string {
            return preg_quote($text, '#');
        };
        // Schrägstrich je Variante: Klartext, \/ und \\\/ – dahinter die Zeichen, die als Grenze gelten.
        foreach ([['/', '[/"\'\\\\\s?\#<)]'], ['\\/', '[\\\\"\'\s?\#<)]'], ['\\\\\\/', '[\\\\"\'\s?\#<)]']] as $variant) {
            list($slash, $edge) = $variant;
            $before = '(?<=' . $quote($slash . $slash) . ')';
            $host   = $quote(str_replace('/', $slash, $needle));
            $tail   = str_replace('/', $slash, $stagingPath);
            $after  = '(?=' . $edge . '|\z)';
            $this->insert[] = ['#' . $before . $host . $after . '(?!' . $quote($tail) . ')#i', $tail];
            $this->remove[] = '#' . $before . '(' . $host . ')' . $quote($tail) . $after . '#i';
        }
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
        foreach ($this->insert as $variant) {
            $tail = $variant[1];
            $out  = preg_replace_callback($variant[0], static function (array $m) use ($tail): string {
                return $m[0] . $tail;
            }, $value);
            if ($out !== null) {
                $value = $out;
            }
        }
        return $value;
    }

    /** Ein Spaltenwert: serialisiert strukturerhaltend, sonst als Text. */
    public function value(string $value): string
    {
        if (!SerializedWalker::looksSerialized($value)) {
            return $this->text($value);
        }
        $new = SerializedWalker::rewrite($value, function (string $text): string {
            return $this->text($text);
        });
        if ($new === null) {
            $this->skipped++;
            return $value;
        }
        return $new;
    }

    /** Umkehr von text(): entfernt den eingefügten Staging-Pfad in allen drei Varianten. */
    public function strip(string $value): string
    {
        foreach ($this->remove as $pattern) {
            $out = preg_replace($pattern, '$1', $value);
            if ($out !== null) {
                $value = $out;
            }
        }
        return $value;
    }

    /**
     * Umkehr von value(). null, wenn der Wert den Staging-Pfad enthält, serialisiert aussieht und
     * sich nicht lesen lässt – er lässt sich dann nicht normalisieren.
     */
    public function stripValue(string $value): ?string
    {
        if (stripos($value, ltrim($this->tail, '/')) === false) {
            return $value;
        }
        if (!SerializedWalker::looksSerialized($value)) {
            return $this->strip($value);
        }
        return SerializedWalker::rewrite($value, function (string $text): string {
            return $this->strip($text);
        });
    }
}
