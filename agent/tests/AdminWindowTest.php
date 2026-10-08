<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use WpSync\Admin;

/** Push-Fenster im WP-Admin (Spec Content-Push §9, AC-145): Admin und Store laufen echt gegen FakeWpdb. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AdminWindowTest extends TestCase
{
    private function boot(): FakeWpdb
    {
        require_once __DIR__ . '/AgentHarness.php';
        require_once __DIR__ . '/FakeWpdb.php';
        $db = new FakeWpdb();
        $db->answer('/^SHOW COLUMNS FROM `wp_wpsync_pairings`/', [
            ['Field' => 'push_until', 'Type' => 'int(10) unsigned'],
            ['Field' => 'push_opened_by', 'Type' => 'bigint(20) unsigned'],
        ]);
        $GLOBALS['wpdb'] = $db;
        return $db;
    }

    /** AC-145 */
    public function testOpeningTheWindowRemembersTheAdministrator(): void
    {
        $db = $this->boot();
        $this->assertTrue(Admin::openWindow('k1', 900, 7));
        $this->assertFalse(Admin::openWindow('k1', 901, 7), 'only the fixed durations');
        Admin::closeWindow('k1');
        $writes = $db->writes();
        $this->assertCount(2, $writes);
        $this->assertMatchesRegularExpression('/^UPDATE `wp_wpsync_pairings` SET \{"push_until":\d+,"push_opened_by":7\} WHERE \{"key_id":"k1"\}\z/', $writes[0]);
        $this->assertSame('UPDATE `wp_wpsync_pairings` SET {"push_until":0,"push_opened_by":null} WHERE {"key_id":"k1"}', $writes[1]);
    }

    /** Die Seite gibt den angemeldeten Benutzer weiter und schreibt das Fenster nur über diese beiden Wege. */
    public function testRenderPassesTheCurrentUser(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../src/Admin.php');
        $this->assertStringContainsString('self::openWindow($keyId, $seconds, get_current_user_id())', $source);
        $this->assertStringContainsString('self::closeWindow($keyId)', $source);
        $this->assertSame(2, substr_count($source, 'Store::setPushUntil('), 'only inside openWindow and closeWindow');
    }
}
