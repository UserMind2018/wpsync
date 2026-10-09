<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use WpSync\ContentException;
use WpSync\ContentImage;
use WpSync\ContentRollback;

require_once __DIR__ . '/ContentRollbackPluginsCase.php';

/** Zählt, ob beim Lesen der Option je ein Objekt entstand (AC-189). */
final class ContentRollbackProbe
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
 * Rücknahme des Plugin-Zustands als Delta (Spec Content-Push P4 §8.3, §12; AC-184, AC-189, AC-197,
 * AC-203, AC-204, AC-209, AC-210): beide Richtungen, zusammen mit den Zeilen des Pakets, und nur
 * nach dem, was das authentisierte Vorher-Abbild nennt.
 */
final class ContentRollbackPluginsTest extends ContentRollbackPluginsCase
{
    private const NONE = ['deactivated' => [], 'reactivated' => []];

    /** AC-184: die Einträge des Pushs sind weg; ein zweiter Lauf findet nichts mehr zu tun. */
    public function testTakesAnActivationBack(): void
    {
        $old    = $this->store->data;
        $result = $this->push(null, ['kunde/kunde.php'], []);
        $this->assertSame(['akismet/akismet.php', 'kunde/kunde.php', 'old/old.php'], $this->active());
        $this->store->log    = [];
        $this->store->locked = [];

        $back = $this->back();
        $this->assertSame(['state' => ContentRollback::DONE, 'changes' => $result['changes'], 'plugins' => ['deactivated' => ['kunde/kunde.php'], 'reactivated' => []]], $back);
        $this->assertSame($old, $this->store->data);
        $this->assertSame(['begin', 'write options:active_plugins', 'commit'], $this->store->log);
        $this->assertSame(['options:active_plugins'], $this->store->locked, 'gelesen unter Sperre');

        $this->assertSame(['state' => ContentRollback::NOTHING, 'changes' => null, 'plugins' => self::NONE], $this->back());
        $this->assertSame($old, $this->store->data);
    }

    /** AC-204: genau die gestrichenen Einträge stehen wieder in der Liste. */
    public function testTakesADeactivationBack(): void
    {
        $this->store->data['options']['active_plugins']['option_value'] = serialize(['akismet/akismet.php', 'old/extra.php', 'old/old.php']);
        $old = $this->store->data;
        $this->push(null, [], ['old']);
        $this->assertSame(['akismet/akismet.php'], $this->active());

        $back = $this->back();
        $this->assertSame(ContentRollback::DONE, $back['state']);
        $this->assertSame(['deactivated' => [], 'reactivated' => ['old/extra.php', 'old/old.php']], $back['plugins']);
        $this->assertSame($old, $this->store->data);
    }

    /** AC-209: Aktivieren und Deaktivieren gehen in einem Zug zurück – ein Schreibzugriff auf die Liste. */
    public function testBothDirectionsInOneWrite(): void
    {
        $old = $this->store->data;
        $this->push(null, ['kunde/kunde.php', 'neu/neu.php'], ['old']);
        $this->store->log = [];
        $back = $this->back();
        $this->assertSame(['deactivated' => ['kunde/kunde.php', 'neu/neu.php'], 'reactivated' => ['old/old.php']], $back['plugins']);
        $this->assertSame($old, $this->store->data);
        $this->assertCount(1, array_keys($this->store->log, 'write options:active_plugins', true));
    }

    /** AC-184, AC-209: mit den Zeilen des Pakets in einer Transaktion – Zeilen zuerst, dann die Liste. */
    public function testTogetherWithTheRowsOfThePackage(): void
    {
        $rows   = $this->rows();
        $old    = $this->store->data;
        $result = $this->push($rows, ['kunde/kunde.php'], ['old']);
        $this->store->log = [];

        $back = $this->back();
        $this->assertSame(ContentRollback::DONE, $back['state']);
        $this->assertSame($result['changes'], $back['changes'], 'dieselben Nacharbeiten wie nach dem Push – samt active_plugins und rewrite');
        $this->assertSame(['deactivated' => ['kunde/kunde.php'], 'reactivated' => ['old/old.php']], $back['plugins']);
        $this->assertSame(self::sorted($old), self::sorted($this->store->data));
        $this->assertCount(1, array_keys($this->store->log, 'begin', true));
        $this->assertSame('write options:active_plugins', $this->store->log[count($this->store->log) - 2]);
        $this->assertSame('commit', $this->store->log[count($this->store->log) - 1]);
    }

