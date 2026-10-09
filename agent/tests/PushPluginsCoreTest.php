<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\TestCase;
use WpSync\PushPlugins;

/**
 * AC-201, Task 0 Nr. 4: der Kopf-Leser liefert, was get_plugin_data() liefert. Der Vergleich
 * läuft gegen get_file_data() und _cleanup_header_comment() aus dem Quelltext eines WordPress –
 * ausgeschnitten und in einem eigenen Prozess ausgeführt, ohne WordPress zu laden. Ohne Core
 * (WPSYNC_WP_CORE, sonst ~/wpsync-e2e/cdb/source/public) wird der Test übersprungen.
 */
final class PushPluginsCoreTest extends TestCase
{
    private string $dir = '';

    protected function tearDown(): void
    {
        if ($this->dir !== '') {
            exec('rm -rf ' . escapeshellarg($this->dir));
        }
    }

    public function testTheHeaderReaderAgreesWithTheCore(): void
    {
        $core      = (string) (getenv('WPSYNC_WP_CORE') ?: getenv('HOME') . '/wpsync-e2e/cdb/source/public');
        $functions = $core . '/wp-includes/functions.php';
        if (!is_file($functions)) {
            $this->markTestSkipped('kein WordPress-Core unter ' . $core . ' (WPSYNC_WP_CORE)');
        }
        $source = (string) file_get_contents($functions);
        $code   = '';
        foreach (['get_file_data', '_cleanup_header_comment'] as $name) {
            $this->assertSame(1, preg_match('/^function ' . $name . '\(.*?^}/ms', $source, $m), $name . ' steht nicht mehr so im Core');
            $code .= $m[0] . "\n";
        }
        $this->dir = sys_get_temp_dir() . '/wpsync-core-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
        $tail = <<<'PHP'
$fields = ['name' => 'Plugin Name', 'version' => 'Version', 'requires_wp' => 'Requires at least', 'requires_php' => 'Requires PHP', 'requires_plugins' => 'Requires Plugins'];
$out    = [];
foreach (array_slice($argv, 1) as $file) {
    $out[] = get_file_data($file, $fields, 'plugin');
}
echo json_encode($out);
PHP;
        file_put_contents($this->dir . '/core.php', "<?php\ndefine('KB_IN_BYTES', 1024);\nfunction apply_filters(\$hook, \$value) { return \$value; }\n" . $code . $tail . "\n");

        $head    = "<?php\n/**\n * Plugin Name: Kunde Widgets\n * Version: 1.2.0\n * Requires at least: 6.2\n * Requires PHP: 8.1\n * Requires Plugins: woo-commerce , elementor, Nicht_Gültig, elementor\n */\n";
        $samples = [
            'lf.php'      => $head,
            'crlf.php'    => str_replace("\n", "\r\n", $head),
            'cr.php'      => str_replace("\n", "\r", $head),
            'bom.php'     => "\xEF\xBB\xBF" . $head,
            'bom1.php'    => "\xEF\xBB\xBF<?php // Plugin Name: Mit BOM in Zeile 1\n",
            'code.php'    => "<?php\nif (!defined('ABSPATH')) { exit; }\n\$x = ['Plugin Name' => 'nein'];\n" . substr($head, 6),
            'inline.php'  => '<?php /* Plugin Name: Inline */ ?>',
            'first.php'   => "<?php // Plugin Name: Erste Zeile\n// Version: 2 ?>\n",
            'hash.php'    => "<?php\n# plugin name: Klein\n# VERSION: 3.0-beta\n",
            'at.php'      => "<?php\n/**\n\t@Plugin Name:\tMit Tab und At  \n */\n",
            'zero.php'    => "<?php\n/* Plugin Name: 0 */\n",
            'late.php'    => str_repeat("\n", 8192) . $head,
            'edge.php'    => str_repeat('x', 8192 - 30) . "\nPlugin Name: Ueber die Grenze hinaus geschrieben\n",
            'empty.php'   => '',
            'nohead.php'  => "<?php\necho 'Plugin Name';\n",
            'twice.php'   => "<?php\n/* Plugin Name: Erster\n Plugin Name: Zweiter */\n",
            'umlaut.php'  => "<?php\n/* Plugin Name: Größe & Co. – „Test“ 🚀\n * Requires Plugins: a,b-c,,d--e, F */\n",
        ];
        $files = [];
        foreach ($samples as $name => $bytes) {
            file_put_contents($this->dir . '/' . $name, $bytes);
            $files[] = $this->dir . '/' . $name;
        }
        // Dazu, was im Core-Checkout an echten Plugins liegt (Akismet, Hello Dolly …).
        foreach (array_merge(glob($core . '/wp-content/plugins/*.php') ?: [], glob($core . '/wp-content/plugins/*/*.php') ?: []) as $real) {
            if (count($files) < 80 && is_file($real) && !is_link($real)) {
                $files[] = $real;
            }
        }
        $json = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->dir . '/core.php') . ' ' . implode(' ', array_map('escapeshellarg', $files)));
        $core = json_decode($json, true);
        $this->assertIsArray($core, $json);
        $this->assertCount(count($files), $core);
        foreach ($files as $i => $file) {
            $mine = PushPlugins::header((string) file_get_contents($file, false, null, 0, PushPlugins::HEAD_BYTES));
            $want = $core[$i];
            // Requires Plugins säubert der Core in WP_Plugin_Dependencies::sanitize_dependency_slugs() (Task 0 Step 3).
            $slugs = [];
            foreach (explode(',', (string) $want['requires_plugins']) as $slug) {
                $slug = trim($slug);
                if (preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/mu', $slug) === 1) {
                    $slugs[] = $slug;
                }
            }
            $slugs = array_values(array_unique($slugs));
            sort($slugs);
            $want['requires_plugins'] = $slugs;
            $this->assertSame($want, $mine, basename($file));
        }
    }
}
