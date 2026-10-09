<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use WpSync\ContentOrigin;
use WpSync\ContentTarget;
use WpSync\Push;
use WpSync\PushContent;
use WpSync\Staging;
use WpSync\Store;

/**
 * Inhalte im echten Push-Ablauf (Spec Content-Push §7; AC-150, AC-151, AC-153, AC-154, AC-157):
 * Push läuft auf einem temporären Webroot; WordPress, Store und Staging sind Attrappen
 * (PushHarness.php), die Tabellen des Ziels ein Store im Speicher.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PushContentFlowTest extends TestCase
{
    private const KEY = '0123456789abcdef';
    /** 1×1-PNG – getimagesize() erkennt es als Bild. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private string $root;
    private string $live;
    private string $staging;
    private ContentMemory $liveDb;
    private ContentMemory $stagingDb;
    /** @var list<string> Ziele, die PushContent aufgelöst hat */
    private array $resolved = [];
    /** Salt des letzten echten Begin – daraus leitet die CLI den Schlüssel für rescue.php ab. */
    private string $salt = '';

    protected function setUp(): void
    {
        require_once __DIR__ . '/PushHarness.php';
        require_once __DIR__ . '/PostActionsHarness.php';
        require_once __DIR__ . '/ContentFixtures.php';
        require_once __DIR__ . '/ContentMemory.php';

        $this->root    = (string) realpath(sys_get_temp_dir()) . '/wpsync-contentflow-' . bin2hex(random_bytes(4));
        $this->live    = $this->root . '/wp-content';
        $this->staging = $this->root . '/' . Staging::DIR . '/wp-content';
        foreach ([$this->live . '/plugins/wpsync-agent', $this->live . '/plugins/x', $this->live . '/uploads/2026/10', $this->staging . '/plugins/x'] as $dir) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($this->live . '/plugins/x/main.php', 'old');
        file_put_contents($this->staging . '/plugins/x/main.php', 'stg-old');

        define('WP_CONTENT_DIR', $this->live);
        $GLOBALS['wpdb']                       = new \WpsyncHarnessDb();
        $GLOBALS['wpsync_test_permalink_base'] = ContentFixtures::HOME;
        Staging::$root   = $this->root;
        Store::$until    = time() + 3600;
        Store::$opener   = 7;
        Push::register($this->live . '/plugins/wpsync-agent');

        $this->liveDb    = new ContentMemory($this->site(''));
        $this->stagingDb = new ContentMemory($this->site('/' . ContentFixtures::STAGING_DIR));
        PushContent::$resolve = function (string $name, string $content): ContentTarget {
            $this->resolved[] = $name;
            return $name === 'staging'
                ? ContentFixtures::staging($this->stagingDb, $content . '/uploads')
                : ContentFixtures::live($this->liveDb, $content . '/uploads');
        };
    }

    protected function tearDown(): void
    {
        exec('chmod -R u+w ' . escapeshellarg($this->root) . ' 2>/dev/null; rm -rf ' . escapeshellarg($this->root));
    }

    /**
     * Die Inhalte einer Site; $path ist der Pfad, den die Staging-Kopie hinter dem Host trägt.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function site(string $path): array
    {
        return [
            'posts' => [
                '219' => ContentFixtures::post('219', ['post_content' => '<a href="' . ContentFixtures::HOME . $path . '/kontakt">Kontakt</a>']),
                '220' => ContentFixtures::post('220', ['post_status' => 'draft', 'post_content' => '']),
            ],
            'postmeta' => ["219\0_elementor_data" => ['values' => ['[{"url":"https:\/\/kunde.de' . str_replace('/', '\/', $path) . '\/x"}]']]],
            'options'  => [
                'blogname'   => ContentFixtures::option('blogname', 'Kunde'),
                'stylesheet' => ContentFixtures::option('stylesheet', 'hello-child'),
            ],
        ];
    }

    /** Abdruck des Manifests – auf Live gerechnet; auf der Kopie gilt derselbe (W1). */
    private function h(string $table, string $key): string
    {
        return ContentFixtures::hash($table, $key, $this->site('')[$table][$key] ?? null);
    }

    /** @return list<array<string, mixed>> ein Satz mit update, Meta, trash, neuer Seite und Option */
    private function rows(): array
    {
        return [
            ContentFixtures::row('update', 'posts', '219', $this->h('posts', '219'), ContentFixtures::postRow('219', ['post_title' => 'Neu', 'post_content' => 'Link: ' . ContentOrigin::PLAIN . '/neu'])),
            ContentFixtures::row('update', 'postmeta', "219\0_elementor_data", $this->h('postmeta', "219\0_elementor_data"), ['values' => ['[{"url":"' . ContentOrigin::ESC1 . '\/neu"}]']]),
            ContentFixtures::row('trash', 'posts', '220', $this->h('posts', '220')),
            ContentFixtures::row('insert', 'posts', '1000001', 'absent', ContentFixtures::postRow('1000001', ['post_title' => 'Ganz neu'])),
            ContentFixtures::row('update', 'options', 'blogname', $this->h('options', 'blogname'), ['option_value' => 'Kunde GmbH']),
        ];
    }

    /**
     * Legt ein Paket über /content/stage ab (in zwei Stücken) und liefert seine sha256.
     *
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed>       $head
     */
    private function stage(array $rows, array $head = []): string
    {
        $text = ContentFixtures::text($rows, $head);
        $sha  = hash('sha256', $text);
        $half = intdiv(strlen($text), 2);
        foreach ([[0, substr($text, 0, $half)], [$half, substr($text, $half)]] as $piece) {
            $result = Push::stage(['sha256' => $sha, 'size' => strlen($text), 'offset' => $piece[0], 'data' => base64_encode($piece[1])], self::KEY);
            $this->assertInstanceOf(\WP_REST_Response::class, $result, $result instanceof \WP_Error ? $result->code : '');
        }
        return $sha;
    }

    /** @return array{size: int, sha256: string, mtime: int} */
    private function entry(string $content): array
    {
        return ['size' => strlen($content), 'sha256' => hash('sha256', $content), 'mtime' => 1700000000];
    }

    /**
     * @param array<string, mixed>  $extra   target, dry, units …
     * @param array<string, string> $uploads Pfad relativ zu uploads/ → Inhalt
     * @return \WP_REST_Response|\WP_Error
     */
    private function begin(?string $sha, array $extra = [], ?string $code = null, array $uploads = [])
    {
        $units = [];
        if ($code !== null) {
            $units[] = ['path' => 'plugins/x', 'files' => ['main.php' => $this->entry($code)], 'base' => []];
        }
        if ($uploads !== []) {
            $units[] = ['path' => 'uploads', 'files' => array_map([$this, 'entry'], $uploads), 'base' => []];
        }
        $params = $extra + ['force' => true, 'units' => $units];
        if ($sha !== null) {
            $params['content'] = ['sha256' => $sha];
        }
        return Push::begin($params, self::KEY);
    }

    /** @param mixed $result */
    private function assertRefused(string $code, int $status, $result): \WP_Error
    {
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame([$code, $status], [$result->code, $result->status], $result->message);
        return $result;
    }

    private function work(string $content): string
    {
        return $content . '/' . Store::pushDirName();
    }

    /** Der Probelauf prüft das ganze Paket – ohne Push-Fenster, ohne Einheiten, ohne etwas anzulegen. */
    public function testDryRunChecksThePackageWithoutAWindow(): void
    {
        Store::$until  = 0;
        Store::$opener = null;
        $sha           = $this->stage($this->rows());
        $dry           = $this->begin($sha, ['dry' => true]);
        $this->assertInstanceOf(\WP_REST_Response::class, $dry, $dry instanceof \WP_Error ? $dry->code : '');
        $content = $dry->data['content'];
        $this->assertTrue($content['ok']);
        $this->assertNull($content['error']);
        $this->assertSame(['posts' => 3, 'postmeta' => 1, 'options' => 1], (array) $content['rows']);
        $this->assertSame(['max_rows' => 5000, 'max_bytes' => 8388608], array_slice($content['limits'], 0, 2));
        $this->assertGreaterThanOrEqual(2, $content['limits']['budget_seconds']);
        $this->assertSame([], $content['conflicts']);
        $this->assertSame([ContentFixtures::HOME . '/?p=219'], $content['health_urls'], 'nur veröffentlichte Seiten, die es schon gibt');
        $this->assertSame([], $dry->data['units']);
        $this->assertFalse($dry->data['window_open']);
        $this->assertSame([], Store::$pushes);
        $this->assertSame([], $this->liveDb->log, 'der Probelauf schreibt nichts');
        $this->assertSame(['live'], array_values(array_unique($this->resolved)));
    }

    public function testWithoutContentNothingChanges(): void
    {
        $dry = $this->begin(null, ['dry' => true], 'new');
        $this->assertArrayNotHasKey('content', $dry->data);
        $this->assertRefused('wpsync_push_units', 400, Push::begin(['dry' => true, 'units' => []], self::KEY));
        $this->assertRefused('wpsync_push_content', 400, Push::begin(['dry' => true, 'content' => ['sha256' => 'kurz']], self::KEY));
        $this->assertRefused('wpsync_push_content', 400, Push::begin(['dry' => true, 'content' => null], self::KEY));
    }

    /** Eine Ablehnung steht im Probelauf in der Antwort – mit allen Schlüsseln (AC-151). */
    public function testDryRunReportsARefusalInBand(): void
    {
        $sha = $this->stage($this->rows());
        $this->liveDb->data['posts']['219']['post_title']     = 'auf Live geändert';
        $this->liveDb->data['options']['blogname']['option_value'] = 'auf Live geändert';
        $content = $this->begin($sha, ['dry' => true])->data['content'];
        $this->assertFalse($content['ok']);
        $this->assertSame('conflict', $content['error']['code']);
        $keys = [['table' => 'posts', 'key' => '219'], ['table' => 'options', 'key' => 'blogname']];
        $this->assertSame($keys, $content['error']['keys']);
        $this->assertSame($keys, $content['conflicts']);
        $this->assertSame(['posts' => 3, 'postmeta' => 1, 'options' => 1], (array) $content['rows']);
        $this->assertSame([], $content['health_urls']);
    }

    public function testAPackageThatWasNeverStaged(): void
    {
        $content = $this->begin(str_repeat('a', 64), ['dry' => true])->data['content'];
        $this->assertSame('package_missing', $content['error']['code']);
        $this->assertRefused('wpsync_content_package_missing', 409, $this->begin(str_repeat('a', 64)));
    }

    /** Der echte Begin lehnt ab, was der Probelauf nennt – mit denselben Einzelheiten in den Fehlerdaten. */
    public function testRealBeginRefusesWithTheSameDetails(): void
    {
        $sha = $this->stage($this->rows());
        $this->liveDb->data['posts']['219']['post_title'] = 'auf Live geändert';
        $error = $this->assertRefused('wpsync_content_conflict', 409, $this->begin($sha, [], 'new'));
        $this->assertSame([['table' => 'posts', 'key' => '219']], $error->data['keys']);
        $this->assertSame(1, $error->data['total']);
        $this->assertSame([], Store::$pushes);
        $this->assertNull(Store::getState('push_lock'));
        $this->assertSame('old', file_get_contents($this->live . '/plugins/x/main.php'));

        Store::$until = 0; // ohne Fenster erfährt der echte Begin nichts über das Paket
        $this->assertRefused('wpsync_push_window', 403, $this->begin($sha));
    }

    /**
     * Härtung S3: der Inhaltskanal ist ein Administrator-Kanal und hat genau die Autorisierung des
     * Code-Pushs – ohne offenes Fenster dieses Pairings weder Begin noch Commit, auch nicht für
     * einen Satz nur aus Inhalten.
     */
    public function testContentNeedsTheSameWindowAsCode(): void
    {
        $sha   = $this->stage($this->rows());
        $begin = $this->begin($sha);
        $id    = (string) $begin->data['push_id'];
        Store::$until = 0;
        $this->assertRefused('wpsync_push_window', 403, Push::commit(['push_id' => $id], self::KEY));
        $this->assertSame([], $this->liveDb->log);
        $this->assertSame('expired', Store::getPush($id)['status']);
        $this->assertRefused('wpsync_push_window', 403, $this->begin($sha));
        Store::$until = time() + 3600;
        $this->assertRefused('wpsync_push_unknown', 404, Push::commit(['push_id' => $id], 'fedcba9876543210'));
        $this->assertSame([], $this->liveDb->log, 'ein anderes Pairing wendet den Push nicht an');
    }

    /** §9: ohne Öffner des Fensters kein neuer Beitrag – aber erst der echte Begin sagt das. */
    public function testAuthorUnknownOnlyAtTheRealBegin(): void
    {
        Store::$opener = null;
        $sha           = $this->stage($this->rows());
        $this->assertTrue($this->begin($sha, ['dry' => true])->data['content']['ok']);
        $this->assertRefused('wpsync_content_author_unknown', 409, $this->begin($sha));
        $this->assertSame([], Store::$pushes);

        $only = $this->stage([ContentFixtures::row('update', 'options', 'blogname', $this->h('options', 'blogname'), ['option_value' => 'Neu'])]);
        $this->assertInstanceOf(\WP_REST_Response::class, $this->begin($only), 'ohne neue Beiträge braucht es keinen Autor');
    }

    /** Ein Satz nur aus Inhalten: der Push entsteht ohne Einheit, das geprüfte Paket liegt in seinem Arbeitsordner. */
    public function testRealBeginCreatesAPushWithOnlyContent(): void
    {
        $sha   = $this->stage($this->rows());
        $begin = $this->begin($sha);
        $this->assertInstanceOf(\WP_REST_Response::class, $begin, $begin instanceof \WP_Error ? $begin->code . ' ' . $begin->message : '');
        $id   = (string) $begin->data['push_id'];
        $base = $this->work($this->live) . '/' . $id;
        $this->assertSame($sha, hash_file('sha256', $base . '/content/package.jsonl'));
        $this->assertSame('0600', substr(sprintf('%o', fileperms($base . '/content/package.jsonl')), -4), 'N2: nur für den Besitzer lesbar');
        $this->assertSame('0700', substr(sprintf('%o', fileperms($base . '/content')), -4));
        $this->assertSame('0600', substr(sprintf('%o', fileperms($this->work($this->live) . '/packages/' . self::KEY . '/' . $sha . '.jsonl')), -4), 'auch die Ablage');
        $plan = json_decode((string) file_get_contents($base . '/plan.json'), true);
        $this->assertSame(['sha256' => $sha, 'rows' => 5], $plan['content']);
        $this->assertSame([], $plan['units']);
        $push = Store::getPush($id);
        $this->assertSame('uploading', $push['status']);
        $this->assertSame(7, $push['opened_by']);
        $this->assertSame([['path' => 'content', 'exists' => true, 'old_version' => '', 'new_version' => '', 'files' => 5, 'uploaded' => 5]], $push['units']);
        $this->assertTrue($begin->data['content']['ok']);
        $this->assertSame([], $this->liveDb->log, 'auch der echte Begin schreibt noch keine Inhalte');
    }

    /**
     * M4 (d): die Projekt-Erweiterungen, mit denen ein Paket gebaut ist, stehen im Probelauf, im Plan
     * und in der Einheit content des Push-Datensatzes – und bleiben dort über den Commit.
     */
    public function testTheExtensionsOfAPackageAreVisibleInThePushRecord(): void
    {
        $ext = ['post_types' => ['referenz'], 'taxonomies' => ['branche'], 'meta_exceptions' => ['design_token']];
        $sha = $this->stage($this->rows(), ['extensions' => $ext]);
        $dry = $this->begin($sha, ['dry' => true]);
        $this->assertSame($ext, $dry->data['content']['extensions']);
        $begin = $this->begin($sha);
        $this->assertInstanceOf(\WP_REST_Response::class, $begin, $begin instanceof \WP_Error ? $begin->code . ' ' . $begin->message : '');
        $id   = (string) $begin->data['push_id'];
        $plan = json_decode((string) file_get_contents($this->work($this->live) . '/' . $id . '/plan.json'), true);
        $this->assertSame($ext, $plan['content']['extensions']);
        $this->assertSame($ext, Store::getPush($id)['units'][0]['extensions']);
        $commit = Push::commit(['push_id' => $id], self::KEY);
        $this->assertInstanceOf(\WP_REST_Response::class, $commit, $commit instanceof \WP_Error ? $commit->code . ' ' . $commit->message : '');
        $unit = Store::getPush($id)['units'][0];
        $this->assertSame('content', $unit['path']);
        $this->assertSame($ext, $unit['extensions']);
        $listed = Push::index()->data['pushes'][0];
        $this->assertSame($ext, $listed['units'][0]['extensions'], 'so steht es in wpsync pushes --json');

        // Ohne Erweiterungen bleibt die Einheit, wie sie war.
        $this->assertArrayNotHasKey('extensions', $this->begin($this->stage([$this->rows()[4]]), ['dry' => true])->data['content']);
    }

    /** §7.8: nach Staging prüft der Begin gegen die Kopie, und das Paket liegt im Arbeitsordner der Kopie. */
    public function testBeginForTheStagingCopy(): void
    {
        $sha   = $this->stage($this->rows());
        $begin = $this->begin($sha, ['target' => 'staging'], 'new');
        $this->assertInstanceOf(\WP_REST_Response::class, $begin, $begin instanceof \WP_Error ? $begin->code . ' ' . $begin->message : '');
        $this->assertTrue($begin->data['content']['ok'], 'dieselben Abdrücke gelten auf der Kopie');
        $this->assertSame(['staging'], array_values(array_unique($this->resolved)));
        $this->assertSame([ContentFixtures::HOME . '/' . ContentFixtures::STAGING_DIR . '/?p=219'], $begin->data['content']['health_urls']);
        $id = (string) $begin->data['push_id'];
        $this->assertFileExists($this->work($this->staging) . '/' . $id . '/content/package.jsonl');
        $this->assertFileExists($this->work($this->live) . '/packages/' . self::KEY . '/' . $sha . '.jsonl', 'die Ablage bleibt für den Push nach Live');
    }

    /**
     * N3: der Probelauf braucht kein Push-Fenster – wer nur das Secret hat, erfährt aus ihm aber
     * nicht, welche Objekte und Dateien es auf der Site gibt. Die Antwort sagt, dass sie Teil ist.
     */
    public function testADryRunWithoutAWindowIsPartial(): void
    {
        $missing = $this->stage([ContentFixtures::row('insert', 'postmeta', "999\0_x", 'absent', ['values' => ['x']])]);
        $file    = $this->stage([
            ContentFixtures::row('insert', 'posts', '1000002', 'absent', ContentFixtures::postRow('1000002', ['post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => 'image/png'])),
            ContentFixtures::row('insert', 'postmeta', "1000002\0_wp_attached_file", 'absent', ['values' => ['2026/10/bild.png']]),
        ]);
        $ok = $this->stage($this->rows());

        $open = $this->begin($missing, ['dry' => true])->data;
        $this->assertTrue($open['window_open']);
        $this->assertFalse($open['content']['partial']);
        $this->assertSame('dangling_reference', $open['content']['error']['code']);
        $this->assertSame('upload_missing', $this->begin($file, ['dry' => true])->data['content']['error']['code']);

        Store::$until = 0;
        $closed       = $this->begin($missing, ['dry' => true])->data;
        $this->assertFalse($closed['window_open']);
        $this->assertTrue($closed['content']['partial']);
        $this->assertSame('blocked_row', $closed['content']['error']['code']);
        $this->assertSame([['table' => 'postmeta', 'key' => "999\0_x"]], $closed['content']['error']['keys']);
        $files = $this->begin($file, ['dry' => true])->data['content'];
        $this->assertTrue($files['ok'], 'Dateien werden ohne Fenster nicht geprüft');
        $this->assertTrue($files['partial']);
        $this->assertNull($files['error']);
        $whole = $this->begin($ok, ['dry' => true])->data['content'];
        $this->assertTrue($whole['ok']);
        $this->assertTrue($whole['partial'], 'auch ein Paket ohne Befund ist ohne Fenster nur teilweise geprüft');
        $this->assertSame([], Store::$pushes);
    }

    /** Nr. 9: die Dateien eines neuen Attachments dürfen mit der Einheit uploads desselben Satzes kommen. */
    public function testAttachmentFilesMayComeWithTheSameSet(): void
    {
        $png = (string) base64_decode(self::PNG);
        $sha = $this->stage([
            ContentFixtures::row('insert', 'posts', '1000002', 'absent', ContentFixtures::postRow('1000002', ['post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => 'image/png'])),
            ContentFixtures::row('insert', 'postmeta', "1000002\0_wp_attached_file", 'absent', ['values' => ['2026/10/bild.png']]),
        ]);
        $alone = $this->begin($sha, ['dry' => true])->data['content'];
        $this->assertSame('upload_missing', $alone['error']['code']);
        $this->assertSame(['2026/10/bild.png'], $alone['error']['paths']);
        $with = $this->begin($sha, ['dry' => true], null, ['2026/10/bild.png' => $png]);
        $this->assertTrue($with->data['content']['ok']);
        $this->assertSame(['2026/10/bild.png'], $with->data['units'][0]['need']);
    }

    /**
     * Begin, Upload der Einheiten, Commit; liefert ID und Antwort des Commits.
     *
     * @param array<string, string> $uploads
     * @return array{0: string, 1: \WP_REST_Response|\WP_Error}
     */
    private function push(?string $sha, ?string $code = null, array $uploads = [], string $target = 'live'): array
    {
        $begin = $this->begin($sha, ['target' => $target], $code, $uploads);
        $this->assertInstanceOf(\WP_REST_Response::class, $begin, $begin instanceof \WP_Error ? $begin->code . ' ' . $begin->message : '');
        $id         = (string) $begin->data['push_id'];
        $this->salt = (string) $begin->data['rescue']['salt'];
        $u          = 0;
        if ($code !== null) {
            $this->assertInstanceOf(\WP_REST_Response::class, Push::upload(['push_id' => $id, 'unit' => $u++, 'files' => [['path' => 'main.php', 'data' => base64_encode($code), 'offset' => 0]]], self::KEY));
        }
        $chunks = [];
        foreach ($uploads === [] ? [] : $begin->data['units'][$u]['need'] as $rel) {
            $chunks[] = ['path' => $rel, 'data' => base64_encode($uploads[$rel]), 'offset' => 0];
        }
        if ($chunks !== []) {
            $up = Push::upload(['push_id' => $id, 'unit' => $u, 'files' => $chunks], self::KEY);
            $this->assertInstanceOf(\WP_REST_Response::class, $up, $up instanceof \WP_Error ? $up->code : '');
        }
        return [$id, Push::commit(['push_id' => $id], self::KEY)];
    }

    /** @return array<string, mixed> rescue.json des Pushs */
    private function rescue(string $content, string $id): array
    {
        return (array) json_decode((string) file_get_contents($this->work($content) . '/' . $id . '/rescue.json'), true);
    }

    /** §7.3, AC-150: Uploads → Code → DB in einem Commit; die Antwort nennt die Abdrücke danach und die Nacharbeiten. */
    public function testCommitAppliesTheContentLast(): void
    {
        $png   = (string) base64_decode(self::PNG);
        $seen  = null;
        $this->liveDb->beforeLock = function () use (&$seen): void {
            $seen = [file_get_contents($this->live . '/plugins/x/main.php'), file_exists($this->live . '/uploads/2026/10/neu.png')];
        };
        list($id, $commit) = $this->push($this->stage($this->rows()), 'new', ['2026/10/neu.png' => $png]);
        $this->assertInstanceOf(\WP_REST_Response::class, $commit, $commit instanceof \WP_Error ? $commit->code . ' ' . $commit->message : '');
        $this->assertSame(['new', true], $seen, 'als die Transaktion begann, lagen Code und Uploads schon');

        $this->assertSame('Neu', $this->liveDb->data['posts']['219']['post_title']);
        $this->assertSame('Link: https://kunde.de/neu', $this->liveDb->data['posts']['219']['post_content']);
        $this->assertSame('trash', $this->liveDb->data['posts']['220']['post_status']);
        $this->assertSame('7', $this->liveDb->data['posts']['1000001']['post_author'], 'Autor ist der Öffner des Fensters');
        $this->assertSame('Kunde GmbH', $this->liveDb->data['options']['blogname']['option_value']);

        $content = $commit->data['content'];
        $this->assertSame(5, $content['rows']);
        $this->assertCount(8, $content['after'], '5 Zeilen und die drei Meta des Papierkorbs');
        $this->assertSame(['t' => 'posts', 'k' => '219', 'h' => ContentFixtures::hash('posts', '219', $this->liveDb->data['posts']['219'])], $content['after'][0]);
        $this->assertSame(['object_cache', 'rewrite_rules', 'revisions'], array_column($content['post_actions'], 'step'));
        $this->assertContains('clean_post_cache [219]', $GLOBALS['wpsync_post_actions']);
        $this->assertContains('wp_save_post_revision [219]', $GLOBALS['wpsync_post_actions']);
        $this->assertIsFloat($content['seconds']);
        $this->assertSame(['plugins/x', 'uploads'], array_keys((array) $commit->data['stamps']));

        $push = Store::getPush($id);
        $this->assertSame('committed', $push['status']);
        $this->assertSame(['plugins/x', 'uploads', 'content'], array_column($push['units'], 'path'));
        $rescue = $this->rescue($this->live, $id);
        $this->assertSame(['state' => 'applied', 'sha256' => hash_file('sha256', $this->work($this->live) . '/' . $id . '/content/package.jsonl')], $rescue['content']);
        $this->assertFileExists($this->work($this->live) . '/' . $id . '/content/before.json');
        $this->assertFileExists($this->work($this->live) . '/' . $id . '/content/after.json');
    }

    /** Ein Satz nur aus Inhalten (--no-code): kein Tausch, keine Stempel, die Datenbank hat den Stand. */
    public function testCommitOfContentOnly(): void
    {
        list($id, $commit) = $this->push($this->stage($this->rows()));
        $this->assertInstanceOf(\WP_REST_Response::class, $commit, $commit instanceof \WP_Error ? $commit->code . ' ' . $commit->message : '');
        $this->assertSame([], (array) $commit->data['stamps']);
        $this->assertSame('Neu', $this->liveDb->data['posts']['219']['post_title']);
        $this->assertSame('old', file_get_contents($this->live . '/plugins/x/main.php'));
        $this->assertSame([], $this->rescue($this->live, $id)['pairs']);
        $this->assertSame('committed', Store::getPush($id)['status']);
    }

    /** AC-150: scheitert das Anwenden, bleibt keine Zeile, und Code und Uploads sind zurückgetauscht. */
    public function testAFailingContentTakesCodeAndUploadsBack(): void
    {
        $old                     = $this->liveDb->data;
        $this->liveDb->failWrite = 3;
        list($id, $commit)       = $this->push($this->stage($this->rows()), 'new', ['2026/10/neu.png' => (string) base64_decode(self::PNG)]);
        $error                   = $this->assertRefused('wpsync_content_content_failed', 500, $commit);
        $this->assertSame($old, $this->liveDb->data);
        $this->assertSame('old', file_get_contents($this->live . '/plugins/x/main.php'));
        $this->assertFileDoesNotExist($this->live . '/uploads/2026/10/neu.png');
        $push = Store::getPush($id);
        $this->assertSame(['failed', true], [$push['status'], $push['pruned']]);
        $this->assertNull(Store::getState('push_lock'));
        $this->assertDirectoryDoesNotExist($this->work($this->live) . '/' . $id);
        $this->assertSame([], $GLOBALS['wpsync_post_actions'], 'ohne Änderung keine Nacharbeiten');
        $this->assertArrayNotHasKey('keys', $error->data);
    }

    /** AC-151: ändert sich eine Zeile zwischen Begin und Commit, fällt das unter Sperre auf – nichts bleibt getauscht. */
    public function testConflictUnderLockAtCommit(): void
    {
        $sha   = $this->stage($this->rows());
        $begin = $this->begin($sha, [], 'new');
        $id    = (string) $begin->data['push_id'];
        Push::upload(['push_id' => $id, 'unit' => 0, 'files' => [['path' => 'main.php', 'data' => base64_encode('new'), 'offset' => 0]]], self::KEY);
        $this->liveDb->data['posts']['219']['post_title'] = 'zwischen Begin und Commit geändert';
        $changed = $this->liveDb->data;

        $error = $this->assertRefused('wpsync_content_conflict', 409, Push::commit(['push_id' => $id], self::KEY));
        $this->assertSame([['table' => 'posts', 'key' => '219']], $error->data['keys']);
        $this->assertSame($changed, $this->liveDb->data);
        $this->assertSame('old', file_get_contents($this->live . '/plugins/x/main.php'));
        $this->assertSame('failed', Store::getPush($id)['status']);
    }

    /** Der Commit wendet nur das Paket an, das der Begin geprüft hat. */
    public function testCommitRefusesAChangedPackage(): void
    {
        $sha   = $this->stage($this->rows());
        $begin = $this->begin($sha, [], 'new');
        $id    = (string) $begin->data['push_id'];
        Push::upload(['push_id' => $id, 'unit' => 0, 'files' => [['path' => 'main.php', 'data' => base64_encode('new'), 'offset' => 0]]], self::KEY);
        $evil = ContentFixtures::text([ContentFixtures::row('update', 'options', 'blogname', $this->h('options', 'blogname'), ['option_value' => 'untergeschoben'])]);
        file_put_contents($this->work($this->live) . '/' . $id . '/content/package.jsonl', $evil);

        $this->assertRefused('wpsync_content_package_missing', 409, Push::commit(['push_id' => $id], self::KEY));
        $this->assertSame('Kunde', $this->liveDb->data['options']['blogname']['option_value']);
        $this->assertSame('old', file_get_contents($this->live . '/plugins/x/main.php'), 'nichts getauscht');
        $this->assertSame('failed', Store::getPush($id)['status']);
    }

    /** §7.8: der Push nach Staging schreibt nur in die Tabellen der Kopie – mit den Adressen der Kopie. */
    public function testCommitIntoTheStagingCopy(): void
    {
        $live              = $this->liveDb->data;
        list($id, $commit) = $this->push($this->stage($this->rows()), 'new', [], 'staging');
        $this->assertInstanceOf(\WP_REST_Response::class, $commit, $commit instanceof \WP_Error ? $commit->code . ' ' . $commit->message : '');
        $path = '/' . ContentFixtures::STAGING_DIR;
        $this->assertSame('Link: https://kunde.de' . $path . '/neu', $this->stagingDb->data['posts']['219']['post_content']);
        $this->assertSame(['[{"url":"https:\/\/kunde.de\\' . $path . '\/neu"}]'], $this->stagingDb->data['postmeta']["219\0_elementor_data"]['values']);
        $this->assertSame($live, $this->liveDb->data, 'Live bleibt unberührt');
        $this->assertSame([], $this->liveDb->log);
        $this->assertSame('new', file_get_contents($this->staging . '/plugins/x/main.php'));
        $this->assertSame('old', file_get_contents($this->live . '/plugins/x/main.php'));
        $this->assertFileExists($this->work($this->staging) . '/' . $id . '/content/before.json');
        $this->assertSame([], $GLOBALS['wpsync_post_actions'], 'keine WordPress-Funktion von Live für die Kopie');
        $this->assertSame(['rewrite_rules'], array_column($commit->data['content']['post_actions'], 'step'));
        $this->assertSame(1, Staging::$used);
        // Dieselben Abdrücke wie später auf Live (AC-147)
        $this->assertSame(
            \WpSync\ContentState::desired('posts', '219', ContentFixtures::postRow('219', ['post_title' => 'Neu', 'post_content' => 'Link: ' . ContentOrigin::PLAIN . '/neu'])),
            $commit->data['content']['after'][0]['h']
        );
    }

    /** rescue.php, wie die CLI es ohne WordPress aufruft. */
    private function rescuePhp(string $id): array
    {
        $key = \WpSync\PushRescue::key((string) Store::secretFor(self::KEY), $id, $this->salt);
        return \WpSync\PushRescue::handle(\WpSync\PushRescue::contentDirs($this->live), ['action' => 'rollback', 'push_id' => $id, 'key' => $key], time());
    }

    /** §7.6, AC-153: DB → Code → Uploads; die Antwort nennt die Nacharbeiten der Rücknahme. */
    public function testRollbackTakesContentBackFirst(): void
    {
        $old               = $this->liveDb->data;
        list($id, $commit) = $this->push($this->stage($this->rows()), 'new', ['2026/10/neu.png' => (string) base64_decode(self::PNG)]);
        $this->assertInstanceOf(\WP_REST_Response::class, $commit);
        Push::confirm(['push_id' => $id], self::KEY);
        $GLOBALS['wpsync_post_actions'] = [];
        $seen                           = null;
        $this->liveDb->beforeLock       = function () use (&$seen): void {
            $seen = file_get_contents($this->live . '/plugins/x/main.php');
        };

        $back = Push::rollback(['push_id' => $id], self::KEY);
        $this->assertInstanceOf(\WP_REST_Response::class, $back, $back instanceof \WP_Error ? $back->code . ' ' . $back->message : '');
        $this->assertSame('new', $seen, 'als die Inhalte zurückgingen, lag der neue Code noch');
        $this->assertSame(['ok', 'status', 'post_actions'], array_keys($back->data));
        $this->assertSame(['object_cache', 'rewrite_rules', 'revisions'], array_column($back->data['post_actions'], 'step'));
        $this->assertContains('clean_post_cache [1000001]', $GLOBALS['wpsync_post_actions']);
        ksort($old['postmeta']);
        ksort($this->liveDb->data['postmeta']);
        $this->assertSame($old, $this->liveDb->data);
        $this->assertSame('old', file_get_contents($this->live . '/plugins/x/main.php'));
        $this->assertFileDoesNotExist($this->live . '/uploads/2026/10/neu.png');
        $this->assertSame(['rolled_back', true], [Store::getPush($id)['status'], Store::getPush($id)['pruned']]);
        $this->assertDirectoryDoesNotExist($this->work($this->live) . '/' . $id, 'das Vorher-Abbild geht mit dem Snapshot');
    }

    /** AC-153: nach einer Änderung seit dem Push wird nichts zurückgenommen – auch Code und Uploads nicht. */
    public function testChangedSincePushKeepsTheWholeSet(): void
    {
        list($id) = $this->push($this->stage($this->rows()), 'new', ['2026/10/neu.png' => (string) base64_decode(self::PNG)]);
        $this->liveDb->data['posts']['219']['post_title'] = 'nach dem Push im WP-Admin geändert';
        $pushed = $this->liveDb->data;

        $error = $this->assertRefused('wpsync_content_changed_since_push', 409, Push::rollback(['push_id' => $id], self::KEY));
        $this->assertSame([['table' => 'posts', 'key' => '219']], $error->data['keys']);
        $this->assertSame($pushed, $this->liveDb->data);
        $this->assertSame('new', file_get_contents($this->live . '/plugins/x/main.php'));
        $this->assertFileExists($this->live . '/uploads/2026/10/neu.png');
        $this->assertSame('committed', Store::getPush($id)['status']);
        $this->assertSame('applied', $this->rescue($this->live, $id)['content']['state']);

        // Stellt jemand die Zeile wieder auf den gepushten Stand, geht die Rücknahme.
        $this->liveDb->data['posts']['219']['post_title'] = 'Neu';
        $this->assertInstanceOf(\WP_REST_Response::class, Push::rollback(['push_id' => $id], self::KEY));
        $this->assertSame('old', file_get_contents($this->live . '/plugins/x/main.php'));
    }

    /**
     * AC-157, C7: antwortet WordPress nicht, nimmt rescue.php Code und Uploads zurück und nennt die
     * Inhalte. Der Push bleibt offen, bis der Agent die Inhalte nachholt.
     */
    public function testRescueLeavesTheContentAndTheAgentCatchesUp(): void
    {
        $old      = $this->liveDb->data;
        list($id) = $this->push($this->stage($this->rows()), 'new');
        $pushed   = $this->liveDb->data;

        list($status, $body) = $this->rescuePhp($id);
        $this->assertSame(200, $status);
        $this->assertSame(['content_not_rolled_back'], $body['warnings']);
        $this->assertSame('old', file_get_contents($this->live . '/plugins/x/main.php'));
        $this->assertSame($pushed, $this->liveDb->data, 'rescue.php kennt keine Datenbank');

        // Der Agent übernimmt den Stand von rescue.php, schliesst den Push aber nicht ab und räumt nichts weg.
        Push::sync();
        Push::prune(time() + 30 * 86400);
        $this->assertSame(['committed', false], [Store::getPush($id)['status'], Store::getPush($id)['pruned']]);
        $this->assertFileExists($this->work($this->live) . '/' . $id . '/content/before.json');
        $this->assertSame($id, Push::pending()['push_id'], 'solange blockiert er weitere Pushes (Exit 42)');
        $this->assertRefused('wpsync_push_pending', 409, $this->begin(null, [], 'newer'));

        $back = Push::rollback(['push_id' => $id], self::KEY);
        $this->assertInstanceOf(\WP_REST_Response::class, $back, $back instanceof \WP_Error ? $back->code . ' ' . $back->message : '');
        $this->assertArrayNotHasKey('warnings', $back->data);
        ksort($old['postmeta']);
        ksort($this->liveDb->data['postmeta']);
        $this->assertSame($old, $this->liveDb->data);
        $this->assertSame('rolled_back', Store::getPush($id)['status']);
        $this->assertDirectoryDoesNotExist($this->work($this->live) . '/' . $id);
        $this->assertNull(Push::pending());
    }

    /** Auch ohne Zeile im Protokoll: ein Arbeitsordner mit offenem DB-Anteil wird nie als Rest weggeräumt. */
    public function testOrphanWithOpenContentIsKept(): void
    {
        list($id) = $this->push($this->stage($this->rows()), 'new');
        $this->rescuePhp($id);
        $dir = $this->work($this->live) . '/' . $id;
        unset(Store::$pushes[$id]);
        touch($dir, time() - 7200);
        Push::prune(time());
        $this->assertFileExists($dir . '/content/before.json');

        \WpSync\PushRescue::setContent($this->work($this->live), $id, \WpSync\PushRescue::CONTENT_DONE);
        touch($dir, time() - 7200);
        Push::prune(time());
        $this->assertDirectoryDoesNotExist($dir);
    }

    /** Starb PHP vor dem COMMIT, hat die Datenbank nichts behalten: die Rücknahme nimmt nur den Code zurück. */
    public function testRollbackOfContentThatNeverArrived(): void
    {
        $old      = $this->liveDb->data;
        list($id) = $this->push($this->stage($this->rows()), 'new');
        // Zustand wie nach einem Absturz mitten in der Transaktion: „pending“, Vorher-Abbild da, Daten alt.
        $this->liveDb->data = $old;
        \WpSync\PushRescue::setContent($this->work($this->live), $id, \WpSync\PushRescue::CONTENT_PENDING);
        unlink($this->work($this->live) . '/' . $id . '/content/after.json');
        $this->liveDb->log              = [];
        $GLOBALS['wpsync_post_actions'] = [];

        $back = Push::rollback(['push_id' => $id], self::KEY);
        $this->assertInstanceOf(\WP_REST_Response::class, $back, $back instanceof \WP_Error ? $back->code . ' ' . $back->message : '');
        $this->assertSame(['ok' => true, 'status' => 'rolled_back'], $back->data);
        $this->assertSame([], preg_grep('/^(write|delete|purge) /', $this->liveDb->log));
        $this->assertSame([], $GLOBALS['wpsync_post_actions']);
        $this->assertSame('old', file_get_contents($this->live . '/plugins/x/main.php'));
    }

    /** Nach dem bestätigten Push nach Live ist die Ablage weg; nach Staging bleibt sie für den Push nach Live. */
    public function testConfirmForgetsTheStagedPackageOnlyOnLive(): void
    {
        $sha    = $this->stage($this->rows());
        $staged = $this->work($this->live) . '/packages/' . self::KEY . '/' . $sha . '.jsonl';
        list($stg) = $this->push($sha, null, [], 'staging');
        Push::confirm(['push_id' => $stg], self::KEY);
        $this->assertFileExists($staged);

        list($id) = $this->push($sha);
        $this->assertFileExists($staged);
        Push::confirm(['push_id' => $id], self::KEY);
        $this->assertFileDoesNotExist($staged);
        $this->assertSame('confirmed', Store::getPush($id)['status']);
        $this->assertFileExists($this->work($this->live) . '/' . $id . '/content/before.json', 'das Vorher-Abbild bleibt, solange der Push sich zurücknehmen lässt');
    }

    /** §7.8: die Rücknahme eines Pushs nach Staging stellt die Tabellen der Kopie wieder her. */
    public function testRollbackOnTheStagingCopy(): void
    {
        $old      = $this->stagingDb->data;
        list($id) = $this->push($this->stage($this->rows()), 'new', [], 'staging');
        $this->assertNotSame($old, $this->stagingDb->data);
        $back = Push::rollback(['push_id' => $id], self::KEY);
        $this->assertInstanceOf(\WP_REST_Response::class, $back, $back instanceof \WP_Error ? $back->code . ' ' . $back->message : '');
        ksort($old['postmeta']);
        ksort($this->stagingDb->data['postmeta']);
        $this->assertSame($old, $this->stagingDb->data);
        $this->assertSame('stg-old', file_get_contents($this->staging . '/plugins/x/main.php'));
        $this->assertSame([], $this->liveDb->log);
    }

    /**
     * §7.6: der Satz bleibt ganz. Lässt sich der Code nicht zurücknehmen, weil ein späterer Push
     * dieselbe Einheit getauscht hat, bleiben auch die Inhalte stehen – geprüft, bevor die Datenbank
     * etwas zurücknimmt.
     */
    public function testASupersededPushKeepsItsContentToo(): void
    {
        $old                 = $this->liveDb->data;
        list($first, $commit) = $this->push($this->stage($this->rows()), 'new');
        $this->assertInstanceOf(\WP_REST_Response::class, $commit, $commit instanceof \WP_Error ? $commit->code . ' ' . $commit->message : '');
        Push::confirm(['push_id' => $first], self::KEY);
        list($second, $commit) = $this->push(null, 'newer');
        $this->assertInstanceOf(\WP_REST_Response::class, $commit, $commit instanceof \WP_Error ? $commit->code . ' ' . $commit->message : '');
        Push::confirm(['push_id' => $second], self::KEY);
        $pushed                         = $this->liveDb->data;
        $this->liveDb->log              = [];
        $GLOBALS['wpsync_post_actions'] = [];

        $error = $this->assertRefused('wpsync_push_rollback', 409, Push::rollback(['push_id' => $first], self::KEY));
        $this->assertStringContainsString($second, $error->message);
        $this->assertSame($pushed, $this->liveDb->data, 'die Inhalte stehen noch');
        $this->assertSame([], $this->liveDb->log, 'keine Transaktion, kein Schreiben');
        $this->assertSame([], $GLOBALS['wpsync_post_actions']);
        $this->assertSame('applied', $this->rescue($this->live, $first)['content']['state']);
        $this->assertSame('newer', file_get_contents($this->live . '/plugins/x/main.php'));
        $this->assertSame('confirmed', Store::getPush($first)['status']);

        // Ist der spätere Push zurück, geht der Satz ganz.
        $this->assertInstanceOf(\WP_REST_Response::class, Push::rollback(['push_id' => $second], self::KEY));
        $back = Push::rollback(['push_id' => $first], self::KEY);
        $this->assertInstanceOf(\WP_REST_Response::class, $back, $back instanceof \WP_Error ? $back->code . ' ' . $back->message : '');
        ksort($old['postmeta']);
        ksort($this->liveDb->data['postmeta']);
        $this->assertSame($old, $this->liveDb->data);
        $this->assertSame('old', file_get_contents($this->live . '/plugins/x/main.php'));
    }

    /**
     * AC-150: auch ein Fehler, den niemand als Ablehnung vorgesehen hat, hinterlässt keinen halben
     * Satz – die Transaktion ist zurück, Code und Uploads auch, der Push ist gescheitert.
     */
    public function testAnUnexpectedErrorWhileApplyingFailsThePush(): void
    {
        $old                      = $this->liveDb->data;
        $this->liveDb->beforeLock = static function (): void {
            throw new \RuntimeException('boom');
        };
        list($id, $commit) = $this->push($this->stage($this->rows()), 'new', ['2026/10/neu.png' => (string) base64_decode(self::PNG)]);
        $error             = $this->assertRefused('wpsync_content_content_failed', 500, $commit);
        $this->assertStringNotContainsString('boom', $error->message);
        $this->assertSame($old, $this->liveDb->data);
        $this->assertSame('old', file_get_contents($this->live . '/plugins/x/main.php'));
        $this->assertFileDoesNotExist($this->live . '/uploads/2026/10/neu.png');
        $push = Store::getPush($id);
        $this->assertSame(['failed', true], [$push['status'], $push['pruned']]);
        $this->assertNull(Store::getState('push_lock'));
    }

    /** Dasselbe im Probelauf: die Ablehnung steht in der Antwort, der Request scheitert nicht. */
    public function testAnUnexpectedErrorInTheDryRunIsReportedInBand(): void
    {
        $sha                  = $this->stage($this->rows());
        PushContent::$resolve = static function (): ContentTarget {
            throw new \RuntimeException('boom');
        };
        $begin = $this->begin($sha, ['dry' => true]);
        $this->assertInstanceOf(\WP_REST_Response::class, $begin);
        $this->assertFalse($begin->data['content']['ok']);
        $this->assertSame('content_failed', $begin->data['content']['error']['code']);
        $this->assertStringNotContainsString('boom', $begin->data['content']['error']['message']);
    }

    /**
     * D6: hat rescue.php Code und Uploads zurückgenommen und lassen sich die Inhalte nicht (oder
     * sollen sie nicht) zurücknehmen, schliesst confirm den Push ab – als zurückgerollt, nie als
     * bestätigt: die Inhalte bleiben, und die Antwort sagt es.
     */
    public function testConfirmAfterRescueClosesThePushAsRolledBackAndKeepsTheContent(): void
    {
        list($id) = $this->push($this->stage($this->rows()), 'new');
        $pushed   = $this->liveDb->data;
        $this->rescuePhp($id);
        $this->liveDb->log = [];

        $done = Push::confirm(['push_id' => $id], self::KEY);
        $this->assertInstanceOf(\WP_REST_Response::class, $done, $done instanceof \WP_Error ? $done->code . ' ' . $done->message : '');
        $this->assertSame(['ok' => true, 'status' => 'rolled_back', 'warnings' => ['content_kept']], $done->data);
        $this->assertSame($pushed, $this->liveDb->data, 'die Inhalte bleiben bewusst stehen');
        $this->assertSame([], $this->liveDb->log);
        $this->assertSame('old', file_get_contents($this->live . '/plugins/x/main.php'));
        $push = Store::getPush($id);
        $this->assertSame(['rolled_back', true], [$push['status'], $push['pruned']]);
        $this->assertDirectoryDoesNotExist($this->work($this->live) . '/' . $id, 'das Vorher-Abbild ist aufgeräumt');
        $this->assertNull(Push::pending());
        $this->assertNull(Store::getState('push_lock'));

        // Ein zweites confirm sagt dasselbe; zurücknehmen lässt sich der Push nicht mehr.
        $again = Push::confirm(['push_id' => $id], self::KEY);
        $this->assertSame(['ok' => true, 'status' => 'rolled_back', 'warnings' => ['content_kept']], $again->data);
        $this->assertRefused('wpsync_push_state', 409, Push::rollback(['push_id' => $id], self::KEY));
        $this->assertRefused('wpsync_push_state', 409, Push::rollbackPush($id));
        $this->assertSame($pushed, $this->liveDb->data);
    }

    /** Ein Push ohne Inhalte, den rescue.php zurückgenommen hat, wird durch confirm nie wieder „bestätigt“. */
    public function testConfirmNeverOverwritesARollbackOfRescue(): void
    {
        list($id, $commit) = $this->push(null, 'new');
        $this->assertInstanceOf(\WP_REST_Response::class, $commit);
        $this->rescuePhp($id);
        $this->assertRefused('wpsync_push_state', 409, Push::confirm(['push_id' => $id], self::KEY));
        $this->assertSame('rolled_back', Store::getPush($id)['status']);
        $this->assertInstanceOf(\WP_REST_Response::class, Push::rollback(['push_id' => $id], self::KEY), 'ohne angenommene Inhalte bleibt die Rücknahme wiederholbar');
    }

    /**
     * Scheitert die Rücknahme des Codes, nachdem die Inhalte schon zurück sind, hält rescue.json das
     * fest und die Meldung sagt es: ein zweiter Lauf fasst die Datenbank nicht mehr an und holt nur
     * Code und Uploads nach.
     */
    public function testAFailedCodeRollbackAfterTheContentCanBeRepeated(): void
    {
        $old      = $this->liveDb->data;
        list($id) = $this->push($this->stage($this->rows()), 'new', ['2026/10/neu.png' => (string) base64_decode(self::PNG)]);
        chmod($this->live . '/plugins', 0555); // der Tausch zurück scheitert

        $error = $this->assertRefused('wpsync_push_rollback', 500, Push::rollback(['push_id' => $id], self::KEY));
        $this->assertStringContainsString('Die Inhalte sind zurückgenommen', $error->message);
        ksort($old['postmeta']);
        ksort($this->liveDb->data['postmeta']);
        $this->assertSame($old, $this->liveDb->data);
        $this->assertSame('new', file_get_contents($this->live . '/plugins/x/main.php'));
        $this->assertSame('rolled_back', $this->rescue($this->live, $id)['content']['state']);
        $this->assertSame('committed', Store::getPush($id)['status']);

        chmod($this->live . '/plugins', 0755);
        $this->liveDb->log              = [];
        $GLOBALS['wpsync_post_actions'] = [];
        $back                           = Push::rollback(['push_id' => $id], self::KEY);
        $this->assertInstanceOf(\WP_REST_Response::class, $back, $back instanceof \WP_Error ? $back->code . ' ' . $back->message : '');
        $this->assertSame(['ok' => true, 'status' => 'rolled_back'], $back->data);
        $this->assertSame([], $this->liveDb->log, 'die Datenbank wird nicht noch einmal angefasst');
        $this->assertSame([], $GLOBALS['wpsync_post_actions']);
        $this->assertSame('old', file_get_contents($this->live . '/plugins/x/main.php'));
        $this->assertFileDoesNotExist($this->live . '/uploads/2026/10/neu.png');
        $this->assertSame('rolled_back', Store::getPush($id)['status']);
    }
}
