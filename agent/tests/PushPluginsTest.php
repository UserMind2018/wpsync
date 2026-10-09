<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\TestCase;
use WpSync\ContentException;
use WpSync\PushPlugins;

/**
 * Was der Agent aus einem Request und aus den Bytes eines Plugin-Kopfs liest (Spec Content-Push P4
 * §4.2, §7.1, §7.2; AC-200, AC-201, AC-206) – ohne WordPress und ohne Datenbank.
 */
final class PushPluginsTest extends TestCase
{
    private const HEAD = "<?php\n/**\n * Plugin Name: Kunde Widgets\n * Version: 1.2.0\n * Requires at least: 6.2\n * Requires PHP: 8.1\n"
        . " * Requires Plugins: woo-commerce , elementor, Nicht_Gültig, elementor\n */\n";

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/wpsync-plugins-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        PushPlugins::$site = null;
        PushPlugins::$can  = null;
        \WpSync\PushUnits::$agent = 'wpsync-agent';
        \WpSync\ContentPlugins::$agent = 'wpsync-agent';
        \WpSync\PushUnits::$agentLink = '';
        \WpSync\ContentPlugins::$agentLink = '';
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    /** @param array<string, mixed> $params */
    private function refused(array $params): ContentException
    {
        try {
            PushPlugins::request($params);
        } catch (ContentException $e) {
            return $e;
        }
        $this->fail('nicht abgelehnt: ' . json_encode($params));
    }

    public function testRequestWithoutTheFieldsIsNoWish(): void
    {
        $this->assertNull(PushPlugins::request(['units' => []]));
        $this->assertNull(PushPlugins::request(['activate' => [], 'deactivate' => []]));
        $this->assertNull(PushPlugins::request(['activate' => []]));
    }

    public function testRequestTakesUnitsAndHeads(): void
    {
        $wish = PushPlugins::request([
            'activate'     => ['plugins/kunde-widgets', 'plugins/b'],
            'deactivate'   => ['plugins/Alt.Plugin_1'],
            'plugin_heads' => ['plugins/kunde-widgets' => ['kunde-widgets.php' => base64_encode(self::HEAD)], 'plugins/b' => []],
        ]);
        $this->assertSame(['plugins/kunde-widgets', 'plugins/b'], $wish['activate']);
        $this->assertSame(['plugins/Alt.Plugin_1'], $wish['deactivate']);
        $this->assertSame(['plugins/kunde-widgets' => ['kunde-widgets.php' => self::HEAD], 'plugins/b' => []], $wish['heads']);
        $this->assertSame(['activate' => [], 'deactivate' => ['plugins/x'], 'heads' => []], PushPlugins::request(['deactivate' => ['plugins/x']]));
    }

    /** AC-206, AC-200: Formfehler sind 400 plugins_invalid – ohne dass irgendetwas gelesen wird. */
    public function testRequestRefusesWhatIsNoPluginUnit(): void
    {
        $twenty = [];
        for ($i = 0; $i <= PushPlugins::MAX_UNITS; $i++) {
            $twenty[] = 'plugins/p' . $i;
        }
        $head  = base64_encode(self::HEAD);
        $cases = [
            'kein Array'              => ['activate' => 'plugins/x'],
            'Theme'                   => ['activate' => ['themes/x']],
            'mu-plugins'              => ['deactivate' => ['mu-plugins']],
            'uploads'                 => ['activate' => ['uploads']],
            'der Agent'               => ['deactivate' => ['plugins/wpsync-agent']],
            'der Agent, gross'        => ['deactivate' => ['plugins/WPSync-Agent']],
            'einzelne Datei'          => ['activate' => ['plugins/hello.php/x']],
            'Pfad'                    => ['activate' => ['plugins/../x']],
            'keine Zeichenkette'      => ['activate' => [7]],
            'doppelt'                 => ['activate' => ['plugins/x', 'plugins/x']],
            'doppelt, gross/klein'    => ['deactivate' => ['plugins/x', 'plugins/X']],
            'in beiden'               => ['activate' => ['plugins/x'], 'deactivate' => ['plugins/x']],
            'zu viele'                => ['deactivate' => $twenty],
            'Köpfe: kein Objekt'      => ['activate' => ['plugins/x'], 'plugin_heads' => 'x'],
            'Köpfe: fremde Einheit'   => ['activate' => ['plugins/x'], 'plugin_heads' => ['plugins/y' => []]],
            'Köpfe: für deactivate'   => ['deactivate' => ['plugins/x'], 'plugin_heads' => ['plugins/x' => []]],
            'Köpfe: sechs Dateien'    => ['activate' => ['plugins/x'], 'plugin_heads' => ['plugins/x' => ['a.php' => $head, 'b.php' => $head, 'c.php' => $head, 'd.php' => $head, 'e.php' => $head, 'f.php' => $head]]],
            'Köpfe: Unterordner'      => ['activate' => ['plugins/x'], 'plugin_heads' => ['plugins/x' => ['inc/a.php' => $head]]],
            'Köpfe: kein PHP'         => ['activate' => ['plugins/x'], 'plugin_heads' => ['plugins/x' => ['a.txt' => $head]]],
            'Köpfe: kein base64'      => ['activate' => ['plugins/x'], 'plugin_heads' => ['plugins/x' => ['a.php' => '***']]],
            'Köpfe: über 8192 Bytes'  => ['activate' => ['plugins/x'], 'plugin_heads' => ['plugins/x' => ['a.php' => base64_encode(str_repeat('x', PushPlugins::HEAD_BYTES + 1))]]],
        ];
        foreach ($cases as $name => $params) {
            $e = $this->refused($params);
            $this->assertSame([ContentException::PLUGINS_INVALID, 400], [$e->reason(), $e->status()], $name);
        }
    }

