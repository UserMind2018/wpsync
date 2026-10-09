<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\TestCase;
use WpSync\ContentException;
use WpSync\ContentPlugins;

require_once __DIR__ . '/ContentFixtures.php';
require_once __DIR__ . '/ContentMemory.php';

/** Zählt, ob beim Lesen eines Optionswerts je ein Objekt entstand (AC-189). */
final class ContentPluginsProbe
{
    /** @var int */
    public static $woke = 0;

    public function __wakeup(): void
    {
        self::$woke++;
    }

    public function __destruct()
    {
        self::$woke++;
    }
}

/**
 * Die Option active_plugins als Liste (Spec Content-Push P4 §7.3, §8.3; AC-177, AC-189, AC-197):
 * gelesen ohne Klassen, gepackt wie der Core, geändert nur als Delta in fester Form.
 */
final class ContentPluginsTest extends TestCase
{
    public function testParseReadsOnlyAListOfStrings(): void
    {
        $this->assertSame(['a/a.php', 'b/b.php'], ContentPlugins::parse(serialize(['a/a.php', 'b/b.php'])));
        $this->assertSame([], ContentPlugins::parse('a:0:{}'));
        // Lücken in den Schlüsseln, wie deactivate_plugins() sie hinterlässt – die Reihenfolge bleibt.
        $this->assertSame(['c/c.php', 'a/a.php', 'hello.php'], ContentPlugins::parse(serialize([4 => 'c/c.php', 0 => 'a/a.php', 9 => 'hello.php'])));
        $bad = [
            null, 12, false, '', 'kaputt', 'b:0;', serialize('a/a.php'), serialize(['a/a.php', 7]), serialize([['x']]),
            serialize(['a/a.php', null]), 'O:8:"stdClass":0:{}', 'a:1:{i:0;O:8:"stdClass":0:{}}', 'a:2:{i:0;s:7:"a/a.php";', ['a/a.php'],
        ];
        foreach ($bad as $i => $value) {
            $this->assertNull(ContentPlugins::parse($value), 'Fall ' . $i);
        }
        $this->assertNull(ContentPlugins::parse(str_repeat('x', 1048577)), 'über 1 MiB wird nicht gelesen');
    }

    /** AC-189: ein Objekt im Optionswert wird nie instanziiert – weder allein noch in der Liste. */
    public function testNeverInstantiatesAnObject(): void
    {
        $alone  = serialize(new ContentPluginsProbe());
        $inside = serialize(['a/a.php', new ContentPluginsProbe()]);
        ContentPluginsProbe::$woke = 0;
        $this->assertNull(ContentPlugins::parse($alone));
        $this->assertNull(ContentPlugins::parse($inside));
        $this->assertSame(0, ContentPluginsProbe::$woke);
    }

    public function testValidKnowsTheFormOfAnEntry(): void
    {
        foreach (['a/a.php', 'woo-commerce/woocommerce.php', 'A1._-x/sub dir/main file.php', 'a/b/c.php', 'borlabs-cookie/borlabs-cookie.php'] as $entry) {
            $this->assertTrue(ContentPlugins::valid($entry), $entry);
        }
        $bad = [
            'hello.php', '/a.php', 'a/', 'a/.php', 'a/a.txt', 'a/b.PHP', '../a.php', 'a/../b.php', 'a/./b.php', 'a//b.php', '.a/a.php', '-a/a.php',
            "a/b\x01.php", "a/b\x7f.php", 'a/b\\c.php', "a/b\0.php", "a/\xff.php", str_repeat('a', 250) . '/a.php', 12, null, ['a/a.php'],
        ];
        foreach ($bad as $i => $entry) {
            $this->assertFalse(ContentPlugins::valid($entry), 'Fall ' . $i);
        }
        $this->assertSame('a', ContentPlugins::slug('a/b/c.php'));
        $this->assertSame('woo-commerce', ContentPlugins::slug('woo-commerce/woocommerce.php'));
    }

