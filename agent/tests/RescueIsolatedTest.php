<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\TestCase;

/**
 * AC-170: die Klassenliste von rescue.php lädt und läuft ohne WordPress. Der Beweis ist
 * tests/rescue-isolated.php – ein eigener PHP-Prozess ohne ABSPATH, ohne Autoloader und ohne
 * Attrappen; hier wird er nur gestartet und sein Ausgang geprüft.
 */
final class RescueIsolatedTest extends TestCase
{
    public function testTheContentRollbackRunsInAProcessWithoutWordPress(): void
    {
        $pipes   = [];
        $process = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', __DIR__ . '/rescue-isolated.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        $out  = (string) stream_get_contents($pipes[1]);
        $err  = (string) stream_get_contents($pipes[2]);
        $code = proc_close($process);
        $this->assertSame(0, $code, $err . $out);
        $this->assertSame('', $err);
        $this->assertMatchesRegularExpression('/^OK 21 classes\n\z/', $out);
    }

    /** Das Skript selbst darf nichts vortäuschen: keine Konstante ABSPATH, kein Autoloader von Composer, keine Attrappe. */
    public function testTheScriptDefinesNothingOfWordPress(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/rescue-isolated.php');
        $code   = (string) preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $source);
        foreach (["define('ABSPATH'", 'vendor/autoload', 'Harness', 'FakeWpdb', 'function get_option', 'function wp_'] as $word) {
            $this->assertStringNotContainsString($word, $code, $word);
        }
        $this->assertStringContainsString("define('WPSYNC_RESCUE', true);", $code);
    }
}
