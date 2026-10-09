<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use WpSync\ContentPackage;
use WpSync\PushContent;

/**
 * Ablage eines Pakets über /content/stage (Spec Content-Push §7.5, Entwurf P2b): in Stücken, per
 * sha256 adressiert, je Kopplung getrennt, mit Verfall.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PushContentStageTest extends TestCase
{
    private const KEY   = '0123456789abcdef';
    private const OTHER = 'fedcba9876543210';

    private string $work;

    protected function setUp(): void
    {
        require_once __DIR__ . '/PushHarness.php';
        $this->work = (string) realpath(sys_get_temp_dir()) . '/wpsync-stage-' . bin2hex(random_bytes(4));
        mkdir($this->work, 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->work));
    }

    /**
     * @param array<string, mixed> $params
     * @return \WP_REST_Response|\WP_Error
     */
    private function stage(array $params, string $key = self::KEY, ?int $now = null)
    {
        return PushContent::stage($params, $key, $this->work, $now ?? time());
    }

    /** @param mixed $result */
    private function assertRefused(string $code, int $status, $result): \WP_Error
    {
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame([$code, $status], [$result->code, $result->status]);
        return $result;
    }

    public function testStagesAPackageInPieces(): void
    {
        $text = str_repeat("{\"zeile\":1}\n", 100);
        $sha  = hash('sha256', $text);
        $size = strlen($text);
        $this->assertSame(['sha256' => $sha, 'received' => 0, 'complete' => false], $this->stage(['sha256' => $sha, 'size' => $size])->data, 'Auskunft ohne data');
        $this->assertNull(PushContent::staged($this->work, self::KEY, $sha, time()));

        $first = $this->stage(['sha256' => $sha, 'size' => $size, 'offset' => 0, 'data' => base64_encode(substr($text, 0, 500))]);
        $this->assertSame(['sha256' => $sha, 'received' => 500, 'complete' => false], $first->data);
        $this->assertSame(500, $this->stage(['sha256' => $sha, 'size' => $size])->data['received'], 'die CLI setzt dort fort');
        $this->assertNull(PushContent::staged($this->work, self::KEY, $sha, time()), 'unvollständig ist nicht abgelegt');

        $error = $this->assertRefused('wpsync_content_offset', 409, $this->stage(['sha256' => $sha, 'size' => $size, 'offset' => 400, 'data' => base64_encode('x')]));
        $this->assertSame(500, $error->data['received']);

        $last = $this->stage(['sha256' => $sha, 'size' => $size, 'offset' => 500, 'data' => base64_encode(substr($text, 500))]);
        $this->assertSame(['sha256' => $sha, 'received' => $size, 'complete' => true], $last->data);
        $file = PushContent::staged($this->work, self::KEY, $sha, time());
        $this->assertSame($this->work . '/packages/' . self::KEY . '/' . $sha . '.jsonl', $file);
        $this->assertSame($text, file_get_contents((string) $file));

        $again = $this->stage(['sha256' => $sha, 'size' => $size, 'offset' => 0, 'data' => base64_encode('ganz anderes')]);
        $this->assertTrue($again->data['complete'], 'was vollständig liegt, wird nicht überschrieben');
        $this->assertSame($text, file_get_contents((string) $file));
        $this->assertNull(PushContent::staged($this->work, self::OTHER, $sha, time()), 'eine andere Kopplung sieht das Paket nicht');
    }

    public function testRefusesWhatDoesNotMatchItsChecksum(): void
    {
        $sha = hash('sha256', 'erwartet');
        $this->assertRefused('wpsync_content_hash', 400, $this->stage(['sha256' => $sha, 'size' => 6, 'offset' => 0, 'data' => base64_encode('anders')]));
        $this->assertNull(PushContent::staged($this->work, self::KEY, $sha, time()));
        $this->assertSame(0, $this->stage(['sha256' => $sha, 'size' => 6])->data['received'], 'der falsche Inhalt ist wieder weg');
    }

    public function testRefusesBadRequests(): void
    {
        $sha = str_repeat('a', 64);
        $this->assertRefused('wpsync_content_stage', 400, $this->stage(['sha256' => '../../etc/passwd', 'size' => 5]));
        $this->assertRefused('wpsync_content_stage', 400, $this->stage(['sha256' => $sha, 'size' => 0]));
        $this->assertRefused('wpsync_content_stage', 400, $this->stage(['sha256' => $sha, 'size' => 5], '../../../tmp'));
        $this->assertRefused('wpsync_content_stage', 400, $this->stage(['sha256' => $sha, 'size' => 5, 'offset' => 0, 'data' => 'kein base64!']));
        $this->assertRefused('wpsync_content_stage', 400, $this->stage(['sha256' => $sha, 'size' => 5, 'data' => base64_encode('x')]));
        $this->assertRefused('wpsync_content_offset', 409, $this->stage(['sha256' => $sha, 'size' => 5, 'offset' => 0, 'data' => base64_encode('zu lang')]));
        $this->assertRefused('wpsync_content_size', 413, $this->stage(['sha256' => $sha, 'size' => 6000000, 'offset' => 0, 'data' => base64_encode(str_repeat('x', 4194305))]));
        $big = $this->assertRefused('wpsync_content_package_too_large', 413, $this->stage(['sha256' => $sha, 'size' => ContentPackage::STAGE_BYTES + 1]));
        $this->assertSame(ContentPackage::MAX_BYTES, $big->data['limits']['max_bytes']);
        $this->assertSame([], glob($this->work . '/packages/*/*') ?: []);
    }

    /** Verfall: 24 Stunden nach der Ablage; nach dem bestätigten Push nach Live sofort (forget). */
    public function testExpiryAndForget(): void
    {
        $now = time();
        $sha = hash('sha256', 'paket');
        $this->stage(['sha256' => $sha, 'size' => 5, 'offset' => 0, 'data' => base64_encode('paket')], self::KEY, $now);
        $this->assertNotNull(PushContent::staged($this->work, self::KEY, $sha, $now + PushContent::TTL));
        $this->assertNull(PushContent::staged($this->work, self::KEY, $sha, $now + PushContent::TTL + 5), 'verfallen, auch wenn die Datei noch liegt');

        PushContent::expire($this->work, $now + PushContent::TTL - 5);
        $this->assertFileExists($this->work . '/packages/' . self::KEY . '/' . $sha . '.jsonl');
        PushContent::expire($this->work, $now + PushContent::TTL + 5);
        $this->assertDirectoryDoesNotExist($this->work . '/packages/' . self::KEY);

        $this->stage(['sha256' => $sha, 'size' => 5, 'offset' => 0, 'data' => base64_encode('paket')], self::KEY, $now);
        PushContent::forget($this->work, self::KEY, $sha);
        $this->assertNull(PushContent::staged($this->work, self::KEY, $sha, $now));
    }

    /** Eine Kopplung belegt nie mehr als KEEP Dateien – auch nicht mit angefangenen Uploads. */
    public function testKeepsAtMostFiveFilesPerPairing(): void
    {
        $dir = $this->work . '/packages/' . self::KEY;
        for ($i = 0; $i < 8; $i++) {
            $text = 'paket ' . $i;
            $this->stage(['sha256' => hash('sha256', $text), 'size' => strlen($text) + ($i % 2), 'offset' => 0, 'data' => base64_encode($text)]);
            foreach (glob($dir . '/*') ?: [] as $file) {
                touch($file, (int) filemtime($file) - 10); // jede Ablage ist älter als die nächste
            }
        }
        $this->assertCount(PushContent::KEEP, glob($dir . '/*') ?: []);
        $this->assertNotNull(PushContent::staged($this->work, self::KEY, hash('sha256', 'paket 6'), time()), 'das neueste vollständige Paket liegt noch');
    }

    /** Die Route selbst: ohne Push-Fenster, immer in den Arbeitsordner von Live, geschützt wie die Snapshots. */
    public function testPushStageNeedsNoWindowAndUsesTheLiveWorkDir(): void
    {
        $live = $this->work . '/wp-content';
        mkdir($live . '/plugins/wpsync-agent', 0777, true);
        define('WP_CONTENT_DIR', $live);
        $GLOBALS['wpdb']        = new \WpsyncHarnessDb();
        \WpSync\Store::$until = 0; // Fenster geschlossen
        \WpSync\Push::register($live . '/plugins/wpsync-agent');

        $sha    = hash('sha256', 'paket');
        $result = \WpSync\Push::stage(['sha256' => $sha, 'size' => 5, 'offset' => 0, 'data' => base64_encode('paket')], self::KEY);
        $this->assertInstanceOf(\WP_REST_Response::class, $result);
        $this->assertTrue($result->data['complete']);
        $dir = $live . '/' . \WpSync\Store::pushDirName();
        $this->assertFileExists($dir . '/packages/' . self::KEY . '/' . $sha . '.jsonl');
        $this->assertStringContainsString('Require all denied', (string) file_get_contents($dir . '/.htaccess'));
        $this->assertSame([], \WpSync\Store::$pushes, 'ablegen legt keinen Push an');
    }

    public function testTakeCopiesExactlyTheCheckedPackage(): void
    {
        $sha = hash('sha256', 'paket');
        $this->stage(['sha256' => $sha, 'size' => 5, 'offset' => 0, 'data' => base64_encode('paket')]);
        $staged = (string) PushContent::staged($this->work, self::KEY, $sha, time());
        $push   = $this->work . '/p_20261009_0123456789ab';
        $this->assertTrue(PushContent::take($staged, $push, $sha));
        $this->assertSame($push . '/content/package.jsonl', PushContent::taken($push, $sha));
        file_put_contents($push . '/content/package.jsonl', 'verändert');
        $this->assertNull(PushContent::taken($push, $sha), 'ein verändertes Paket wird nicht angewandt');
        $this->assertFalse(PushContent::take($staged, $this->work . '/p_20261009_ba9876543210', str_repeat('0', 64)));
    }
}
