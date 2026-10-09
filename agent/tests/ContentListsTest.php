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
        $this->assertSame('meta_key', ContentLists::blocked('postmeta', '219', $page), 'Schlüssel ohne Trenner');
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
}
