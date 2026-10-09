<?php
/**
 * Notfall-Rollback für wpsync push. Lädt kein WordPress, damit es auch antwortet, wenn der gepushte
 * Code oder Inhalt einen Fatal auslöst. Kennt „ping“, „rollback“ und „cache“ und prüft dafür einen
 * pro Push abgeleiteten Schlüssel gegen dessen Hash (src/PushRescue.php).
 *
 * rollback nimmt in der Reihenfolge DB → Code → Uploads zurück (Spec Content-Push P3 §4.1). Die
 * Inhalte nur, wenn der Aufrufer content=1 schickt – über eine eigene Datenbankverbindung aus dem
 * versiegelten Umschlag des Pushs, den nur dieser Schlüssel öffnet; wp-config.php wird dafür nie
 * gelesen. Bis zur bestandenen Schlüsselprüfung ist nichts geladen ausser PushSwap und PushRescue.
 * Bleiben die Inhalte stehen, sagt es die Antwort: warnings: ["content_not_rolled_back"].
 *
 * cache ist ein getrennter zweiter Schritt (R8): er lädt WordPress mit SHORTINIT – ohne Plugins,
 * Themes und mu-plugins – und leert den Object-Cache, der sonst den gepushten Stand weiter
 * auslieferte. Ein Fehler darin gefährdet die Rücknahme nie; sie ist dann schon abgeschlossen.
 */
define('WPSYNC_RESCUE', true);

ini_set('display_errors', '0');
header('Content-Type: application/json');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo '{"ok":false,"error":"POST only"}';
    exit;
}

require __DIR__ . '/src/PushSwap.php';
require __DIR__ . '/src/PushRescue.php';

// Standardlayout wp-content/plugins/wpsync-agent/ und wp-content direkt im Webroot – /push/begin und
// /staging/begin lehnen jedes andere ab. Staging-Pushs liegen in der Kopie (Spec 2b 5.8, V8).
try {
    list($wpsync_status, $wpsync_body) = \WpSync\PushRescue::handle(\WpSync\PushRescue::contentDirs(dirname(__DIR__, 2)), $_POST, time());
} catch (\Throwable $e) {
    // z. B. rescue.json nicht schreibbar: als JSON antworten statt mit leerem 500. Ohne Meldung –
    // sie könnte Pfade oder, bei der Datenbank, Zugangsdaten nennen.
    list($wpsync_status, $wpsync_body) = [500, ['ok' => false, 'error' => 'rescue failed']];
}

// action=cache: der Schlüssel stimmt, der Push ist ganz zurück, und der Object-Cache trägt noch den
// gepushten Stand. WordPress wird hier geladen, im globalen Geltungsbereich – wie in index.php:
// wp-config.php setzt Variablen (etwa $memcached_servers, $redis_server), die ein
// Object-Cache-Drop-in als globale liest. In einer Funktion geladen sähe es sie nicht.
if ($wpsync_status === \WpSync\PushRescue::FLUSH) {
    $wpsync_flush = $wpsync_body;
    $wpsync_done  = false;
    $wpsync_ok    = false;
    // WordPress beendet sich an mehreren Stellen selbst (Wartungsmodus, keine Datenbank, Fatal im
    // Drop-in): dann antwortet diese Funktion – mit JSON statt einer HTML-Seite – und beendet den
    // Request, bevor die Fehlerseite von WordPress folgt.
    register_shutdown_function(static function () use (&$wpsync_done): void {
        if ($wpsync_done) {
            return;
        }
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
            header('Cache-Control: no-store');
        }
        echo '{"ok":false,"error":"cache failed"}';
        exit;
    });
    ob_start(); // was WordPress oder ein Drop-in ausgibt, verlässt den Server nicht
    try {
        $wpsync_load = dirname(__DIR__, 3) . '/wp-load.php';
        if (!defined('SHORTINIT') && is_file($wpsync_load)) {
            define('SHORTINIT', true);
            require $wpsync_load;
            $wpsync_ok = function_exists('wp_cache_flush') && wp_cache_flush() !== false;
        }
    } catch (\Throwable $wpsync_error) {
        $wpsync_ok = false;
    }
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    $wpsync_done = true;
    ini_set('display_errors', '0'); // WordPress stellt es mit WP_DEBUG_DISPLAY um
    if (!headers_sent()) {
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex');
    }
    try {
        list($wpsync_status, $wpsync_body) = \WpSync\PushRescue::flushed((string) $wpsync_flush['work'], (string) $wpsync_flush['push_id'], $wpsync_ok);
    } catch (\Throwable $e) {
        list($wpsync_status, $wpsync_body) = [500, ['ok' => false, 'error' => 'cache failed']];
    }
}
http_response_code($wpsync_status);
echo json_encode($wpsync_body);
