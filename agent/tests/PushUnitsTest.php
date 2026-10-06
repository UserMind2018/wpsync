<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\TestCase;
use WpSync\PushUnits;

final class PushUnitsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/wpsync-units-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/plugin/inc', 0777, true);
        mkdir($this->root . '/theme', 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testValidUnits(): void
    {
        foreach (['plugins/mein-plugin', 'plugins/woo.commerce_2', 'themes/kunde-child', 'mu-plugins'] as $unit) {
            $this->assertTrue(PushUnits::valid($unit), $unit);
        }
    }

    /** AC-59: der Agent selbst, Pfade nach draussen und alles ausserhalb der drei Code-Ordner. */
    public function testInvalidUnits(): void
    {
        $invalid = [
            'plugins/wpsync-agent', 'plugins/WPSYNC-Agent', 'plugins', 'themes', 'plugins/', 'plugins/a/b',
            'plugins/..', 'plugins/.hidden', '../plugins/a', '/plugins/a', 'uploads/2026', 'mu-plugins/x',
            'plugins/hello.php/', "plugins/a\0b", 'languages', '',
        ];
        foreach ($invalid as $unit) {
            $this->assertFalse(PushUnits::valid($unit), var_export($unit, true));
        }
    }

    public function testValidFiles(): void
    {
        $this->assertTrue(PushUnits::validFile('plugins/a', 'a.php'));
        $this->assertTrue(PushUnits::validFile('plugins/a', 'inc/deep/file.name.php'));
        $this->assertTrue(PushUnits::validFile('mu-plugins', 'loader.php'));
        $this->assertTrue(PushUnits::validFile('plugins/a', 'lang/übersetzung-größe.po'));
        $this->assertTrue(PushUnits::validFile('plugins/a', '日本.css'));
    }

    /** N2: C1- und Bidi-Steuerzeichen sowie ungültiges UTF-8 – wie die CLI (push.Ignored). */
    public function testFilesWithC1OrBidiControlsAreInvalid(): void
    {
        $invalid = ["a\u{9b}.php", "inc/\u{85}x.php", "wp_\u{202e}gnp.php", "\u{202a}a.php", "\u{2066}a.php", "\u{2069}a.php", "a\x9b.php", "a\xc3.php"];
        foreach ($invalid as $rel) {
            $this->assertFalse(PushUnits::validFile('plugins/a', $rel), bin2hex($rel));
        }
    }

    /** AC-59 */
    public function testInvalidFiles(): void
    {
        $invalid = ['', '/a.php', 'a/../b.php', '../b.php', 'a//b.php', './a.php', 'a/.', "a\tb.php", "a\nb.php", 'a\\b.php', '.git/config', 'inc/.GIT/HEAD', 'inc/.svn/x'];
        foreach ($invalid as $rel) {
            $this->assertFalse(PushUnits::validFile('plugins/a', $rel), var_export($rel, true));
        }
        $this->assertFalse(PushUnits::validFile('plugins/a', str_repeat('a', 1025)));
    }

    /** P11: der lokale Mail-Riegel und wpsync-eigene Dateien in mu-plugins sind nie pushbar. */
    public function testProtectedFilesInMuPlugins(): void
    {
        foreach (['00-local-mailguard.php', '00-Local-Mailguard.php', 'wpsync-loader.php', 'wpsync/agent.php', 'WPSYNC.php'] as $rel) {
            $this->assertTrue(PushUnits::isProtected('mu-plugins', $rel), $rel);
            $this->assertFalse(PushUnits::validFile('mu-plugins', $rel), $rel);
        }
        $this->assertFalse(PushUnits::isProtected('mu-plugins', 'kunde.php'));
        $this->assertFalse(PushUnits::isProtected('plugins/a', '00-local-mailguard.php'), 'only mu-plugins has protected names');
    }

    public function testVersionFromPluginHeader(): void
    {
        file_put_contents($this->root . '/plugin/readme.php', "<?php\n// Version: 9.9.9 – kein Plugin-Kopf\n");
        file_put_contents($this->root . '/plugin/main.php', "<?php\n/**\n * Plugin Name: Mein Plugin\n * Version:     1.4.0\n */\n");
        file_put_contents($this->root . '/plugin/inc/other.php', "<?php\n/* Plugin Name: Tief\n Version: 0.0.1 */\n");
        $this->assertSame('1.4.0', PushUnits::version($this->root . '/plugin', 'plugins/mein-plugin'));
    }

    public function testVersionFromThemeStylesheet(): void
    {
        file_put_contents($this->root . '/theme/style.css', "/*\nTheme Name: Kunde Child\nVersion: 2.1\n*/\n");
        $this->assertSame('2.1', PushUnits::version($this->root . '/theme', 'themes/kunde-child'));
    }

    public function testVersionIsEmptyWithoutHeaderOrDirectory(): void
    {
        $this->assertSame('', PushUnits::version($this->root . '/theme', 'themes/leer'));
        $this->assertSame('', PushUnits::version($this->root . '/fehlt', 'plugins/fehlt'));
        $this->assertSame('', PushUnits::version($this->root . '/plugin', 'mu-plugins'));
    }
}
