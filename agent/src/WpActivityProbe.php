<?php
namespace WpSync;

defined('ABSPATH') || exit;

/** ActivityProbe über WordPress: Plugin- und Theme-Köpfe frisch von der Platte, Core aus version.php. */
final class WpActivityProbe implements ActivityProbe
{
    public function snapshot(): array
    {
        if (!function_exists('get_plugins')) {
            $file = ABSPATH . 'wp-admin/includes/plugin.php';
            if (!is_readable($file)) {
                throw new \RuntimeException('wp-admin/includes/plugin.php not readable');
            }
            require_once $file;
        }
        // Nach einem Update im selben Request stünde sonst die alte Version im Cache.
        if (function_exists('wp_clean_plugins_cache')) {
            wp_clean_plugins_cache(false);
        }
        if (function_exists('wp_clean_themes_cache')) {
            wp_clean_themes_cache(false);
        }
        $probe = new WpProbe();
        return ActivityDiff::snapshot(self::coreVersion(), $probe->plugins(), $probe->themes(), (string) get_stylesheet());
    }

    /**
     * Die Version aus der Datei: nach einem Core-Update im selben Request trägt $wp_version noch die alte.
     */
    public static function coreVersion(): string
    {
        $file   = ABSPATH . WPINC . '/version.php';
        $source = is_readable($file) ? (string) file_get_contents($file) : '';
        if (preg_match('/\$wp_version\s*=\s*\'([^\']{1,64})\'/', $source, $m) === 1) {
            return $m[1];
        }
        return (string) get_bloginfo('version');
    }

    public function userLogin(int $id): ?string
    {
        $user = get_userdata($id);
        return $user instanceof \WP_User ? (string) $user->user_login : null;
    }

    public function autoUpdates(): array
    {
        $plugins = [];
        foreach ((array) get_option('auto_update_plugins', []) as $file) {
            if (is_string($file) && $file !== '') {
                $plugins[] = ActivityDiff::clip(Inventory::pluginSlug($file));
            }
        }
        $themes = [];
        foreach ((array) get_option('auto_update_themes', []) as $slug) {
            if (is_string($slug) && $slug !== '') {
                $themes[] = ActivityDiff::clip($slug);
            }
        }
        $plugins = array_values(array_unique($plugins));
        $themes  = array_values(array_unique($themes));
        sort($plugins, SORT_STRING);
        sort($themes, SORT_STRING);
        return ['plugins' => $plugins, 'themes' => $themes];
    }
}
