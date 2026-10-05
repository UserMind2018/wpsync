<?php
/**
 * Notfall-Rollback für wpsync push. Lädt kein WordPress und keine Datenbank, damit es auch
 * antwortet, wenn der gepushte Code einen Fatal auslöst. Kennt nur „ping“ und „rollback“ und
 * prüft dafür einen pro Push abgeleiteten Schlüssel gegen dessen Hash (src/PushRescue.php).
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

// Standardlayout wp-content/plugins/wpsync-agent/ – /push/begin lehnt jedes andere ab.
list($wpsync_status, $wpsync_body) = \WpSync\PushRescue::handle(dirname(__DIR__, 2), $_POST, time());
http_response_code($wpsync_status);
echo json_encode($wpsync_body);
