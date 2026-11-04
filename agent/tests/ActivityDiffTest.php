<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\TestCase;
use WpSync\ActivityDiff;

/** Nachtbericht: aus zwei Schnappschüssen werden Ereignisse. */
final class ActivityDiffTest extends TestCase
{
    /** @return array<string, mixed> */
    private function snap(string $core, array $plugins, array $themes = ['tt5' => ['v' => '1.0', 'n' => 'TT5']], string $theme = 'tt5'): array
    {
        return ['core' => $core, 'theme' => $theme, 'plugins' => $plugins, 'themes' => $themes];
    }

    public function testSnapshotFromProbeRows(): void
    {
        $snap = ActivityDiff::snapshot('6.8.1', [
            ['slug' => 'zeta', 'name' => 'Zeta', 'version' => '2.0', 'active' => false],
            ['slug' => 'akismet', 'name' => "Akis\x1bmet", 'version' => '5.3', 'active' => true],
        ], [['slug' => 'tt5', 'name' => 'TT5', 'version' => '1.0', 'active' => true]], 'tt5');
        $this->assertSame([
            'core'    => '6.8.1',
            'theme'   => 'tt5',
            'plugins' => ['akismet' => ['v' => '5.3', 'a' => true, 'n' => 'Akis met'], 'zeta' => ['v' => '2.0', 'a' => false, 'n' => 'Zeta']],
            'themes'  => ['tt5' => ['v' => '1.0', 'n' => 'TT5']],
        ], $snap);
    }

    public function testNoChangeNoEvent(): void
    {
        $s = $this->snap('6.8.1', ['a' => ['v' => '1', 'a' => true, 'n' => 'A']]);
        $this->assertSame([], ActivityDiff::diff($s, $s));
    }

    public function testCoreAndPluginVersions(): void
    {
        $old = $this->snap('6.8.1', ['a' => ['v' => '1.0', 'a' => true, 'n' => 'A'], 'b' => ['v' => '2.0', 'a' => true, 'n' => 'B']]);
        $new = $this->snap('6.8.3', ['a' => ['v' => '1.1', 'a' => true, 'n' => 'A'], 'b' => ['v' => '1.9', 'a' => true, 'n' => 'B']]);
        $this->assertSame([
            ['kind' => 'updated', 'type' => 'core', 'slug' => 'wordpress', 'name' => 'WordPress', 'from' => '6.8.1', 'to' => '6.8.3', 'detail' => null],
            ['kind' => 'updated', 'type' => 'plugin', 'slug' => 'a', 'name' => 'A', 'from' => '1.0', 'to' => '1.1', 'detail' => null],
            ['kind' => 'downgraded', 'type' => 'plugin', 'slug' => 'b', 'name' => 'B', 'from' => '2.0', 'to' => '1.9', 'detail' => null],
        ], ActivityDiff::diff($old, $new));
    }

    public function testInstalledActiveDeletedAndSwitched(): void
    {
        $old = $this->snap('6.8.1', ['gone' => ['v' => '3', 'a' => true, 'n' => 'Gone'], 'off' => ['v' => '1', 'a' => true, 'n' => 'Off']]);
        $new = $this->snap('6.8.1', ['new' => ['v' => '0.1', 'a' => true, 'n' => 'New'], 'off' => ['v' => '1', 'a' => false, 'n' => 'Off']],
            ['tt5' => ['v' => '1.0', 'n' => 'TT5'], 'tt4' => ['v' => '1.2', 'n' => 'TT4']], 'tt4');
        $this->assertSame([
            ['kind' => 'deleted', 'type' => 'plugin', 'slug' => 'gone', 'name' => 'Gone', 'from' => '3', 'to' => null, 'detail' => null],
            ['kind' => 'installed', 'type' => 'plugin', 'slug' => 'new', 'name' => 'New', 'from' => null, 'to' => '0.1', 'detail' => null],
            ['kind' => 'activated', 'type' => 'plugin', 'slug' => 'new', 'name' => 'New', 'from' => null, 'to' => null, 'detail' => null],
            ['kind' => 'deactivated', 'type' => 'plugin', 'slug' => 'off', 'name' => 'Off', 'from' => null, 'to' => null, 'detail' => null],
            ['kind' => 'installed', 'type' => 'theme', 'slug' => 'tt4', 'name' => 'TT4', 'from' => null, 'to' => '1.2', 'detail' => null],
            ['kind' => 'theme_switched', 'type' => 'theme', 'slug' => 'tt4', 'name' => 'TT4', 'from' => 'tt5', 'to' => 'tt4', 'detail' => null],
        ], ActivityDiff::diff($old, $new));
    }

    public function testNumericSlugsCompareAsStrings(): void
    {
        $old = $this->snap('6.8.1', json_decode('{"123":{"v":"1","a":false,"n":"N"}}', true));
        $new = $this->snap('6.8.1', json_decode('{"123":{"v":"2","a":false,"n":"N"}}', true));
        $this->assertSame('123', ActivityDiff::diff($old, $new)[0]['slug']);
    }

    public function testResultsReportFailuresAndCountTranslations(): void
    {
        $this->assertSame([
            ['kind' => 'update_failed', 'type' => 'plugin', 'slug' => 'woo', 'name' => 'Woo', 'from' => null, 'to' => null, 'detail' => 'download_failed'],
            ['kind' => 'translations_updated', 'type' => 'translation', 'slug' => 'translations', 'name' => 'Übersetzungen', 'from' => null, 'to' => null, 'detail' => '2'],
        ], ActivityDiff::results([
            ['type' => 'plugin', 'slug' => 'akismet', 'name' => 'Akismet', 'ok' => true, 'error' => ''],
            ['type' => 'plugin', 'slug' => 'woo', 'name' => 'Woo', 'ok' => false, 'error' => 'download_failed'],
            ['type' => 'translation', 'slug' => 'akismet', 'name' => 'de_DE', 'ok' => true, 'error' => ''],
            ['type' => 'translation', 'slug' => 'woo', 'name' => 'de_DE', 'ok' => true, 'error' => ''],
            ['type' => 'nonsense', 'slug' => 'x', 'name' => 'x', 'ok' => false, 'error' => 'x'],
        ]));
    }

    public function testClipRemovesControlsAndCutsWholeCharacters(): void
    {
        $this->assertSame('a b', ActivityDiff::clip("a\x00\x07b"));
        $long = str_repeat('ä', 100); // 200 Byte
        $cut  = ActivityDiff::clip($long);
        $this->assertLessThanOrEqual(ActivityDiff::TEXT, strlen($cut));
        $this->assertSame(1, preg_match('//u', $cut), 'gültiges UTF-8');
    }

    /** Ungültiges UTF-8 aus einem Plugin-Header darf weder INSERT noch JSON-Antwort scheitern lassen. */
    public function testClipRepairsInvalidUtf8AndStaysWithinTheLimit(): void
    {
        $text = ActivityDiff::clip("Caf\xE9 \xC3 Plugin");
        $this->assertSame(1, preg_match('//u', $text));
        $this->assertStringStartsWith('Caf', $text);
        $long = ActivityDiff::clip(str_repeat("a\xFF", 200));
        $this->assertSame(1, preg_match('//u', $long));
        $this->assertLessThanOrEqual(ActivityDiff::TEXT, strlen($long));
    }
}
