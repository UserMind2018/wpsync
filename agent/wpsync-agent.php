<?php
/**
 * Plugin Name:       wpsync Agent
 * Description:       Signierte Schnittstelle für wpsync: pull (Live → Lokal) und push von Code im Push-Fenster.
 * Version:           0.4.1
 * Requires at least: 5.9
 * Requires PHP:      7.4
 * Author:            usermind
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 */

defined('ABSPATH') || exit;

const WPSYNC_VERSION = '0.4.1';

foreach ([
    'Signature', 'Budget', 'Excludes', 'Scope', 'FileWalker', 'SizeScan', 'SqlBuilder', 'Frames', 'Pairing',
    'Classifier', 'Anonymizer', 'Probe', 'Inventory', 'TableList', 'SecretBox', 'SecretKey', 'Store', 'WpProbe',
    'Infosheet', 'Protection', 'PushUnits', 'PushManifest', 'PushSwap', 'PushRescue', 'PushWindow', 'Push', 'Rest', 'Admin',
] as $wpsync_class) {
    require_once __DIR__ . '/src/' . $wpsync_class . '.php';
}

register_activation_hook(__FILE__, [\WpSync\Store::class, 'activate']);
register_deactivation_hook(__FILE__, static function (): void {
    \WpSync\Infosheet::unschedule();
    \WpSync\Push::unschedule();
    \WpSync\Push::uninstall(); // vor Store::uninstall(): der Name des Arbeitsordners steht in wpsync_state
    \WpSync\Store::uninstall();
});

\WpSync\Protection::register();
\WpSync\Rest::register(__DIR__);
\WpSync\Admin::register();
\WpSync\Infosheet::register();
\WpSync\Push::register(__DIR__);

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
