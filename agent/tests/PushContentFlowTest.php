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
}
