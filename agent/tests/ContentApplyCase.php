<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\TestCase;
use WpSync\ContentApply;
use WpSync\ContentOrigin;
use WpSync\ContentPackage;
use WpSync\ContentTarget;

require_once __DIR__ . '/ContentFixtures.php';
require_once __DIR__ . '/ContentMemory.php';

/**
 * Gemeinsamer Aufbau von ContentApplyTest und ContentRollbackTest: ein Ziel im Speicher, ein
 * Arbeitsordner und ein Satz mit jeder Art von Zeile.
 */
abstract class ContentApplyCase extends TestCase
{
    protected const NOW = 1791500000;

    protected ContentMemory $store;
    protected string $dir;
    /** @var list<string> */
    protected array $files = [];

    protected function setUp(): void
    {
        $this->dir   = sys_get_temp_dir() . '/wpsync-apply-' . bin2hex(random_bytes(4)) . '/content';
        $this->store = new ContentMemory([
            'posts' => [
                '219' => ContentFixtures::post('219'),
                '220' => ContentFixtures::post('220', ['post_status' => 'draft']),
            ],
            'postmeta' => [
                "219\0_elementor_data" => ['values' => ['[{"url":"https:\/\/kunde.de\/x"}]']],
                "219\0_thumbnail_id"   => ['values' => ['300']],
                "219\0_fremd"          => ['values' => ['bleibt']],
            ],
            'terms'              => ['5' => ContentFixtures::term('5', 'News')],
            'term_taxonomy'      => ['5' => ContentFixtures::taxonomy('5', '5', 'category')],
            'term_relationships' => ["219\0category" => ['values' => ['5:0']]],
            'options'            => [
                'blogname'   => ContentFixtures::option('blogname', 'Kunde'),
                'stylesheet' => ContentFixtures::option('stylesheet', 'hello-child'),
            ],
        ]);
    }

    protected function tearDown(): void
    {
        array_map('unlink', array_filter($this->files, 'is_file'));
        exec('chmod -R u+w ' . escapeshellarg(dirname($this->dir)) . ' 2>/dev/null; rm -rf ' . escapeshellarg(dirname($this->dir)));
    }

    protected function h(string $table, string $key): string
    {
        return ContentFixtures::hash($table, $key, $this->store->data[$table][$key] ?? null);
    }

    /**
     * Die Daten des Stores mit sortierten Schlüsseln je Tabelle – ein zurückgeschriebener Schlüssel
     * steht im Array hinten, in der Datenbank gibt es diese Reihenfolge nicht.
     *
     * @param array<string, array<string, mixed>> $data
     * @return array<string, array<string, mixed>>
     */
    protected static function sorted(array $data): array
    {
        foreach ($data as $table => $rows) {
            ksort($rows, SORT_STRING);
            $data[$table] = $rows;
        }
        return $data;
    }

    /** @return list<array<string, mixed>> ein Satz mit update, insert, trash, gelöschtem Paar, neuer Option */
    protected function rows(): array
    {
        return [
            ContentFixtures::row('update', 'posts', '219', $this->h('posts', '219'), ContentFixtures::postRow('219', ['post_title' => 'Neu', 'post_content' => 'Link: ' . ContentOrigin::PLAIN . '/neu'])),
            ContentFixtures::row('update', 'postmeta', "219\0_elementor_data", $this->h('postmeta', "219\0_elementor_data"), ['values' => ['[{"url":"' . ContentOrigin::ESC1 . '\/neu"}]']]),
            ContentFixtures::row('update', 'postmeta', "219\0_thumbnail_id", $this->h('postmeta', "219\0_thumbnail_id"), ['values' => []]),
            ContentFixtures::row('trash', 'posts', '220', $this->h('posts', '220')),
            ContentFixtures::row('insert', 'posts', '1000001', 'absent', ContentFixtures::postRow('1000001', ['post_title' => 'Ganz neu'])),
            ContentFixtures::row('insert', 'postmeta', "1000001\0_wp_page_template", 'absent', ['values' => ['default']]),
            ContentFixtures::row('insert', 'terms', '1000001', 'absent', ['name' => 'Neu', 'slug' => 'neu', 'term_group' => '0']),
            ContentFixtures::row('insert', 'term_taxonomy', '1000002', 'absent', ['term_id' => '1000001', 'taxonomy' => 'category', 'description' => '', 'parent' => '0']),
            ContentFixtures::row('insert', 'term_relationships', "1000001\0category", 'absent', ['values' => ['1000002:0']]),
            ContentFixtures::row('update', 'term_relationships', "219\0category", $this->h('term_relationships', "219\0category"), ['values' => ['5:0', '1000002:0']]),
            ContentFixtures::row('update', 'options', 'blogname', $this->h('options', 'blogname'), ['option_value' => 'Kunde GmbH']),
            ContentFixtures::row('insert', 'options', 'page_on_front', 'absent', ['option_value' => '1000001']),
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    protected function apply(array $rows, ?int $author = 7, ?ContentTarget $target = null): array
    {
        $this->files[] = $file = ContentFixtures::file($rows);
        return ContentApply::run(ContentPackage::read($file), $target ?? ContentFixtures::live($this->store), $this->dir, $author, self::NOW, '2026-10-09 14:13:20');
    }
}
