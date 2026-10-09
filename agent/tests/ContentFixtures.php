<?php
declare(strict_types=1);

namespace WpSync\Tests;

use WpSync\Canon;
use WpSync\ContentOrigin;
use WpSync\ContentState;

/**
 * Bausteine der Inhalts-Tests: Rohzeilen wie aus der Datenbank, Zeilen eines Pakets und das
 * Paket als Datei. Werte in Paketzeilen sind normalisiert (Platzhalter statt Domain).
 */
final class ContentFixtures
{
    public const HOME  = 'https://kunde.de';
    public const LOCAL = 'kunde.ddev.site';

    /**
     * Eine ganze posts-Zeile, wie SELECT * sie liefert.
     *
     * @param array<string, string> $over
     * @return array<string, string>
     */
    public static function post(string $id, array $over = []): array
    {
        return $over + [
            'ID' => $id, 'post_author' => '7', 'post_date' => '2026-01-01 10:00:00', 'post_date_gmt' => '2026-01-01 09:00:00',
            'post_content' => '<a href="' . self::HOME . '/kontakt">Kontakt</a>', 'post_title' => 'Seite ' . $id, 'post_excerpt' => '',
            'post_status' => 'publish', 'comment_status' => 'closed', 'ping_status' => 'closed', 'post_password' => '',
            'post_name' => 'seite-' . $id, 'to_ping' => '', 'pinged' => '', 'post_modified' => '2026-02-02 10:00:00',
            'post_modified_gmt' => '2026-02-02 09:00:00', 'post_content_filtered' => '', 'post_parent' => '0',
            'guid' => self::HOME . '/?p=' . $id, 'menu_order' => '0', 'post_type' => 'page', 'post_mime_type' => '', 'comment_count' => '0',
        ];
    }

    /**
     * Die Spalten des Abdrucks einer posts-Zeile in normalisierter Form – so steht sie im Paket.
     *
     * @param array<string, string> $over
     * @return array<string, string>
     */
    public static function postRow(string $id, array $over = []): array
    {
        $raw                 = self::post($id);
        $raw['post_content'] = '<a href="' . ContentOrigin::PLAIN . '/kontakt">Kontakt</a>';
        return $over + array_intersect_key($raw, array_flip(Canon::POSTS));
    }

    /** @return array<string, string> */
    public static function option(string $name, string $value, string $id = '10'): array
    {
        return ['option_id' => $id, 'option_name' => $name, 'option_value' => $value, 'autoload' => 'yes'];
    }

    /** @return array<string, string> */
    public static function term(string $id, string $name): array
    {
        return ['term_id' => $id, 'name' => $name, 'slug' => strtolower($name), 'term_group' => '0'];
    }

    /** @return array<string, string> */
    public static function taxonomy(string $id, string $termId, string $taxonomy): array
    {
        return ['term_taxonomy_id' => $id, 'term_id' => $termId, 'taxonomy' => $taxonomy, 'description' => '', 'parent' => '0', 'count' => '0'];
    }

    public const STAGING_DIR = 'wpsync-staging-0123456789ab';

    /** Das Ziel Live über einem Store im Speicher. */
    public static function live(ContentMemory $store, string $uploadsDir = '/nonexistent/uploads', string $siteurl = self::HOME): \WpSync\ContentTarget
    {
        return new \WpSync\ContentTarget('live', $store, new ContentOrigin(self::HOME), self::HOME, $siteurl, self::HOME, 'wp_', $uploadsDir, self::HOME . '/wp-content/uploads', 'auto');
    }

    /** Die Staging-Kopie derselben Site: ihre Werte tragen den Pfad der Kopie hinter dem Host. */
    public static function staging(ContentMemory $store, string $uploadsDir = '/nonexistent/uploads'): \WpSync\ContentTarget
    {
        $url    = self::HOME . '/' . self::STAGING_DIR;
        $origin = new ContentOrigin(self::HOME, new \WpSync\StagingReplace(self::HOME, '/' . self::STAGING_DIR));
        return new \WpSync\ContentTarget('staging', $store, $origin, self::HOME, self::HOME, $url, 'stgabcdef_', $uploadsDir, $url . '/wp-content/uploads', 'auto');
    }

    /** Abdruck, den das Manifest für diesen Rohzustand des Ziels nennt (Ziel: Live mit HOME). */
    public static function hash(string $table, string $key, ?array $raw): string
    {
        $reader = new \WpSync\ContentReader(null, '', new ContentOrigin(self::HOME));
        return (string) (ContentState::record($reader, $table, $key, $raw)['h'] ?? '');
    }

    /**
     * Eine Zeile des Pakets, Werte noch nicht kodiert.
     *
     * @param array<string, mixed>|null $row
     * @return array<string, mixed>
     */
    public static function row(string $op, string $table, string $key, string $expected, ?array $row = null): array
    {
        $out = ['op' => $op, 'table' => $table, 'key' => $key, 'expected' => $expected];
        if ($row !== null) {
            $out['row'] = $row;
        }
        return $out;
    }

    /** @return array<string, mixed> */
    public static function head(array $over = []): array
    {
        return $over + [
            'list_version'  => \WpSync\ContentLists::VERSION,
            'extensions'    => ['post_types' => [], 'taxonomies' => [], 'meta_exceptions' => []],
            'corridor'      => ['offset' => 1000000, 'posts' => [1000001, 1999999], 'terms' => [1000001, 1999999], 'term_taxonomy' => [1000001, 1999999]],
            'canon_version' => 1,
            'variants'      => ['plain', 'esc1', 'esc2'],
            'home'          => self::HOME,
            'map_id'        => str_repeat('ab', 32),
            'local_host'    => self::LOCAL,
        ];
    }

    /**
     * Der Text eines Pakets: Kopf mit rows und sha256, dann die Zeilen, Werte base64.
     *
     * @param list<array<string, mixed>> $rows aus row()
     * @param array<string, mixed>       $head überschreibt Felder des Kopfs (auch rows und sha256)
     */
    public static function text(array $rows, array $head = []): string
    {
        $pack = static function ($value) {
            return $value === null ? null : base64_encode((string) $value);
        };
        $body = '';
        foreach ($rows as $row) {
            if (isset($row['row'])) {
                $row['row'] = isset($row['row']['values'])
                    ? ['values' => array_map($pack, $row['row']['values'])]
                    : array_map($pack, $row['row']);
            }
            $body .= json_encode($row, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        }
        $head = $head + self::head() + ['rows' => count($rows), 'sha256' => hash('sha256', $body)];
        return json_encode(['head' => $head], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n" . $body;
    }

    /**
     * Schreibt das Paket in eine temporäre Datei und liefert ihren Pfad.
     *
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed>       $head
     */
    public static function file(array $rows, array $head = []): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'wpsync-pkg-');
        file_put_contents($file, self::text($rows, $head));
        return $file;
    }
}
