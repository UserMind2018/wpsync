<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\TestCase;
use WpSync\PushRescue;
use WpSync\PushRescueStub;

/** Rescue-Stub im Webroot (Spec Stufe 2, 12; B1). */
final class PushRescueStubTest extends TestCase
{
    private const ID = 'p_20261008_0123456789ab';

    private string $root;
    private string $plugin;

    protected function setUp(): void
    {
        $this->root   = (string) realpath(sys_get_temp_dir()) . '/wpsync-stub-' . bin2hex(random_bytes(4));
        $this->plugin = $this->root . '/wp-content/plugins/wpsync-agent';
        mkdir($this->plugin . '/src', 0777, true);
        // Die echten Dateien, die rescue.php lädt – der Stub soll sie über sein require finden.
        $agent = dirname(__DIR__);
        copy($agent . '/rescue.php', $this->plugin . '/rescue.php');
        copy($agent . '/src/PushSwap.php', $this->plugin . '/src/PushSwap.php');
        copy($agent . '/src/PushRescue.php', $this->plugin . '/src/PushRescue.php');
    }

    protected function tearDown(): void
    {
        @chmod($this->root, 0777);
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    /** @return list<string> Stubs im Webroot, nur echte Dateien mit dem Namensmuster */
    private function stubs(): array
    {
        $out = [];
        foreach (scandir($this->root) ?: [] as $name) {
            if (preg_match(PushRescueStub::NAME, $name) === 1 && !is_link($this->root . '/' . $name)) {
                $out[] = $name;
            }
        }
        return $out;
    }

    /**
     * Ruft den Stub so auf, wie PHP-FPM es täte: POST mit Formularfeldern.
     *
     * @param array<string, string> $post
     */
    private function post(string $stub, array $post): string
    {
        $script = '$_SERVER["REQUEST_METHOD"] = "POST"; $_POST = json_decode((string) getenv("POST"), true); require getenv("STUB");';
        $cmd    = 'STUB=' . escapeshellarg($this->root . '/' . $stub) . ' POST=' . escapeshellarg((string) json_encode($post))
            . ' ' . escapeshellarg(PHP_BINARY) . ' -d display_errors=1 -r ' . escapeshellarg($script) . ' 2>&1';
        return (string) shell_exec($cmd);
    }

    /** AC-135 */
    public function testCreateWritesOnlyARelativeRequire(): void
    {
        $name = PushRescueStub::create($this->root, $this->plugin);
        $this->assertNotNull($name);
        $this->assertMatchesRegularExpression(PushRescueStub::NAME, (string) $name);
        $this->assertSame([$name], $this->stubs());
        $code = (string) file_get_contents($this->root . '/' . $name);
        $this->assertStringContainsString("\$f = __DIR__ . '/wp-content/plugins/wpsync-agent/rescue.php';", $code);
        $this->assertStringContainsString('if (!is_file($f)) {', $code);
        $this->assertStringContainsString('require $f;', $code);
        $this->assertStringNotContainsString($this->root, $code, 'no absolute server path');
        $this->assertSame('0644', substr(sprintf('%o', fileperms($this->root . '/' . $name)), -4));
        $this->assertSame([], glob($this->root . '/.wpsync-rescue-*') ?: [], 'no temp file left');
        $this->assertNotSame($name, PushRescueStub::create($this->root, $this->plugin), 'every stub gets a new name');
    }

    /** AC-132: ping und rollback über den Stub wirken wie über rescue.php. */
    public function testTheStubAnswersLikeRescuePhp(): void
    {
        $name = (string) PushRescueStub::create($this->root, $this->plugin);
        $this->assertSame('{"ok":true}', $this->post($name, ['action' => 'ping']));

        $key = PushRescue::key(str_repeat('ab', 32), self::ID, 'salt');
        PushRescue::write($this->root . '/wp-content/wpsync-push-0123456789abcdef', self::ID, hash('sha256', $key), [], PushRescue::COMMITTED);
        $this->assertSame('{"ok":true,"status":"rolled_back","push_id":"' . self::ID . '"}', $this->post($name, ['action' => 'rollback', 'push_id' => self::ID, 'key' => $key]));
    }

    /** N1: Agent-Ordner per FTP gelöscht – der verwaiste Stub verrät keinen Serverpfad. */
    public function testAnOrphanedStubAnswersWithoutAPath(): void
    {
        $name = (string) PushRescueStub::create($this->root, $this->plugin);
        rename($this->plugin, $this->plugin . '-off');
        $this->assertSame('', $this->post($name, ['action' => 'ping']));
    }

    /** R6 */
    public function testCreateRefusesAForeignLayout(): void
    {
        mkdir($this->root . '/elsewhere');
        $this->assertNull(PushRescueStub::create($this->root . '/elsewhere', $this->plugin), 'agent not below this webroot');
        mkdir($this->root . '/other');
        $this->assertNull(PushRescueStub::create($this->root, $this->root . '/other'), 'no agent under wp-content/plugins');
        $this->assertSame([], $this->stubs());
    }

    /** AC-133 */
    public function testCreateFallsBackWhenTheWebrootIsReadOnly(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root ignores file permissions');
        }
        chmod($this->root, 0555);
        $this->assertNull(PushRescueStub::create($this->root, $this->plugin));
    }

    /** R9 */
    public function testRemoveTouchesOnlyStubsAndKeepsTheNamedOne(): void
    {
        $keep = (string) PushRescueStub::create($this->root, $this->plugin);
        $drop = (string) PushRescueStub::create($this->root, $this->plugin);
        $tmp  = $this->root . '/.wpsync-rescue-' . str_repeat('a', 32) . '.php.tmp';
        $link = $this->root . '/wpsync-rescue-' . str_repeat('b', 32) . '.php';
        file_put_contents($tmp, 'x');
        file_put_contents($this->root . '/wpsync-rescue-notes.php', 'x');
        file_put_contents($this->root . '/index.php', 'x');
        symlink($this->root . '/index.php', $link);

        PushRescueStub::remove($this->root, $keep);
        $this->assertFileExists($this->root . '/' . $keep);
        $this->assertFileDoesNotExist($this->root . '/' . $drop);
        $this->assertFileDoesNotExist($tmp);
        $this->assertFileExists($this->root . '/wpsync-rescue-notes.php');
        $this->assertFileExists($this->root . '/index.php');
        $this->assertTrue(is_link($link), 'symlinks are not touched');

        PushRescueStub::remove($this->root);
        $this->assertSame([], $this->stubs());
        $this->assertTrue(is_link($link));
    }

    public function testExistsChecksNameAndFile(): void
    {
        $name = (string) PushRescueStub::create($this->root, $this->plugin);
        $this->assertTrue(PushRescueStub::exists($this->root, $name));
        $this->assertFalse(PushRescueStub::exists($this->root, '../' . $name));
        $this->assertFalse(PushRescueStub::exists($this->root, 'wpsync-rescue-' . str_repeat('c', 32) . '.php'));
    }

    /** AC-136 */
    public function testHardeningNamesKnownSecurityPlugins(): void
    {
        $this->assertSame(['better-wp-security', 'sucuri-scanner'], PushRescueStub::hardening([
            'sucuri-scanner/sucuri.php', 'woocommerce/woocommerce.php', 'better-wp-security/better-wp-security.php', 7, 'hello.php',
        ]));
        $this->assertSame([], PushRescueStub::hardening([]));
    }
}
