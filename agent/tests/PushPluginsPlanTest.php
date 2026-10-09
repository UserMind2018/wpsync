<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use WpSync\ContentException;
use WpSync\ContentTarget;
use WpSync\PushContent;
use WpSync\PushPlugins;

/**
 * Der Plan des Begin und die Prüfung des Commits (Spec Content-Push P4 §4.2, §7.1, §7.2; AC-179,
 * AC-180, AC-192, AC-199, AC-200, AC-203, AC-206–AC-208, AC-210) gegen echte Ordner und einen Store
 * im Speicher. PushContent::target() setzt eine Konstante – deshalb ein Prozess je Test.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PushPluginsPlanTest extends TestCase
{
    private string $root;
    private string $content;
    private string $copy;
    private ContentMemory $live;
    private ContentMemory $stage;

    protected function setUp(): void
    {
        require_once __DIR__ . '/ContentFixtures.php';
        require_once __DIR__ . '/ContentMemory.php';
        $this->root    = (string) realpath(sys_get_temp_dir()) . '/wpsync-plan-' . bin2hex(random_bytes(4));
        $this->content = $this->root . '/wp-content';
        $this->copy    = $this->root . '/' . ContentFixtures::STAGING_DIR . '/wp-content';
        foreach ([$this->content . '/plugins', $this->copy . '/plugins', $this->root . '/new'] as $dir) {
            mkdir($dir, 0777, true);
        }
        $this->plugin($this->content, 'akismet', 'akismet.php', self::head('Akismet', '5.3'));
        $this->plugin($this->content, 'old', 'old.php', self::head('Altes Plugin', '3.2.1') . "register_deactivation_hook(__FILE__, 'old_off');\n");
        $this->plugin($this->content, 'addon', 'addon.php', self::head('Addon', '1.0', ['Requires Plugins' => 'old']));
        $this->plugin($this->content, 'da', 'da.php', self::head('Liegt da', '0.1'));
        $this->live  = new ContentMemory(['options' => ['active_plugins' => ContentFixtures::option('active_plugins', serialize(['akismet/akismet.php', 'old/old.php', 'hello.php']), '33')]]);
        $this->stage = new ContentMemory(['options' => ['active_plugins' => ContentFixtures::option('active_plugins', serialize(['akismet/akismet.php']), '33')]]);
        PushContent::$resolve = function (string $name, string $content): ContentTarget {
            return $name === 'staging' ? ContentFixtures::staging($this->stage) : ContentFixtures::live($this->live);
        };
        PushPlugins::$site = ['php' => '8.1.0', 'wp' => '6.5.2', 'multisite' => false];
        PushPlugins::$can  = static function (int $id): bool {
            return $id === 7;
        };
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    /** @param array<string, string> $more weitere Kopfzeilen */
    private static function head(string $name, string $version, array $more = []): string
    {
        $out = "<?php\n/**\n * Plugin Name: " . $name . "\n * Version: " . $version . "\n";
        foreach ($more as $field => $value) {
            $out .= ' * ' . $field . ': ' . $value . "\n";
        }
        return $out . " */\n";
    }

    private function plugin(string $content, string $slug, string $file, string $bytes): void
    {
        if (!is_dir($content . '/plugins/' . $slug)) {
            mkdir($content . '/plugins/' . $slug, 0777, true);
        }
        file_put_contents($content . '/plugins/' . $slug . '/' . $file, $bytes);
    }

    /**
     * @param list<string>                          $activate
     * @param list<string>                          $deactivate
     * @param array<string, array<string, string>> $heads
     * @return array<string, mixed>
     */
    private static function wish(array $activate, array $deactivate = [], array $heads = []): array
    {
        return ['activate' => $activate, 'deactivate' => $deactivate, 'heads' => $heads];
    }

    /** @param list<string> $list */
    private function active(ContentMemory $store, array $list): void
    {
        $store->data['options']['active_plugins']['option_value'] = serialize($list);
    }

    /** Das gebaute Verzeichnis der n-ten Einheit, wie der Commit es vor dem Tausch vorfindet. */
    private function build(int $n, string $file, string $bytes): void
    {
        if (!is_dir($this->root . '/new/' . $n)) {
            mkdir($this->root . '/new/' . $n, 0777, true);
        }
        file_put_contents($this->root . '/new/' . $n . '/' . $file, $bytes);
    }

    private function sealed(): string
    {
        $file = $this->root . '/rescue.sealed';
        file_put_contents($file, 'umschlag');
        return $file;
    }

    /**
     * @param array<string, mixed> $wish
     * @param list<string>         $units
     */
    private function checkFails(array $wish, array $units, ?int $opener, string $sealed, string $target = 'live'): ContentException
    {
        try {
            PushPlugins::check($wish, $units, $this->root . '/new', $target, $target === 'staging' ? $this->copy : $this->content, $opener, $sealed);
        } catch (ContentException $e) {
            return $e;
        }
        $this->fail('die Prüfung hat nicht abgelehnt');
    }

    /** AC-199: der Probelauf nennt je Plugin Datei, Name, Version, Stand und das Ergebnis der Kopf-Prüfung. */
    public function testPlanForANewPlugin(): void
    {
        $head = self::head('Kunde Widgets', '1.2.0', ['Requires PHP' => '8.0', 'Requires at least' => '6.2', 'Requires Plugins' => 'akismet']);
        $plan = PushPlugins::plan(self::wish(['plugins/kunde'], [], ['plugins/kunde' => ['kunde.php' => $head, 'uninstall.php' => "<?php\n"]]), ['plugins/kunde'], 'live', $this->content);
        $this->assertSame([
            'ok'          => true,
            'error'       => null,
            'activate'    => [[
                'unit' => 'plugins/kunde', 'state' => 'new', 'file' => 'kunde/kunde.php', 'name' => 'Kunde Widgets', 'version' => '1.2.0',
                'requirements' => ['checked' => 'head', 'ok' => true, 'failed' => []],
            ]],
            'deactivate'  => [],
            'warnings'    => [],
            'health_urls' => ['https://kunde.de/wp-admin/admin-ajax.php'],
        ], $plan);
        $this->assertSame([], $this->live->locked, 'der Probelauf sperrt nichts');
        $this->assertSame([], $this->live->log, 'und schreibt nichts');
    }

    public function testPlanKnowsTheStateOnTheTarget(): void
    {
        $heads = [
            'plugins/akismet' => ['akismet.php' => self::head('Akismet', '5.4')],
            'plugins/da'      => ['da.php' => self::head('Liegt da', '0.2')],
        ];
        $plan = PushPlugins::plan(self::wish(['plugins/akismet', 'plugins/da'], [], $heads), ['plugins/akismet', 'plugins/da'], 'live', $this->content);
        $this->assertTrue($plan['ok']);
        $this->assertSame(['active', 'inactive'], array_column($plan['activate'], 'state'));
        $this->assertSame(['5.4', '0.2'], array_column($plan['activate'], 'version'), 'aus dem mitgeschickten Kopf, nicht vom Ziel');
    }

    /** AC-200: ohne Kopf wird im Probelauf nichts geprüft – die Antwort sagt es. */
    public function testWithoutHeadsTheCheckWaitsForTheCommit(): void
    {
        $plan = PushPlugins::plan(self::wish(['plugins/kunde', 'plugins/akismet', 'plugins/da']), ['plugins/kunde', 'plugins/akismet', 'plugins/da'], 'live', $this->content);
        $this->assertTrue($plan['ok']);
        $this->assertSame(['new', 'active', 'inactive'], array_column($plan['activate'], 'state'));
        foreach ($plan['activate'] as $row) {
            $this->assertSame([null, null, null, ['checked' => 'at_commit', 'ok' => true, 'failed' => []]], [$row['file'], $row['name'], $row['version'], $row['requirements']]);
        }
        $this->assertSame([PushPlugins::UNCHECKED], $plan['warnings']);
    }

    /** AC-179: nicht im Satz, keine oder mehrere Hauptdateien – plugins_invalid mit Einheit und Grund. */
    public function testWhatCannotBeResolvedIsInvalid(): void
    {
        $head  = self::head('X', '1.0');
        $cases = [
            'unit_missing'   => [self::wish(['plugins/kunde'], [], ['plugins/kunde' => ['kunde.php' => $head]]), []],
            'no_plugin_file' => [self::wish(['plugins/kunde'], [], ['plugins/kunde' => ['kunde.php' => "<?php\n"]]), ['plugins/kunde']],
            'ambiguous'      => [self::wish(['plugins/kunde'], [], ['plugins/kunde' => ['a.php' => $head, 'b.php' => $head]]), ['plugins/kunde']],
        ];
        foreach ($cases as $why => $case) {
            $plan = PushPlugins::plan($case[0], $case[1], 'live', $this->content);
            $this->assertFalse($plan['ok'], $why);
            $this->assertSame('plugins_invalid', $plan['error']['code'], $why);
            $this->assertSame([['unit' => 'plugins/kunde', 'why' => $why]], $plan['error']['plugins'], $why);
            $this->assertSame('plugins/kunde', $plan['activate'][0]['unit'], 'die Zeile steht trotzdem im Plan');
        }
    }

    /** AC-199: ein Verstoss gegen den Kopf ist im Probelauf eine Ablehnung – mit why, needs, has. */
    public function testRequirementsOfTheHeadAreRefused(): void
    {
        $head = self::head('Kunde', '1.0', ['Requires PHP' => '8.2', 'Requires at least' => '7.0', 'Requires Plugins' => 'fehlt, akismet']);
        $plan = PushPlugins::plan(self::wish(['plugins/kunde'], [], ['plugins/kunde' => ['kunde.php' => $head]]), ['plugins/kunde'], 'live', $this->content);
        $this->assertFalse($plan['ok']);
        $this->assertSame('plugins_requirements', $plan['error']['code']);
        $failed = [
            ['why' => 'requires_php', 'needs' => '8.2', 'has' => '8.1.0'],
            ['why' => 'requires_wp', 'needs' => '7.0', 'has' => '6.5.2'],
            ['why' => 'requires_plugins', 'needs' => 'fehlt', 'has' => ''],
        ];
        $this->assertSame(['checked' => 'head', 'ok' => false, 'failed' => $failed], $plan['activate'][0]['requirements']);
        $this->assertSame(array_map(static function (array $f): array {
            return ['unit' => 'plugins/kunde'] + $f;
        }, $failed), $plan['error']['plugins']);
    }

    /** AC-179: eine Abhängigkeit, die im selben Satz aktiviert wird, gilt als erfüllt – eine, die er abschaltet, nicht. */
    public function testDependenciesInsideTheSameSet(): void
    {
        $heads = [
            'plugins/kunde' => ['kunde.php' => self::head('Kunde', '1.0', ['Requires Plugins' => 'basis'])],
            'plugins/basis' => ['basis.php' => self::head('Basis', '1.0')],
        ];
        $plan = PushPlugins::plan(self::wish(['plugins/kunde', 'plugins/basis'], [], $heads), ['plugins/basis', 'plugins/kunde'], 'live', $this->content);
        $this->assertTrue($plan['ok'], json_encode($plan['error']));

        // Auch wenn für die Abhängigkeit kein Kopf mitkam.
        unset($heads['plugins/basis']);
        $this->assertTrue(PushPlugins::plan(self::wish(['plugins/kunde', 'plugins/basis'], [], $heads), ['plugins/basis', 'plugins/kunde'], 'live', $this->content)['ok']);

        $needsOld = ['plugins/kunde' => ['kunde.php' => self::head('Kunde', '1.0', ['Requires Plugins' => 'old'])]];
        $this->assertTrue(PushPlugins::plan(self::wish(['plugins/kunde'], [], $needsOld), ['plugins/kunde'], 'live', $this->content)['ok'], 'old ist aktiv');
        $plan = PushPlugins::plan(self::wish(['plugins/kunde'], ['plugins/old'], $needsOld), ['plugins/kunde'], 'live', $this->content);
        $this->assertSame('plugins_requirements', $plan['error']['code'], 'old wird im selben Satz abgeschaltet');
        $this->assertSame([['unit' => 'plugins/kunde', 'why' => 'requires_plugins', 'needs' => 'old', 'has' => '']], $plan['error']['plugins']);
    }

    /** AC-202, AC-208: Deaktivieren braucht die Einheit nicht im Satz; der Plan nennt Name, Version, Einträge und die Warnung. */
    public function testPlanForADeactivation(): void
    {
        $plan = PushPlugins::plan(self::wish([], ['plugins/old', 'plugins/da', 'plugins/nie']), [], 'live', $this->content);
        $this->assertTrue($plan['ok']);
        $this->assertSame([
            ['unit' => 'plugins/old', 'state' => 'active', 'files' => ['old/old.php'], 'name' => 'Altes Plugin', 'version' => '3.2.1', 'required_by' => [], 'hooks' => true],
            ['unit' => 'plugins/da', 'state' => 'inactive', 'files' => [], 'name' => null, 'version' => null, 'required_by' => [], 'hooks' => false],
            ['unit' => 'plugins/nie', 'state' => 'absent', 'files' => [], 'name' => null, 'version' => null, 'required_by' => [], 'hooks' => false],
        ], $plan['deactivate']);
        $this->assertSame([PushPlugins::REVIEW], $plan['warnings']);
        // AC-203: schon inaktiv oder gar nicht da ist kein Fehler – und keine Warnung.
        $this->assertSame([], PushPlugins::plan(self::wish([], ['plugins/da', 'plugins/nie']), [], 'live', $this->content)['warnings']);
        // AC-197: die Antwort nennt nie einen fremden Eintrag der Liste.
        $json = (string) json_encode($plan);
        $this->assertStringNotContainsString('hello.php', $json);
        $this->assertStringNotContainsString('akismet', $json);
    }

    /** Ein Plugin, das auf dem Ziel aktiv ist, dessen Datei aber fehlt: abschaltbar, nur ohne Name. */
    public function testDeactivatingAnEntryWithoutAFile(): void
    {
        $this->active($this->live, ['weg/weg.php', 'akismet/akismet.php']);
        $plan = PushPlugins::plan(self::wish([], ['plugins/weg']), [], 'live', $this->content);
        $this->assertTrue($plan['ok']);
        $this->assertSame(['unit' => 'plugins/weg', 'state' => 'active', 'files' => ['weg/weg.php'], 'name' => null, 'version' => null, 'required_by' => [], 'hooks' => false], $plan['deactivate'][0]);
    }

    /** AC-207: was ein anderes aktives Plugin voraussetzt, geht nicht allein – zusammen mit dem abhängigen schon. */
    public function testRequiredByBlocksADeactivation(): void
    {
        $this->active($this->live, ['akismet/akismet.php', 'old/old.php', 'addon/addon.php']);
        $plan = PushPlugins::plan(self::wish([], ['plugins/old']), [], 'live', $this->content);
        $this->assertFalse($plan['ok']);
        $this->assertSame('plugins_requirements', $plan['error']['code']);
        $this->assertSame([['unit' => 'plugins/old', 'why' => 'required_by', 'needs' => 'plugins/addon', 'has' => '']], $plan['error']['plugins']);
        $this->assertSame(['plugins/addon'], $plan['deactivate'][0]['required_by']);

        $both = PushPlugins::plan(self::wish([], ['plugins/old', 'plugins/addon']), [], 'live', $this->content);
        $this->assertTrue($both['ok'], json_encode($both['error']));
        $this->assertSame([[], []], array_column($both['deactivate'], 'required_by'));
    }

    /** AC-180: Multisite ist schon im Probelauf plugins_unsupported; MyISAM und eine unlesbare Liste ebenso eine Ablehnung. */
    public function testWhatTheTargetCannotDo(): void
    {
        $wish = self::wish([], ['plugins/old']);
        PushPlugins::$site['multisite'] = true;
        $plan = PushPlugins::plan($wish, [], 'live', $this->content);
        $this->assertSame([false, 'plugins_unsupported'], [$plan['ok'], $plan['error']['code']]);
        PushPlugins::$site['multisite'] = false;

        $this->live->engines['options'] = 'MyISAM';
        $this->assertSame('engine_unsupported', PushPlugins::plan($wish, [], 'live', $this->content)['error']['code']);
        $this->live->engines = [];

        foreach (['kaputt', serialize(['a/a.php', 7]), 'O:8:"stdClass":0:{}'] as $value) {
            $this->live->data['options']['active_plugins']['option_value'] = $value;
            $this->assertSame('plugins_failed', PushPlugins::plan($wish, [], 'live', $this->content)['error']['code']);
        }
        unset($this->live->data['options']['active_plugins']);
        $this->assertSame('plugins_failed', PushPlugins::plan($wish, [], 'live', $this->content)['error']['code'], 'die Zeile fehlt');

        // Ein unvorhergesehener Fehler steht in der Antwort, statt den Begin scheitern zu lassen.
        PushContent::$resolve = static function (): ContentTarget {
            throw new \RuntimeException('GEHEIM');
        };
        $plan = PushPlugins::plan($wish, [], 'live', $this->content);
        $this->assertSame('content_failed', $plan['error']['code']);
        $this->assertStringNotContainsString('GEHEIM', (string) json_encode($plan));
    }

    /** AC-192, AC-210, A14, V9, V11: die Kopie – übersprungen statt abgelehnt, ihre eigene Liste, ihre Adresse. */
    public function testThePlanForTheStagingCopy(): void
    {
        $heads = [
            'plugins/wp-rocket' => ['wp-rocket.php' => self::head('WP Rocket', '3.0')],
            'plugins/kunde'     => ['kunde.php' => self::head('Kunde', '1.0')],
            'plugins/turbo'     => ['turbo.php' => self::head('Turbo', '1.0', ['Requires Plugins' => 'wp-rocket'])],
        ];
        $units = ['plugins/kunde', 'plugins/turbo', 'plugins/wp-rocket'];
        $wish  = self::wish(['plugins/wp-rocket', 'plugins/kunde', 'plugins/turbo'], ['plugins/old', 'plugins/wordfence'], $heads);
        $plan  = PushPlugins::plan($wish, $units, 'staging', $this->copy);
        $this->assertTrue($plan['ok'], json_encode($plan['error']));
        $this->assertSame(['skipped', 'new', 'skipped'], array_column($plan['activate'], 'state'));
        $this->assertSame(PushPlugins::DISABLED_ON_STAGING, $plan['activate'][0]['why']);
        $this->assertArrayNotHasKey('why', $plan['activate'][1]);
        $this->assertSame(PushPlugins::REQUIRES_SKIPPED, $plan['activate'][2]['why'], 'was ein übersprungenes Plugin voraussetzt, bleibt ebenfalls aus');
        // In der Kopie ist „old“ nicht aktiv (ihre Liste, nicht die von Live) und liegt dort auch nicht.
        $this->assertSame(['absent', 'absent'], array_column($plan['deactivate'], 'state'));
        $this->assertSame([], $plan['warnings']);
        $this->assertSame(['https://kunde.de/' . ContentFixtures::STAGING_DIR . '/wp-admin/admin-ajax.php'], $plan['health_urls']);

        // Auf Live wird dasselbe aktiviert und abgeschaltet.
        $this->plugin($this->content, 'wp-rocket', 'wp-rocket.php', $heads['plugins/wp-rocket']['wp-rocket.php']);
        $live = PushPlugins::plan($wish, $units, 'live', $this->content);
        $this->assertTrue($live['ok'], json_encode($live['error']));
        $this->assertSame(['inactive', 'new', 'new'], array_column($live['activate'], 'state'));
        $this->assertSame(['active', 'absent'], array_column($live['deactivate'], 'state'));
    }

    /** §12: Name und Version aus einem Kopf gehen bereinigt in die Antwort. */
    public function testTextFromAHeadIsCleaned(): void
    {
        $head = "<?php\n/* Plugin Name: Böse\x1b[31m \u{202e}Name\n * Version: 1\x07.0 */\n";
        $plan = PushPlugins::plan(self::wish(['plugins/kunde'], [], ['plugins/kunde' => ['kunde.php' => $head]]), ['plugins/kunde'], 'live', $this->content);
        $this->assertSame(['Böse?[31m ?Name', '1?.0'], [$plan['activate'][0]['name'], $plan['activate'][0]['version']]);
    }

    /** §8.1 Nr. 2: die Prüfung im Commit liest das gebaute Verzeichnis – und liefert, was die Transaktion braucht. */
    public function testCheckResolvesFromTheBuiltDirectory(): void
    {
        $this->build(1, 'kunde.php', self::head('Kunde', '1.0', ['Requires Plugins' => 'akismet']));
        $this->build(1, 'helper.php', "<?php\n");
        $got = PushPlugins::check(self::wish(['plugins/kunde'], ['plugins/old', 'plugins/nie']), ['uploads', 'plugins/kunde'], $this->root . '/new', 'live', $this->content, 7, $this->sealed());
        $this->assertSame(['add' => ['plugins/kunde' => 'kunde/kunde.php'], 'skipped' => [], 'drop' => ['old', 'nie']], $got);
        $this->assertSame([], $this->live->locked);

        // Ein Satz nur aus --deactivate hat keine Einheit.
        $this->assertSame(
            ['add' => [], 'skipped' => [], 'drop' => ['old']],
            PushPlugins::check(self::wish([], ['plugins/old']), [], $this->root . '/new', 'live', $this->content, 7, $this->sealed())
        );
    }

    /** AC-180, AC-181: Öffner und Umschlag, vor allem anderen. */
    public function testCheckNeedsTheOpenerAndTheEnvelope(): void
    {
        $this->build(0, 'kunde.php', self::head('Kunde', '1.0'));
        $wish = self::wish(['plugins/kunde']);
        foreach ([null, 0, 8] as $opener) {
            $e = $this->checkFails($wish, ['plugins/kunde'], $opener, $this->sealed());
            $this->assertSame([ContentException::PLUGINS_NOT_ALLOWED, 403], [$e->reason(), $e->status()]);
        }
        $e = $this->checkFails($wish, ['plugins/kunde'], 7, $this->root . '/fehlt.sealed');
        $this->assertSame([ContentException::PLUGINS_RESCUE_DB, 409, 'write_failed'], [$e->reason(), $e->status(), $e->toArray()['detail']]);
        symlink($this->sealed(), $this->root . '/link.sealed');
        $this->assertSame(ContentException::PLUGINS_RESCUE_DB, $this->checkFails($wish, ['plugins/kunde'], 7, $this->root . '/link.sealed')->reason(), 'ein Symlink ist kein Umschlag');

        PushPlugins::$site['multisite'] = true;
        $this->assertSame(ContentException::PLUGINS_UNSUPPORTED, $this->checkFails($wish, ['plugins/kunde'], 7, $this->sealed())->reason());
    }

    /** AC-200: der mitgeschickte Kopf entscheidet nichts – im Commit zählt die Datei. */
    public function testALyingHeadFailsAtTheCommit(): void
    {
        $harmless = ['plugins/kunde' => ['kunde.php' => self::head('Kunde', '1.0', ['Requires PHP' => '7.4'])]];
        $wish     = self::wish(['plugins/kunde'], [], $harmless);
        $this->assertTrue(PushPlugins::plan($wish, ['plugins/kunde'], 'live', $this->content)['ok'], 'der Probelauf glaubt dem Kopf');

        $this->build(0, 'kunde.php', self::head('Kunde', '1.0', ['Requires PHP' => '9.0']));
        $e = $this->checkFails($wish, ['plugins/kunde'], 7, $this->sealed());
        $this->assertSame(ContentException::PLUGINS_REQUIREMENTS, $e->reason());
        $this->assertSame([['unit' => 'plugins/kunde', 'why' => 'requires_php', 'needs' => '9.0', 'has' => '8.1.0']], $e->toArray()['plugins']);
    }

    /** AC-179 im Commit: keine oder mehrere Hauptdateien im gebauten Verzeichnis, Einheit nicht im Satz. */
    public function testCheckRefusesWhatTheBuiltDirectoryDoesNotCarry(): void
    {
        $wish = self::wish(['plugins/kunde']);
        mkdir($this->root . '/new/0');
        file_put_contents($this->root . '/new/0/readme.txt', self::head('Kunde', '1.0'));
        $e = $this->checkFails($wish, ['plugins/kunde'], 7, $this->sealed());
        $this->assertSame([ContentException::PLUGINS_INVALID, [['unit' => 'plugins/kunde', 'why' => 'no_plugin_file']]], [$e->reason(), $e->toArray()['plugins']]);

        $this->build(0, 'a.php', self::head('A', '1.0'));
        $this->build(0, 'b.php', self::head('B', '1.0'));
        $this->assertSame([['unit' => 'plugins/kunde', 'why' => 'ambiguous']], $this->checkFails($wish, ['plugins/kunde'], 7, $this->sealed())->toArray()['plugins']);

        $this->assertSame([['unit' => 'plugins/kunde', 'why' => 'unit_missing']], $this->checkFails($wish, ['plugins/anderes'], 7, $this->sealed())->toArray()['plugins']);
    }

    /** AC-192: im Commit in die Kopie wird ein Plugin, das sie abschaltet, übersprungen – nicht geschrieben. */
    public function testCheckSkipsOnTheCopy(): void
    {
        $this->build(0, 'kunde.php', self::head('Kunde', '1.0'));
        $this->build(1, 'wp-rocket.php', self::head('WP Rocket', '3.0'));
        $got = PushPlugins::check(self::wish(['plugins/wp-rocket', 'plugins/kunde']), ['plugins/kunde', 'plugins/wp-rocket'], $this->root . '/new', 'staging', $this->copy, 7, $this->sealed());
        $this->assertSame([
            'add'     => ['plugins/kunde' => 'kunde/kunde.php'],
            'skipped' => [['unit' => 'plugins/wp-rocket', 'why' => PushPlugins::DISABLED_ON_STAGING]],
            'drop'    => [],
        ], $got);
    }

    /** §4.3: das Ergebnis des Commits aus dem, was die Transaktion wirklich geändert hat. */
    public function testResultOfACommit(): void
    {
        $wish     = self::wish(['plugins/kunde', 'plugins/akismet', 'plugins/wp-rocket'], ['plugins/old', 'plugins/nie']);
        $resolved = [
            'add'     => ['plugins/kunde' => 'kunde/kunde.php', 'plugins/akismet' => 'akismet/akismet.php'],
            'skipped' => [['unit' => 'plugins/wp-rocket', 'why' => PushPlugins::DISABLED_ON_STAGING]],
            'drop'    => ['old', 'nie'],
        ];
        $delta = ['added' => ['kunde/kunde.php'], 'removed' => ['old/old.php', 'old/extra.php']];
        $this->assertSame([
            'activated'   => [['unit' => 'plugins/kunde', 'file' => 'kunde/kunde.php']],
            'deactivated' => [['unit' => 'plugins/old', 'files' => ['old/old.php', 'old/extra.php']]],
            'unchanged'   => ['plugins/akismet', 'plugins/nie'],
            'skipped'     => [['unit' => 'plugins/wp-rocket', 'why' => PushPlugins::DISABLED_ON_STAGING]],
        ], PushPlugins::result($wish, $resolved, $delta));
    }
}
