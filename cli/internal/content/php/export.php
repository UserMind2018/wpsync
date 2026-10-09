<?php
namespace WpSync;

defined('ABSPATH') || exit;

// Treiber von `wpsync content export` (Spec Content-Push §4.4), läuft per `wp eval-file -` in der
// lokalen Site. $args: [lokale URL, Inhaltstabellen ohne Präfix kommagetrennt oder "all"].
// Jede Ausgabezeile ist ein Datensatz {t, k, h[, row]}; die letzte nennt ihre Zahl.
global $wpdb;
$wpsync_tables = ($args[1] ?? 'all') === 'all' ? null : explode(',', (string) $args[1]);
$wpsync_reader = new ContentReader($wpdb, (string) $wpdb->prefix, new ContentOrigin((string) ($args[0] ?? '')));
$wpsync_rows   = 0;
$wpsync_cursor = null;
do {
    $wpsync_cursor = $wpsync_reader->read($wpsync_cursor, microtime(true) + 5.0, true, static function (array $record) use (&$wpsync_rows): void {
        echo json_encode($record, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), "\n";
        $wpsync_rows++;
    }, $wpsync_tables);
} while ($wpsync_cursor !== null);
echo json_encode(['end' => true, 'rows' => $wpsync_rows]), "\n";
