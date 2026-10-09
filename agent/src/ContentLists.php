<?php
namespace WpSync;

defined('ABSPATH') || exit;

/**
 * Listen des Agents für den Inhalts-Push (Studio-Spec §5, Spec Content-Push §11): was ein Paket
 * schreiben darf. Die Sperrliste gilt auch dann, wenn ein Muster der Whitelist passt. Die Listen
 * im Paket sind nur ein Abgleich (VERSION), nie eine Erweiterung der Rechte; ein Projekt kann
 * allein Beitragstypen, Taxonomien und Ausnahmen von der Teilstring-Sperre für Meta-Schlüssel
 * mitbringen (§5.4).
 */
final class ContentLists
{
    public const VERSION = 1;

    public const POST_TYPES = [
        'page', 'post', 'attachment', 'nav_menu_item', 'wp_block', 'wp_template', 'wp_template_part',
        'wp_global_styles', 'wp_navigation', 'custom_css',
        'elementor_library',
        'acf-field-group', 'acf-field', 'acf-post-type', 'acf-taxonomy', 'acf-ui-options-page',
    ];

    public const TAXONOMIES = [
        'nav_menu', 'category', 'post_tag', 'wp_theme', 'wp_template_part_area',
        'elementor_library_type', 'elementor_library_category',
    ];

    public const OPTIONS = [
        'page_on_front', 'page_for_posts', 'show_on_front', 'blogname', 'blogdescription', 'sticky_posts', 'site_icon',
        'elementor_active_kit', 'elementor_pro_theme_builder_conditions', 'elementor_cpt_support',
        'elementor_disable_color_schemes', 'elementor_disable_typography_schemes',
        'wpseo_titles', 'wpseo_social', 'rank-math-options-titles',
    ];
    /** Dazu theme_mods_<aktives Theme>. */
    public const OPTION_PREFIXES = ['elementor_experiment-', 'options_', '_options_'];

    public const BLOCKED_META = [
        '_edit_lock', '_edit_last', '_elementor_css', '_elementor_element_cache', '_elementor_page_assets',
        '_wp_old_slug', '_wp_old_date', '_encloseme', '_pingme',
    ];
    public const BLOCKED_META_PREFIXES = ['_elementor_screenshot', '_wp_trash_meta_', '_yoast_indexnow_'];
    /** Teilstrings, gross/klein egal; nur hiervon kann ein Projekt einzelne Schlüssel ausnehmen (W11). */
    public const BLOCKED_META_WORDS = ['license', 'api_key', 'token', 'secret', 'password'];

    public const BLOCKED_OPTIONS = [
        'siteurl', 'home', 'active_plugins', 'cron', 'rewrite_rules', 'elementor_pro_license_key',
        'admin_email', 'new_admin_email',
    ];
    public const BLOCKED_OPTION_PREFIXES = ['_transient_', '_site_transient_', 'mailserver_', 'elementor_css_', 'wpsync_'];
    public const BLOCKED_OPTION_WORDS = ['license', 'key', 'secret', 'token', 'password'];

    private const NO_EXTENSIONS = ['post_types' => [], 'taxonomies' => [], 'meta_exceptions' => []];

    /** @param array{post_types: list<string>, taxonomies: list<string>, meta_exceptions: list<string>}|array{} $ext */
    public static function postType(string $type, array $ext = []): bool
    {
        return in_array($type, self::POST_TYPES, true) || in_array($type, $ext['post_types'] ?? [], true);
    }

    /** @param array<string, list<string>> $ext */
    public static function taxonomy(string $taxonomy, array $ext = []): bool
    {
        return in_array($taxonomy, self::TAXONOMIES, true) || in_array($taxonomy, $ext['taxonomies'] ?? [], true);
    }

    /**
     * Darf ein Meta-Schlüssel an einem erlaubten Beitrag (oder Term) ins Paket? Alle ausser der
     * Sperrliste – und ausser denen, die der Pull pseudonymisiert (D12).
     *
     * @param array<string, list<string>> $ext
     */
    public static function metaKey(string $key, array $ext = []): bool
    {
        if ($key === '' || in_array($key, self::BLOCKED_META, true) || in_array($key, Anonymizer::metaKeys('postmeta'), true)) {
            return false;
        }
        foreach (self::BLOCKED_META_PREFIXES as $prefix) {
            if (strpos($key, $prefix) === 0) {
                return false;
            }
        }
        if (in_array($key, $ext['meta_exceptions'] ?? [], true)) {
            return true;
        }
        return !self::hasWord($key, self::BLOCKED_META_WORDS);
    }

