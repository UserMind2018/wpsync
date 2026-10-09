<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\TestCase;
use WpSync\ContentOrigin;
use WpSync\ContentTarget;
use WpSync\Push;
use WpSync\PushContent;
use WpSync\Staging;
use WpSync\Store;

/**
 * Gemeinsamer Aufbau der Tests, die Inhalte im echten Push-Ablauf prüfen (PushContentFlowTest,
 * PushRescueFlowTest): Push läuft auf einem temporären Webroot; WordPress, Store und Staging sind
 * Attrappen (PushHarness.php), die Tabellen des Ziels ein Store im Speicher. Jede Unterklasse
 * läuft in eigenen Prozessen (RunTestsInSeparateProcesses) – die Attrappen definieren globale
 * Funktionen.
 */
abstract class PushContentFlowCase extends TestCase
{
    protected const KEY = '0123456789abcdef';
    /** 1×1-PNG – getimagesize() erkennt es als Bild. */
    protected const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected string $root;
    protected string $live;
    protected string $staging;
    protected ContentMemory $liveDb;
    protected ContentMemory $stagingDb;
    /** @var list<string> Ziele, die PushContent aufgelöst hat */
    protected array $resolved = [];
    /** Salt des letzten echten Begin – daraus leitet die CLI den Schlüssel für rescue.php ab. */
    protected string $salt = '';

    protected function setUp(): void
    {
        require_once __DIR__ . '/PushHarness.php';
        require_once __DIR__ . '/PostActionsHarness.php';
        require_once __DIR__ . '/ContentFixtures.php';
        require_once __DIR__ . '/ContentMemory.php';

        $this->root    = (string) realpath(sys_get_temp_dir()) . '/wpsync-contentflow-' . bin2hex(random_bytes(4)) . $this->rootSuffix();
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

    /** Hängt am Namen des temporären Webroots – für Tests mit Zeichen im Pfad, die glob() als Muster läse. */
    protected function rootSuffix(): string
    {
        return '';
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
    protected function site(string $path): array
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
    protected function h(string $table, string $key): string
    {
        return ContentFixtures::hash($table, $key, $this->site('')[$table][$key] ?? null);
    }

    /** @return list<array<string, mixed>> ein Satz mit update, Meta, trash, neuer Seite und Option */
    protected function rows(): array
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
    protected function stage(array $rows, array $head = []): string
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
    protected function entry(string $content): array
    {
        return ['size' => strlen($content), 'sha256' => hash('sha256', $content), 'mtime' => 1700000000];
    }

    /**
     * @param array<string, mixed>  $extra   target, dry, units …
     * @param array<string, string> $uploads Pfad relativ zu uploads/ → Inhalt
     * @return \WP_REST_Response|\WP_Error
     */
    protected function begin(?string $sha, array $extra = [], ?string $code = null, array $uploads = [])
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
    protected function assertRefused(string $code, int $status, $result): \WP_Error
    {
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame([$code, $status], [$result->code, $result->status], $result->message);
        return $result;
    }

    protected function work(string $content): string
    {
        return $content . '/' . Store::pushDirName();
    }

    /**
     * Begin, Upload der Einheiten, Commit; liefert ID und Antwort des Commits.
     *
     * @param array<string, string> $uploads
     * @return array{0: string, 1: \WP_REST_Response|\WP_Error}
     */
    protected function push(?string $sha, ?string $code = null, array $uploads = [], string $target = 'live'): array
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
    protected function rescue(string $content, string $id): array
    {
        return (array) json_decode((string) file_get_contents($this->work($content) . '/' . $id . '/rescue.json'), true);
    }

    /** rescue.php, wie die CLI es ohne WordPress aufruft. */
    protected function rescuePhp(string $id): array
    {
        $key = \WpSync\PushRescue::key((string) Store::secretFor(self::KEY), $id, $this->salt);
        return \WpSync\PushRescue::handle(\WpSync\PushRescue::contentDirs($this->live), ['action' => 'rollback', 'push_id' => $id, 'key' => $key], time());
    }
}
