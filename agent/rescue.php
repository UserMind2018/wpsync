<?php
/**
 * Notfall-Rollback für wpsync push. Lädt kein WordPress und keine Datenbank, damit es auch
 * antwortet, wenn der gepushte Code einen Fatal auslöst. Kennt nur „ping“ und „rollback“ und
 * prüft dafür einen pro Push abgeleiteten Schlüssel gegen dessen Hash (src/PushRescue.php).
 * Nimmt Code und Uploads zurück, nie Inhalte: hat der Push einen DB-Anteil, meldet die Antwort
 * warnings: ["content_not_rolled_back"] (Spec Content-Push §7.6).
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
    // z. B. rescue.json nicht schreibbar: als JSON antworten statt mit leerem 500.
    list($wpsync_status, $wpsync_body) = [500, ['ok' => false, 'error' => 'rescue failed']];
}
http_response_code($wpsync_status);
echo json_encode($wpsync_body);