    /**
     * @param string $prefix     Tabellen-Präfix des Ziels (für <prefix>user_roles)
     * @param string $stylesheet aktives Theme des Ziels (für theme_mods_<theme>)
     */
    public static function option(string $name, string $prefix, string $stylesheet): bool
    {
        if (in_array($name, self::BLOCKED_OPTIONS, true) || $name === $prefix . 'user_roles' || self::hasWord($name, self::BLOCKED_OPTION_WORDS)) {
            return false;
        }
        foreach (self::BLOCKED_OPTION_PREFIXES as $blocked) {
            if (strpos($name, $blocked) === 0) {
                return false;
            }
        }
        if (preg_match('/^elementor_.*_cache/', $name) === 1) {
            return false;
        }
        if (in_array($name, self::OPTIONS, true) || ($stylesheet !== '' && $name === 'theme_mods_' . $stylesheet)) {
            return true;
        }
        foreach (self::OPTION_PREFIXES as $allowed) {
            if (strpos($name, $allowed) === 0 && strlen($name) > strlen($allowed)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Projekt-Erweiterungen aus dem Paketkopf, geprüft. Optionen und Tabellen lassen sich nicht
     * freigeben, Beitragstypen, die der Pull pseudonymisiert, auch nicht.
     *
     * @param mixed $raw
     * @return array{post_types: list<string>, taxonomies: list<string>, meta_exceptions: list<string>}|null null: ungültig
     */
    public static function extensions($raw): ?array
    {
        if ($raw === null || $raw === []) {
            return self::NO_EXTENSIONS;
        }
        if (!is_array($raw) || array_diff(array_keys($raw), array_keys(self::NO_EXTENSIONS)) !== []) {
            return null;
        }
        $out     = self::NO_EXTENSIONS;
        $pattern = ['post_types' => '/^[a-z0-9_-]{1,20}\z/', 'taxonomies' => '/^[a-z0-9_-]{1,32}\z/', 'meta_exceptions' => '/^[A-Za-z0-9_-]{1,255}\z/'];
        foreach ($pattern as $field => $regex) {
            $list = $raw[$field] ?? [];
            if (!is_array($list) || count($list) > 100) {
                return null;
            }
            foreach ($list as $name) {
                if (!is_string($name) || preg_match($regex, $name) !== 1) {
                    return null;
                }
                $out[$field][] = $name;
            }
        }
        if (array_intersect($out['post_types'], Anonymizer::postTypes()) !== []) {
            return null;
        }
        return $out;
    }

    /** @return array<string, mixed> die Listen als Daten – für den Manifest-Kopf */
    public static function export(): array
    {
        return [
            'version'                 => self::VERSION,
            'post_types'              => self::POST_TYPES,
            'taxonomies'              => self::TAXONOMIES,
            'options'                 => self::OPTIONS,
            'option_prefixes'         => array_merge(self::OPTION_PREFIXES, ['theme_mods_<stylesheet>']),
            'blocked_meta'            => array_values(array_unique(array_merge(self::BLOCKED_META, Anonymizer::metaKeys('postmeta')))),
            'blocked_meta_prefixes'   => self::BLOCKED_META_PREFIXES,
            'blocked_meta_words'      => self::BLOCKED_META_WORDS,
            'blocked_options'         => array_merge(self::BLOCKED_OPTIONS, ['<prefix>user_roles']),
            'blocked_option_prefixes' => self::BLOCKED_OPTION_PREFIXES,
            'blocked_option_words'    => self::BLOCKED_OPTION_WORDS,
            'blocked_option_patterns' => ['^elementor_.*_cache'],
        ];
    }

    /** @param list<string> $words */
    private static function hasWord(string $name, array $words): bool
    {
        $lower = strtolower($name);
        foreach ($words as $word) {
            if (strpos($lower, $word) !== false) {
                return true;
            }
        }
        return false;
    }
}