    public function testHeaderReadsLikeGetFileData(): void
    {
        $want = ['name' => 'Kunde Widgets', 'version' => '1.2.0', 'requires_wp' => '6.2', 'requires_php' => '8.1', 'requires_plugins' => ['elementor', 'woo-commerce']];
        $this->assertSame($want, PushPlugins::header(self::HEAD));
        $this->assertSame($want, PushPlugins::header(str_replace("\n", "\r\n", self::HEAD)), 'CRLF');
        $this->assertSame($want, PushPlugins::header(str_replace("\n", "\r", self::HEAD)), 'nur CR');
        $this->assertSame($want, PushPlugins::header("\xEF\xBB\xBF" . self::HEAD), 'BOM vor <?php, Kopf ab Zeile 2');
        $this->assertSame($want, PushPlugins::header("<?php\nif (!defined('ABSPATH')) { exit; }\n\$x = 1;\n" . substr(self::HEAD, 6)), 'Kopf nach Code');
        $none = ['name' => '', 'version' => '', 'requires_wp' => '', 'requires_php' => '', 'requires_plugins' => []];
        $this->assertSame($none, PushPlugins::header(''));
        $this->assertSame($none, PushPlugins::header("<?php\n// nur Code, der den Text Plugin Name erwähnt\n"));
        $this->assertSame($none, PushPlugins::header(str_repeat("\n", PushPlugins::HEAD_BYTES) . self::HEAD), 'hinter den ersten 8192 Bytes zählt nichts');
        $this->assertSame('Inline', PushPlugins::header('<?php /* Plugin Name: Inline */ ?>')['name']);
        $this->assertSame('Erste Zeile', PushPlugins::header("<?php // Plugin Name: Erste Zeile\n")['name']);
        $this->assertSame('', PushPlugins::header("\xEF\xBB\xBF<?php // Plugin Name: Mit BOM in Zeile 1\n")['name'], 'wie der Core: das BOM steht vor <?php');
        $this->assertSame('Klein', PushPlugins::header("<?php\n# plugin name: Klein\n")['name']);
        $this->assertSame('0', PushPlugins::header("<?php\n/* Plugin Name: 0 */\n")['name'], 'wie der Core: der Wert bleibt, als Name zählt er nicht (mainFile)');
        $this->assertSame('', PushPlugins::header("<?php\n/* Plugin Name:0\n */\n")['name'], 'wie der Core: ein Treffer, der für PHP falsch ist, zählt nicht');
        $this->assertSame('1.0', PushPlugins::header("<?php\n/*\nPlugin Name: X\nVersion: 1.0 */\n")['version']);
    }

