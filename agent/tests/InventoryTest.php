<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\TestCase;
use WpSync\Inventory;
use WpSync\Probe;
use WpSync\SizeScan;

final class FakeProbe implements Probe
{
    /** @var list<array{0: int, 1: int}> */
    public $postCalls = [];

    public function env(): array
    {
        return ['wp_version' => '6.8.2', 'table_prefix' => 'wp_'];
    }

    public function prefix(): string
    {
        return 'wp_';
    }

    public function plugins(): array
    {
        return [
            ['slug' => 'elementor', 'name' => 'Elementor', 'version' => '3.30.0', 'active' => true],
            ['slug' => 'duplicator-pro', 'name' => 'Duplicator Pro', 'version' => '4.5.0', 'active' => false],
        ];
    }

    public function themes(): array
    {
        return [['slug' => 'astra', 'name' => 'Astra', 'version' => '4.0.0', 'active' => true]];
    }

    public function tables(): array
    {
        return [
            ['name' => 'wp_posts', 'rows' => 30, 'bytes' => 3000],
            ['name' => 'wp_e_submissions_values', 'rows' => 5, 'bytes' => 500],
        ];
    }

    public function maxIds(): array
    {
        return ['posts' => 25000, 'postmeta' => 100];
    }

    public function postStats(int $from, int $to): array
    {
        $this->postCalls[] = [$from, $to];
        if ($from === 0) {
            return ['page' => ['count' => 2, 'bytes' => 200], 'revision' => ['count' => 10, 'bytes' => 1000]];
        }
        return ['revision' => ['count' => 5, 'bytes' => 500], 'iwp_log' => ['count' => 3, 'bytes' => 30]];
    }

    public function postmetaStats(int $from, int $to): array
    {
        return ['revision' => ['count' => 40, 'bytes' => 4000], '' => ['count' => 2, 'bytes' => 20]];
    }

    public function dropIns(): array
    {
        return ['wp-content/object-cache.php'];
    }
}

final class InventoryTest extends TestCase
{
    /** @var string */
    private $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/wpsync-inventory-' . bin2hex(random_bytes(4));
        $files = [
            'plugins/elementor/elementor.php'     => 10,
            'plugins/duplicator-pro/dup.php'      => 20,
            'themes/astra/style.css'              => 5,
            'uploads/2024/05/a.jpg'               => 100,
            'uploads/elementor/css/post-1.css'    => 1,
            'backups-dup-pro/archive.zip'         => 500,
        ];
        foreach ($files as $path => $size) {
            @mkdir(dirname($this->dir . '/' . $path), 0777, true);
            file_put_contents($this->dir . '/' . $path, str_repeat('x', $size));
        }
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function testRunsAllPhasesAndBuildsSheet(): void
    {
        $probe = new FakeProbe();
        $inv   = new Inventory($probe, new SizeScan($this->dir));
        $state = Inventory::initial(100.0);
        for ($i = 0; $i < 20 && $state['phase'] !== 'done'; $i++) {
            $state = $inv->step($state, microtime(true) + 60);
        }

        $this->assertSame('done', $state['phase']);
        $this->assertSame([[0, 20000], [20000, 40000]], $probe->postCalls, 'ID ranges, never LIMIT offset');

        $sheet = $inv->sheet($state, 160.0);
        $this->assertSame(160, $sheet['generated_at']);
        $this->assertSame(60.0, $sheet['duration']);

        $types = array_column($sheet['post_types'], null, 'name');
        $this->assertSame(
            ['name' => 'revision', 'count' => 15, 'bytes' => 1500, 'meta_rows' => 40, 'meta_bytes' => 4000, 'class' => 'log'],
            $types['revision']
        );
        $this->assertSame('log', $types['iwp_log']['class']);
        $this->assertSame('revision', $sheet['post_types'][0]['name'], 'largest first');
        $this->assertSame(['rows' => 2, 'bytes' => 20], $sheet['orphan_meta']);

        $tables = array_column($sheet['tables'], null, 'name');
        $this->assertSame('pii', $tables['wp_e_submissions_values']['class']);
        $this->assertTrue($tables['wp_posts']['essential']);
        $this->assertTrue($tables['wp_posts']['anonymized'], 'posts carry WooCommerce order rules');
        $this->assertFalse($tables['wp_e_submissions_values']['anonymized'], 'form entries have no rule');

        $plugins = array_column($sheet['plugins'], null, 'slug');
        $this->assertSame(20, $plugins['duplicator-pro']['bytes']);
        $this->assertFalse($plugins['duplicator-pro']['active']);
        $this->assertSame(5, array_column($sheet['themes'], null, 'slug')['astra']['bytes']);

        $this->assertSame(
            [['year' => '2024', 'files' => 1, 'bytes' => 100], ['year' => 'other', 'files' => 1, 'bytes' => 1]],
            $sheet['uploads']
        );
        $this->assertContains(['kind' => 'backup_dir', 'path' => 'wp-content/backups-dup-pro', 'bytes' => 500], $sheet['findings']);
        $this->assertContains(['kind' => 'drop_in', 'path' => 'wp-content/object-cache.php', 'bytes' => 0], $sheet['findings']);
    }

