<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use WpSync\ContentApply;
use WpSync\ContentException;
use WpSync\ContentImage;
use WpSync\ContentPackage;

require_once __DIR__ . '/ContentApplyCase.php';

/**
 * Der Plugin-Zustand im DB-Schritt des Commits (Spec Content-Push P4 §7.3, §8.1, §8.5; AC-177,
 * AC-182, AC-183, AC-197, AC-203, AC-209): eine Transaktion mit den Zeilen des Pakets – oder ganz
 * ohne Paket –, die Liste auf dem Server gebaut, im Vorher-Abbild nur das Delta.
 */
final class ContentApplyPluginsTest extends ContentApplyCase
{
    private const ACTIVE = ['akismet/akismet.php', 'old/old.php'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->store->data['options']['active_plugins'] = ContentFixtures::option('active_plugins', serialize(self::ACTIVE), '33');
    }

    /**
     * @param list<array<string, mixed>>|null $rows Zeilen eines Pakets; null: der Satz hat keins
     * @param list<string>                    $add  aufgelöste Einträge
     * @param list<string>                    $drop Ordner
     * @return array<string, mixed>
     */
    private function plug(?array $rows, array $add, array $drop, ?callable $gate = null): array
    {
        $package = null;
        if ($rows !== null) {
            $this->files[] = $file = ContentFixtures::file($rows);
            $package       = ContentPackage::read($file);
        }
        return ContentApply::run($package, ContentFixtures::live($this->store), $this->dir, 7, self::NOW, '2026-10-09 14:13:20', $gate, ['add' => $add, 'drop' => $drop]);
    }

    private function value(): string
    {
        return (string) $this->store->data['options']['active_plugins']['option_value'];
    }

    /** @param list<string> $log */
    private static function last(array $log): string
    {
        return (string) ($log[count($log) - 1] ?? '');
    }

    /** AC-202, A7: ein Satz ohne Paket hat einen DB-Schritt ohne Paketzeilen. */
    public function testWritesTheListWithoutAPackage(): void
    {
        $result = $this->plug(null, ['kunde/kunde.php'], ['old']);
        $value  = serialize(['akismet/akismet.php', 'kunde/kunde.php']);
        $delta  = ['added' => ['kunde/kunde.php'], 'removed' => ['old/old.php']];
        // autoload und option_id der Zeile bleiben (AC-177).
        $this->assertSame(['option_id' => '33', 'option_name' => 'active_plugins', 'option_value' => $value, 'autoload' => 'yes'], $this->store->data['options']['active_plugins']);
        $this->assertSame(0, $result['rows']);
        $this->assertSame([], $result['after'], 'content.after nennt active_plugins nie (§4.3)');
        $this->assertSame($delta, $result['plugins']);
        $changes = ['posts' => [], 'revisions' => [], 'terms' => [], 'term_taxonomy' => [], 'options' => ['active_plugins'], 'rewrite' => true, 'plugins' => $delta + ['h' => hash('sha256', $value)]];
        $this->assertSame($changes, $result['changes']);
        $this->assertSame(['begin', 'write options:active_plugins', 'commit'], $this->store->log);
        $this->assertSame(['options:active_plugins'], $this->store->locked, 'gelesen unter Sperre');
        // §8.5: das Vorher-Abbild trägt nur das Delta, das Nachher-Abbild die Nacharbeiten.
        $before = ContentImage::get($this->dir, ContentImage::BEFORE);
        $after  = ContentImage::get($this->dir, ContentImage::AFTER);
        $this->assertSame(['keys' => [], 'plugins' => $delta], $before);
        $this->assertSame(['keys' => [], 'changes' => $changes], $after);
        // AC-197: nirgends die Liste des Ziels oder ein Eintrag, den der Push nicht anfasst.
        $this->assertStringNotContainsString('akismet', (string) json_encode([$result, $before, $after]));
    }

    /** AC-209, §8.1 Nr. 5: Zeilen des Pakets und Liste in einer Transaktion, die Liste zuletzt. */
    public function testOneTransactionWithTheRowsOfThePackage(): void
    {
        $result = $this->plug($this->rows(), ['kunde/kunde.php'], ['old']);
        $this->assertSame(12, $result['rows']);
        $this->assertSame('Kunde GmbH', $this->store->data['options']['blogname']['option_value']);
        $this->assertSame(serialize(['akismet/akismet.php', 'kunde/kunde.php']), $this->value());
        $this->assertCount(1, array_keys($this->store->log, 'begin', true));
        $this->assertSame('commit', self::last($this->store->log));
        $this->assertSame('write options:active_plugins', $this->store->log[count($this->store->log) - 2], 'ein Schreibzugriff auf die Liste, nach den Zeilen des Pakets');
        $this->assertCount(1, array_keys($this->store->log, 'write options:active_plugins', true));
        $this->assertGreaterThan(array_search('options:blogname', $this->store->locked, true), array_search('options:active_plugins', $this->store->locked, true), 'erst die Zeilen sperren, dann die Liste');
        $this->assertSame(['blogname', 'page_on_front', 'active_plugins'], $result['changes']['options']);
        $this->assertTrue($result['changes']['rewrite']);
        // V2: active_plugins steht nie unter den Schlüsseln – weder in content.after noch in einem Abbild.
        $this->assertNotContains('active_plugins', array_column($result['after'], 'k'));
        $before = ContentImage::get($this->dir, ContentImage::BEFORE);
        $this->assertNotContains('active_plugins', array_column($before['keys'], 'k'));
        $this->assertSame(['added' => ['kunde/kunde.php'], 'removed' => ['old/old.php']], $before['plugins']);
    }

