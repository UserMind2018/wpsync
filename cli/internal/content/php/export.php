<?php
namespace WpSync;

defined('ABSPATH') || exit;

// Treiber von `wpsync content export` (Spec Content-Push §4.4), läuft per `wp eval-file -` in der
// lokalen Site. $args: [lokale URL, Inhaltstabellen ohne Präfix kommagetrennt oder "all"].
// Jede Ausgabezeile ist ein Datensatz {t, k, h[, row], p[, why]}; die letzte nennt ihre Zahl.
// p: laut ContentLists pushbar (ohne Projekt-Erweiterungen); why: der Grund, wenn nicht.
global $wpdb;
$wpsync_tables = ($args[1] ?? 'all') === 'all' ? null : explode(',', (string) $args[1]);
$wpsync_prefix = (string) $wpdb->prefix;
$wpsync_quote  = static function (string $table) use ($wpsync_prefix): string {
    return '`' . str_replace('`', '``', $wpsync_prefix . $table) . '`';
};
// $wpdb leert last_error vor jeder Abfrage – deshalb nach jeder einzelnen prüfen.
$wpsync_check = static function () use ($wpdb): void {
    if ((string) $wpdb->last_error !== '') {
        echo "wpsync: reading the maps for the rating failed\n";
        exit(1);
    }
};

// Karten für die Bewertung: Beitrag → Typ, Term → Taxonomien, term_taxonomy_id → Taxonomie.
// Beitragstypen als geteilte Zeichenketten, damit die Karte auch bei 100.000 Beiträgen klein bleibt.
$wpsync_types = [];
$wpsync_names = [];
$wpsync_after = 0;
do {
    $wpsync_page = (array) $wpdb->get_results($wpdb->prepare('SELECT `ID`, `post_type` FROM ' . $wpsync_quote('posts') . ' WHERE `ID` > %d ORDER BY `ID` LIMIT 5000', $wpsync_after), ARRAY_N);
    $wpsync_check();
    foreach ($wpsync_page as $wpsync_r) {
        $wpsync_type  = (string) $wpsync_r[1];
        $wpsync_after = (int) $wpsync_r[0];
        $wpsync_names[$wpsync_type] = $wpsync_names[$wpsync_type] ?? $wpsync_type;
        $wpsync_types[$wpsync_after] = $wpsync_names[$wpsync_type];
    }
} while (count($wpsync_page) === 5000);
$wpsync_term_tax = [];
$wpsync_tt       = [];
$wpsync_page     = (array) $wpdb->get_results('SELECT `term_taxonomy_id`, `term_id`, `taxonomy` FROM ' . $wpsync_quote('term_taxonomy'), ARRAY_N);
$wpsync_check();
foreach ($wpsync_page as $wpsync_r) {
    $wpsync_tt[(int) $wpsync_r[0]]         = (string) $wpsync_r[2];
    $wpsync_term_tax[(int) $wpsync_r[1]][] = (string) $wpsync_r[2];
}
unset($wpsync_page, $wpsync_names);
// Direkt aus der Tabelle: --skip-themes lässt get_option('stylesheet') leer zurückkommen.
$wpsync_sheet = (string) $wpdb->get_var($wpdb->prepare('SELECT `option_value` FROM ' . $wpsync_quote('options') . ' WHERE `option_name` = %s', 'stylesheet'));
$wpsync_check();

$wpsync_reader = new ContentReader($wpdb, $wpsync_prefix, new ContentOrigin((string) ($args[0] ?? '')));
$wpsync_rows   = 0;
$wpsync_emit   = static function (array $record) use (&$wpsync_rows, $wpsync_types, $wpsync_term_tax, $wpsync_tt, $wpsync_prefix, $wpsync_sheet): void {
    $why = $record['why'] ?? null; // Grund des Lesers: unnormalizable, key_encoding
    if ($record['h'] !== null) {
        $id  = (int) $record['k']; // "219" wie "219\0_elementor_data"; bei Optionen ungenutzt
        $ctx = ['prefix' => $wpsync_prefix, 'stylesheet' => $wpsync_sheet];
        switch ($record['t']) {
            case 'posts':
            case 'postmeta':
            case 'term_relationships':
                $ctx['post_type'] = $wpsync_types[$id] ?? null;
                break;
            case 'terms':
            case 'termmeta':
                $ctx['taxonomies'] = $wpsync_term_tax[$id] ?? [];
                break;
            case 'term_taxonomy':
                $ctx['taxonomies'] = isset($wpsync_tt[$id]) ? [$wpsync_tt[$id]] : [];
                break;
        }
        $why = ContentLists::blocked((string) $record['t'], (string) $record['k'], $ctx);
    }
    unset($record['why']);
    $record['p'] = $why === null;
    if ($why !== null) {
        $record['why'] = $why;
    }
    echo json_encode($record, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), "\n";
    $wpsync_rows++;
};
$wpsync_cursor = null;
do {
    $wpsync_cursor = $wpsync_reader->read($wpsync_cursor, microtime(true) + 5.0, true, $wpsync_emit, $wpsync_tables);
} while ($wpsync_cursor !== null);
echo json_encode(['end' => true, 'rows' => $wpsync_rows]), "\n";
