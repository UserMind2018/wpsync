<?php
/**
 * Stellt rescue.php einen POST-Request, wo es kein php-cgi gibt – für RescueScriptTest, in einem
 * eigenen PHP-Prozess: kein ABSPATH, kein Autoloader, kein WordPress. Aufruf:
 *   php rescue-request.php <pfad/zu/rescue.php> <POST als JSON> [<Methode>]
 * Auf stdout steht die Antwort; der HTTP-Status folgt auf stderr – ausser rescue.php beendet den
 * Request selbst in seiner Shutdown-Funktion (dann läuft keine weitere mehr).
 */
$_SERVER['REQUEST_METHOD'] = $argv[3] ?? 'POST';
$_POST                     = (array) json_decode((string) ($argv[2] ?? '[]'), true);
register_shutdown_function(static function (): void {
    // hinten anstellen: erst nach allem, was rescue.php selbst für das Ende registriert
    register_shutdown_function(static function (): void {
        fwrite(STDERR, 'status=' . (int) http_response_code());
    });
});
require $argv[1];
