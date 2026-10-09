<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\TestCase;
use WpSync\Anonymizer;
use WpSync\ContentLists;

final class ContentListsTest extends TestCase
{
    public function testPostTypesAndTaxonomies(): void
    {
        foreach (['page', 'post', 'attachment', 'nav_menu_item', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'custom_css', 'elementor_library', 'acf-field-group', 'acf-field', 'acf-post-type', 'acf-taxonomy', 'acf-ui-options-page'] as $type) {
            $this->assertTrue(ContentLists::postType($type), $type);
        }
        foreach (['revision', 'customize_changeset', 'oembed_cache', 'user_request', 'shop_order', 'flamingo_inbound', 'referenz'] as $type) {
            $this->assertFalse(ContentLists::postType($type), $type);
        }
        $this->assertTrue(ContentLists::postType('referenz', ['post_types' => ['referenz'], 'taxonomies' => [], 'meta_exceptions' => []]));
        $this->assertTrue(ContentLists::taxonomy('nav_menu'));
        $this->assertFalse(ContentLists::taxonomy('language'));
        $this->assertTrue(ContentLists::taxonomy('branche', ['post_types' => [], 'taxonomies' => ['branche'], 'meta_exceptions' => []]));
    }

    public function testMetaKeys(): void
    {
        foreach (['_elementor_data', '_thumbnail_id', '_wp_page_template', '_menu_item_object_id', '_wp_attached_file', '_wp_attachment_metadata', '_yoast_wpseo_title', 'rank_math_title', 'mein_feld'] as $key) {
            $this->assertTrue(ContentLists::metaKey($key), $key);
        }
        foreach (['_edit_lock', '_edit_last', '_elementor_css', '_elementor_element_cache', '_elementor_page_assets', '_elementor_screenshot', '_elementor_screenshot_failed', '_wp_old_slug', '_wp_old_date', '_wp_trash_meta_status', '_wp_trash_meta_time', '_encloseme', '_pingme', '_yoast_indexnow_last_ping', 'mailchimp_api_key', 'my_License', 'x_token_y', 'client_secret', 'user_password'] as $key) {
            $this->assertFalse(ContentLists::metaKey($key), $key);
        }
        $ext = ['post_types' => [], 'taxonomies' => [], 'meta_exceptions' => ['design_token']];
        $this->assertFalse(ContentLists::metaKey('design_token'));
        $this->assertTrue(ContentLists::metaKey('design_token', $ext), 'W11: Ausnahme von der Teilstring-Sperre');
        $this->assertFalse(ContentLists::metaKey('_edit_lock', ['post_types' => [], 'taxonomies' => [], 'meta_exceptions' => ['_edit_lock']]), 'feste Schlüssel lassen sich nicht ausnehmen');
    }

    public function testOptions(): void
    {
        foreach (['page_on_front', 'page_for_posts', 'show_on_front', 'blogname', 'blogdescription', 'sticky_posts', 'site_icon', 'elementor_active_kit', 'elementor_pro_theme_builder_conditions', 'elementor_cpt_support', 'elementor_disable_color_schemes', 'elementor_disable_typography_schemes', 'elementor_experiment-container', 'options_footer_text', '_options_footer_text', 'wpseo_titles', 'wpseo_social', 'rank-math-options-titles', 'theme_mods_hello-child'] as $name) {
            $this->assertTrue(ContentLists::option($name, 'wp_', 'hello-child'), $name);
        }
        foreach (['siteurl', 'home', 'active_plugins', 'cron', 'rewrite_rules', '_transient_x', '_site_transient_x', 'elementor_pro_license_key', 'my_license_status', 'admin_email', 'new_admin_email', 'mailserver_url', 'wp_user_roles', 'options_api_key', 'options_secret_text', 'elementor_css_print_method', 'elementor_x_cache_y', 'theme_mods_anderes-theme', 'wpsync_schema', 'irgendeine_option', 'blogname2'] as $name) {
            $this->assertFalse(ContentLists::option($name, 'wp_', 'hello-child'), $name);
        }
    }

    public function testExtensionsAreValidated(): void
    {
        $this->assertSame(['post_types' => [], 'taxonomies' => [], 'meta_exceptions' => []], ContentLists::extensions(null));
        $this->assertSame(['post_types' => ['referenz'], 'taxonomies' => ['branche'], 'meta_exceptions' => ['key_visual']], ContentLists::extensions(['post_types' => ['referenz'], 'taxonomies' => ['branche'], 'meta_exceptions' => ['key_visual']]));
        $this->assertNull(ContentLists::extensions(['post_types' => ['Referenz!']]));
        $this->assertNull(ContentLists::extensions(['options' => ['x']]), 'Optionen lassen sich nicht freigeben');
        $this->assertNull(ContentLists::extensions('x'));
        $this->assertNull(ContentLists::extensions(['post_types' => ['shop_order']]), 'pseudonymisierte Typen lassen sich nicht freigeben');
    }

