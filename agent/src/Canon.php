<?php
namespace WpSync;

defined('ABSPATH') || exit;

/**
 * Kanonische Form einer Inhaltszeile und ihr Fingerabdruck (Spec Content-Push §6.1,
 * canon_version 1): h = sha256(Tabelle "\n" Schlüssel "\n" body) über die normalisierten Werte.
 * Ein Wert ist N (NULL) oder S<Bytezahl>:<Bytes>, so wie die Datenbank ihn liefert.
 */
final class Canon
{
    public const VERSION = 1;

    /** Die sieben Inhaltstabellen ohne Präfix, in der Reihenfolge von Manifest und Export. */
    public const TABLES = ['posts', 'postmeta', 'terms', 'term_taxonomy', 'term_relationships', 'termmeta', 'options'];

    /** posts: nie im Abdruck sind post_author, post_modified(_gmt), guid, to_ping, pinged, comment_count. */
    public const POSTS = [
        'post_date', 'post_date_gmt', 'post_content', 'post_title', 'post_excerpt', 'post_status',
        'comment_status', 'ping_status', 'post_password', 'post_name', 'post_parent', 'menu_order',
        'post_type', 'post_mime_type', 'post_content_filtered',
    ];
    public const TERMS = ['name', 'slug', 'term_group'];
    /** term_taxonomy ohne count. */
    public const TAXONOMY = ['term_id', 'taxonomy', 'description', 'parent'];

    public static function value(?string $value): string
    {
        return $value === null ? 'N' : 'S' . strlen($value) . ':' . $value;
    }

    /**
     * @param list<string>               $names Spalten in der Reihenfolge des Abdrucks
     * @param array<string, string|null> $row
     */
    public static function columns(array $names, array $row): string
    {
        $out = '';
        foreach ($names as $name) {
            $value = $row[$name] ?? null;
            $out  .= $name . '=' . self::value($value === null ? null : (string) $value) . "\n";
        }
        return $out;
    }

    /**
     * Sortierte Multimenge: bytegenau sortiert, Duplikate bleiben, je Wert eine Zeile.
     *
     * @param list<string|null> $values
     */
    public static function set(array $values): string
    {
        $lines = [];
        foreach ($values as $value) {
            $lines[] = self::value($value === null ? null : (string) $value);
        }
        sort($lines, SORT_STRING);
        return $lines === [] ? '' : implode("\n", $lines) . "\n";
    }

    public static function hash(string $table, string $key, string $body): string
    {
        return hash('sha256', $table . "\n" . $key . "\n" . $body);
    }

    /** Schlüssel eines Meta-Paars (object_id, meta_key) und einer Zuordnung (object_id, taxonomy). */
    public static function pairKey(string $objectId, string $name): string
    {
        return $objectId . "\0" . $name;
    }
}