    /** AC-182, AC-203, A11: schon im gewünschten Zustand – nichts wird geschrieben, kein Byte ändert sich. */
    public function testAlreadyInTheWantedStateWritesNothing(): void
    {
        $gaps = serialize([3 => 'old/old.php', 0 => 'akismet/akismet.php']); // wie nach einem deactivate_plugins() im WP-Admin
        $this->store->data['options']['active_plugins']['option_value'] = $gaps;
        $result = $this->plug(null, ['akismet/akismet.php'], ['fehlt']);
        $this->assertSame(['added' => [], 'removed' => []], $result['plugins']);
        $this->assertSame($gaps, $this->value());
        $this->assertSame(['begin', 'commit'], $this->store->log);
        $this->assertSame([], $result['changes']['options']);
        $this->assertFalse($result['changes']['rewrite']);
        $this->assertSame(['added' => [], 'removed' => [], 'h' => null], $result['changes']['plugins']);
        $this->assertSame(['keys' => [], 'plugins' => ['added' => [], 'removed' => []]], ContentImage::get($this->dir, ContentImage::BEFORE));

        // Derselbe Aufruf zweimal ergibt dieselbe Liste.
        $first = $this->plug(null, ['kunde/kunde.php'], []);
        $once  = $this->value();
        $again = $this->plug(null, ['kunde/kunde.php'], []);
        $this->assertSame(['kunde/kunde.php'], $first['plugins']['added']);
        $this->assertSame([], $again['plugins']['added']);
        $this->assertSame($once, $this->value());
    }

    /** AC-177, Task 0 Nr. 2: bytegleich mit dem, was activate_plugin() auf derselben Liste hinterliesse. */
    public function testTheListIsBuiltLikeTheCoreDoes(): void
    {
        $have = [4 => 'old/old.php', 1 => 'akismet/akismet.php', 7 => 'hello.php'];
        $this->store->data['options']['active_plugins']['option_value'] = serialize($have);
        $this->plug(null, ['kunde/kunde.php'], []);
        $core   = $have;
        $core[] = 'kunde/kunde.php';
        sort($core);
        $this->assertSame(serialize($core), $this->value());
    }

    /** @return array<string, array{0: string|null}> */
    public static function unreadable(): array
    {
        return [
            'die Zeile fehlt'          => [null],
            'kein serialisierter Wert' => ['kaputt'],
            'leer'                     => [''],
            'ein Objekt'               => ['O:8:"stdClass":0:{}'],
            'eine Zahl in der Liste'   => [serialize(['a/a.php', 7])],
        ];
    }

    /** AC-183: ist active_plugins nicht lesbar, bleibt weder eine Zeile des Pakets noch eine Änderung der Liste. */
    #[DataProvider('unreadable')]
    public function testAnUnreadableListFailsTheWholeStep(?string $value): void
    {
        $rows = $this->rows();
        if ($value === null) {
            unset($this->store->data['options']['active_plugins']);
        } else {
            $this->store->data['options']['active_plugins']['option_value'] = $value;
        }
        $old = $this->store->data;
        try {
            $this->plug($rows, ['kunde/kunde.php'], []);
            $this->fail('nicht abgelehnt');
        } catch (ContentException $e) {
            $this->assertSame(ContentException::PLUGINS_FAILED, $e->reason());
        }
        $this->assertSame($old, $this->store->data);
        $this->assertSame('rollback', self::last($this->store->log));
        $this->assertNotContains('commit', $this->store->log);
        $this->assertNull(ContentImage::get($this->dir, ContentImage::BEFORE), 'ohne Vorher-Abbild wurde nie geschrieben');
    }

    /** AC-183, AC-209: scheitert eine Zeile des Pakets, ist auch an der Liste nichts geschehen. */
    public function testAFailingRowOfThePackageLeavesTheListAlone(): void
    {
        $rows                   = $this->rows();
        $old                    = $this->store->data;
        $this->store->failWrite = 3;
        try {
            $this->plug($rows, ['kunde/kunde.php'], ['old']);
            $this->fail('nicht gescheitert');
        } catch (ContentException $e) {
            $this->assertSame(ContentException::FAILED, $e->reason());
        }
        $this->assertSame($old, $this->store->data);
        $this->assertNotContains('write options:active_plugins', $this->store->log);
    }