    /** AC-177, Task 0 Nr. 2: derselbe Wert, den activate_plugin() über update_option() schreibt. */
    public function testPackIsWhatTheCoreWrites(): void
    {
        $this->assertSame('a:2:{i:0;s:7:"a/a.php";i:1;s:7:"b/b.php";}', ContentPlugins::pack(['b/b.php', 'a/a.php']));
        // Die Schritte des Core auf einer Liste mit Lücken: $current[] = $plugin; sort($current); serialize().
        $current   = [4 => 'c/c.php', 0 => 'a/a.php'];
        $current[] = 'b/b.php';
        sort($current);
        $have = ContentPlugins::parse(serialize([4 => 'c/c.php', 0 => 'a/a.php']));
        $this->assertSame(serialize($current), ContentPlugins::pack(ContentPlugins::apply((array) $have, ['b/b.php'], [])));
        $this->assertSame('a:0:{}', ContentPlugins::pack([]));
    }

    public function testChangeNamesOnlyWhatReallyChanges(): void
    {
        $list = ['akismet/akismet.php', 'old/old.php', 'old/extra.php', 'older/older.php', 'hello.php'];
        $this->assertSame(
            ['added' => ['neu/neu.php'], 'removed' => ['old/old.php', 'old/extra.php']],
            ContentPlugins::change($list, ['neu/neu.php', 'akismet/akismet.php'], ['old', 'fehlt'])
        );
        // A11: schon aktiv bzw. schon inaktiv – nichts zu tun.
        $this->assertSame(['added' => [], 'removed' => []], ContentPlugins::change($list, ['akismet/akismet.php'], ['fehlt']));
        // „old“ trifft „older/…“ nicht: verglichen wird mit dem Schrägstrich.
        $this->assertSame(['added' => [], 'removed' => ['older/older.php']], ContentPlugins::change($list, [], ['older']));
        // V4: ein zu streichender Eintrag ohne die Form des Abbilds, zu viele Einträge, ein ungültiger neuer.
        $this->assertNull(ContentPlugins::change(["old/a\x01.php"], [], ['old']));
        $many = [];
        for ($i = 0; $i <= ContentPlugins::MAX; $i++) {
            $many[] = 'old/p' . $i . '.php';
        }
        $this->assertNull(ContentPlugins::change($many, [], ['old']));
        $this->assertNull(ContentPlugins::change([], ['hello.php'], []));
    }

    public function testDeltaChecksTheFieldOfTheBeforeImage(): void
    {
        $this->assertSame(['added' => [], 'removed' => []], ContentPlugins::delta(null), 'ohne Feld: der Push hatte keinen Plugin-Zustand');
        $ok = ['added' => ['a/a.php'], 'removed' => ['b/b.php', 'b/c.php']];
        $this->assertSame($ok, ContentPlugins::delta($ok));
        $this->assertSame($ok, ContentPlugins::delta($ok + ['h' => 'egal', 'staging_hooks' => true]), 'weitere Felder zählen hier nicht');
        $full = [];
        for ($i = 0; $i <= ContentPlugins::MAX; $i++) {
            $full[] = 'a/p' . $i . '.php';
        }
        $bad = [
            'kein array', [], ['added' => ['a/a.php']], ['added' => 'a/a.php', 'removed' => []], ['added' => [], 'removed' => 'b/b.php'],
            ['added' => ['../a.php'], 'removed' => []], ['added' => ['hello.php'], 'removed' => []], ['added' => [7], 'removed' => []],
            ['added' => ['a/a.php', 'a/a.php'], 'removed' => []], ['added' => ['a/a.php'], 'removed' => ['a/a.php']],
            ['added' => $full, 'removed' => []], ['added' => [], 'removed' => $full],
        ];
        foreach ($bad as $i => $raw) {
            $this->assertNull(ContentPlugins::delta($raw), 'Fall ' . $i);
        }
    }

