<?php
namespace WpSync;

defined('ABSPATH') || exit;

/**
 * Vergleicht eine Einheit auf dem Server mit dem, was der Client kennt und liefern will
 * (Spec Stufe 2, 5.3). Pfade sind relativ zur Einheit und mit „/“ getrennt.
 */
final class PushManifest
{
    /**
     * Dateien, wie /delta sie liefert: ohne Symlinks, ohne feste Ausschlüsse (Excludes).
     *
     * @return array<string, array{size: int, mtime: int}>
     */
    public static function stamps(string $dir, string $unit): array
    {
        $out = [];
        foreach (self::walk($dir, '', false) as $rel) {
            $full = $dir . '/' . $rel;
            $size = (int) @filesize($full);
            if (Excludes::path($unit . '/' . $rel, $size) === null) {
                $out[$rel] = ['size' => $size, 'mtime' => (int) @filemtime($full)];
            }
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /**
     * Dateien, die in keiner Baseline stehen und den Tausch trotzdem überleben müssen:
     * feste Ausschlüsse, geschützte Dateien, Symlinks.
     *
     * @return list<string>
     */
    public static function carried(string $dir, string $unit): array
    {
        $out = [];
        foreach (self::walk($dir, '', true) as $rel) {
            $full = $dir . '/' . $rel;
            if (is_link($full) || PushUnits::isProtected($unit, $rel) || Excludes::path($unit . '/' . $rel, (int) @filesize($full)) !== null) {
                $out[] = $rel;
            }
        }
        sort($out, SORT_STRING);
        return $out;
    }

    /**
     * Dateien, deren Stand auf dem Server nicht mehr der Baseline des Clients entspricht.
     *
     * @param array<string, array{size: int, mtime: int}> $server
     * @param array<string, mixed>                        $base
     * @return list<string>
     */
    public static function conflicts(array $server, array $base): array
    {
        $out = [];
        foreach ($server as $rel => $stamp) {
            $known = $base[$rel] ?? null;
            if (!is_array($known) || (int) ($known['size'] ?? -1) !== $stamp['size'] || (int) ($known['mtime'] ?? -1) !== $stamp['mtime']) {
                $out[] = (string) $rel;
            }
        }
        foreach ($base as $rel => $unused) {
            if (!isset($server[$rel])) {
                $out[] = (string) $rel;
            }
        }
        sort($out, SORT_STRING);
        return $out;
    }

    /**
     * Dateien des Soll-Manifests, die der Server nicht in genau dieser Fassung hat.
     *
     * @param array<string, array{size: int, sha256: string}> $files
     * @return list<string>
     */
    public static function need(string $dir, array $files): array
    {
        $out = [];
        foreach ($files as $rel => $want) {
            $rel  = (string) $rel;
            $full = $dir . '/' . $rel;
            $same = is_file($full) && !is_link($full)
                && (int) filesize($full) === (int) $want['size']
                && hash_equals((string) $want['sha256'], (string) hash_file('sha256', $full));
            if (!$same) {
                $out[] = $rel;
            }
        }
        return $out;
    }

    /**
     * @return \Generator<int, string>
     */
    private static function walk(string $dir, string $prefix, bool $withLinks): \Generator
    {
        $entries = @scandir($dir);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $full = $dir . '/' . $name;
            $rel  = $prefix === '' ? $name : $prefix . '/' . $name;
            if (is_link($full)) {
                if ($withLinks) {
                    yield $rel; // nie hineinsteigen: Links zeigen typischerweise aus der Site heraus
                }
                continue;
            }
            if (is_dir($full)) {
                yield from self::walk($full, $rel, $withLinks);
                continue;
            }
            yield $rel;
        }
    }
}