    public function testMainFileIsTheOneFileWithAPluginName(): void
    {
        $other = "<?php\n// Hilfsdatei\n";
        $main  = PushPlugins::mainFile(['kunde-widgets.php' => self::HEAD, 'uninstall.php' => $other]);
        $this->assertSame('kunde-widgets.php', $main['file']);
        $this->assertSame('Kunde Widgets', $main['header']['name']);
        $this->assertSame(['why' => 'no_plugin_file'], PushPlugins::mainFile(['a.php' => $other]));
        $this->assertSame(['why' => 'no_plugin_file'], PushPlugins::mainFile([]));
        $this->assertSame(['why' => 'no_plugin_file'], PushPlugins::mainFile(['a.php' => "<?php\n/* Plugin Name: 0 */\n"]), 'wie get_plugins(): empty() – ein Name „0“ ist keiner');
        $this->assertSame(['why' => 'ambiguous'], PushPlugins::mainFile(['a.php' => self::HEAD, 'b.php' => self::HEAD]));
        $this->assertSame(['why' => 'file_name'], PushPlugins::mainFile(['mein plugin.php' => self::HEAD]));
        $this->assertSame(['why' => 'file_name'], PushPlugins::mainFile(['.versteckt.php' => self::HEAD]));
    }

    /** §7.1: nur *.php direkt im Ordner, nie ein Symlink, nie mehr als die ersten 8192 Bytes. */
    public function testBuiltReadsTheHeadsOfADirectory(): void
    {
        mkdir($this->dir . '/inc');
        file_put_contents($this->dir . '/kunde.php', self::HEAD . str_repeat('x', 20000));
        file_put_contents($this->dir . '/helper.php', "<?php\n");
        file_put_contents($this->dir . '/.hidden.php', self::HEAD);
        file_put_contents($this->dir . '/readme.txt', self::HEAD);
        file_put_contents($this->dir . '/inc/deep.php', self::HEAD);
        symlink($this->dir . '/kunde.php', $this->dir . '/link.php');
        $heads = PushPlugins::built($this->dir);
        ksort($heads);
        $this->assertSame(['helper.php', 'kunde.php'], array_keys($heads));
        $this->assertSame(PushPlugins::HEAD_BYTES, strlen($heads['kunde.php']));
        $this->assertSame('kunde.php', PushPlugins::mainFile($heads)['file']);
        $this->assertSame([], PushPlugins::built($this->dir . '/fehlt'));
    }

    public function testVersionRulesAreThoseOfTheCore(): void
    {
        $this->assertTrue(PushPlugins::phpOk('', '7.4.33'));
        $this->assertTrue(PushPlugins::phpOk('8.1', '8.1.0'));
        $this->assertTrue(PushPlugins::phpOk('7.4', '8.2.12'));
        $this->assertFalse(PushPlugins::phpOk('8.1', '8.0.30'));
        $this->assertTrue(PushPlugins::wpOk('', '6.5.2'));
        $this->assertTrue(PushPlugins::wpOk('6.5', '6.5.2'));
        $this->assertTrue(PushPlugins::wpOk('6.5.0', '6.5'), 'wie der Core: ein „.0“ am Ende einer dreiteiligen Angabe zählt nicht');
        $this->assertTrue(PushPlugins::wpOk(' 6.5 ', '6.5-beta1-57000'), 'Zusätze der installierten Version fallen weg');
        $this->assertFalse(PushPlugins::wpOk('6.6', '6.5.2-RC1'));
        $this->assertFalse(PushPlugins::wpOk('7.0', '6.9.9'));
    }

    public function testRequirementsNameWhatIsMissing(): void
    {
        $header = PushPlugins::header(self::HEAD);
        $site   = ['php' => '8.0.30', 'wp' => '6.1.1', 'multisite' => false];
        $this->assertSame([
            ['why' => 'requires_php', 'needs' => '8.1', 'has' => '8.0.30'],
            ['why' => 'requires_wp', 'needs' => '6.2', 'has' => '6.1.1'],
            ['why' => 'requires_plugins', 'needs' => 'woo-commerce', 'has' => ''],
        ], PushPlugins::requirements($header, ['elementor', 'akismet'], $site));
        $this->assertSame([], PushPlugins::requirements($header, ['elementor', 'woo-commerce'], ['php' => '8.2.0', 'wp' => '6.5', 'multisite' => false]));
        $this->assertSame([], PushPlugins::requirements(PushPlugins::header("<?php\n/* Plugin Name: X */"), [], $site));
    }