    /** AC-148 / D12: keine Anonymisierungsregel erfasst eine Zeile der Whitelist */
    public function testNoAnonymizerRuleTouchesAWhitelistedRow(): void
    {
        foreach (Anonymizer::postTypes() as $type) {
            $this->assertFalse(ContentLists::postType($type), 'posts: ' . $type . ' wird pseudonymisiert und darf nicht pushbar sein');
        }
        foreach (Anonymizer::metaKeys('postmeta') as $key) {
            $this->assertFalse(ContentLists::metaKey($key), 'postmeta: ' . $key);
            $this->assertFalse(ContentLists::metaKey($key, ['post_types' => [], 'taxonomies' => [], 'meta_exceptions' => [$key]]), 'postmeta: ' . $key . ' lässt sich nicht ausnehmen');
        }
        foreach (Anonymizer::metaKeys('options') as $name) {
            $this->assertFalse(ContentLists::option($name, 'wp_', 'hello-child'), 'options: ' . $name);
        }
        foreach (['terms', 'term_taxonomy', 'term_relationships', 'termmeta'] as $table) {
            $this->assertFalse(Anonymizer::changes('wp_' . $table, 'wp_'), $table . ' darf keine Regel bekommen, ohne dass dieser Test angepasst wird');
        }
        $this->assertSame([], Anonymizer::columns('posts', true), 'posts: nur Regeln mit when sind erlaubt');
    }

    public function testExportNamesTheLists(): void
    {
        $lists = ContentLists::export();
        $this->assertSame(ContentLists::VERSION, $lists['version']);
        $this->assertContains('elementor_library', $lists['post_types']);
        $this->assertContains('nav_menu', $lists['taxonomies']);
        $this->assertContains('_edit_lock', $lists['blocked_meta']);
        $this->assertContains('_customer_ip_address', $lists['blocked_meta']);
        $this->assertContains('page_on_front', $lists['options']);
    }

    public function testBlockedNamesTheReasonPerTable(): void
    {
        $page  = ['post_type' => 'page'];
        $order = ['post_type' => 'shop_order'];
        $this->assertNull(ContentLists::blocked('posts', '219', $page));
        $this->assertSame('post_type', ContentLists::blocked('posts', '219', $order));
        $this->assertSame('no_object', ContentLists::blocked('posts', '219', []));
        $this->assertSame('post_type', ContentLists::blocked('posts', '219', ['post_type' => '']), 'ein Beitrag ohne Typ ist da, aber nicht erlaubt');

        $this->assertNull(ContentLists::blocked('postmeta', "219\0_elementor_data", $page));
        $this->assertSame('post_type', ContentLists::blocked('postmeta', "219\0_elementor_data", $order));
        $this->assertSame('no_object', ContentLists::blocked('postmeta', "219\0_elementor_data", ['post_type' => null]));
        $this->assertSame('meta_key', ContentLists::blocked('postmeta', "219\0_edit_lock", $page));
        $this->assertSame('meta_key', ContentLists::blocked('postmeta', "219\0_elementor_screenshot_failed", $page));
        $this->assertSame('meta_key', ContentLists::blocked('postmeta', "219\0_billing_email", $page), 'pseudonymisiert: fest gesperrt');
        $this->assertSame('meta_key', ContentLists::blocked('postmeta', "219\0", $page), 'leerer Meta-Schlüssel');
        $this->assertSame('key', ContentLists::blocked('postmeta', '219', $page), 'Schlüssel ohne Trenner');
        $this->assertSame('meta_word', ContentLists::blocked('postmeta', "219\0design_token", $page));
        $this->assertNull(ContentLists::blocked('postmeta', "219\0design_token", $page, ['meta_exceptions' => ['design_token']]));
        $this->assertNull(ContentLists::blocked('postmeta', "219\0a\0b", $page), 'nur der erste Trenner teilt');

        $menu = ['taxonomies' => ['nav_menu']];
        $this->assertNull(ContentLists::blocked('terms', '7', $menu));
        $this->assertNull(ContentLists::blocked('term_taxonomy', '9', $menu));
        $this->assertSame('taxonomy', ContentLists::blocked('terms', '7', ['taxonomies' => ['nav_menu', 'language']]));
        $this->assertSame('no_object', ContentLists::blocked('terms', '7', ['taxonomies' => []]));
        $this->assertSame('no_object', ContentLists::blocked('terms', '7', []));
        $this->assertNull(ContentLists::blocked('terms', '7', ['taxonomies' => ['branche']], ['taxonomies' => ['branche']]));
        $this->assertNull(ContentLists::blocked('termmeta', "7\0farbe", $menu));
        $this->assertSame('meta_word', ContentLists::blocked('termmeta', "7\0api_key", $menu));
        $this->assertSame('taxonomy', ContentLists::blocked('termmeta', "7\0farbe", ['taxonomies' => ['language']]));
        $this->assertSame('taxonomy', ContentLists::blocked('termmeta', "7\0farbe", ['taxonomies' => ['nav_menu', 'language']]));

        $this->assertNull(ContentLists::blocked('term_relationships', "219\0category", ['post_type' => 'post']));
        $this->assertSame('taxonomy', ContentLists::blocked('term_relationships', "219\0language", ['post_type' => 'post']));
        $this->assertSame('post_type', ContentLists::blocked('term_relationships', "219\0category", $order));
        $this->assertSame('no_object', ContentLists::blocked('term_relationships', "3\0category", []), 'Zuordnung eines Links');

        $site = ['prefix' => 'wp_', 'stylesheet' => 'hello-child'];
        $this->assertNull(ContentLists::blocked('options', 'blogname', $site));
        $this->assertNull(ContentLists::blocked('options', 'theme_mods_hello-child', $site));
        $this->assertSame('option', ContentLists::blocked('options', 'siteurl', $site));
        $this->assertSame('option', ContentLists::blocked('options', 'wp_user_roles', $site));
        $this->assertSame('option', ContentLists::blocked('options', 'theme_mods_hello-child', []), 'ohne Theme keine theme_mods');

        $this->assertSame('table', ContentLists::blocked('users', '1', []));
    }