    /** AC-182, AC-203: ein Push, der an der Liste nichts geändert hat, nimmt auch nichts zurück – die Liste wird nicht einmal gelesen. */
    public function testAPushThatChangedNothingTakesNothingBack(): void
    {
        $this->push(null, ['akismet/akismet.php'], ['fehlt']);
        $stands              = $this->store->data;
        $this->store->locked = [];
        $this->assertSame(['state' => ContentRollback::NOTHING, 'changes' => null, 'plugins' => self::NONE], $this->back());
        $this->assertSame([], $this->store->locked);
        $this->assertSame($stands, $this->store->data);
        // Auch wenn inzwischen jemand genau dieses Plugin abschaltet: die Rücknahme aktiviert es nicht.
        $this->adminDeactivates('akismet/akismet.php');
        $this->assertSame(ContentRollback::NOTHING, $this->back()['state']);
        $this->assertSame(['old/old.php'], $this->active());
    }

    /** AC-197, §8.5: das Vorher-Abbild trägt das Delta – nie die Liste des Ziels, nie einen Wert. */
    public function testTheBeforeImageNeverCarriesTheList(): void
    {
        $this->push(null, ['kunde/kunde.php'], ['old']);
        $before = ContentImage::get($this->dir, ContentImage::BEFORE);
        $this->assertSame(['keys' => [], 'plugins' => ['added' => ['kunde/kunde.php'], 'removed' => ['old/old.php']]], $before);
        $this->assertStringNotContainsString('akismet', (string) file_get_contents($this->dir . '/' . ContentImage::BEFORE));
        $this->assertStringNotContainsString('akismet', (string) file_get_contents($this->dir . '/' . ContentImage::AFTER));
    }

    /** @return array<string, array{0: mixed}> */
    public static function badFields(): array
    {
        $full = [];
        for ($i = 0; $i <= 100; $i++) {
            $full[] = 'a/p' . $i . '.php';
        }
        return [
            'kein Objekt'          => ['x'],
            'ohne removed'         => [['added' => []]],
            'keine Liste'          => [['added' => 'a/a.php', 'removed' => []]],
            'Pfad nach oben'       => [['added' => ['../../evil.php'], 'removed' => []]],
            'Pfad in der Mitte'    => [['added' => [], 'removed' => ['a/../b.php']]],
            'einzelne Datei'       => [['added' => [], 'removed' => ['hello.php']]],
            'keine Zeichenkette'   => [['added' => [7], 'removed' => []]],
            'doppelt'              => [['added' => [], 'removed' => ['a/a.php', 'a/a.php']]],
            'in beiden'            => [['added' => ['a/a.php'], 'removed' => ['a/a.php']]],
            'mehr als 100'         => [['added' => [], 'removed' => $full]],
        ];
    }

    /** §8.3 Nr. 1: ein Feld plugins, das nicht die feste Form hat, macht das ganze Abbild ungültig. */
    #[DataProvider('badFields')]
    public function testAMalformedPluginFieldInvalidatesTheImage($field): void
    {
        $this->push(null, ['kunde/kunde.php'], []);
        ContentImage::put($this->dir, ContentImage::BEFORE, ['keys' => [], 'plugins' => $field]);
        $this->store->log = [];
        $this->assertSame(ContentException::IMAGE, $this->refused()->reason());
        $this->assertSame([], $this->store->log, 'abgelehnt, bevor eine Transaktion beginnt');
    }

