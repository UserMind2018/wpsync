<?php
/**
 * Plugin Name:       wpsync Agent
 * Description:       Signierte Schnittstelle für wpsync: pull (Live → Lokal), push von Code im Push-Fenster und Staging-Kopie auf dem Server.
 * Version:           0.6.0
 * Requires at least: 5.9
 * Requires PHP:      7.4
 * Author:            usermind
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 */

defined('ABSPATH') || exit;

// Ein Agent läuft nie in einer Staging-Kopie (Spec 2b 5.10) – dort gäbe es sonst einen Weg zurück nach Live.
// Der Wert spielt keine Rolle, und es zählt auch dann, wenn active_plugins den Agent doch enthält.
if (defined('WPSYNC_STAGING')) {
    return;
}

const WPSYNC_VERSION = '0.6.0';

foreach ([
    'Signature', 'Budget', 'Excludes', 'Scope', 'FileWalker', 'SizeScan', 'SqlBuilder', 'Frames', 'Pairing',
    'Classifier', 'Anonymizer', 'Probe', 'Inventory', 'TableList', 'SecretBox', 'SecretKey', 'Store', 'WpProbe',
    'Infosheet', 'Protection', 'PushUnits', 'PushManifest', 'PushUploads', 'PushSwap', 'PushRescue', 'PushRescueStub', 'PushWindow', 'Push',
    'StagingException', 'StagingGuard', 'SerializedWalker', 'StagingReplace', 'ContentOrigin', 'StagingConfig', 'StagingAccess', 'StagingHosts',
    'StagingFiles', 'StagingDb', 'Staging', 'Rest', 'Admin',
] as $wpsync_class) {
    require_once __DIR__ . '/src/' . $wpsync_class . '.php';
}

register_activation_hook(__FILE__, [\WpSync\Store::class, 'activate']);
register_deactivation_hook(__FILE__, static function (): void {
    \WpSync\Infosheet::unschedule();
    \WpSync\Push::unschedule();
    \WpSync\Staging::unschedule();
    // Reihenfolge: die Kopie zuerst (Spec 2b 5.9) – sie räumt ihre Pushes über Push::dropTarget() ab und
    // braucht dafür wpsync_pushes und wpsync_state. Dann Push (Name des Arbeitsordners steht in
    // wpsync_state), zuletzt die Tabellen selbst.
    \WpSync\Staging::uninstall();
    \WpSync\Push::uninstall();
    \WpSync\Store::uninstall();
});

\WpSync\Protection::register();
\WpSync\Rest::register(__DIR__);
\WpSync\Admin::register();
\WpSync\Infosheet::register();
\WpSync\Push::register(__DIR__);
\WpSync\Staging::register(__DIR__);

if (defined('WP_CLI') && WP_CLI) {
    \WP_CLI::add_command('wpsync pair-code', static function (): void {
        \WP_CLI::line(\WpSync\Store::issuePairingCode());
    });
    \WP_CLI::add_command('wpsync infosheet', static function (): void {
        \WpSync\Infosheet::begin();
        do {
            $status = \WpSync\Infosheet::run(\WpSync\Infosheet::SLICE);
            \WP_CLI::line((string) wp_json_encode($status));
        } while ($status['running']);
    });
}
