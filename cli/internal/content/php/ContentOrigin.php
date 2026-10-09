<?php
namespace WpSync;

defined('ABSPATH') || exit;

/**
 * Normalisieren statt übersetzen (Spec Content-Push §5, C2): Jede Site ersetzt ihre eigene Origin
 * durch Platzhalter – je Schreibweise einen (Klartext, \/ und \\\/), damit beim Einsetzen dieselbe
 * Schreibweise entsteht. Reiner Teilstring wie wp search-replace (C4). Serialisierte Werte
 * durchläuft SerializedWalker. Für die Staging-Kopie entfernt normalize() zuerst die eingefügten
 * Staging-Pfade und setzt insert() sie wieder ein.
 */
final class ContentOrigin
{
    /** Anfang jedes Platzhalters: ⟦wpsync:origin */
    public const MARK  = "\xE2\x9F\xA6wpsync:origin";
    public const PLAIN = "\xE2\x9F\xA6wpsync:origin\xE2\x9F\xA7";
    public const ESC1  = "\xE2\x9F\xA6wpsync:origin:esc1\xE2\x9F\xA7";
    public const ESC2  = "\xE2\x9F\xA6wpsync:origin:esc2\xE2\x9F\xA7";
    public const VARIANTS = ['plain', 'esc1', 'esc2'];

    /** @var array<string, string> Origin je Schreibweise → Platzhalter, längste zuerst */
    private $toMark;
    /** @var array<string, string> */
    private $toOrigin;
    /** @var string Host mit Port – ohne ihn im Wert gibt es nichts zu ersetzen */
    private $needle;
    /** @var StagingReplace|null */
    private $staging;

    /**
     * @param string              $home    home der Site ohne Schrägstrich am Ende, z. B. https://kunde.de
     * @param StagingReplace|null $staging für die Staging-Kopie: der Ersetzer, mit dem sie angelegt wurde
     */
    public function __construct(string $home, ?StagingReplace $staging = null)
    {
        $home = rtrim($home, '/');
        if (preg_match('#^https?://([^/\s]+)#i', $home, $m) !== 1) {
            throw new \InvalidArgumentException('invalid home');
        }
        $variants       = self::variants($home);
        $this->toMark   = [$variants['esc2'] => self::ESC2, $variants['esc1'] => self::ESC1, $variants['plain'] => self::PLAIN];
        $this->toOrigin = [self::ESC2 => $variants['esc2'], self::ESC1 => $variants['esc1'], self::PLAIN => $variants['plain']];
        $this->needle   = $m[1];
        $this->staging  = $staging;
    }

    /** @return array{plain: string, esc1: string, esc2: string} */
    public static function variants(string $url): array
    {
        return [
            'plain' => $url,
            'esc1'  => str_replace('/', '\\/', $url),
            'esc2'  => str_replace('/', '\\\\\\/', $url),
        ];
    }

    /** @return string|null die normalisierte Form; null, wenn sich der Wert nicht normalisieren lässt */
    public function normalize(string $value): ?string
    {
        if (strpos($value, self::MARK) !== false) {
            return null; // ein Platzhalter im Rohwert wäre nach dem Einsetzen nicht mehr zu unterscheiden
        }
        if ($this->staging !== null) {
            $value = $this->staging->stripValue($value);
            if ($value === null) {
                return null;
            }
        }
        if (strpos($value, $this->needle) === false) {
            return $value;
        }
        return self::map($value, $this->toMark);
    }

    /** @return string|null der Wert mit der Origin dieser Site; null bei unbekanntem Platzhalter oder unlesbarem Wert */
    public function insert(string $value): ?string
    {
        if (strpos($value, self::MARK) !== false) {
            $value = self::map($value, $this->toOrigin);
            if ($value === null || strpos($value, self::MARK) !== false) {
                return null;
            }
        }
        if ($this->staging === null) {
            return $value;
        }
        $skipped = $this->staging->skipped();
        $value   = $this->staging->value($value);
        return $this->staging->skipped() === $skipped ? $value : null;
    }

    /** @param array<string, string> $pairs */
    private static function map(string $value, array $pairs): ?string
    {
        if (!SerializedWalker::looksSerialized($value)) {
            return strtr($value, $pairs);
        }
        return SerializedWalker::rewrite($value, static function (string $text) use ($pairs): string {
            return strtr($text, $pairs);
        });
    }
}
