<?php
namespace WpSync;

/**
 * Einstufung von Tabellen und Post-Typen für Infosheet und Presets (Spec 4.4, AC-8).
 * Namen ohne Tabellen-Prefix und klein geschrieben; exakte Treffer vor Prefix-Treffern,
 * der längste Prefix gewinnt. Unbekanntes bleibt „unknown“ bzw. bei Post-Typen „content“.
 */
final class Classifier
{
    public const CLASSES = ['content', 'config', 'pii', 'log', 'cache', 'backup', 'unknown'];

    /** Ohne diese Tabellen läuft keine WordPress-Site – Presets lassen sie immer vollständig. */
    public const ESSENTIAL = ['options', 'posts', 'postmeta', 'terms', 'term_taxonomy', 'term_relationships', 'termmeta', 'users', 'usermeta'];

    /** @var array<string, array{0: string, 1: string}> */
    private const TABLES = [
        // WordPress Core
        'options'            => ['config', 'core'],
        'posts'              => ['content', 'core'],
        'postmeta'           => ['content', 'core'],
        'terms'              => ['content', 'core'],
        'term_taxonomy'      => ['content', 'core'],
        'term_relationships' => ['content', 'core'],
        'termmeta'           => ['content', 'core'],
        'links'              => ['content', 'core'],
        'users'              => ['pii', 'core'],
        'usermeta'           => ['pii', 'core'],
        'comments'           => ['pii', 'core'],
        'commentmeta'        => ['pii', 'core'],
        // WooCommerce: Bestellungen inkl. HPOS, Kunden, Sitzungen, Zahlungs- und API-Schlüssel
        'wc_orders'                                    => ['pii', 'woocommerce'],
        'wc_orders_meta'                               => ['pii', 'woocommerce'],
        'wc_order_addresses'                           => ['pii', 'woocommerce'],
        'wc_order_operational_data'                    => ['pii', 'woocommerce'],
        'wc_order_stats'                               => ['pii', 'woocommerce'],
        'wc_order_product_lookup'                      => ['pii', 'woocommerce'],
        'wc_order_tax_lookup'                          => ['pii', 'woocommerce'],
        'wc_order_coupon_lookup'                       => ['pii', 'woocommerce'],
        'wc_customer_lookup'                           => ['pii', 'woocommerce'],
        'wc_download_log'                              => ['pii', 'woocommerce'],
        'wc_webhooks'                                  => ['pii', 'woocommerce'],
        'woocommerce_order_items'                      => ['pii', 'woocommerce'],
        'woocommerce_order_itemmeta'                   => ['pii', 'woocommerce'],
        'woocommerce_sessions'                         => ['pii', 'woocommerce'],
        'woocommerce_api_keys'                         => ['pii', 'woocommerce'],
        'woocommerce_payment_tokens'                   => ['pii', 'woocommerce'],
        'woocommerce_payment_tokenmeta'                => ['pii', 'woocommerce'],
        'woocommerce_downloadable_product_permissions' => ['pii', 'woocommerce'],
        'woocommerce_log'                              => ['log', 'woocommerce'],
        'wc_rate_limits'                               => ['cache', 'woocommerce'],
        'wc_reserved_stock'                            => ['cache', 'woocommerce'],
        // Elementor
        'e_submissions'             => ['pii', 'elementor'],
        'e_submissions_values'      => ['pii', 'elementor'],
        'e_submissions_actions_log' => ['pii', 'elementor'],
        'e_events'                  => ['log', 'elementor'],
        'e_notes'                   => ['content', 'elementor'],
        'e_notes_users_relations'   => ['content', 'elementor'],
        // Yoast
        'yoast_migrations'   => ['config', 'yoast'],
        'yoast_primary_term' => ['content', 'yoast'],
        // Rank Math
        'rank_math_404_logs'           => ['log', 'rank-math'],
        'rank_math_internal_links'     => ['cache', 'rank-math'],
        'rank_math_internal_meta'      => ['cache', 'rank-math'],
        'rank_math_redirections_cache' => ['cache', 'rank-math'],
        // Borlabs Cookie
        'borlabs_cookie_consent_log' => ['pii', 'borlabs-cookie'],
        // Formulare
        'db7_forms'                  => ['pii', 'contact-form-cfdb7'],
        'gf_entry'                   => ['pii', 'gravityforms'],
        'gf_entry_meta'              => ['pii', 'gravityforms'],
        'gf_entry_notes'             => ['pii', 'gravityforms'],
        'gf_draft_submissions'       => ['pii', 'gravityforms'],
        'gf_rest_api_keys'           => ['pii', 'gravityforms'],
        'gf_form_view'               => ['log', 'gravityforms'],
        'gf_form_revisions'          => ['log', 'gravityforms'],
        'rg_form_view'               => ['log', 'gravityforms'],
        'rg_incomplete_submissions'  => ['pii', 'gravityforms'],
        'wpforms_tasks_meta'         => ['log', 'wpforms'],
        'wpforms_logs'               => ['log', 'wpforms'],
        'fluentform_submissions'     => ['pii', 'fluentform'],
        'fluentform_submission_meta' => ['pii', 'fluentform'],
        'fluentform_entry_details'   => ['pii', 'fluentform'],
        'fluentform_transactions'    => ['pii', 'fluentform'],
        'fluentform_order_items'     => ['pii', 'fluentform'],
        'fluentform_subscriptions'   => ['pii', 'fluentform'],
        'fluentform_logs'            => ['log', 'fluentform'],
        'fluentform_form_analytics'  => ['log', 'fluentform'],
        // Wordfence
        'wfconfig'         => ['config', 'wordfence'],
        'wfls_settings'    => ['config', 'wordfence'],
        'wfls_2fa_secrets' => ['pii', 'wordfence'],
        // Redirection
        'redirection_logs' => ['log', 'redirection'],
        'redirection_404'  => ['log', 'redirection'],
    ];

