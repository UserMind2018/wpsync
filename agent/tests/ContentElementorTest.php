<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\TestCase;
use WpSync\ContentElementor;

require_once __DIR__ . '/ContentFixtures.php';
require_once __DIR__ . '/ContentMemory.php';

/**
 * Umfang der Nacharbeit elementor_css (Spec 0.9.1 Block E): welche Beiträge ein Paket berührt,
 * wann nur sie geleert werden und wann alles – und das gezielte Leeren selbst.
 */
final class ContentElementorTest extends TestCase
{
    private ContentMemory $store;

    protected function setUp(): void
    {
        $this->store = new ContentMemory([
            'posts' => [
                '219' => ContentFixtures::post('219'),
                '220' => ContentFixtures::post('220'),
                '300' => ContentFixtures::post('300', ['post_type' => 'elementor_library']),
                '400' => ContentFixtures::post('400', ['post_type' => 'revision', 'post_parent' => '220']),
                '401' => ContentFixtures::post('401', ['post_type' => 'revision', 'post_parent' => '0']),
                '402' => ContentFixtures::post('402', ['post_type' => 'revision', 'post_parent' => '999']),
                '403' => ContentFixtures::post('403', ['post_type' => 'revision', 'post_parent' => '300']),
                '9'   => ContentFixtures::post('9', ['post_type' => 'page']),
            ],
            'options' => ['elementor_active_kit' => ContentFixtures::option('elementor_active_kit', '9')],
        ]);
    }

    /**
     * @param list<array{0: string, 1: string, 2: array<string, mixed>|null}> $writes
     * @return array{posts: list<int>, global: bool}
     */
    private function touched(array $writes): array
    {
        $before = [];
        foreach ($writes as $write) {
            if (in_array($write[0], ['posts', 'postmeta'], true)) {
                $id          = explode("\0", $write[1])[0];
                $before[$id] = $this->store->data['posts'][$id] ?? null;
            }
        }
        return ContentElementor::touched($writes, $before, $this->store);
    }

    /** E1: posts- und postmeta-Zeilen zählen, term_relationships allein nicht. */
    public function testPostsAndMetaRowsTouchAPostRelationsDoNot(): void
    {
        $this->assertSame(['posts' => [219, 220], 'global' => false], $this->touched([
            ['posts', '219', ContentFixtures::post('219', ['post_title' => 'Neu'])],
            ['postmeta', "220\0_elementor_data", ['values' => ['[]']]],
            ['term_relationships', "221\0category", ['values' => ['5:0']]],
            ['options', 'blogname', ['option_value' => 'x']],
            ['terms', '5', ['name' => 'News']],
        ]));
        $this->assertSame(['posts' => [], 'global' => false], $this->touched([
            ['term_relationships', "219\0category", ['values' => ['5:0']]],
            ['terms', '5', ['name' => 'News']],
            ['options', 'blogname', ['option_value' => 'x']],
        ]));
    }

    /** Ein eingefügter Beitrag: sein Typ steht in der Zeile des Pakets. */
    public function testAnInsertedPostCountsWithTheTypeOfItsRow(): void
    {
        $this->assertSame(['posts' => [1000001], 'global' => false], $this->touched([
            ['posts', '1000001', ContentFixtures::post('1000001')],
            ['postmeta', "1000001\0_elementor_data", ['values' => ['[]']]],
        ]));
        $this->assertTrue($this->touched([['posts', '1000001', ContentFixtures::post('1000001', ['post_type' => 'elementor_library'])]])['global']);
    }

    /** AC-238: bei einer Revision zählt der Elternbeitrag – auch wenn nur ihre Meta im Paket steht. */
    public function testARevisionCountsAsItsParent(): void
    {
        $this->assertSame(['posts' => [220], 'global' => false], $this->touched([['posts', '400', ContentFixtures::post('400', ['post_type' => 'revision', 'post_parent' => '220'])]]));
        $this->assertSame(['posts' => [220], 'global' => false], $this->touched([['postmeta', "400\0_elementor_data", ['values' => ['[]']]]]));
        // Eine Revision ohne Eltern, mit Eltern, die es nicht gibt, oder eines Templates: global.
        $this->assertTrue($this->touched([['postmeta', "401\0_x", ['values' => ['1']]]])['global']);
        $this->assertTrue($this->touched([['postmeta', "402\0_x", ['values' => ['1']]]])['global']);
        $this->assertTrue($this->touched([['postmeta', "403\0_x", ['values' => ['1']]]])['global']);
    }