    /** AC-148 / D12: was der Pull pseudonymisiert, ist fest gesperrt – nie nur per Teilstring */
    public function testAnonymizedMetaIsBlockedForGood(): void
    {
        foreach (Anonymizer::metaKeys('postmeta') as $key) {
            $this->assertSame('meta_key', ContentLists::blocked('postmeta', "1\0" . $key, ['post_type' => 'page']), $key);
            $this->assertSame('meta_key', ContentLists::blocked('postmeta', "1\0" . $key, ['post_type' => 'page'], ['meta_exceptions' => [$key]]), $key);
        }
    }

    /** @return array<string, array{0: string, 1: string}> Tabelle, Schlüssel */
    public static function badKeys(): array
    {
        $out = [];
        foreach (['posts', 'terms', 'term_taxonomy'] as $table) {
            foreach (['219abc', 'abc', '', '0', '0219', '-1', '+219', ' 219', "219\n", '2.19', '1e3', "219\0", "219\0_x", str_repeat('9', 21), str_repeat('9', 19), '18446744073709551615'] as $key) {
                $out[$table . ' ' . json_encode($key)] = [$table, $key];
            }
        }
        foreach (['postmeta', 'termmeta', 'term_relationships'] as $table) {
            foreach (["219abc\0_x", "abc\0_x", "\0_x", "0\0_x", "0219\0_x", "-1\0_x", " 219\0_x", "219\n\0_x", "2.19\0_x", '219', '219abc', '', '_x', str_repeat('9', 21) . "\0_x", str_repeat('9', 19) . "\0_x", "18446744073709551615\0_x"] as $key) {
                $out[$table . ' ' . json_encode($key)] = [$table, $key];
            }
        }
        return $out;
    }

    /** Security-Review H3.3: die Objekt-ID im Schlüssel ist eine Zahl und nichts sonst – auch wenn alles andere erlaubt wäre */
    #[\PHPUnit\Framework\Attributes\DataProvider('badKeys')]
    public function testAKeyWithoutACleanObjectIdIsBlocked(string $table, string $key): void
    {
        $ctx = ['post_type' => 'page', 'taxonomies' => ['nav_menu']];
        $this->assertSame('key', ContentLists::blocked($table, $key, $ctx));
        $this->assertSame('key', ContentLists::blocked($table, $key, []), 'vor jedem anderen Grund');
    }

    public function testCleanObjectIdsPass(): void
    {
        $ctx = ['post_type' => 'page', 'taxonomies' => ['nav_menu']];
        // Höchstens 18 Stellen: dieselbe Grenze wie im Paket (ContentPackage) – so viel passt in jedem PHP in eine Zahl.
        $this->assertSame(1, preg_match(ContentLists::OBJECT_ID, str_repeat('9', 18)));
        foreach (['1', '219', '999999999999999999', str_repeat('9', 18)] as $id) {
            $this->assertNull(ContentLists::blocked('posts', $id, $ctx), $id);
            $this->assertNull(ContentLists::blocked('terms', $id, $ctx), $id);
            $this->assertNull(ContentLists::blocked('term_taxonomy', $id, $ctx), $id);
            $this->assertNull(ContentLists::blocked('postmeta', $id . "\0_x", $ctx), $id);
            $this->assertNull(ContentLists::blocked('termmeta', $id . "\0farbe", $ctx), $id);
            $this->assertNull(ContentLists::blocked('term_relationships', $id . "\0nav_menu", $ctx), $id);
        }
        $this->assertSame('option', ContentLists::blocked('options', '219abc', ['prefix' => 'wp_']), 'Optionen haben keinen Zahlenschlüssel');
    }

