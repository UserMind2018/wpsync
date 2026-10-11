<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use WpSync\Admin;
use WpSync\Store;

/**
 * Gerätename und Kennung im WP-Admin (Spec 0.9.1 G1, G4; AC-247): Admin und Store laufen echt gegen
 * FakeWpdb. Die Seite selbst (Formular, Tabelle, Nonce) prüft scripts/e2e-local.sh gegen WordPress.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AdminDeviceTest extends TestCase
{
    private function boot(): FakeWpdb
    {
        require_once __DIR__ . '/AgentHarness.php';
        require_once __DIR__ . '/FakeWpdb.php';
        $GLOBALS['wpdb'] = new FakeWpdb();
        return $GLOBALS['wpdb'];
    }

    /** Die Bereinigung für beide Formulare: sanitize_text_field, höchstens 100 Zeichen, leer bleibt leer. */
    public function testANameFromAFormIsCleaned(): void
    {
        $this->boot();
        $this->assertSame('Studio Büro', Admin::deviceName("  Studio <b>Büro</b>\n "));
        $this->assertSame('', Admin::deviceName(" \t "));
        $this->assertSame('', Admin::deviceName('<script></script>'));
        $this->assertSame(100, mb_strlen(Admin::deviceName(str_repeat('ä', 150)), 'UTF-8'));
        $this->assertSame('', Admin::deviceName(['kein', 'Text']));
        $this->assertSame("O'Brien", Admin::deviceName("O\\'Brien"), 'wie WordPress $_POST liefert: mit Schrägstrich');
    }

    /** AC-247: umbenannt wird genau die Zeile dieser Kennung; ein leerer Name wird abgelehnt. */
    public function testRenameChangesOnlyTheRowOfThisKey(): void
    {
        $db = $this->boot();
        $this->assertTrue(Admin::rename('0123456789abcdef', ' Studio <i>Büro</i> '));
        $this->assertSame(['UPDATE `wp_wpsync_pairings` SET {"device":"Studio B\u00fcro"} WHERE {"key_id":"0123456789abcdef"}'], $db->writes());
        $db->queries = [];
        $this->assertFalse(Admin::rename('0123456789abcdef', '   '), 'leerer Name');
        $this->assertFalse(Admin::rename('0123456789abcdef', '<b></b>'), 'nach der Bereinigung leer');
        $this->assertFalse(Admin::rename('', 'Laptop'), 'ohne Kennung');
        $this->assertSame([], $db->writes());
        $this->assertTrue(Store::renamePairing('k2', 'Laptop'));
        $this->assertFalse(Store::renamePairing('k2', ''));
    }

    /** Nonce, Recht und Escaping: die Seite nimmt jede Aktion nur mit check_admin_referer an und gibt Namen und Kennung escaped aus. */
    public function testThePageGuardsAndEscapes(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../src/Admin.php');
        $this->assertSame(1, substr_count($source, "isset(\$_POST['wpsync_action']) && check_admin_referer('wpsync_admin')"), 'ein Tor für alle Aktionen');
        $this->assertStringContainsString("if (!current_user_can('manage_options')) {", $source);
        $this->assertStringContainsString("\$action === 'rename' && \$keyId !== ''", $source);
        $this->assertStringContainsString("self::rename(\$keyId, \$_POST['device'] ?? '')", $source);
        $this->assertStringContainsString("Store::issuePairingCode(self::deviceName(\$_POST['device'] ?? ''))", $source);
        $this->assertStringContainsString("<?php echo esc_html(\$pairing['device']); ?>", $source);
        $this->assertStringContainsString("<code><?php echo esc_html(\$pairing['key_id']); ?></code>", $source);
        $this->assertStringContainsString('name="device" value="<?php echo esc_attr($pairing[\'device\']); ?>"', $source);
        $this->assertSame(1, substr_count($source, 'Store::renamePairing('), 'nur in rename()');
        // Jedes Formular der Seite trägt die Nonce.
        $this->assertSame(substr_count($source, '<form method="post"'), substr_count($source, "wp_nonce_field('wpsync_admin')"));
    }
}