    /** AC-235, E3 Nr. 1: ein Template – vor oder nach dem Paket – heisst global. */
    public function testATemplateBeforeOrAfterMeansGlobal(): void
    {
        $this->assertSame(['posts' => [], 'global' => true], $this->touched([['postmeta', "300\0_elementor_data", ['values' => ['[]']]]]));
        $this->assertTrue($this->touched([['posts', '300', ContentFixtures::post('300', ['post_type' => 'page'])]])['global'], 'vorher Template');
        $this->assertTrue($this->touched([['posts', '219', ContentFixtures::post('219', ['post_type' => 'elementor_library'])]])['global'], 'nachher Template');
        $this->assertTrue($this->touched([['posts', '300', null]])['global'], 'gelöschtes Template');
    }

    /** AC-236, E3 Nr. 2 und 3: das Kit und jede Option von Elementor. */
    public function testTheKitAndElementorOptionsMeanGlobal(): void
    {
        $this->assertTrue($this->touched([['postmeta', "9\0_elementor_page_settings", ['values' => ['a:0:{}']]]])['global']);
        $this->assertTrue($this->touched([['options', 'elementor_cpt_support', ['option_value' => 'a:0:{}']]])['global']);
        $this->assertTrue($this->touched([['options', '_elementor_general_settings', ['option_value' => 'a:0:{}']]])['global']);
        $this->assertTrue($this->touched([['options', 'elementor_active_kit', null]])['global']);
        $this->assertFalse($this->touched([['options', 'my_elementor_note', ['option_value' => 'x']]])['global']);
        // Das Kit, wie es nach dem Paket gilt – gelesen vom Ziel.
        $this->store->data['options']['elementor_active_kit']['option_value'] = '219';
        $this->assertTrue($this->touched([['posts', '219', ContentFixtures::post('219')]])['global']);
        $this->assertFalse($this->touched([['posts', '9', ContentFixtures::post('9')]])['global']);
    }

    /** E3 Nr. 4: lässt sich der Typ nicht bestimmen, im Zweifel global. */
    public function testAnUnknownTypeMeansGlobal(): void
    {
        $this->assertTrue($this->touched([['postmeta', "777\0_x", ['values' => ['1']]]])['global'], 'Meta an einem Beitrag, den es nicht gibt');
        $this->assertTrue($this->touched([['posts', '778', null]])['global'], 'gelöscht und kein Vorher-Stand');
        // Ein gelöschter Beitrag mit Vorher-Stand zählt mit seinem Typ.
        $this->assertSame(['posts' => [219], 'global' => false], $this->touched([['posts', '219', null]]));
        // Lässt sich das Ziel nicht lesen: ebenso.
        $this->store->failRead = true;
        $this->assertTrue($this->touched([['posts', '219', ContentFixtures::post('219')]])['global']);
    }

    /** E3 Nr. 5: mehr als 200 berührte Beiträge. */
    public function testMoreThanTwoHundredPostsMeanGlobal(): void
    {
        $writes = [];
        for ($id = 1000; $id < 1200; $id++) {
            $writes[] = ['posts', (string) $id, ContentFixtures::post((string) $id)];
        }
        $this->assertCount(200, $this->touched($writes)['posts']);
        $this->assertFalse($this->touched($writes)['global']);
        $writes[] = ['posts', '1200', ContentFixtures::post('1200')];
        $this->assertSame(['posts' => [], 'global' => true], $this->touched($writes));
    }

    public function testScopeFromChanges(): void
    {
        $base = ['posts' => [219], 'revisions' => [], 'terms' => [], 'term_taxonomy' => [], 'options' => [], 'rewrite' => false];
        $this->assertSame(['scope' => 'posts', 'posts' => [219, 220]], ContentElementor::scope($base + ['elementor' => ['posts' => [219, '220', 219, 0, 'x'], 'global' => false]]));
        $this->assertSame(['scope' => 'none', 'posts' => []], ContentElementor::scope($base + ['elementor' => ['posts' => [], 'global' => false]]));
        $this->assertSame(['scope' => 'global', 'posts' => []], ContentElementor::scope($base + ['elementor' => ['posts' => [219], 'global' => true]]));
        $this->assertSame(['scope' => 'global', 'posts' => []], ContentElementor::scope($base + ['elementor' => ['posts' => range(1, 201), 'global' => false]]));
        $this->assertSame(['scope' => 'global', 'posts' => []], ContentElementor::scope($base + ['elementor' => ['posts' => 'kaputt']]));
    }

