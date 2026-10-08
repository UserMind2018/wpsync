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

    /** Versteckte mittlere Endung: was WordPress' sanitize_file_name() umbenennen würde, geht nicht raus. */
    public function testHiddenMiddleExtensionsAreRefused(): void
    {
        foreach (['2026/10/bild.html.jpg', '2026/10/bild.shtml.jpg', '2026/10/bild.cgi.png', '2026/10/bild.pl.gif', '2026/10/foto.final.v2.jpg'] as $rel) {
            $this->assertError('wpsync_upload_type_blocked', 400, $this->begin([$rel => 'x'], ['dry' => true]));
        }
        foreach (['2026/10/bild-300x200.jpg', '2026/10/Foto_2026.JPG', '2026/10/foto.jpg.png', '2026/10/scan.2026.pdf'] as $rel) {
            $result = $this->begin([$rel => 'x'], ['dry' => true]);
            $this->assertInstanceOf(\WP_REST_Response::class, $result, $rel . ($result instanceof \WP_Error ? ' ' . $result->code : ''));
        }
    }

    /** Aktive Typen bleiben gesperrt, auch wenn ein Theme sie per upload_mimes erlaubt. */
    public function testActiveTypesStayBlockedEvenIfWordPressAllowsThem(): void
    {
        $GLOBALS['wpsync_test_extra_mimes'] = [
            'svg|svgz' => 'image/svg+xml', 'js|mjs' => 'text/javascript', 'xml' => 'application/xml', 'xhtml' => 'application/xhtml+xml',
        ];
        foreach (['x.svg', 'x.SVG', 'x.svgz', 'x.js', 'x.mjs', 'x.xml', 'x.xhtml'] as $name) {
            $this->assertError('wpsync_upload_type_blocked', 400, $this->begin(['2026/10/' . $name => 'x'], ['dry' => true]));
        }
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

    /** AC-140: neue Dateien liegen danach da, mit Ordnern, Rechten und Stempeln; gleiche bleiben unberührt. */
    public function testAPushAddsTheNewFilesOnly(): void
    {
        chmod($this->live . '/uploads/2026', 0750);
        file_put_contents($this->upload('2026/10/gleich.png'), $this->png());
        touch($this->upload('2026/10/gleich.png'), 1600000000);
        $files = ['2026/10/neu.png' => $this->png('neu'), '2026/11/tief/b.png' => $this->png('b'), '2026/10/gleich.png' => $this->png()];

        [$id, $commit] = $this->push($files);

        $this->assertInstanceOf(\WP_REST_Response::class, $commit, $commit instanceof \WP_Error ? $commit->code : '');
        $this->assertSame($this->png('neu'), file_get_contents($this->upload('2026/10/neu.png')));
        $this->assertSame($this->png('b'), file_get_contents($this->upload('2026/11/tief/b.png')));
        $this->assertSame(1600000000, filemtime($this->upload('2026/10/gleich.png')));
        $this->assertSame(['0750', '0750', '0640'], [$this->perms($this->upload('2026/11')), $this->perms($this->upload('2026/11/tief')), $this->perms($this->upload('2026/11/tief/b.png'))]);
        $stamps = (array) $commit->data['stamps']->uploads;
        $this->assertSame(['2026/10/neu.png', '2026/11/tief/b.png'], array_keys($stamps));
        $this->assertSame(['size' => strlen($this->png('neu')), 'mtime' => 1700000000], $stamps['2026/10/neu.png']);

        $record = PushRescue::read($this->live . '/' . Store::pushDirName(), $id);
        $this->assertSame([], $record['pairs']);
        $this->assertSame([
            'added' => [
                ['path' => '2026/10/neu.png', 'sha256' => hash('sha256', $this->png('neu'))],
                ['path' => '2026/11/tief/b.png', 'sha256' => hash('sha256', $this->png('b'))],
            ],
            'dirs'  => ['uploads/2026/11', 'uploads/2026/11/tief'],
        ], $record['uploads']);
        $this->assertSame([['path' => 'uploads', 'exists' => true, 'old_version' => '', 'new_version' => '', 'files' => 3, 'uploaded' => 2]], Store::getPush($id)['units']);
        $this->assertSame(PushRescue::COMMITTED, Store::getPush($id)['status']);
    }

    /** AC-141/AC-144: taucht die Datei vor dem Commit auf, bricht der Satz vor jedem Code-Tausch ab. */
    public function testAFileThatAppearsBeforeTheCommitStopsTheSetBeforeAnyCode(): void
    {
        $files = ['2026/10/neu.png' => $this->png('neu'), '2026/10/spaet.png' => $this->png('spaet')];
        $begin = $this->begin($files, [], 'new');
        $this->assertInstanceOf(\WP_REST_Response::class, $begin);
        $this->sendAll($begin, $files, 'new');
        file_put_contents($this->upload('2026/10/spaet.png'), 'fremd'); // ein Redakteur lädt sie inzwischen hoch

        $this->assertError('wpsync_upload_exists', 409, Push::commit(['push_id' => (string) $begin->data['push_id']], self::KEY));
        $this->assertSame('old', file_get_contents($this->live . '/plugins/x/main.php'));
        $this->assertFileDoesNotExist($this->upload('2026/10/neu.png'));
        $this->assertSame('fremd', file_get_contents($this->upload('2026/10/spaet.png')));
        $this->assertSame(Push::FAILED, Store::getPush((string) $begin->data['push_id'])['status']);
        $this->assertNull(Store::getState('push_lock'));
    }

    /** AC-144: scheitert der Code-Tausch nach den Uploads, nimmt der Agent die Uploads wieder weg. */
    public function testAFailedCodeSwapTakesTheUploadsBack(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root ignores file permissions');
        }
        $files = ['2026/12/neu.png' => $this->png('neu')];
        $begin = $this->begin($files, [], 'new');
        $this->assertInstanceOf(\WP_REST_Response::class, $begin);
        $this->sendAll($begin, $files, 'new');
        chmod($this->live . '/plugins', 0555); // plugins/x lässt sich nicht mehr beiseite legen

        $result = Push::commit(['push_id' => (string) $begin->data['push_id']], self::KEY);
        chmod($this->live . '/plugins', 0777);

        $this->assertError('wpsync_push_swap', 500, $result);
        $this->assertSame('old', file_get_contents($this->live . '/plugins/x/main.php'));
        $this->assertFileDoesNotExist($this->upload('2026/12/neu.png'));
        $this->assertDirectoryDoesNotExist($this->upload('2026/12'));
    }

    /** AC-143/AC-144: die Rücknahme nimmt Code und Uploads zurück – genau die hinzugefügten. */
    public function testRollbackTakesBackCodeAndUploads(): void
    {
        file_put_contents($this->upload('2026/10/gleich.png'), $this->png());
        [$id, $commit] = $this->push(['2026/12/neu.png' => $this->png('neu'), '2026/10/gleich.png' => $this->png()], 'new');
        $this->assertInstanceOf(\WP_REST_Response::class, $commit);
        $this->assertSame('new', file_get_contents($this->live . '/plugins/x/main.php'));
        $this->assertInstanceOf(\WP_REST_Response::class, Push::confirm(['push_id' => $id], self::KEY));

        $result = Push::rollbackPush($id);

        $this->assertInstanceOf(\WP_REST_Response::class, $result);
        $this->assertSame(['ok' => true, 'status' => PushRescue::ROLLED_BACK], $result->data);
        $this->assertSame('old', file_get_contents($this->live . '/plugins/x/main.php'));
        $this->assertFileDoesNotExist($this->upload('2026/12/neu.png'));
        $this->assertDirectoryDoesNotExist($this->upload('2026/12'));
        $this->assertFileExists($this->upload('2026/10/gleich.png'));
    }

    /** AC-143: eine seither geänderte Datei bleibt und wird gemeldet. */
    public function testAChangedUploadStaysAndIsReported(): void
    {
        [$id] = $this->push(['2026/10/a.png' => $this->png('a'), '2026/10/b.png' => $this->png('b')]);
        file_put_contents($this->upload('2026/10/b.png'), 'vom Redakteur ersetzt');

        $result = Push::rollbackPush($id);

        $this->assertInstanceOf(\WP_REST_Response::class, $result);
        $this->assertSame(['ok' => true, 'status' => PushRescue::ROLLED_BACK, 'warnings' => [PushRescue::UPLOAD_CHANGED], 'kept' => ['2026/10/b.png']], $result->data);
        $this->assertFileDoesNotExist($this->upload('2026/10/a.png'));
        $this->assertSame('vom Redakteur ersetzt', file_get_contents($this->upload('2026/10/b.png')));
    }

    /** Ziel Staging: die Kopie hat noch kein uploads/ – der Push legt es an, die Rücknahme räumt es weg. */
    public function testAStagingPushAddsToTheCopyOnly(): void
    {
        [$id, $commit] = $this->push(['2026/10/neu.png' => $this->png('neu')], null, 'staging');

        $this->assertInstanceOf(\WP_REST_Response::class, $commit, $commit instanceof \WP_Error ? $commit->code : '');
        $this->assertSame($this->png('neu'), file_get_contents($this->staging . '/uploads/2026/10/neu.png'));
        $this->assertFileDoesNotExist($this->upload('2026/10/neu.png'));
        $record = PushRescue::read($this->staging . '/' . Store::pushDirName(), $id);
        $this->assertSame(['uploads', 'uploads/2026', 'uploads/2026/10'], $record['uploads']['dirs']);

        $this->assertInstanceOf(\WP_REST_Response::class, Push::rollbackPush($id));
        $this->assertDirectoryDoesNotExist($this->staging . '/uploads');
    }
}
