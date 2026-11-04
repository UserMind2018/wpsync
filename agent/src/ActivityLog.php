<?php
namespace WpSync;

defined('ABSPATH') || exit;

/**
 * Protokoll des Nachtberichts: eigene Tabelle wpsync_activity. Jeder Wert geht
 * durch $wpdb->prepare(); Art, Typ und Auslöser nur aus den festen Listen von ActivityDiff.
 */
final class ActivityLog
{
    /** Aufbewahrung. */
    public const KEEP_DAYS = 90;
    /** Höchstens so viele Ereignisse je Abfrage. */
    public const MAX_LIMIT = 500;

    private const COLUMNS = 'id, at, kind, type, slug, name, from_version, to_version, source, user_id, detail';

    public static function table(): string
    {
        return Store::table('activity');
    }

    /**
     * @param list<array{kind: string, type: string, slug: string, name: string, from: ?string, to: ?string, detail: ?string}> $events
     * @param string $detail für Ereignisse ohne eigenes detail (z. B. "push:<id>")
     * @return int geschriebene Zeilen
     */
    public static function add(array $events, int $at, string $source, ?int $user, string $detail): int
    {
        global $wpdb;
        if (!in_array($source, ActivityDiff::SOURCES, true)) {
            return 0;
        }
        $written = 0;
        foreach ($events as $e) {
            if (!in_array($e['kind'], ActivityDiff::KINDS, true) || !in_array($e['type'], ActivityDiff::TYPES, true)) {
                continue;
            }
            $text = $e['detail'] ?? null;
            if ($text === null && $detail !== '') {
                $text = $detail;
            }
            $sql = 'INSERT INTO `' . self::table() . '` (at, kind, type, slug, name, from_version, to_version, source, user_id, detail) VALUES ('
                . $wpdb->prepare('%d, %s, %s, %s, %s', $at, $e['kind'], $e['type'], ActivityDiff::clip((string) $e['slug']), ActivityDiff::clip((string) $e['name'])) . ', '
                . self::nullable($e['from']) . ', '
                . self::nullable($e['to']) . ', '
                . $wpdb->prepare('%s', $source) . ', '
                . ($user === null ? 'NULL' : $wpdb->prepare('%d', $user)) . ', '
                . self::nullable($text) . ')';
            if ($wpdb->query($sql) !== false) {
                $written++;
            }
        }
        return $written;
    }

    /**
     * @return list<array{at: int, kind: string, type: string, slug: string, name: string, from: ?string, to: ?string, source: string, user_id: ?int, detail: ?string}>
     */
    public static function between(int $since, int $until, int $limit): array
    {
        global $wpdb;
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            'SELECT ' . self::COLUMNS . ' FROM `' . self::table() . '` WHERE at >= %d AND at <= %d ORDER BY at ASC, id ASC LIMIT %d',
            $since,
            $until,
            $limit
        ), ARRAY_A);
        $out = [];
        foreach ($rows as $row) {
            $row   = (array) $row;
            $out[] = [
                'at'      => (int) $row['at'],
                'kind'    => (string) $row['kind'],
                'type'    => (string) $row['type'],
                'slug'    => (string) $row['slug'],
                'name'    => (string) $row['name'],
                'from'    => $row['from_version'] === null ? null : (string) $row['from_version'],
                'to'      => $row['to_version'] === null ? null : (string) $row['to_version'],
                'source'  => (string) $row['source'],
                'user_id' => $row['user_id'] === null ? null : (int) $row['user_id'],
                'detail'  => $row['detail'] === null ? null : (string) $row['detail'],
            ];
        }
        return $out;
    }

    public static function prune(int $before): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare('DELETE FROM `' . self::table() . '` WHERE at < %d', $before));
    }

    private static function nullable(?string $value): string
    {
        global $wpdb;
        return $value === null ? 'NULL' : (string) $wpdb->prepare('%s', ActivityDiff::clip($value));
    }
}
