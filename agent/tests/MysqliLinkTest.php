<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\TestCase;
use WpSync\MysqliLink;
use WpSync\RescueContent;
use WpSync\RescueDb;

/**
 * MysqliLink ohne Datenbank (Spec Content-Push P3 §6.1, AC-173): ein gescheiterter
 * Verbindungsaufbau ist still – keine Ausgabe, keine Warnung, keine Zeile im Fehlerprotokoll;
 * die Meldung von mysqli nennte Benutzer und Host. Was eine echte Datenbank antwortet, prüft
 * scripts/e2e-rescue-db.sh.
 */
final class MysqliLinkTest extends TestCase
{
    /** Nichts hört auf Port 1: die Verbindung wird sofort abgelehnt. */
    private const DB = [
        'host' => '127.0.0.1', 'port' => 1, 'socket' => null, 'user' => 'geheimer_benutzer', 'password' => 'geheimes passwort', 'name' => 'geheime_db',
        'flags' => 0, 'charset' => 'utf8mb4', 'collate' => '', 'sql_mode' => '',
    ];

    private string $log;
    /** @var array<string, string|false> */
    private array $ini = [];

    protected function setUp(): void
    {
        if (!extension_loaded('mysqli')) {
            $this->markTestSkipped('needs ext-mysqli');
        }
        $this->log = (string) tempnam(sys_get_temp_dir(), 'wpsync-log-');
        foreach (['log_errors' => '1', 'error_log' => $this->log, 'display_errors' => '1'] as $name => $value) {
            $this->ini[$name] = ini_set($name, $value);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->ini as $name => $value) {
            if ($value !== false) {
                ini_set($name, $value);
            }
        }
        if (isset($this->log)) {
            @unlink($this->log);
        }
        RescueContent::$resolve = null;
    }

    private function assertSilent(string $output): void
    {
        $this->assertSame('', $output);
        $this->assertSame('', (string) file_get_contents($this->log), 'nichts im Fehlerprotokoll');
        $this->assertNull(error_get_last());
    }

    /**
     * Security-Review P3, N2: LOAD DATA LOCAL INFILE ist auf dieser Verbindung aus, bevor sie steht –
     * ein fremder oder übernommener Datenbankserver liest über sie keine Datei des Webservers.
     */
    public function testLocalInfileIsOffBeforeTheConnectionIsMade(): void
    {
        $options = MysqliLink::options();
        $this->assertSame(0, $options[MYSQLI_OPT_LOCAL_INFILE] ?? null);
        $this->assertSame(MysqliLink::CONNECT_SECONDS, $options[MYSQLI_OPT_CONNECT_TIMEOUT] ?? null);
        // Dieselbe Liste geht an mysqli_options(), vor mysqli_real_connect() – und ohne sie keine Verbindung.
        $source  = (string) file_get_contents(dirname(__DIR__) . '/src/MysqliLink.php');
        $set     = strpos($source, 'foreach (self::options() as $option => $value)');
        $connect = strpos($source, 'mysqli_real_connect($handle');
        $this->assertIsInt($set);
        $this->assertIsInt($connect);
        $this->assertLessThan($connect, $set);
        $this->assertSame(1, substr_count($source, 'mysqli_real_connect($handle'));
        $this->assertSame(1, preg_match('/if \(!mysqli_options\(\$handle, \$option, \$value\) && \$option === MYSQLI_OPT_LOCAL_INFILE\) \{\s+return null;/', $source));
        // Am echten Handle: die Optionen lassen sich setzen.
        $handle = mysqli_init();
        foreach ($options as $option => $value) {
            $this->assertTrue(mysqli_options($handle, $option, $value), 'option ' . $option);
        }
    }

    public function testAFailedConnectionIsSilentAndNamesNothing(): void
    {
        error_clear_last();
        ob_start();
        $link = MysqliLink::open(self::DB);
        $db   = RescueDb::connect(self::DB);
        $this->assertSilent((string) ob_get_clean());
        $this->assertNull($link);
        $this->assertNull($db);
    }

    /** Auch die Probe des Begin und Schritt 6 sagen dann nur: nicht erreichbar. */
    public function testTheProbeFailsSilently(): void
    {
        error_clear_last();
        ob_start();
        $ok = RescueContent::probe(['db' => self::DB, 'prefix' => 'wp_'], '/var/www/html/wp-content');
        $this->assertSilent((string) ob_get_clean());
        $this->assertFalse($ok);
    }

    public function testOpenWithoutUsableDataGivesNothing(): void
    {
        ob_start();
        $this->assertNull(MysqliLink::open([]));
        $this->assertNull(MysqliLink::open(['host' => '127.0.0.1', 'port' => 1, 'socket' => 'kein socket', 'flags' => 'x']));
        $this->assertSame('', (string) ob_get_clean());
        $this->assertSame('', (string) file_get_contents($this->log));
    }
}
