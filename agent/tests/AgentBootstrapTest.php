<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * wpsync-agent.php selbst (Spec 2b 5.9, 5.10): lädt alle Klassen, hängt Staging ein – und tut in
 * einer Staging-Kopie gar nichts. Die Datei definiert Konstanten, deshalb ein Prozess pro Test.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AgentBootstrapTest extends TestCase
{
    private const PLUGIN = __DIR__ . '/../wpsync-agent.php';

    private function source(): string
    {
        return (string) file_get_contents(self::PLUGIN);
    }

    public function testLoadsEveryClassAndRegistersStaging(): void
    {
        require_once __DIR__ . '/AgentHarness.php';
        require self::PLUGIN;

        $this->assertSame('0.8.0', WPSYNC_VERSION);
        foreach (glob(__DIR__ . '/../src/*.php') ?: [] as $file) {
            $name = 'WpSync\\' . basename($file, '.php');
            $this->assertTrue(class_exists($name, false) || interface_exists($name, false), basename($file) . ' fehlt in der Klassenliste');
        }
        $this->assertContains(\WpSync\Staging::CRON, $GLOBALS['wpsync_calls']['actions']);
        $this->assertContains('rest_api_init', $GLOBALS['wpsync_calls']['actions']);
        $this->assertSame(1, $GLOBALS['wpsync_calls']['activation']);
        $this->assertCount(1, $GLOBALS['wpsync_calls']['deactivation']);
    }

    /** Spec 2b 5.10: auch wenn active_plugins den Agent in der Kopie doch enthält. */
    public function testDoesNothingInsideAStagingCopy(): void
    {
        require_once __DIR__ . '/AgentHarness.php';
        define('WPSYNC_STAGING', true);
        require self::PLUGIN;

        $this->assertFalse(defined('WPSYNC_VERSION'));
        $this->assertFalse(class_exists('WpSync\\Rest', false));
        $this->assertFalse(class_exists('WpSync\\Staging', false));
        $this->assertSame(
            ['routes' => [], 'actions' => [], 'filters' => [], 'activation' => 0, 'deactivation' => []],
            $GLOBALS['wpsync_calls']
        );
    }

    /** Der Wert der Konstante spielt keine Rolle – eine Kopie mit WPSYNC_STAGING false bleibt eine Kopie. */
    public function testAFalseConstantStillCountsAsStagingCopy(): void
    {
        require_once __DIR__ . '/AgentHarness.php';
        define('WPSYNC_STAGING', false);
        require self::PLUGIN;

        $this->assertFalse(class_exists('WpSync\\Rest', false));
        $this->assertSame([], $GLOBALS['wpsync_calls']['actions']);
    }

    public function testTheStagingGuardComesBeforeAnyOtherCode(): void
    {
        $source = $this->source();
        $guard  = strpos($source, "if (defined('WPSYNC_STAGING'))");
        $this->assertNotFalse($guard);
        foreach (['const WPSYNC_VERSION', 'require_once', 'register_activation_hook', '::register('] as $needle) {
            $this->assertGreaterThan($guard, strpos($source, $needle), $needle);
        }
    }

    /** Staging::uninstall braucht die Push-Zeilen und wpsync_state – beides räumen die anderen ab. */
    public function testDeactivationRemovesTheStagingCopyFirst(): void
    {
        $source  = $this->source();
        $staging = strpos($source, '\WpSync\Staging::uninstall()');
        $push    = strpos($source, '\WpSync\Push::uninstall()');
        $store   = strpos($source, '\WpSync\Store::uninstall()');
        $this->assertNotFalse($staging);
        $this->assertNotFalse($push);
        $this->assertNotFalse($store);
        $this->assertLessThan($push, $staging);
        $this->assertLessThan($store, $push);
        $this->assertNotFalse(strpos($source, '\WpSync\Staging::unschedule()'));
    }

    public function testHeaderAndConstantCarryTheSameVersion(): void
    {
        $source = $this->source();
        $this->assertSame(1, preg_match('/^ \* Version:\s+(\S+)$/m', $source, $header));
        $this->assertSame(1, preg_match("/^const WPSYNC_VERSION = '([^']+)';$/m", $source, $const));
        $this->assertSame('0.8.0', $header[1]);
        $this->assertSame($header[1], $const[1]);
    }
}
