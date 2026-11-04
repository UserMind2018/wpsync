<?php
namespace WpSync;

defined('ABSPATH') || exit;

/**
 * Nachtbericht: macht aus zwei Schnappschüssen der installierten Software und
 * aus dem Ergebnis eines Auto-Updates Ereignisse. Rein – kein WordPress, keine Datenbank.
 *
 * Schnappschuss: ['core' => '6.8.1', 'theme' => '<stylesheet>',
 *                 'plugins' => [slug => ['v' => Version, 'a' => aktiv, 'n' => Name]],
 *                 'themes'  => [slug => ['v' => Version, 'n' => Name]]]
 */
final class ActivityDiff
{
    public const KINDS   = ['updated', 'downgraded', 'installed', 'deleted', 'activated', 'deactivated', 'theme_switched', 'update_failed', 'translations_updated'];
    public const TYPES   = ['core', 'plugin', 'theme', 'translation'];
    public const SOURCES = ['auto', 'wpsync', 'cli', 'user', 'other', 'detected'];

    /** Höchstlänge jedes Texts in Byte (Spalten VARCHAR(191)). */
    public const TEXT = 191;

    /**
     * @param list<array{slug: string, name: string, version: string, active: bool}> $plugins wie Probe::plugins()
     * @param list<array{slug: string, name: string, version: string, active: bool}> $themes  wie Probe::themes()
     * @return array<string, mixed>
     */
    public static function snapshot(string $core, array $plugins, array $themes, string $theme): array
    {
        $p = [];
        foreach ($plugins as $item) {
            $p[self::clip((string) $item['slug'])] = [
                'v' => self::clip((string) $item['version']),
                'a' => (bool) $item['active'],
                'n' => self::clip((string) $item['name']),
            ];
        }
        $t = [];
        foreach ($themes as $item) {
            $t[self::clip((string) $item['slug'])] = ['v' => self::clip((string) $item['version']), 'n' => self::clip((string) $item['name'])];
        }
        ksort($p, SORT_STRING);
        ksort($t, SORT_STRING);
        return ['core' => self::clip($core), 'theme' => self::clip($theme), 'plugins' => $p, 'themes' => $t];
    }

    /**
     * @param array<string, mixed> $old
     * @param array<string, mixed> $new
     * @return list<array{kind: string, type: string, slug: string, name: string, from: ?string, to: ?string, detail: ?string}>
     */
    public static function diff(array $old, array $new): array
    {
        $out  = [];
        $from = (string) ($old['core'] ?? '');
        $to   = (string) ($new['core'] ?? '');
        if ($from !== '' && $to !== '' && $from !== $to) {
            $out[] = self::event(self::direction($from, $to), 'core', 'wordpress', 'WordPress', $from, $to);
        }
        foreach (['plugin' => 'plugins', 'theme' => 'themes'] as $type => $key) {
            $out = array_merge($out, self::items($type, (array) ($old[$key] ?? []), (array) ($new[$key] ?? [])));
        }
        $from = (string) ($old['theme'] ?? '');
        $to   = (string) ($new['theme'] ?? '');
        if ($from !== '' && $to !== '' && $from !== $to) {
            $name  = (string) ($new['themes'][$to]['n'] ?? $to);
            $out[] = self::event('theme_switched', 'theme', $to, $name, $from, $to);
        }
        return $out;
    }

    /**
     * Fehlschläge und Übersetzungen aus automatic_updates_complete. Erfolgreiche Updates von Core,
     * Plugins und Themes meldet der Abgleich (diff), nicht diese Methode.
     *
     * @param list<array{type: string, slug: string, name: string, ok: bool, error: string}> $results
     * @return list<array{kind: string, type: string, slug: string, name: string, from: ?string, to: ?string, detail: ?string}>
     */
    public static function results(array $results): array
    {
        $out          = [];
        $translations = 0;
        foreach ($results as $r) {
            $type = (string) $r['type'];
            if (!in_array($type, self::TYPES, true)) {
                continue;
            }
            if ($r['ok']) {
                if ($type === 'translation') {
                    $translations++;
                }
                continue;
            }
            $out[] = self::event('update_failed', $type, (string) $r['slug'], (string) $r['name'], null, null, (string) $r['error']);
        }
        if ($translations > 0) {
            $out[] = self::event('translations_updated', 'translation', 'translations', 'Übersetzungen', null, null, (string) $translations);
        }
        return $out;
    }

    /**
     * Steuerzeichen raus, ungültiges UTF-8 ersetzt, höchstens TEXT Byte, kein halbes UTF-8-Zeichen am Ende.
     * Ein Plugin-Header mit kaputten Bytes liesse sonst den INSERT scheitern (Ereignis verloren).
     */
    public static function clip(string $text): string
    {
        $text = (string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text);
        if (preg_match('//u', $text) !== 1) {
            if (function_exists('mb_scrub')) {
                $text = mb_scrub($text, 'UTF-8');
            } else {
                $fixed = function_exists('iconv') ? @iconv('UTF-8', 'UTF-8//IGNORE', $text) : false;
                $text  = is_string($fixed) ? $fixed : (string) preg_replace('/[^\x20-\x7E]/', '?', $text);
            }
        }
        if (strlen($text) <= self::TEXT) {
            return $text;
        }
        return function_exists('mb_strcut') ? (string) mb_strcut($text, 0, self::TEXT, 'UTF-8') : substr($text, 0, self::TEXT);
    }

    /**
     * @param array<array-key, mixed> $old
     * @param array<array-key, mixed> $new
     * @return list<array{kind: string, type: string, slug: string, name: string, from: ?string, to: ?string, detail: ?string}>
     */
    private static function items(string $type, array $old, array $new): array
    {
        $out   = [];
        $slugs = array_map('strval', array_unique(array_merge(array_keys($old), array_keys($new))));
        sort($slugs, SORT_STRING);
        foreach ($slugs as $slug) {
            $was  = isset($old[$slug]) ? (array) $old[$slug] : null;
            $is   = isset($new[$slug]) ? (array) $new[$slug] : null;
            $name = (string) (($is ?? $was)['n'] ?? $slug);
            if ($was === null) {
                $out[] = self::event('installed', $type, $slug, $name, null, (string) ($is['v'] ?? ''));
            } elseif ($is === null) {
                $out[] = self::event('deleted', $type, $slug, $name, (string) ($was['v'] ?? ''), null);
                continue;
            } elseif ((string) ($was['v'] ?? '') !== (string) ($is['v'] ?? '')) {
                $out[] = self::event(self::direction((string) $was['v'], (string) $is['v']), $type, $slug, $name, (string) $was['v'], (string) $is['v']);
            }
            if ($type === 'plugin' && (bool) ($was['a'] ?? false) !== (bool) ($is['a'] ?? false)) {
                $out[] = self::event((bool) $is['a'] ? 'activated' : 'deactivated', $type, $slug, $name, null, null);
            }
        }
        return $out;
    }

    private static function direction(string $from, string $to): string
    {
        return version_compare($from, $to, '>') ? 'downgraded' : 'updated';
    }

    /** @return array{kind: string, type: string, slug: string, name: string, from: ?string, to: ?string, detail: ?string} */
    private static function event(string $kind, string $type, string $slug, string $name, ?string $from, ?string $to, ?string $detail = null): array
    {
        return [
            'kind'   => $kind,
            'type'   => $type,
            'slug'   => self::clip($slug),
            'name'   => self::clip($name),
            'from'   => $from === null ? null : self::clip($from),
            'to'     => $to === null ? null : self::clip($to),
            'detail' => $detail === null ? null : self::clip($detail),
        ];
    }
}
