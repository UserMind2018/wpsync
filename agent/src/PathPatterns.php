<?php
namespace WpSync;

defined('ABSPATH') || exit;

/**
 * Ausschluss-Muster des Profils relativ zu wp-content (Spec 0.10.0 A). Das CLI hat dieselben
 * Regeln in cli/internal/pathmatch; beide prüft tests/fixtures/pathmatch.json. Verglichen wird
 * ASCII-case-insensitiv wie in Excludes (SEC-08).
 */
final class PathPatterns
{
    public const MAX_ENTRIES = 200;
    public const MAX_LEN     = 512;

    private const DIR  = 0;
    private const FILE = 1;
    private const NAME = 2;

    /** @var list<array{0: int, 1: list<string>}> */
    private $list = [];

    /**
     * @param mixed $raw
     * @throws \InvalidArgumentException
     */
    public static function fromList($raw): self
    {
        if ($raw === null) {
            $raw = [];
        }
        if (!is_array($raw) || array_values($raw) !== $raw) {
            throw new \InvalidArgumentException('exclude_paths must be a list');
        }
        $set = new self();
        foreach (self::normalize($raw) as $p) {
            if (substr($p, -1) === '/') {
                $set->list[] = [self::DIR, explode('/', strtolower(substr($p, 0, -1)))];
            } elseif (strpos($p, '/') !== false) {
                $set->list[] = [self::FILE, explode('/', strtolower($p))];
            } else {
                $set->list[] = [self::NAME, [strtolower($p)]];
            }
        }
        return $set;
    }

    /**
     * @param array<mixed> $in
     * @return list<string>
     * @throws \InvalidArgumentException
     */
    public static function normalize(array $in): array
    {
        if (count($in) > self::MAX_ENTRIES) {
            throw new \InvalidArgumentException('more than ' . self::MAX_ENTRIES . ' exclude paths');
        }
        $out  = [];
        $seen = [];
        foreach ($in as $raw) {
            if (!is_string($raw)) {
                throw new \InvalidArgumentException('exclude path must be a string');
            }
            $p   = self::clean($raw);
            $key = strtolower($p);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $out[]      = $p;
            }
        }
        usort($out, static function (string $a, string $b): int {
            return strcmp(strtolower($a), strtolower($b));
        });
        return $out;
    }

    public function isEmpty(): bool
    {
        return $this->list === [];
    }

    /** @param string $rel relativ zu wp-content, mit „/“ getrennt */
    public function matches(string $rel, bool $isDir): bool
    {
        if ($this->list === []) {
            return false;
        }
        $segs = explode('/', strtolower($rel));
        foreach ($this->list as list($kind, $pat)) {
            $n = count($pat);
            if ($kind === self::DIR) {
                if (count($segs) >= $n && (count($segs) > $n || $isDir) && self::segs($pat, array_slice($segs, 0, $n))) {
                    return true;
                }
            } elseif ($kind === self::FILE) {
                if (!$isDir && count($segs) === $n && self::segs($pat, $segs)) {
                    return true;
                }
            } elseif (!$isDir && self::wildcard($pat[0], $segs[count($segs) - 1])) {
                return true;
            }
        }
        return false;
    }

    private static function clean(string $raw): string
    {
        $p = trim($raw, " \t\n\r\0\x0B");
        while (strpos($p, '//') !== false) {
            $p = str_replace('//', '/', $p);
        }
        if ($p === '' || $p === '/') {
            throw new \InvalidArgumentException('empty exclude path');
        }
        if (strlen($p) > self::MAX_LEN || $p[0] === '/' || strpos($p, '\\') !== false
            || preg_match('/[\x00-\x1f\x7f]/', $p) === 1 || strpos($p, '**') !== false || $p[0] === '!'
            || trim($p, '*?/') === '') {
            throw new \InvalidArgumentException('invalid exclude path');
        }
        $segs = explode('/', substr($p, -1) === '/' ? substr($p, 0, -1) : $p);
        foreach ($segs as $s) {
            if ($s === '' || $s === '.' || $s === '..') {
                throw new \InvalidArgumentException('invalid exclude path');
            }
        }
        $top = strtolower($segs[0]);
        if ((($top === 'plugins' || $top === 'themes') && count($segs) <= 2)
            || ($top === 'mu-plugins' && count($segs) === 1 && substr($p, -1) === '/')) {
            throw new \InvalidArgumentException('whole plugins and themes are excluded with exclude_plugins');
        }
        return $p;
    }

    /**
     * @param list<string> $pat
     * @param list<string> $segs
     */
    private static function segs(array $pat, array $segs): bool
    {
        foreach ($pat as $i => $p) {
            if (!self::wildcard($p, $segs[$i])) {
                return false;
            }
        }
        return true;
    }

    /** Ein Segment: * beliebig viele Bytes, ? genau eins, alles andere wörtlich. */
    private static function wildcard(string $pat, string $s): bool
    {
        $px = 0;
        $sx = 0;
        $star = -1;
        $mark = 0;
        $pl = strlen($pat);
        $sl = strlen($s);
        while ($sx < $sl) {
            if ($px < $pl && ($pat[$px] === '?' || $pat[$px] === $s[$sx])) {
                $px++;
                $sx++;
            } elseif ($px < $pl && $pat[$px] === '*') {
                $star = $px;
                $mark = $sx;
                $px++;
            } elseif ($star >= 0) {
                $px = $star + 1;
                $mark++;
                $sx = $mark;
            } else {
                return false;
            }
        }
        while ($px < $pl && $pat[$px] === '*') {
            $px++;
        }
        return $px === $pl;
    }
}
