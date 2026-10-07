<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use WpSync\Admin;
use WpSync\Staging;

/**
 * Abschnitt Staging der Admin-Seite: nur Anzeige, alles aus dem Datensatz escaped, kein Weg zu
 * einem Login-Link (T1). Admin, Staging und Store laufen echt gegen FakeWpdb.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AdminStagingTest extends TestCase
{
    /** @param array<string, mixed>|null $record */
    private function render(?array $record): string
    {
        require_once __DIR__ . '/AgentHarness.php';
        require_once __DIR__ . '/FakeWpdb.php';
        $db = new FakeWpdb();
        $db->answer('/^SELECT value FROM `wp_wpsync_state`/', $record === null ? null : json_encode($record));
        $GLOBALS['wpdb'] = $db;

        ob_start();
        try {
            Admin::staging();
        } finally {
            $html = (string) ob_get_clean();
        }
        $this->assertSame([], $db->writes(), 'die Admin-Seite ändert an der Kopie nichts');
        return $html;
    }

    public function testWithoutACopyItPointsToTheCommand(): void
    {
        $html = $this->render(null);
        $this->assertStringContainsString('Keine Staging-Kopie', $html);
        $this->assertStringContainsString('wpsync staging create &lt;site&gt;', $html);
    }

    public function testShowsStatusAddressFolderAndPrefix(): void
    {
        $html = $this->render(['status' => Staging::READY, 'prefix' => 'stgabc123_', 'dir' => 'wpsync-staging-0123456789ab']);
        $this->assertStringContainsString('bereit', $html);
        $this->assertStringContainsString('https://example.test/wpsync-staging-0123456789ab', $html);
        $this->assertStringContainsString('<code>stgabc123_</code>', $html);
        $this->assertStringNotContainsString('<a ', $html, 'kein Link: ohne Einmal-Link antwortet die Kopie mit 403');
        $this->assertStringNotContainsString('<form', $html);
        $this->assertStringNotContainsString('wpsync_login', $html);
    }

    public function testLockedCopyGetsANotice(): void
    {
        $html = $this->render(['status' => Staging::LOCKED, 'prefix' => 'stgabc123_', 'dir' => 'wpsync-staging-0123456789ab']);
        $this->assertStringContainsString('notice-warning', $html);
        $this->assertStringContainsString('wpsync staging open &lt;site&gt;', $html);
    }

    public function testEverythingFromTheRecordIsEscaped(): void
    {
        $evil = '"><script>alert(1)</script>';
        $html = $this->render([
            'status' => Staging::FAILED . $evil,
            'prefix' => 'stg' . $evil,
            'dir'    => 'wpsync-staging-' . $evil,
            'error'  => 'kaputt ' . $evil,
        ]);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('"><', $html);
        $this->assertSame(5, substr_count($html, '&lt;script&gt;alert(1)&lt;/script&gt;'), 'Status, Adresse, Ordner, Präfix, Fehler');
    }

    public function testFailedCopyShowsItsEscapedError(): void
    {
        $html = $this->render([
            'status' => Staging::FAILED,
            'prefix' => 'stgabc123_',
            'dir'    => 'wpsync-staging-0123456789ab',
            'error'  => 'Datei nicht kopierbar: <b>x</b>',
        ]);
        $this->assertStringContainsString('fehlgeschlagen', $html);
        $this->assertStringContainsString('Datei nicht kopierbar: &lt;b&gt;x&lt;/b&gt;', $html);
    }

    /**
     * Staging::login() macht den Aufrufer zum Administrator der Kopie und prüft ihn nicht selbst:
     * erreichbar ist es nur über die signierte Route. Die Admin-Seite kennt weder login() noch
     * eine der Job-Methoden – und keine POST-Aktion für Staging.
     */
    public function testTheAdminPageNeverCallsIntoTheStagingJob(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../src/Admin.php');
        preg_match_all('/Staging::(\w+)\(/', $source, $calls);
        $this->assertSame(['record', 'summary'], array_values(array_unique($this->sorted($calls[1]))));
        $this->assertSame(0, preg_match('/\$action === \'[a-z_]*staging/', $source), 'keine POST-Aktion für Staging');

        $callers = [];
        foreach (glob(__DIR__ . '/../src/*.php') ?: [] as $file) {
            if (preg_match('/Staging::login\(/', (string) file_get_contents($file)) === 1) {
                $callers[] = basename($file);
            }
        }
        $this->assertSame(['Rest.php'], $callers);
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        sort($values);
        return $values;
    }
}