    /**
     * AC-189, AC-210: was zurückgeschaltet wird, bestimmt nur das authentisierte Abbild. Wer es verändert –
     * etwa um über removed ein fremdes Plugin einzuschalten –, bekommt before_image_invalid.
     */
    public function testATamperedImageSwitchesNothing(): void
    {
        ContentImage::$keys    = [hash('sha256', 'schlüssel der installation', true)];
        ContentImage::$encrypt = false; // signiert, lesbar – damit sich der Text gezielt fälschen lässt
        $this->push(null, [], ['old']);
        $file = $this->dir . '/' . ContentImage::BEFORE;
        $raw  = (string) file_get_contents($file);
        $this->assertStringContainsString('old/old.php', $raw);
        file_put_contents($file, str_replace('old/old.php', 'bad/bad.php', $raw));

        $this->assertSame(ContentException::IMAGE, $this->refused()->reason());
        $this->assertSame(['akismet/akismet.php'], $this->active(), 'weder das fremde noch das eigene Plugin ist eingeschaltet');

        // Ein Klartext-Abbild gilt nicht, sobald die Installation einen Schlüssel hat.
        file_put_contents($file, (string) json_encode(['keys' => [], 'plugins' => ['added' => [], 'removed' => ['bad/bad.php']]]));
        $this->assertSame(ContentException::IMAGE, $this->refused()->reason());
    }

    /** AC-189, §8.3 Nr. 3: ein Objekt im Optionswert wird nie instanziiert; die Liste gilt dann als nicht lesbar. */
    public function testAnObjectInTheOptionIsNeverInstantiated(): void
    {
        $this->push(null, ['kunde/kunde.php'], []);
        $payload = serialize(['akismet/akismet.php', new ContentRollbackProbe()]);
        $this->store->data['options']['active_plugins']['option_value'] = $payload;
        ContentRollbackProbe::$woke = 0;
        $e = $this->refused();
        $this->assertSame(ContentException::CHANGED, $e->reason());
        $this->assertSame([['table' => 'options', 'key' => 'active_plugins']], $e->keys());
        $this->assertSame(0, ContentRollbackProbe::$woke);
        $this->assertStringNotContainsString('akismet', $e->getMessage());
    }

    /** §8.3 Nr. 3: ist die Liste nicht lesbar, bleibt der Satz ganz – auch die Zeilen des Pakets gehen nicht zurück. */
    public function testAnUnreadableListKeepsTheWholeSet(): void
    {
        $this->push($this->rows(), ['kunde/kunde.php'], []);
        foreach (['kaputt', '', serialize('a/a.php')] as $value) {
            $this->store->data['options']['active_plugins']['option_value'] = $value;
            $e = $this->refused();
            $this->assertSame(ContentException::CHANGED, $e->reason());
            $this->assertSame([['table' => 'options', 'key' => 'active_plugins']], $e->keys());
        }
        unset($this->store->data['options']['active_plugins']);
        $this->assertSame(ContentException::CHANGED, $this->refused()->reason(), 'die Zeile fehlt');
        $this->assertSame('Neu', $this->store->data['posts']['219']['post_title'], 'die Zeilen des Pakets stehen noch');
    }

    /** A17: bei der Rücknahme läuft kein Hook – ContentRollback kennt keine Funktion von WordPress. */
    public function testTheRollbackKnowsNoWordPressFunction(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../src/ContentRollback.php') . (string) file_get_contents(__DIR__ . '/../src/ContentPlugins.php');
        $code   = (string) preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $source); // ohne Kommentare: dort stehen die Namen des Core als Erklärung
        $this->assertStringContainsString('ContentPlugins::undo(', $code);
        foreach (['do_action', 'apply_filters', 'deactivate_plugins', 'activate_plugin', 'update_option', 'get_option', 'include', 'include_once', 'require', 'require_once'] as $word) {
            $this->assertSame(0, preg_match('/\b' . $word . '\s*[\( ]/', $code), $word);
        }
    }

    /** Ohne Plugin-Zustand ist das Ergebnis wie vor P4. */
    public function testWithoutAPluginStateTheResultIsAsBefore(): void
    {
        $this->apply($this->rows());
        $back = $this->back();
        $this->assertSame(['state', 'changes'], array_keys($back));
        $this->assertSame(serialize(self::ACTIVE), $this->value());
    }
}