    /** Zwischen den Häppchen liegt der Zustand als JSON in wpsync_state. */
    public function testStateSurvivesJsonBetweenSlices(): void
    {
        $inv   = new Inventory(new FakeProbe(), new SizeScan($this->dir));
        $state = Inventory::initial(100.0);
        for ($i = 0; $i < 100 && $state['phase'] !== 'done'; $i++) {
            $state = json_decode((string) json_encode($inv->step($state, microtime(true) - 1)), true);
        }
        $this->assertSame('done', $state['phase']);
        $this->assertGreaterThan(5, $state['steps']);

        $sheet = $inv->sheet($state, 200.0);
        $this->assertSame(['rows' => 2, 'bytes' => 20], $sheet['orphan_meta']);
        $this->assertSame(15, array_column($sheet['post_types'], null, 'name')['revision']['count']);
    }

    /** Ein Job, den Agent ≤ 0.2.1 begonnen hat, kennt nur den Zähl-Cursor – Dateien werden neu gezählt. */
    public function testLegacyFileJobIsRecounted(): void
    {
        $inv   = new Inventory(new FakeProbe(), new SizeScan($this->dir));
        $state = Inventory::initial(100.0);
        while ($state['phase'] !== 'files') {
            $state = $inv->step($state, microtime(true) - 1);
        }
        $fresh = $inv->step($state, microtime(true) + 60);

        unset($state['data']['after']);
        $state['cursor']          = 3;
        $state['data']['buckets'] = ['top/backups-dup-pro' => ['files' => 1, 'bytes' => 500]];
        $legacy = $inv->step($state, microtime(true) + 60);

        $this->assertSame('done', $legacy['phase']);
        $this->assertSame($fresh['data']['buckets'], $legacy['data']['buckets']);
    }

    public function testFileProgressCountsFiles(): void
    {
        $inv   = new Inventory(new FakeProbe(), new SizeScan($this->dir));
        $state = Inventory::initial(100.0);
        while ($state['phase'] !== 'files') {
            $state = $inv->step($state, microtime(true) - 1);
        }
        $state = $inv->step($state, microtime(true) - 1);
        $state = $inv->step($state, microtime(true) - 1);
        $this->assertSame(2, Inventory::progress($state)['done']);
    }

    public function testProgress(): void
    {
        $this->assertSame(['phase' => 'done', 'done' => 0, 'total' => 0], Inventory::progress(null));
        $this->assertSame(
            ['phase' => 'postmeta', 'done' => 40000, 'total' => 1700000],
            Inventory::progress(['phase' => 'postmeta', 'cursor' => 40000, 'data' => ['max' => ['posts' => 10, 'postmeta' => 1700000]]])
        );
        $this->assertSame(['phase' => 'files', 'done' => 1234, 'total' => 0], Inventory::progress(['phase' => 'files', 'cursor' => 1234, 'data' => []]));
    }

    public function testPluginSlug(): void
    {
        $this->assertSame('elementor', Inventory::pluginSlug('elementor/elementor.php'));
        $this->assertSame('hello', Inventory::pluginSlug('hello.php'));
    }
}
