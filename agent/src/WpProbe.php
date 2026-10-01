<?php
namespace WpSync;

defined('ABSPATH') || exit;

/** Probe über $wpdb, get_plugins() und wp_get_themes(). */
final class WpProbe implements Probe
{
    public function env(): array
    {
        return Rest::env();
    }

    public function prefix(): string
    {
        global $wpdb;
        return (string) $wpdb->base_prefix;
    }

    public function plugins(): array
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $active = (array) get_option('active_plugins', []);
        $out    = [];
        foreach (get_plugins() as $file => $data) {
            $out[] = [
                'slug'    => Inventory::pluginSlug((string) $file),
                'name'    => (string) $data['Name'],
                'version' => (string) $data['Version'],
                'active'  => in_array($file, $active, true),
            ];
        }
        return $out;
    }

    public function themes(): array
    {
        $active = [get_stylesheet(), get_template()];
        $out    = [];
        foreach (wp_get_themes() as $slug => $theme) {
            $out[] = [
                'slug'    => (string) $slug,
                'name'    => (string) $theme->get('Name'),
                'version' => (string) $theme->get('Version'),
                'active'  => in_array((string) $slug, $active, true),
            ];
        }
        return $out;
    }

    public function tables(): array
    {
        global $wpdb;
        $rows = (array) $wpdb->get_results($wpdb->prepare('SHOW TABLE STATUS LIKE %s', $wpdb->esc_like($wpdb->base_prefix) . '%'), ARRAY_A);
        $allowed = array_flip(Store::dataTables());
        $out     = [];
        foreach ($rows as $row) {
            if (!isset($allowed[$row['Name']])) {
                continue; // Views, eigene Tabellen, fremde Installationen
            }
            $out[] = [
                'name'  => (string) $row['Name'],
                'rows'  => (int) $row['Rows'],
                'bytes' => (int) $row['Data_length'] + (int) $row['Index_length'],
            ];
        }
        return $out;
    }

    public function maxIds(): array
    {
        global $wpdb;
        return [
            'posts'    => (int) $wpdb->get_var("SELECT MAX(ID) FROM `{$wpdb->posts}`"),
            'postmeta' => (int) $wpdb->get_var("SELECT MAX(meta_id) FROM `{$wpdb->postmeta}`"),
        ];
    }

    public function postStats(int $from, int $to): array
    {
        global $wpdb;
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT post_type AS t, COUNT(*) AS c, SUM(LENGTH(post_content) + LENGTH(post_title) + LENGTH(post_excerpt) + 256) AS b
             FROM `{$wpdb->posts}` WHERE ID > %d AND ID <= %d GROUP BY post_type",
            $from,
            $to
        ), ARRAY_A);
        return self::stats($rows);
    }

    public function postmetaStats(int $from, int $to): array
    {
        global $wpdb;
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT p.post_type AS t, COUNT(*) AS c, SUM(LENGTH(m.meta_value) + LENGTH(m.meta_key) + 32) AS b
             FROM `{$wpdb->postmeta}` m LEFT JOIN `{$wpdb->posts}` p ON p.ID = m.post_id
             WHERE m.meta_id > %d AND m.meta_id <= %d GROUP BY p.post_type",
            $from,
            $to
        ), ARRAY_A);
        return self::stats($rows);
    }

    public function dropIns(): array
    {
        $out = [];
        foreach (['advanced-cache.php', 'object-cache.php', 'maintenance.php'] as $file) {
            if (file_exists(WP_CONTENT_DIR . '/' . $file)) {
                $out[] = 'wp-content/' . $file;
            }
        }
        return $out;
    }

    /**
     * @param list<array{t: string|null, c: string, b: string|null}> $rows
     * @return array<string, array{count: int, bytes: int}>
     */
    private static function stats(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['t']] = ['count' => (int) $row['c'], 'bytes' => (int) $row['b']];
        }
        return $out;
    }
}
