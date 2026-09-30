<?php
/**
 * Plugin Name:       wpsync Agent
 * Description:       Signierte, lesende Schnittstelle für wpsync pull (Live → Lokal).
 * Version:           0.2.0
 * Requires at least: 5.9
 * Requires PHP:      7.4
 * Author:            usermind
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 */

defined('ABSPATH') || exit;

const WPSYNC_VERSION = '0.2.0';

foreach ([
    'Signature', 'Budget', 'Excludes', 'Scope', 'FileWalker', 'SizeScan', 'SqlBuilder', 'Frames', 'Pairing',
    'Classifier', 'Probe', 'Inventory', 'Store', 'WpProbe', 'Infosheet', 'Protection', 'Rest', 'Admin',
] as $wpsync_class) {
    require_once __DIR__ . '/src/' . $wpsync_class . '.php';
}

register_activation_hook(__FILE__, [\WpSync\Store::class, 'install']);
register_deactivation_hook(__FILE__, static function (): void {
    \WpSync\Infosheet::unschedule();
    \WpSync\Store::uninstall();
});

\WpSync\Protection::register();
\WpSync\Rest::register(__DIR__);
\WpSync\Admin::register();
\WpSync\Infosheet::register();

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