    public function testApplySettledAndUndo(): void
    {
        $list = ['b/b.php', 'a/a.php', 'b/b.php'];
        $this->assertSame(['a/a.php', 'c/c.php'], ContentPlugins::apply($list, ['c/c.php', 'a/a.php'], ['b/b.php']), 'jedes Vorkommen geht, nichts kommt doppelt');
        $this->assertTrue(ContentPlugins::settled(['a/a.php', 'c/c.php'], ['c/c.php'], ['b/b.php']));
        $this->assertFalse(ContentPlugins::settled(['a/a.php'], ['c/c.php'], []));
        $this->assertFalse(ContentPlugins::settled(['a/a.php', 'b/b.php'], [], ['b/b.php']));
        // Rücknahme als Delta (A21): raus, was der Push hinzufügte und noch dasteht; rein, was er strich und noch fehlt.
        $delta = ['added' => ['neu/neu.php', 'weg/weg.php'], 'removed' => ['alt/alt.php', 'wieder/wieder.php']];
        $this->assertSame(
            ['deactivated' => ['neu/neu.php'], 'reactivated' => ['alt/alt.php']],
            ContentPlugins::undo(['neu/neu.php', 'wieder/wieder.php', 'fremd/fremd.php'], $delta)
        );
        $this->assertSame(['deactivated' => [], 'reactivated' => []], ContentPlugins::undo(['wieder/wieder.php', 'alt/alt.php'], $delta));
    }

    public function testLandedReadsTheListWithoutALock(): void
    {
        $store = new ContentMemory(['options' => ['active_plugins' => ContentFixtures::option('active_plugins', serialize(['a/a.php']), '33')]]);
        $this->assertTrue(ContentPlugins::landed($store, ['a/a.php'], ['b/b.php']));
        $this->assertFalse(ContentPlugins::landed($store, ['b/b.php'], []));
        $this->assertSame([], $store->locked);
        $store->data['options']['active_plugins']['option_value'] = 'kaputt';
        $this->assertFalse(ContentPlugins::landed($store, ['a/a.php'], []));
        // Ohne Delta gibt es nichts nachzusehen – auch wenn die Zeile fehlt (ein Push ohne Plugin-Zustand).
        $this->assertTrue(ContentPlugins::landed(new ContentMemory(), [], []));
    }

    /** V5: die Gründe plugins_* tragen ihre Statuscodes; die Einheiten stehen unter plugins. */
    public function testThePluginReasonsOfTheException(): void
    {
        $failed = ContentPlugins::failed();
        $this->assertSame([ContentException::PLUGINS_FAILED, 409], [$failed->reason(), $failed->status()]);
        $this->assertStringNotContainsString('.php', $failed->getMessage(), 'nie ein Eintrag der Liste');
        $this->assertSame(400, (new ContentException(ContentException::PLUGINS_INVALID, 'x'))->status());
        $this->assertSame(403, (new ContentException(ContentException::PLUGINS_NOT_ALLOWED, 'x'))->status());
        foreach ([ContentException::PLUGINS_REQUIREMENTS, ContentException::PLUGINS_UNSUPPORTED, ContentException::PLUGINS_RESCUE_DB] as $reason) {
            $this->assertSame(409, (new ContentException($reason, 'x'))->status(), $reason);
        }
        $refused = new ContentException(ContentException::PLUGINS_REQUIREMENTS, 'nein', [], ['plugins' => [['unit' => 'plugins/a', 'why' => 'requires_php', 'needs' => '8.2', 'has' => '8.0']]]);
        $this->assertSame(
            ['code' => 'plugins_requirements', 'message' => 'nein', 'plugins' => [['unit' => 'plugins/a', 'why' => 'requires_php', 'needs' => '8.2', 'has' => '8.0']]],
            $refused->toArray()
        );
        $this->assertSame($refused->toArray(), ContentException::fromArray($refused->toArray())->toArray());
    }
}
