<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\TestCase;
use WpSync\PushRescue;

/**
 * rescue.php selbst, als Request (Spec Content-Push P3 §4, §7.5, R8; AC-169, AC-170): in einem
 * eigenen PHP-Prozess – mit php-cgi, wo es das gibt, sonst über tests/rescue-request.php – gegen
 * einen temporären Webroot im Standardlayout. WordPress ist dort eine wp-load.php, die sich wie
 * WordPress mit SHORTINIT verhält, soweit es der Cache-Schritt braucht.
 */
final class RescueScriptTest extends TestCase
{
    private const ID  = 'p_20261009_0123456789ab';
    private const SHA = 'ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12';

    private string $root;
    private string $content;
    private string $work;
    private string $key;

    protected function setUp(): void
    {
        $this->root    = (string) realpath(sys_get_temp_dir()) . '/wpsync-rescuescript-' . bin2hex(random_bytes(4));
        $this->content = $this->root . '/wp-content';
        $this->work    = $this->content . '/wpsync-push-0123456789abcdef';
        $this->key     = PushRescue::key(str_repeat('ab', 32), self::ID, 'salt');
        $plugin        = $this->content . '/plugins/wpsync-agent';
        mkdir($plugin . '/src', 0777, true);
        copy(__DIR__ . '/../rescue.php', $plugin . '/rescue.php');
        foreach (glob(__DIR__ . '/../src/*.php') ?: [] as $file) {
            copy($file, $plugin . '/src/' . basename($file));
        }
        mkdir($this->content . '/plugins/x', 0777, true);
        mkdir($this->work . '/' . self::ID . '/old/0', 0777, true);
        file_put_contents($this->content . '/plugins/x/main.php', 'new');
        file_put_contents($this->work . '/' . self::ID . '/old/0/main.php', 'old');
    }

    protected function tearDown(): void
    {
        exec('chmod -R u+w ' . escapeshellarg($this->root) . ' 2>/dev/null; rm -rf ' . escapeshellarg($this->root));
    }

    /** Ein getauschter Push mit DB-Anteil in diesem Webroot. */
    private function committed(?string $sha = self::SHA): void
    {
        PushRescue::write($this->work, self::ID, hash('sha256', $this->key), [[
            'unit'     => 'plugins/x',
            'target'   => $this->content . '/plugins/x',
            'snapshot' => $this->work . '/' . self::ID . '/old/0',
            'discard'  => $this->work . '/' . self::ID . '/discard/0',
        ]], PushRescue::COMMITTED, [], $sha);
    }

    /** Der Push ist ganz zurück, rescue.php hat Zeilen zurückgeschrieben, ein Object-Cache-Drop-in liegt da. */
    private function stale(): void
    {
        $this->committed();
        PushRescue::setStatus($this->work, self::ID, PushRescue::ROLLED_BACK);
        PushRescue::setContentFields($this->work, self::ID, ['state' => 'rolled_back', 'via' => 'rescue', 'post' => 'pending', 'cache' => 'stale']);
    }

    /** Eine wp-load.php, wie der Cache-Schritt sie antrifft: $body läuft dort, wo wp-settings.php liefe. */
    private function wordpress(string $body): void
    {
        file_put_contents($this->root . '/wp-load.php', "<?php\nfile_put_contents(__DIR__ . '/loaded.txt', defined('SHORTINIT') && SHORTINIT === true ? 'shortinit' : 'full');\n" . $body . "\n");
    }

