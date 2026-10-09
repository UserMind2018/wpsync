<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use WpSync\ContentPostActions;

/**
 * Nacharbeiten (Spec Content-Push §7.7, AC-154): Reihenfolge, nur vorhandene Plugins, ein
 * Fehlschlag bricht nichts ab und steht im Ergebnis.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ContentPostActionsTest extends TestCase
{
    private const CHANGES = [
        'posts' => [219, 1000001], 'revisions' => [219], 'terms' => [5], 'term_taxonomy' => [5, 6, 7],
        'options' => ['blogname'], 'rewrite' => true,
    ];

    private FakeWpdb $db;

    protected function setUp(): void
    {
        require_once __DIR__ . '/PostActionsHarness.php';
        require_once __DIR__ . '/FakeWpdb.php';
        require_once __DIR__ . '/ContentMemory.php';
        $this->db                    = new FakeWpdb();
        $GLOBALS['wpsync_test_tt']   = [5 => 'category', 6 => 'category', 7 => 'unregistered'];
    }

    /** @return list<string> */
    private function calls(): array
    {
        return $GLOBALS['wpsync_post_actions'];
    }

    /**
     * M2: auch die Nacharbeiten schreiben Werte des Pakets (Revisionen, Indexables) – ein
     * Datenbankfehler darin landet nicht samt Abfrage im Fehlerprotokoll.
     */
    public function testNoQueryOfThePostActionsReachesTheErrorLog(): void
    {
        $this->db->fail('/^DELETE FROM `wp_yoast_indexable`/', 'Table is marked as crashed');
        $GLOBALS['wpsync_test_revision_db'] = $this->db; // wp_save_post_revision() fragt $wpdb wie WordPress
        $steps = ContentPostActions::live(self::CHANGES, $this->db, 'wp_yoast_indexable');
        $this->assertContains(['step' => 'seo_indexables', 'ok' => false], $steps);
        $this->assertSame([], $this->db->logged);
        $this->assertSame([], $this->db->unsuppressed, 'jede Abfrage der Nacharbeiten läuft unterdrückt');
        $this->assertNotSame([], $this->db->queries);
        $this->assertFalse($this->db->suppress_errors, 'danach gilt wieder die Einstellung der Site');

        $this->db = new FakeWpdb();
        $this->db->fail('/^DELETE FROM `stg_yoast_indexable`/', 'Table is marked as crashed');
        $steps = ContentPostActions::staging(self::CHANGES, new ContentMemory(), $this->db, 'stg_yoast_indexable', '', false);
        $this->assertContains(['step' => 'seo_indexables', 'ok' => false], $steps);
        $this->assertSame([], $this->db->logged);
        $this->assertFalse($this->db->suppress_errors);
    }

    public function testLiveStepsInOrderWithoutPlugins(): void
    {
        $steps = ContentPostActions::live(self::CHANGES, $this->db, '');
        $this->assertSame([
            ['step' => 'object_cache', 'ok' => true],
            ['step' => 'rewrite_rules', 'ok' => true],
            ['step' => 'term_counts', 'ok' => true],
            ['step' => 'revisions', 'ok' => true],
        ], $steps);
        $this->assertSame([
            'clean_post_cache [219]',
            'clean_post_cache [1000001]',
            'clean_term_cache [[5]]',
            'wp_cache_delete ["blogname","options"]',
            'wp_cache_delete ["alloptions","options"]',
            'wp_cache_delete ["notoptions","options"]',
            'delete_option ["rewrite_rules"]',
            'wp_update_term_count_now [[5,6],"category"]',
            'wp_save_post_revision [219]',
        ], $this->calls());
        $this->assertSame([], $this->db->queries, 'ohne Yoast kein SQL');
    }

    public function testOnlyWhatChangedIsDone(): void
    {
        $steps = ContentPostActions::live(['posts' => [], 'revisions' => [], 'terms' => [], 'term_taxonomy' => [], 'options' => ['blogname'], 'rewrite' => false], $this->db, 'wp_yoast_indexable');
        $this->assertSame([['step' => 'object_cache', 'ok' => true]], $steps);
    }

    /** Plugins: nur, wenn ihre Funktion existiert – in der Reihenfolge Elementor, Yoast, Cache-Plugins. */
    public function testPluginStepsOnlyWhenThePluginIsThere(): void
    {
        eval('namespace Elementor { class Plugin { public static $instance; } }');
        \Elementor\Plugin::$instance = (object) ['files_manager' => new class {
            public function clear_cache(): void
            {
                \wpsync_note('elementor clear_cache');
            }
        }];
        eval('function rocket_clean_domain() { wpsync_note("rocket_clean_domain"); } function w3tc_flush_all() { wpsync_note("w3tc_flush_all"); } function sg_cachepress_purge_cache() { wpsync_note("sg_cachepress_purge_cache"); }');
        define('LSCWP_V', '6.0');
        define('BREEZE_VERSION', '2.0');

        $steps = ContentPostActions::live(self::CHANGES, $this->db, 'wp_yoast_indexable');
        $this->assertSame(
            ['object_cache', 'elementor_css', 'seo_indexables', 'cache_wp_rocket', 'cache_w3tc', 'cache_litespeed', 'cache_sg_optimizer', 'cache_breeze', 'rewrite_rules', 'term_counts', 'revisions'],
            array_column($steps, 'step')
        );
        $this->assertSame([true], array_values(array_unique(array_column($steps, 'ok'))));
        $this->assertContains('elementor clear_cache', $this->calls());
        $this->assertContains('do_action ["litespeed_purge_all"]', $this->calls());
        $this->assertContains('do_action ["breeze_clear_all_cache"]', $this->calls());
        $this->assertSame(["DELETE FROM `wp_yoast_indexable` WHERE `object_type` = 'post' AND `object_id` IN (219,1000001)"], $this->db->queries);
    }

    /** AC-154: ein Fehlschlag erscheint in post_actions, die übrigen Schritte laufen. */
    public function testAFailedStepDoesNotStopTheOthers(): void
    {
        $GLOBALS['wpsync_post_actions_fail'] = ['clean_post_cache', 'wp_update_term_count_now'];
        $this->db->fail('/yoast_indexable/', 'table crashed');
        $steps = ContentPostActions::live(self::CHANGES, $this->db, 'wp_yoast_indexable');
        $this->assertSame([
            ['step' => 'object_cache', 'ok' => false],
            ['step' => 'seo_indexables', 'ok' => false],
            ['step' => 'rewrite_rules', 'ok' => true],
            ['step' => 'term_counts', 'ok' => false],
            ['step' => 'revisions', 'ok' => true],
        ], $steps);
    }

    public function testATableNameIsNeverTakenOnTrust(): void
    {
        $steps = ContentPostActions::live(self::CHANGES, $this->db, 'wp_yoast_indexable` WHERE 1=1; --');
        $this->assertContains(['step' => 'seo_indexables', 'ok' => false], $steps);
        $this->assertSame([], $this->db->queries);
    }

    /** §7.8: in der Kopie nur SQL und Dateien – über den Store der Kopie. */
    public function testStagingStepsUseOnlyTheStoreAndFiles(): void
    {
        // Mit Zeichen im Pfad, die für glob() Muster wären (Security-Review P3, H1).
        $dir = sys_get_temp_dir() . '/wpsync-postactions-[' . bin2hex(random_bytes(4)) . ']';
        mkdir($dir . '/elementor/css', 0777, true);
        file_put_contents($dir . '/elementor/css/.versteckt.css', 'x');
        file_put_contents($dir . '/elementor/css/post-219.css', 'alt');
        file_put_contents($dir . '/elementor/css/bleibt.txt', 'x');
        $store = new ContentMemory([
            'postmeta'           => ["219\0_elementor_css" => ['values' => ['x']], "219\0_elementor_data" => ['values' => ['[]']]],
            'options'            => ['_elementor_global_css' => ['option_value' => 'x'], 'rewrite_rules' => ['option_value' => 'a:0:{}'], 'blogname' => ['option_value' => 'Kunde']],
            'term_taxonomy'      => ['5' => ContentFixturesLite::taxonomy('5')],
            'term_relationships' => ["219\0category" => ['values' => ['5:0']], "220\0category" => ['values' => ['5:0']]],
        ]);
        $steps = ContentPostActions::staging(self::CHANGES, $store, $this->db, 'stgabcdef_yoast_indexable', $dir, true);
        $this->assertSame(
            [['step' => 'elementor_css', 'ok' => true], ['step' => 'seo_indexables', 'ok' => true], ['step' => 'rewrite_rules', 'ok' => true], ['step' => 'term_counts', 'ok' => true]],
            $steps
        );
        $this->assertSame(["219\0_elementor_data"], array_keys($store->data['postmeta']));
        $this->assertSame(['blogname'], array_keys($store->data['options']));
        $this->assertSame('2', $store->data['term_taxonomy']['5']['count']);
        $this->assertFileDoesNotExist($dir . '/elementor/css/post-219.css');
        $this->assertFileExists($dir . '/elementor/css/bleibt.txt');
        $this->assertFileExists($dir . '/elementor/css/.versteckt.css');
        $this->assertSame(["DELETE FROM `stgabcdef_yoast_indexable` WHERE `object_type` = 'post' AND `object_id` IN (219,1000001)"], $this->db->queries);
        $this->assertSame([], $this->calls(), 'keine WordPress-Funktion von Live für die Kopie');

        $none = ContentPostActions::staging(['posts' => [219], 'term_taxonomy' => [], 'rewrite' => false], $store, $this->db, '', '', false);
        $this->assertSame([], $none);
        exec('rm -rf ' . escapeshellarg($dir));
    }
}

/** Nur was dieser Test braucht – ContentFixtures.php zieht Klassen nach, die hier nicht geladen sind. */
final class ContentFixturesLite
{
    /** @return array<string, string> */
    public static function taxonomy(string $id): array
    {
        return ['term_taxonomy_id' => $id, 'term_id' => $id, 'taxonomy' => 'category', 'description' => '', 'parent' => '0', 'count' => '0'];
    }
}
