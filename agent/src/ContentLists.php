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
    public const VERSION = 2;

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
    public const BLOCKED_META_WORDS = ['license', 'api_key', 'token', 'secret', 'password', 'passwd', 'credential', 'apikey', 'api-key', 'webhook', 'oauth'];
    /**
     * Kurze Wörter, die nur als ganzes Namensglied sperren (zwischen _ - . : oder am Rand): „auth“
     * trifft _auth_code, nicht author; „pass“ trifft smtp_pass, nicht passage. Für Meta und Optionen.
     */
    public const BLOCKED_SEGMENTS = ['pass', 'pwd', 'auth', 'salt', 'sk', 'private'];
    /** Meta-Schlüssel und Optionsnamen, die ein Paket nennen darf (Schutz vor Alias über die Kollation). */
    public const NAME = '/^[A-Za-z0-9_.:\-]{1,255}\z/';

    /**
     * Beitragstypen, die keine Projekt-Erweiterung freigeben kann – die Erweiterungen kommen aus
     * dem Paket selbst: Interna, Code-Träger, Shop, Formulareinträge, geplante Aktionen.
     */
    public const NEVER_POST_TYPES = [
        'revision', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_font_family', 'wp_font_face',
        'wpcode', 'elementor_snippet', 'code_snippet', 'wp_code_snippet', 'insertheadersandfooters', 'ihaf_snippet',
        'product', 'product_variation', 'shop_coupon', 'shop_subscription',
        'wpcf7_contact_form', 'wpforms', 'nf_sub', 'feedback', 'scheduled-action',
    ];
    public const NEVER_POST_TYPE_PREFIXES = ['shop_order', 'flamingo_', 'wpforms', 'wpcode'];

    public const BLOCKED_OPTIONS = [
        'siteurl', 'home', 'active_plugins', 'cron', 'rewrite_rules', 'elementor_pro_license_key',
        'admin_email', 'new_admin_email',
    ];
    public const BLOCKED_OPTION_PREFIXES = ['_transient_', '_site_transient_', 'mailserver_', 'elementor_css_', 'wpsync_'];
    public const BLOCKED_OPTION_WORDS = ['license', 'key', 'secret', 'token', 'password', 'passwd', 'credential', 'webhook', 'oauth'];

    /**
     * Objekt-ID in einem Schlüssel: dezimal, ohne Vorzeichen, führende Null und Zusatz, höchstens
     * 18 Stellen – so viel passt in jedem PHP in eine Zahl (%d). Dieselbe Regel gilt im Paket.
     */
    public const OBJECT_ID = '/^[1-9][0-9]{0,17}\z/';
    /** Tabellen, deren Schlüssel die ID ist – und die, deren Schlüssel <ID>\0<Name> ist. */
    private const ID_KEYS   = ['posts', 'terms', 'term_taxonomy'];
    private const PAIR_KEYS = ['postmeta', 'termmeta', 'term_relationships'];

    private const NO_EXTENSIONS = ['post_types' => [], 'taxonomies' => [], 'meta_exceptions' => []];

    /** @var list<string>|null */
    private static $anonymizedMeta;

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
        // Die Datenbank vergleicht Schlüssel ohne Gross/klein: gesperrt ist, was selbst oder kleingeschrieben gesperrt ist.
        $lower = strtolower($key);
        $fixed = array_merge(self::BLOCKED_META, self::anonymizedMeta());
        if ($key === '' || in_array($key, $fixed, true) || in_array($lower, array_map('strtolower', $fixed), true)) {
            return false;
        }
        foreach (self::BLOCKED_META_PREFIXES as $prefix) {
            if (strpos($lower, $prefix) === 0) {
                return false;
            }
        }
        if (in_array($key, $ext['meta_exceptions'] ?? [], true)) {
            return true;
        }
        return !self::hasWord($key, self::BLOCKED_META_WORDS) && !self::hasSegment($key);
    }

    /**
     * @param string $prefix     Tabellen-Präfix des Ziels (für <prefix>user_roles)
     * @param string $stylesheet aktives Theme des Ziels (für theme_mods_<theme>)
     */
    public static function option(string $name, string $prefix, string $stylesheet): bool
    {
        $lower = strtolower($name);
        if (in_array($lower, self::BLOCKED_OPTIONS, true) || $lower === strtolower($prefix) . 'user_roles' || self::hasWord($name, self::BLOCKED_OPTION_WORDS) || self::hasSegment($name)) {
            return false;
        }
        foreach (self::BLOCKED_OPTION_PREFIXES as $blocked) {
            if (strpos($lower, $blocked) === 0) {
                return false;
            }
        }
        if (preg_match('/^elementor_.*_cache/', $lower) === 1) {
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
     * Darf eine Zeile – Schlüssel wie in Manifest und Export – geschrieben werden? Der Aufrufer
     * löst das Objekt auf: post_type des Beitrags (posts, postmeta, term_relationships; null: es
     * gibt ihn nicht) bzw. die Taxonomien des Terms (terms, termmeta, term_taxonomy), für Optionen
     * Präfix und aktives Theme des Ziels.
     *
     * @param array{post_type?: string|null, taxonomies?: list<string>, prefix?: string, stylesheet?: string} $ctx
     * @param array<string, list<string>> $ext
     * @return string|null null: pushbar; sonst der Grund – key (die Objekt-ID im Schlüssel ist keine
     *                     Zahl ohne Zusatz oder der Trenner eines Paars fehlt), post_type, taxonomy,
     *                     meta_key (fest), meta_word (ausnehmbar, W11), option, no_object, table
     */
    public static function blocked(string $table, string $key, array $ctx, array $ext = []): ?string
    {
        $cut  = strpos($key, "\0");
        $name = $cut === false ? '' : (string) substr($key, $cut + 1); // Meta-Schlüssel bzw. Taxonomie eines Paars
        // Wer den Schlüssel später als Zahl liest, läse aus "219abc" die 219: nur die reine ID zählt.
        if (in_array($table, self::ID_KEYS, true) && preg_match(self::OBJECT_ID, $key) !== 1) {
            return 'key';
        }
        if (in_array($table, self::PAIR_KEYS, true) && ($cut === false || preg_match(self::OBJECT_ID, substr($key, 0, $cut)) !== 1)) {
            return 'key';
        }
        switch ($table) {
            case 'posts':
                return self::blockedType($ctx, $ext);
            case 'postmeta':
                return self::blockedType($ctx, $ext) ?? self::blockedMeta($name, $ext);
            case 'terms':
            case 'term_taxonomy':
                return self::blockedTaxonomies($ctx, $ext);
            case 'termmeta':
                return self::blockedTaxonomies($ctx, $ext) ?? self::blockedMeta($name, $ext);
            case 'term_relationships':
                return self::taxonomy($name, $ext) ? self::blockedType($ctx, $ext) : 'taxonomy';
            case 'options':
                return self::option($key, (string) ($ctx['prefix'] ?? ''), (string) ($ctx['stylesheet'] ?? '')) ? null : 'option';
        }
        return 'table';
    }

    /**
     * @param array<string, mixed>        $ctx
     * @param array<string, list<string>> $ext
     */
    private static function blockedType(array $ctx, array $ext): ?string
    {
        if (!isset($ctx['post_type'])) {
            return 'no_object';
        }
        return self::postType((string) $ctx['post_type'], $ext) ? null : 'post_type';
    }

    /**
     * @param array<string, mixed>        $ctx
     * @param array<string, list<string>> $ext
     */
    private static function blockedTaxonomies(array $ctx, array $ext): ?string
    {
        $taxonomies = (array) ($ctx['taxonomies'] ?? []);
        if ($taxonomies === []) {
            return 'no_object';
        }
        foreach ($taxonomies as $taxonomy) {
            if (!self::taxonomy((string) $taxonomy, $ext)) {
                return 'taxonomy';
            }
        }
        return null;
    }

    /** @param array<string, list<string>> $ext */
    private static function blockedMeta(string $key, array $ext): ?string
    {
        if (self::metaKey($key, $ext)) {
            return null;
        }
        // Liesse eine Ausnahme den Schlüssel zu, sperrt ihn nur die Teilstring-Liste.
        return self::metaKey($key, ['meta_exceptions' => [$key]]) ? 'meta_word' : 'meta_key';
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
        foreach ($out['post_types'] as $type) {
            if (self::neverPostType($type)) {
                return null;
            }
        }
        return $out;
    }

    /** Ein Beitragstyp, den keine Erweiterung freigibt: was der Pull pseudonymisiert und die feste Liste. */
    public static function neverPostType(string $type): bool
    {
        if (in_array($type, Anonymizer::postTypes(), true) || in_array($type, self::NEVER_POST_TYPES, true)) {
            return true;
        }
        foreach (self::NEVER_POST_TYPE_PREFIXES as $prefix) {
            if (strpos($type, $prefix) === 0) {
                return true;
            }
        }
        return false;
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
            'blocked_meta'            => array_values(array_unique(array_merge(self::BLOCKED_META, self::anonymizedMeta()))),
            'blocked_meta_prefixes'   => self::BLOCKED_META_PREFIXES,
            'blocked_meta_words'      => self::BLOCKED_META_WORDS,
            'blocked_segments'        => self::BLOCKED_SEGMENTS,
            'never_post_types'        => self::NEVER_POST_TYPES,
            'never_post_type_prefixes' => self::NEVER_POST_TYPE_PREFIXES,
            'blocked_options'         => array_merge(self::BLOCKED_OPTIONS, ['<prefix>user_roles']),
            'blocked_option_prefixes' => self::BLOCKED_OPTION_PREFIXES,
            'blocked_option_words'    => self::BLOCKED_OPTION_WORDS,
            'blocked_option_patterns' => ['^elementor_.*_cache'],
        ];
    }

    /** @return list<string> Meta-Schlüssel, die der Pull in postmeta pseudonymisiert (D12) */
    private static function anonymizedMeta(): array
    {
        if (self::$anonymizedMeta === null) {
            self::$anonymizedMeta = Anonymizer::metaKeys('postmeta');
        }
        return self::$anonymizedMeta;
    }

    /** Trägt der Name eines der kurzen Sperrwörter als ganzes Glied? */
    private static function hasSegment(string $name): bool
    {
        return array_intersect(preg_split('/[_\-.:]+/', strtolower($name)) ?: [], self::BLOCKED_SEGMENTS) !== [];
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