    /** @var array<string, array{0: string, 1: string}> */
    private const TABLE_PREFIXES = [
        'actionscheduler_'                 => ['log', 'action-scheduler'],
        'woocommerce_'                     => ['config', 'woocommerce'],
        'wc_'                              => ['config', 'woocommerce'],
        'yoast_'                           => ['cache', 'yoast'],
        'rank_math_analytics_'             => ['log', 'rank-math'],
        'rank_math_'                       => ['config', 'rank-math'],
        'borlabs_cookie_consent_statistic' => ['log', 'borlabs-cookie'],
        'borlabs_cookie_statistic'         => ['log', 'borlabs-cookie'],
        'borlabs_cookie_'                  => ['config', 'borlabs-cookie'],
        'duplicator'                       => ['backup', 'duplicator'],
        'iwp_'                             => ['backup', 'infinitewp'],
        'wptc_'                            => ['backup', 'wp-time-capsule'],
        'gf_'                              => ['config', 'gravityforms'],
        'rg_lead'                          => ['pii', 'gravityforms'],
        'rg_'                              => ['config', 'gravityforms'],
        'wpforms_entr'                     => ['pii', 'wpforms'],
        'wpforms_payment'                  => ['pii', 'wpforms'],
        'wpforms_'                         => ['config', 'wpforms'],
        'fluentform_'                      => ['config', 'fluentform'],
        'wf'                               => ['log', 'wordfence'],
        'redirection_'                     => ['config', 'redirection'],
    ];

    /** @var array<string, string> */
    private const POST_TYPES = [
        'revision'             => 'log',
        'iwp_log'              => 'log',
        'customize_changeset'  => 'log',
        'scheduled-action'     => 'log',
        'wpforms_log'          => 'log',
        'oembed_cache'         => 'cache',
        'user_request'         => 'pii',
        'shop_order'           => 'pii',
        'shop_order_refund'    => 'pii',
        'shop_order_placehold' => 'pii',
        'shop_subscription'    => 'pii',
        'jobpost_applicants'   => 'pii',
        'awsm_job_application' => 'pii',
        'flamingo_inbound'     => 'pii',
        'flamingo_contact'     => 'pii',
        'flamingo_outbound'    => 'pii',
        'nav_menu_item'        => 'config',
        'custom_css'           => 'config',
        'wp_global_styles'     => 'config',
        'wp_template'          => 'config',
        'wp_template_part'     => 'config',
        'wp_navigation'        => 'config',
        'wp_font_family'       => 'config',
        'wp_font_face'         => 'config',
        'shop_coupon'          => 'config',
        'wpcf7_contact_form'   => 'config',
        'acf-field-group'      => 'config',
        'acf-field'            => 'config',
        'acf-post-type'        => 'config',
        'acf-taxonomy'         => 'config',
        'elementor_snippet'    => 'config',
        'elementor_font'       => 'config',
        'elementor_icons'      => 'config',
        'wpforms'              => 'config',
    ];

    /** @return array{class: string, plugin: string|null, essential: bool} */
    public static function table(string $table, string $prefix): array
    {
        $name      = strtolower(strpos($table, $prefix) === 0 ? substr($table, strlen($prefix)) : $table);
        $essential = in_array($name, self::ESSENTIAL, true);
        if (isset(self::TABLES[$name])) {
            return ['class' => self::TABLES[$name][0], 'plugin' => self::TABLES[$name][1], 'essential' => $essential];
        }
        $best = null;
        foreach (array_keys(self::TABLE_PREFIXES) as $candidate) {
            if (strpos($name, $candidate) === 0 && ($best === null || strlen($candidate) > strlen($best))) {
                $best = $candidate;
            }
        }
        if ($best !== null) {
            return ['class' => self::TABLE_PREFIXES[$best][0], 'plugin' => self::TABLE_PREFIXES[$best][1], 'essential' => $essential];
        }
        return ['class' => 'unknown', 'plugin' => null, 'essential' => $essential];
    }

    public static function postType(string $type): string
    {
        return self::POST_TYPES[$type] ?? 'content';
    }

    /** @return list<string> alle in Regeln verwendeten Einstufungen (für Tests) */
    public static function ruleClasses(): array
    {
        return array_merge(array_column(self::TABLES, 0), array_column(self::TABLE_PREFIXES, 0), array_values(self::POST_TYPES));
    }
}