    public function testSlugPrintableAndTheSeams(): void
    {
        $this->assertSame('kunde-widgets', PushPlugins::slug('plugins/kunde-widgets'));
        $this->assertSame('Größe 3.0 🚀', PushPlugins::printable('Größe 3.0 🚀'));
        $this->assertSame('a?b?c', PushPlugins::printable("a\x1bb\u{202e}c"));
        $this->assertSame('a?b', PushPlugins::printable("a\xffb"), 'ungültiges UTF-8 wird ASCII');
        $this->assertSame(200, mb_strlen(PushPlugins::printable(str_repeat('ä', 300))));
        // Öffner (A12): ohne Benutzer nie; sonst entscheidet WordPress – hier die Naht.
        $this->assertFalse(PushPlugins::allowed(null));
        $this->assertFalse(PushPlugins::allowed(0));
        $this->assertFalse(PushPlugins::allowed(7), 'ohne WordPress-Funktionen: nein');
        PushPlugins::$can = static function (int $id): bool {
            return $id === 7;
        };
        $this->assertTrue(PushPlugins::allowed(7));
        $this->assertFalse(PushPlugins::allowed(8));
        PushPlugins::$site = ['php' => '8.2.0', 'wp' => '6.5', 'multisite' => true];
        $this->assertSame(['php' => '8.2.0', 'wp' => '6.5', 'multisite' => true], PushPlugins::site());
        $e = PushPlugins::refuse(ContentException::PLUGINS_NOT_ALLOWED, 'nein', [['unit' => 'plugins/x', 'why' => 'y']], ['detail' => 'z']);
        $this->assertSame(['code' => 'plugins_not_allowed', 'message' => 'nein', 'plugins' => [['unit' => 'plugins/x', 'why' => 'y']], 'detail' => 'z'], $e->toArray());
    }

    /**
     * Security-Review P4 S3: der Selbstschutz hängt nicht am Ordnernamen wpsync-agent – liegt der laufende
     * Agent unter einem anderen Namen (ZIP von GitHub: wpsync-agent-main), ist auch dieser Ordner tabu:
     * als Schalter (400 plugins_invalid) und als Einheit des Code-Kanals.
     */
    public function testTheFolderOfTheRunningAgentIsNeverSwitchedOrPushed(): void
    {
        \WpSync\PushUnits::$agent = 'wpsync-agent-main';
        foreach ([['deactivate' => ['plugins/wpsync-agent-main']], ['activate' => ['plugins/WPSync-Agent-Main']], ['deactivate' => ['plugins/wpsync-agent']]] as $params) {
            $e = $this->refused($params);
            $this->assertSame([ContentException::PLUGINS_INVALID, 400], [$e->reason(), $e->status()], json_encode($params));
        }
        $this->assertFalse(\WpSync\PushUnits::valid('plugins/wpsync-agent-main'));
        $this->assertFalse(\WpSync\PushUnits::valid('plugins/wpsync-agent'), 'der feste Name bleibt gesperrt');
        $this->assertTrue(\WpSync\PushUnits::valid('plugins/wpsync-agent-main-addon'));
        $this->assertTrue(\WpSync\PushUnits::valid('themes/wpsync-agent-main'));
        $this->assertNotNull(PushPlugins::request(['deactivate' => ['plugins/anderes']]));
    }

    /**
     * Nach-Review NR-7: ist der Agent über einen Symlink in plugins/ eingebunden, kennt WordPress ihn unter
     * dem Namen des Links (plugin_basename()) – auch dieser Ordner ist als Schalter und Einheit tabu, und
     * sein Eintrag wird nie gestrichen.
     */
    public function testTheNameWordPressKnowsTheAgentByIsProtectedToo(): void
    {
        \WpSync\PushUnits::$agent          = 'agent-real';
        \WpSync\PushUnits::$agentLink      = 'agent-link';
        \WpSync\ContentPlugins::$agent     = 'agent-real';
        \WpSync\ContentPlugins::$agentLink = 'agent-link';
        foreach (['plugins/agent-link', 'plugins/Agent-Link', 'plugins/agent-real', 'plugins/wpsync-agent'] as $unit) {
            $this->assertSame(ContentException::PLUGINS_INVALID, $this->refused(['deactivate' => [$unit]])->reason(), $unit);
            $this->assertFalse(\WpSync\PushUnits::valid($unit), $unit);
        }
        $list = ['agent-link/wpsync-agent.php', 'old/old.php'];
        $this->assertSame(['added' => [], 'removed' => ['old/old.php']], \WpSync\ContentPlugins::change($list, [], ['agent-link', 'old']));
        $source = (string) file_get_contents(__DIR__ . '/../src/Push.php');
        $this->assertStringContainsString("plugin_basename(\$pluginDir . '/wpsync-agent.php')", $source, 'Push::register() fragt WordPress nach dem Namen');
    }
}