    /**
     * @param array<string, string> $post
     * @return array{0: int|null, 1: string} HTTP-Status (null: nicht zu ermitteln) und Body
     */
    private function request(array $post, string $method = 'POST'): array
    {
        $script = $this->content . '/plugins/wpsync-agent/rescue.php';
        $cgi    = dirname(PHP_BINARY) . '/php-cgi';
        if (is_executable($cgi) && getenv('WPSYNC_TEST_NO_CGI') === false) { // die Variable erzwingt den Weg ohne php-cgi
            $body = http_build_query($post);
            $env  = [
                'REDIRECT_STATUS' => '1', 'GATEWAY_INTERFACE' => 'CGI/1.1', 'REQUEST_METHOD' => $method, 'SCRIPT_FILENAME' => $script,
                'CONTENT_TYPE' => 'application/x-www-form-urlencoded', 'CONTENT_LENGTH' => (string) strlen($body), 'PATH' => (string) getenv('PATH'),
            ];
            $process = proc_open([$cgi, '-d', 'log_errors=0'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
            $this->assertIsResource($process);
            fwrite($pipes[0], $body);
            fclose($pipes[0]);
            $out = (string) stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            proc_close($process);
            $parts  = explode("\r\n\r\n", $out, 2);
            $status = preg_match('/^Status: (\d{3})/mi', $parts[0], $m) === 1 ? (int) $m[1] : 200;
            $this->assertStringContainsString('Content-type: application/json', strtr($parts[0], ['Content-Type' => 'Content-type']));
            return [$status, $parts[1] ?? ''];
        }
        $process = proc_open([PHP_BINARY, '-d', 'log_errors=0', __DIR__ . '/rescue-request.php', $script, (string) json_encode($post), $method], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        proc_close($process);
        return [preg_match('/status=(\d+)\z/', $err, $m) === 1 ? (int) $m[1] : null, $out];
    }

    /** @return array<string, string> */
    private function post(string $action, array $over = []): array
    {
        return $over + ['action' => $action, 'push_id' => self::ID, 'key' => $this->key];
    }

    /** @return array<string, mixed> */
    private function record(): array
    {
        return (array) PushRescue::read($this->work, self::ID);
    }

    public function testAnswersWithoutWordPress(): void
    {
        $this->assertSame([405, '{"ok":false,"error":"POST only"}'], $this->request([], 'GET'));
        $this->assertSame([200, '{"ok":true}'], $this->request(['action' => 'ping']));
        $this->assertSame([400, '{"ok":false,"error":"bad request"}'], $this->request(['action' => 'rollback']));
        $this->assertSame([404, '{"ok":false,"error":"unknown push"}'], $this->request($this->post('rollback')));
        $this->committed(null);
        $this->assertSame([403, '{"ok":false,"error":"wrong key"}'], $this->request($this->post('rollback', ['key' => 'falsch'])));
        $this->assertSame('new', file_get_contents($this->content . '/plugins/x/main.php'));
        $this->assertSame([200, '{"ok":true,"status":"rolled_back"}'], $this->request($this->post('rollback')));
        $this->assertSame('old', file_get_contents($this->content . '/plugins/x/main.php'));
        $this->assertFileExists($this->work . '/rescue.pending');
    }

    /**
     * AC-170 am echten Einstieg: mit content=1 lädt rescue.php seine Liste ohne WordPress, findet
     * keinen brauchbaren Umschlag – und nimmt Code und Uploads trotzdem zurück (R7).
     */
    public function testWithContentItLoadsItsClassesAndFallsBackToCodeAndUploads(): void
    {
        $this->committed();
        PushRescue::setContent($this->work, self::ID, PushRescue::CONTENT_APPLIED);
        mkdir($this->work . '/' . self::ID . '/content');
        file_put_contents($this->work . '/' . self::ID . '/content/before.json', '{"keys":[]}');
        file_put_contents($this->work . '/' . self::ID . '/rescue.sealed', "wpsync-rescue:v1:gcm\nkein Umschlag");
        list($status, $body) = $this->request($this->post('rollback', ['content' => '1']));
        $this->assertSame(200, $status);
        $this->assertSame(
            ['ok' => true, 'status' => 'rolled_back', 'content' => ['state' => 'kept', 'error' => ['code' => 'rescue_db_unavailable']], 'warnings' => ['content_not_rolled_back']],
            json_decode($body, true)
        );
        $this->assertSame('old', file_get_contents($this->content . '/plugins/x/main.php'));
        $this->assertFileDoesNotExist($this->root . '/loaded.txt', 'die Rücknahme lädt nie WordPress');
    }

    /** Fehlt dem Agent die Rücknahme der Inhalte (halb aktualisiert), antwortet rescue.php trotzdem – und nimmt den Code zurück. */
    public function testAMissingContentClassIsNoFatal(): void
    {
        $this->committed();
        PushRescue::setContent($this->work, self::ID, PushRescue::CONTENT_APPLIED);
        mkdir($this->work . '/' . self::ID . '/content');
        file_put_contents($this->work . '/' . self::ID . '/content/before.json', '{"keys":[]}');
        unlink($this->content . '/plugins/wpsync-agent/src/RescueContent.php');
        list($status, $body) = $this->request($this->post('rollback', ['content' => '1']));
        $this->assertSame(200, $status);
        $this->assertSame(['state' => 'kept', 'error' => ['code' => 'rescue_db_unavailable']], json_decode($body, true)['content']);
        $this->assertSame('old', file_get_contents($this->content . '/plugins/x/main.php'));
    }

    /**
     * R8, AC-169: action=cache lädt WordPress mit SHORTINIT im globalen Geltungsbereich – ein
     * Drop-in sieht die Variablen aus wp-config.php –, verwirft jede Ausgabe und leert den Cache.
     */
    public function testTheCacheStepLoadsWordPressWithShortinitInTheGlobalScope(): void
    {
        $this->stale();
        $this->wordpress(<<<'PHP'
$memcached_servers = ['default' => ['127.0.0.1:11211']]; // wie in wp-config.php: eine globale Variable
echo '<b>Notice</b>: Ausgabe eines Drop-ins';
ini_set('display_errors', '1');                          // wie wp_debug_mode() mit WP_DEBUG_DISPLAY
function wp_cache_flush()
{
    global $memcached_servers;
    file_put_contents(__DIR__ . '/flushed.txt', json_encode([$memcached_servers, defined('WPSYNC_RESCUE')]));
    return true;
}
PHP);
        $this->assertSame([200, '{"ok":true,"cache":"flushed"}'], $this->request($this->post('cache')));
        $this->assertSame('shortinit', file_get_contents($this->root . '/loaded.txt'));
        $this->assertSame('[{"default":["127.0.0.1:11211"]},true]', file_get_contents($this->root . '/flushed.txt'), 'das Drop-in sah die globale Variable');
        $this->assertSame('flushed', $this->record()['content']['cache']);
        // Einmal geleert: ein zweiter Aufruf lädt WordPress nicht noch einmal.
        unlink($this->root . '/loaded.txt');
        $this->assertSame([409, '{"ok":false,"error":"nothing to flush"}'], $this->request($this->post('cache')));
        $this->assertFileDoesNotExist($this->root . '/loaded.txt');
    }

    /** Vor der Schlüsselprüfung und ohne „stale“ wird WordPress nie geladen. */
    public function testTheCacheStepNeverLoadsWordPressWithoutTheKeyOrWithoutAStaleCache(): void
    {
        $this->stale();
        $this->wordpress('function wp_cache_flush() { return true; }');
        $this->assertSame([403, '{"ok":false,"error":"wrong key"}'], $this->request($this->post('cache', ['key' => 'falsch'])));
        PushRescue::setContentFields($this->work, self::ID, ['cache' => 'none']);
        $this->assertSame([409, '{"ok":false,"error":"nothing to flush"}'], $this->request($this->post('cache')));
        PushRescue::setContentFields($this->work, self::ID, ['cache' => 'stale']);
        PushRescue::setStatus($this->work, self::ID, PushRescue::COMMITTED);
        $this->assertSame([409, '{"ok":false,"error":"nothing to flush"}'], $this->request($this->post('cache')), 'solange der gepushte Code noch liegt');
        $this->assertFileDoesNotExist($this->root . '/loaded.txt');
    }

    /** @return array<string, array{0: string|null}> */
    public static function brokenWordPress(): array
    {
        return [
            'keine wp-load.php'                => [null],
            'Wartungsmodus (die)'              => ["http_response_code(503);\necho 'Briefly unavailable for scheduled maintenance.';\ndie();"],
            'keine Datenbank (exit mit Seite)' => ["header('Content-Type: text/html');\nexit('<h1>Error establishing a database connection</h1> db.internal');"],
            'Fatal im Drop-in'                 => ['wpsync_function_of_a_missing_plugin();'],
            'Exception im Drop-in'             => ["throw new RuntimeException('Redis: connection refused at 10.0.0.5:6379');"],
            'kein wp_cache_flush'              => ['// ein Drop-in ohne die Funktion'],
            'das Leeren scheitert'             => ['function wp_cache_flush() { return false; }'],
            'das Leeren wirft'                 => ["function wp_cache_flush() { throw new Exception('geheim'); }"],
        ];
    }

    /**
     * AC-169: scheitert der Schritt, bleibt die Rücknahme gültig – die Antwort ist JSON ohne ein Wort
     * von WordPress, der Cache bleibt „stale“, und die CLI meldet object_cache_stale.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('brokenWordPress')]
    public function testAFailingCacheStepAnswersWithJsonAndKeepsTheRollback(?string $body): void
    {
        $this->stale();
        if ($body !== null) {
            $this->wordpress($body);
        }
        list($status, $answer) = $this->request($this->post('cache'));
        $this->assertSame('{"ok":false,"error":"cache failed"}', $answer);
        if ($status !== null) {
            $this->assertSame(500, $status);
        }
        $record = $this->record();
        $this->assertSame(['rolled_back', 'stale', 'rolled_back'], [$record['status'], $record['content']['cache'], $record['content']['state']]);
    }
}
