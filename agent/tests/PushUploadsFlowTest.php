<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use WpSync\Push;
use WpSync\PushRescue;
use WpSync\PushUploads;
use WpSync\Staging;
use WpSync\Store;

/**
 * Einheit uploads im echten Push-Ablauf (Spec Content-Push §8, AC-140–144): Push läuft auf einem
 * temporären Webroot, WordPress, Store und Staging sind Attrappen (PushHarness.php).
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PushUploadsFlowTest extends TestCase
{
    private const KEY = 'k_test';
    /** 1×1-PNG – getimagesize() erkennt es als Bild. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private string $root;
    private string $live;
    private string $staging;

    protected function setUp(): void
    {
        require_once __DIR__ . '/PushHarness.php';

        $this->root    = (string) realpath(sys_get_temp_dir()) . '/wpsync-uploadflow-' . bin2hex(random_bytes(4));
        $this->live    = $this->root . '/wp-content';
        $this->staging = $this->root . '/' . Staging::DIR . '/wp-content';
        foreach ([$this->live . '/plugins/wpsync-agent', $this->live . '/plugins/x', $this->live . '/uploads/2026/10', $this->staging . '/plugins/x'] as $dir) {
            mkdir($dir, 0777, true);
        }
        chmod($this->live . '/uploads/2026', 0755);
        chmod($this->live . '/uploads/2026/10', 0755);
        file_put_contents($this->live . '/plugins/x/main.php', 'old');
        file_put_contents($this->staging . '/plugins/x/main.php', 'stg-old');

        define('WP_CONTENT_DIR', $this->live);
        $GLOBALS['wpdb'] = new \WpsyncHarnessDb();
        Staging::$root   = $this->root;
        Store::$until    = time() + 3600;
        Push::register($this->live . '/plugins/wpsync-agent');
    }

    protected function tearDown(): void
    {
        exec('chmod -R u+w ' . escapeshellarg($this->root) . ' 2>/dev/null; rm -rf ' . escapeshellarg($this->root));
    }

    private function png(string $tail = ''): string
    {
        return (string) base64_decode(self::PNG) . $tail;
    }

    /** @return array{size: int, sha256: string, mtime: int} */
    private function entry(string $content): array
    {
        return ['size' => strlen($content), 'sha256' => hash('sha256', $content), 'mtime' => 1700000000];
    }

    /**
     * @param array<string, string> $uploads Pfad relativ zu uploads/ → Inhalt
     * @param array<string, mixed>  $extra
     * @return \WP_REST_Response|\WP_Error
     */
    private function begin(array $uploads, array $extra = [], ?string $code = null)
    {
        $units = [];
        if ($code !== null) {
            $units[] = ['path' => 'plugins/x', 'files' => ['main.php' => $this->entry($code)], 'base' => []];
        }
        $units[] = ['path' => 'uploads', 'files' => array_map([$this, 'entry'], $uploads), 'base' => []];
        return Push::begin($extra + ['force' => true, 'units' => $units], self::KEY);
    }

    /** @param array<string, string> $uploads */
    private function sendAll(\WP_REST_Response $begin, array $uploads, ?string $code): void
    {
        $id = (string) $begin->data['push_id'];
        $u  = 0;
        if ($code !== null) {
            $this->assertInstanceOf(\WP_REST_Response::class, Push::upload(['push_id' => $id, 'unit' => 0, 'files' => [['path' => 'main.php', 'data' => base64_encode($code), 'offset' => 0]]], self::KEY));
            $u = 1;
        }
        $chunks = [];
        foreach ($begin->data['units'][$u]['need'] as $rel) {
            $chunks[] = ['path' => $rel, 'data' => base64_encode($uploads[$rel]), 'offset' => 0];
        }
        if ($chunks !== []) {
            $up = Push::upload(['push_id' => $id, 'unit' => $u, 'files' => $chunks], self::KEY);
            $this->assertInstanceOf(\WP_REST_Response::class, $up, $up instanceof \WP_Error ? $up->code : '');
        }
    }

    /**
     * Begin, Upload, Commit; liefert ID und Antwort des Commits.
     *
     * @param array<string, string> $uploads
     * @return array{0: string, 1: \WP_REST_Response|\WP_Error}
     */
    private function push(array $uploads, ?string $code = null, string $target = 'live'): array
    {
        $begin = $this->begin($uploads, ['target' => $target], $code);
        $this->assertInstanceOf(\WP_REST_Response::class, $begin, $begin instanceof \WP_Error ? $begin->code : '');
        $this->sendAll($begin, $uploads, $code);
        $id = (string) $begin->data['push_id'];
        return [$id, Push::commit(['push_id' => $id], self::KEY)];
    }

    /** @param mixed $result */
    private function assertError(string $code, int $status, $result): void
    {
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame([$code, $status], [$result->code, $result->status]);
    }

    private function upload(string $rel): string
    {
        return $this->live . '/uploads/' . $rel;
    }

    private function perms(string $path): string
    {
        clearstatcache();
        return substr(sprintf('%o', fileperms($path)), -4);
    }

    /** AC-140/AC-141: der Probelauf ordnet jede Datei ein und legt nichts an. */
    public function testTheDryRunSortsEveryFile(): void
    {
        file_put_contents($this->upload('2026/10/gleich.png'), $this->png());
        file_put_contents($this->upload('2026/10/anders.png'), $this->png('alt'));
        $dry = $this->begin([
            '2026/10/neu.png'    => $this->png('neu'),
            '2026/10/gleich.png' => $this->png(),
            '2026/10/anders.png' => $this->png('neu'),
        ], ['dry' => true]);
        $this->assertInstanceOf(\WP_REST_Response::class, $dry);
        $plan = $dry->data['units'][0];
        $this->assertSame(
            ['uploads', ['2026/10/neu.png'], ['2026/10/gleich.png'], ['2026/10/anders.png'], true],
            [$plan['path'], $plan['need'], $plan['same'], $plan['conflicts'], $plan['writable']]
        );
        $this->assertSame([], Store::$pushes);
        $this->assertFileDoesNotExist($this->upload('2026/10/neu.png'));
    }

    /** AC-141: anderer Inhalt am selben Pfad – der echte Begin bricht ab, auch mit --force, auch der Code. */
    public function testAnExistingFileWithOtherContentStopsTheWholeSet(): void
    {
        file_put_contents($this->upload('2026/10/anders.png'), $this->png('alt'));
        $result = $this->begin(['2026/10/anders.png' => $this->png('neu'), '2026/10/neu.png' => $this->png('neu')], [], 'new');
        $this->assertError('wpsync_upload_exists', 409, $result);
        $this->assertSame([], Store::$pushes);
        $this->assertNull(Store::getState('push_lock'));
        $this->assertSame($this->png('alt'), file_get_contents($this->upload('2026/10/anders.png')));
        $this->assertFileDoesNotExist($this->upload('2026/10/neu.png'));
        $this->assertSame('old', file_get_contents($this->live . '/plugins/x/main.php'));
    }

    /** AC-142: ausführbar, versteckt oder von WordPress auf dem Ziel nicht erlaubt. */
    public function testTypesThatWordPressOrWpsyncRefuse(): void
    {
        $names = [
            '2026/10/x.php', '2026/10/X.PHTML', '2026/10/x.phar', '2026/10/x.php5', '2026/10/x.pht', '2026/10/bild.php.png',
            '.htaccess', '2026/10/.htaccess', '.user.ini', '2026/10/.versteckt.png', '2026/10/x.svg', '2026/10/x.exe', '2026/10/debug.log',
        ];
        foreach ($names as $rel) {
            $this->assertError('wpsync_upload_type_blocked', 400, $this->begin([$rel => 'x'], ['dry' => true]));
        }
        $this->assertInstanceOf(\WP_REST_Response::class, $this->begin(['2026/10/bild.png' => $this->png()], ['dry' => true]));
    }

    public function testPathsOutsideTheUploadsAreRefused(): void
    {
        foreach (['../plugins/x/main.php', '2026/../../x.png', 'wpsync-push-0123456789abcdef/a.png', '2026/.git/a.png', "2026/\x01.png"] as $rel) {
            $this->assertError('wpsync_upload_path', 400, $this->begin([$rel => 'x'], ['dry' => true]));
        }
    }

    public function testTheUnitItselfIsChecked(): void
    {
        $unit = ['path' => 'uploads', 'files' => ['2026/10/a.png' => $this->entry('a')], 'base' => []];
        $this->assertError('wpsync_push_unit', 400, Push::begin(['dry' => true, 'units' => [$unit, $unit]], self::KEY));
        $this->assertError('wpsync_push_unit', 400, Push::begin(['dry' => true, 'units' => [['path' => 'Uploads'] + $unit]], self::KEY));
        $many = [];
        for ($i = 0; $i <= PushUploads::MAX_FILES; $i++) {
            $many['2026/10/' . $i . '.png'] = $this->entry('a');
        }
        $this->assertError('wpsync_push_units', 400, Push::begin(['dry' => true, 'units' => [['path' => 'uploads', 'files' => $many, 'base' => []]]], self::KEY));
    }

    /** A4: Uploads nur in den Ordner, den WordPress benutzt – kein Symlink, kein anderes basedir. */
    public function testTheUploadsMustBeTheFolderWordPressUses(): void
    {
        $GLOBALS['wpsync_test_upload_basedir'] = $this->root . '/anderswo';
        $this->assertError('wpsync_upload_layout', 409, $this->begin(['2026/10/a.png' => $this->png()], ['dry' => true]));
        unset($GLOBALS['wpsync_test_upload_basedir']);

        rename($this->live . '/uploads', $this->live . '/uploads-real');
        symlink($this->live . '/uploads-real', $this->live . '/uploads');
        $this->assertError('wpsync_upload_layout', 409, $this->begin(['2026/10/a.png' => $this->png()], ['dry' => true]));
    }

    /** AC-142: der Inhalt wird beim Upload geprüft – PHP als .png kommt nicht durch. */
    public function testContentThatDoesNotMatchItsTypeIsRefusedOnUpload(): void
    {
        $begin = $this->begin(['2026/10/bild.png' => '<?php echo 1;']);
        $this->assertInstanceOf(\WP_REST_Response::class, $begin);
        $id     = (string) $begin->data['push_id'];
        $result = Push::upload(['push_id' => $id, 'unit' => 0, 'files' => [['path' => '2026/10/bild.png', 'data' => base64_encode('<?php echo 1;'), 'offset' => 0]]], self::KEY);
        $this->assertError('wpsync_upload_type_blocked', 400, $result);
        $this->assertFileDoesNotExist($this->live . '/' . Store::pushDirName() . '/' . $id . '/stage/0/2026/10/bild.png');
        $this->assertError('wpsync_push_build', 409, Push::commit(['push_id' => $id], self::KEY));
        $this->assertFileDoesNotExist($this->upload('2026/10/bild.png'));
    }
}