    /** Härtung S2: die Erweiterungen kommen aus dem Paket – gefährliche Beitragstypen gibt keine frei. */
    public function testExtensionsNeverFreeDangerousPostTypes(): void
    {
        $never = array_merge(ContentLists::NEVER_POST_TYPES, ['shop_order', 'shop_order_refund', 'shop_order_placehold', 'flamingo_inbound', 'flamingo_contact', 'wpforms_log', 'wpcode_snippet']);
        foreach ($never as $type) {
            $this->assertNull(ContentLists::extensions(['post_types' => [$type]]), $type);
            $this->assertTrue(ContentLists::neverPostType($type), $type);
        }
        $this->assertNotNull(ContentLists::extensions(['post_types' => ['referenz', 'team-mitglied'], 'taxonomies' => ['branche'], 'meta_exceptions' => ['design_token']]));
        $this->assertFalse(ContentLists::neverPostType('referenz'));
    }

    /** Härtung S4: mehr Sperrwörter – kurze nur als ganzes Namensglied, damit übliche Schlüssel pushbar bleiben. */
    public function testWordListsBlockSecretsButNotUsualKeys(): void
    {
        foreach (['smtp_pass', 'ftp-pwd', '_auth_code', 'user.auth', 'stripe_sk', 'pw_salt', 'private_note', 'my_credentials', 'ApiKey', 'api-key-live', 'slack_webhook_url', 'oauth_state', 'db_passwd'] as $key) {
            $this->assertFalse(ContentLists::metaKey($key), $key);
            $this->assertSame('meta_word', ContentLists::blocked('postmeta', "5\0" . $key, ['post_type' => 'page']), $key);
            $this->assertTrue(ContentLists::metaKey($key, ['meta_exceptions' => [$key]]), $key . ' lässt sich je Projekt ausnehmen');
        }
        $usual = [
            '_elementor_data', '_elementor_page_settings', '_thumbnail_id', '_wp_page_template', '_wp_attached_file', '_wp_attachment_metadata',
            '_wp_attachment_image_alt', '_menu_item_type', '_menu_item_object_id', '_menu_item_menu_item_parent', '_menu_item_classes',
            '_menu_item_url', '_yoast_wpseo_title', '_yoast_wpseo_metadesc', 'rank_math_title', 'author', 'post_author_name', 'passage',
            'compass', 'asked_by', 'skill', 'basalt', 'privates', '_wp_desired_post_slug', 'footnotes',
        ];
        foreach ($usual as $key) {
            $this->assertTrue(ContentLists::metaKey($key), $key);
        }
        foreach (ContentLists::OPTIONS as $name) {
            $this->assertTrue(ContentLists::option($name, 'wp_', 'hello-child'), $name);
        }
        foreach (['theme_mods_hello-child', 'elementor_experiment-container', 'options_footer_text', '_options_footer_text'] as $name) {
            $this->assertTrue(ContentLists::option($name, 'wp_', 'hello-child'), $name);
        }
        foreach (['options_smtp_pass', 'options_auth', 'options_webhook', '_options_stripe_sk'] as $name) {
            $this->assertFalse(ContentLists::option($name, 'wp_', 'hello-child'), $name);
        }
    }

    /** Härtung S4: die Datenbank unterscheidet Gross/klein nicht – gesperrt ist auch die andere Schreibweise. */
    public function testListsAlsoApplyToTheLowercaseForm(): void
    {
        foreach (['_Edit_Lock', '_ELEMENTOR_CSS', '_WP_Trash_Meta_Status', '_Billing_Email', 'My_Token'] as $key) {
            $this->assertFalse(ContentLists::metaKey($key), $key);
        }
        foreach (['SiteUrl', 'HOME', 'Active_Plugins', 'WP_user_roles', '_Transient_x', 'WPSYNC_schema', 'Elementor_x_Cache'] as $name) {
            $this->assertFalse(ContentLists::option($name, 'wp_', 'hello-child'), $name);
        }
        $this->assertFalse(ContentLists::option('Blogname', 'wp_', 'hello-child'), 'die Whitelist gilt bytegenau');
        $this->assertSame(2, ContentLists::VERSION);
    }
}