    /** Ein Nachher-Abbild von vor 0.9.1 nennt den Umfang nicht: mit Beiträgen oder einer Option von Elementor wie bisher alles. */
    public function testChangesOfAnOlderAgentFallBackToGlobal(): void
    {
        $old = ['posts' => [219], 'revisions' => [], 'terms' => [], 'term_taxonomy' => [], 'options' => [], 'rewrite' => false];
        $this->assertSame('global', ContentElementor::scope($old)['scope']);
        $this->assertSame('global', ContentElementor::scope(['posts' => [], 'options' => ['elementor_cpt_support']] + $old)['scope']);
        $this->assertSame('none', ContentElementor::scope(['posts' => [], 'options' => ['blogname']] + $old)['scope']);
    }

    /** E2: je Beitrag die drei Meta und die CSS-Datei – nichts sonst, kein Symlink, kein fremder Name. */
    public function testClearTakesOnlyTheCacheOfTheNamedPosts(): void
    {
        // Mit Zeichen im Pfad, die für glob() Muster wären.
        $dir = sys_get_temp_dir() . '/wpsync-elementor-[' . bin2hex(random_bytes(4)) . ']';
        mkdir($dir . '/elementor/css', 0777, true);
        mkdir($dir . '/aussen');
        file_put_contents($dir . '/aussen/ziel.css', 'fremd');
        foreach (['post-219.css', 'post-220.css', 'post-2190.css', 'global.css', 'loop-219.css'] as $name) {
            file_put_contents($dir . '/elementor/css/' . $name, 'x');
        }
        symlink($dir . '/aussen/ziel.css', $dir . '/elementor/css/post-221.css');
        $store = new ContentMemory(['postmeta' => [
            "219\0_elementor_css"           => ['values' => ['a']],
            "219\0_elementor_element_cache" => ['values' => ['b']],
            "219\0_elementor_page_assets"   => ['values' => ['c']],
            "219\0_elementor_data"          => ['values' => ['[]']],
            "220\0_elementor_css"           => ['values' => ['y']],
            "221\0_elementor_css"           => ['values' => ['z']],
        ]]);
        ContentElementor::clear($store, $dir, [219, 221]);
        $this->assertSame(["219\0_elementor_data", "220\0_elementor_css"], array_keys($store->data['postmeta']));
        $this->assertFileDoesNotExist($dir . '/elementor/css/post-219.css');
        foreach (['post-220.css', 'post-2190.css', 'global.css', 'loop-219.css'] as $name) {
            $this->assertFileExists($dir . '/elementor/css/' . $name);
        }
        $this->assertTrue(is_link($dir . '/elementor/css/post-221.css'), 'ein Symlink bleibt');
        $this->assertSame('fremd', file_get_contents($dir . '/aussen/ziel.css'));
        $this->assertSame(['begin', 'delete postmeta:' . "219\0_elementor_css", 'delete postmeta:' . "219\0_elementor_element_cache", 'delete postmeta:' . "219\0_elementor_page_assets",
            'delete postmeta:' . "221\0_elementor_css", 'delete postmeta:' . "221\0_elementor_element_cache", 'delete postmeta:' . "221\0_elementor_page_assets", 'commit'], $store->log);

        // Ohne Ordner uploads (er liegt nicht im Ziel): nur die Meta. Ist der Ordner css selbst ein Symlink: keine Datei.
        ContentElementor::clear($store, '', [220]);
        $this->assertSame(["219\0_elementor_data"], array_keys($store->data['postmeta']));
        $this->assertFileExists($dir . '/elementor/css/post-220.css');
        rename($dir . '/elementor/css', $dir . '/echt');
        symlink($dir . '/echt', $dir . '/elementor/css');
        ContentElementor::clear($store, $dir, [220]);
        $this->assertFileExists($dir . '/echt/post-220.css');
        exec('rm -rf ' . escapeshellarg($dir));
    }
}