    /** V4: ein Eintrag, den kein Abbild nennen dürfte, lässt den Schritt scheitern – statt ein Abbild zu hinterlassen, das die Rücknahme ablehnt. */
    public function testAnEntryThatNoImageMayNameFails(): void
    {
        $this->store->data['options']['active_plugins']['option_value'] = serialize(["old/a\x01.php", 'akismet/akismet.php']);
        $old = $this->store->data;
        try {
            $this->plug(null, [], ['old']);
            $this->fail('nicht abgelehnt');
        } catch (ContentException $e) {
            $this->assertSame(ContentException::PLUGINS_FAILED, $e->reason());
        }
        $this->assertSame($old, $this->store->data);
    }

    /** AC-191 (Naht R10): gefragt wird, nachdem die Liste geschrieben und beide Abbilder abgelegt sind; nein ⇒ ROLLBACK. */
    public function testTheGateIsAskedAfterTheListIsWritten(): void
    {
        $asked = 0;
        $seen  = null;
        try {
            $this->plug(null, ['kunde/kunde.php'], ['old'], function () use (&$asked, &$seen): bool {
                $asked++;
                $seen = [$this->value(), ContentImage::get($this->dir, ContentImage::AFTER) !== null];
                return false;
            });
            $this->fail('trotz „nein“ festgeschrieben');
        } catch (ContentException $e) {
            $this->assertSame(ContentException::FAILED, $e->reason());
        }
        $this->assertSame(1, $asked);
        $this->assertSame([serialize(['akismet/akismet.php', 'kunde/kunde.php']), true], $seen);
        $this->assertSame(serialize(self::ACTIVE), $this->value(), 'die Transaktion ist zurückgenommen');
        $this->assertSame('rollback', self::last($this->store->log));
    }

    /** Geht die Verbindung im COMMIT verloren, entscheidet bei einem Satz ohne Paket allein die Liste, ob er ankam. */
    public function testAnUnclearCommitIsDecidedByTheList(): void
    {
        $start                     = $this->store->data;
        $this->store->loseAtCommit = 'landed';
        $result                    = $this->plug(null, ['kunde/kunde.php'], ['old']);
        $this->assertSame(['added' => ['kunde/kunde.php'], 'removed' => ['old/old.php']], $result['plugins']);
        $this->assertSame(serialize(['akismet/akismet.php', 'kunde/kunde.php']), $this->value());

        $this->store               = new ContentMemory($start);
        $this->store->loseAtCommit = 'discarded';
        try {
            $this->plug(null, ['kunde/kunde.php'], ['old']);
            $this->fail('ein verworfener COMMIT gilt nicht als angewandt');
        } catch (ContentException $e) {
            $this->assertSame(ContentException::FAILED, $e->reason());
        }
        $this->assertSame($start, $this->store->data);
    }

    /** D23: geht die Verbindung beim Schreiben der Liste verloren, steht danach wieder der Wert von vorher. */
    public function testALostConnectionAtTheListIsRepaired(): void
    {
        $old                      = $this->store->data;
        $this->store->loseAtWrite = 1;
        try {
            $this->plug(null, ['kunde/kunde.php'], ['old']);
            $this->fail('nicht gescheitert');
        } catch (ContentException $e) {
            $this->assertSame(ContentException::FAILED, $e->reason());
            $this->assertArrayNotHasKey('unrestored', $e->toArray());
        }
        $this->assertSame($old, $this->store->data);
    }

    /** Ohne Paket prüft kein ContentCheck die Engine: der Schritt tut es selbst, vor START TRANSACTION. */
    public function testWithoutAPackageTheEngineIsStillChecked(): void
    {
        $this->store->engines['options'] = 'MyISAM';
        try {
            $this->plug(null, ['kunde/kunde.php'], []);
            $this->fail('MyISAM angenommen');
        } catch (ContentException $e) {
            $this->assertSame(ContentException::ENGINE, $e->reason());
        }
        $this->assertSame([], $this->store->log);
    }

    /** Ohne Plugin-Zustand ist alles wie vor P4: kein Feld plugins, die Liste wird nicht einmal gelesen. */
    public function testWithoutAPluginStateNothingChanges(): void
    {
        $result = $this->apply($this->rows());
        $this->assertSame(['rows', 'after', 'changes'], array_keys($result));
        $this->assertArrayNotHasKey('plugins', $result['changes']);
        $this->assertSame(['keys'], array_keys((array) ContentImage::get($this->dir, ContentImage::BEFORE)));
        $this->assertNotContains('options:active_plugins', $this->store->locked);
        $this->assertSame(serialize(self::ACTIVE), $this->value());
    }
}
