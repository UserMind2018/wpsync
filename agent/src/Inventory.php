<?php
namespace WpSync;

defined('ABSPATH') || exit;

/**
 * Infosheet in Häppchen (Spec 4.4, E20): Übersicht → Post-Typen nach ID-Bereichen → Postmeta
 * nach meta_id-Bereichen → Dateigrößen. Der Zustand ist reines JSON und liegt zwischen den
 * Häppchen in wpsync_state. Keine Abfrage mit LIMIT offset (Spec 7, Spike B26).
 */
final class Inventory
{
    public const RANGE = 20000;

    /** @var Probe */
    private $probe;
    /** @var SizeScan */
    private $sizes;

    public function __construct(Probe $probe, SizeScan $sizes)
    {
        $this->probe = $probe;
        $this->sizes = $sizes;
    }

    /** @return array<string, mixed> */
    public static function initial(float $now): array
    {
        return ['phase' => 'meta', 'cursor' => 0, 'started' => $now, 'steps' => 0, 'data' => []];
    }

    /**
     * Arbeitet bis $deadline, mindestens eine Einheit.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    public function step(array $state, float $deadline): array
    {
        $state['steps'] = (int) $state['steps'] + 1;
        do {
            $state = $this->unit($state, $deadline);
        } while ($state['phase'] !== 'done' && microtime(true) < $deadline);
        return $state;
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function unit(array $state, float $deadline): array
    {
        $data   = $state['data'];
        $cursor = (int) $state['cursor'];

        if ($state['phase'] === 'meta') {
            $state['data'] = [
                'prefix'     => $this->probe->prefix(),
                'env'        => $this->probe->env(),
                'plugins'    => $this->probe->plugins(),
                'themes'     => $this->probe->themes(),
                'tables'     => $this->probe->tables(),
                'max'        => $this->probe->maxIds(),
                'drop_ins'   => $this->probe->dropIns(),
                'post_types' => [],
                'meta'       => [],
                'buckets'    => [],
                'large'      => [],
                'after'      => '',
            ];
            return self::next($state, 'posts');
        }
        if ($state['phase'] === 'posts') {
            $to = $cursor + self::RANGE;
            $state['data']['post_types'] = self::merge($data['post_types'], $this->probe->postStats($cursor, $to));
            return $to >= (int) $data['max']['posts'] ? self::next($state, 'postmeta') : self::advance($state, $to);
        }
        if ($state['phase'] === 'postmeta') {
            $to = $cursor + self::RANGE;
            $state['data']['meta'] = self::merge($data['meta'], $this->probe->postmetaStats($cursor, $to));
            return $to >= (int) $data['max']['postmeta'] ? self::next($state, 'files') : self::advance($state, $to);
        }
        if ($state['phase'] === 'files') {
            if (!isset($data['after'])) { // Job eines Agents ≤ 0.2.1 mit Zähl-Cursor: Dateien neu zählen
                $data   = ['buckets' => [], 'large' => [], 'after' => ''] + $data;
                $cursor = 0;
            }
            $page = $this->sizes->page((string) $data['after'], $deadline, $data['buckets'], $data['large']);
            $state['data']['buckets'] = $page['buckets'];
            $state['data']['large']   = $page['large'];
            $state['data']['after']   = (string) $page['next'];
            return $page['next'] === null ? self::next($state, 'done') : self::advance($state, $cursor + $page['files']);
        }
        return $state;
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    public function sheet(array $state, float $now): array
    {
        $d       = $state['data'];
        $buckets = $d['buckets'];

        $tables = [];
        foreach ($d['tables'] as $t) {
            $tables[] = ['name' => (string) $t['name'], 'rows' => (int) $t['rows'], 'bytes' => (int) $t['bytes']]
                + Classifier::table((string) $t['name'], (string) $d['prefix'])
                + ['anonymized' => Anonymizer::covers((string) $t['name'], (string) $d['prefix'])];
        }

        $postTypes = [];
        foreach ($d['post_types'] as $type => $s) {
            $type        = (string) $type;
            $m           = $d['meta'][$type] ?? ['count' => 0, 'bytes' => 0];
            $postTypes[] = [
                'name'       => $type,
                'count'      => (int) $s['count'],
                'bytes'      => (int) $s['bytes'],
                'meta_rows'  => (int) $m['count'],
                'meta_bytes' => (int) $m['bytes'],
                'class'      => Classifier::postType($type),
            ];
        }
        usort($postTypes, static function (array $a, array $b): int {
            return ($b['bytes'] + $b['meta_bytes']) <=> ($a['bytes'] + $a['meta_bytes']);
        });
        $orphan = $d['meta'][''] ?? ['count' => 0, 'bytes' => 0];

        $uploads = [];
        foreach ($buckets as $key => $b) {
            if (strpos((string) $key, 'uploads/') === 0) {
                $uploads[] = ['year' => substr((string) $key, 8), 'files' => (int) $b['files'], 'bytes' => (int) $b['bytes']];
            }
        }
        usort($uploads, static function (array $a, array $b): int {
            return [$a['year'] === 'other', $a['year']] <=> [$b['year'] === 'other', $b['year']];
        });

        $findings = [];
        foreach (Excludes::BACKUP_DIRS as $dir) {
            if (isset($buckets['top/' . $dir])) {
                $findings[] = ['kind' => 'backup_dir', 'path' => 'wp-content/' . $dir, 'bytes' => (int) $buckets['top/' . $dir]['bytes']];
            }
        }
        foreach ($d['large'] as $f) {
            $findings[] = ['kind' => 'large_file', 'path' => (string) $f['path'], 'bytes' => (int) $f['bytes']];
        }
        foreach ($d['drop_ins'] as $path) {
            $findings[] = ['kind' => 'drop_in', 'path' => (string) $path, 'bytes' => 0];
        }

        return [
            'generated_at' => (int) $now,
            'duration'     => round($now - (float) $state['started'], 1),
            'steps'        => (int) $state['steps'],
            'env'          => $d['env'],
            'plugins'      => self::withSizes($d['plugins'], $buckets, 'plugins/'),
            'themes'       => self::withSizes($d['themes'], $buckets, 'themes/'),
            'tables'       => $tables,
            'post_types'   => $postTypes,
            'orphan_meta'  => ['rows' => (int) $orphan['count'], 'bytes' => (int) $orphan['bytes']],
            'uploads'      => $uploads,
            'findings'     => $findings,
        ];
    }

    /**
     * @param array<string, mixed>|null $job
     * @return array{phase: string, done: int, total: int}
     */
    public static function progress(?array $job): array
    {
        if ($job === null) {
            return ['phase' => 'done', 'done' => 0, 'total' => 0];
        }
        $max   = $job['data']['max'] ?? [];
        $total = 0;
        if ($job['phase'] === 'posts') {
            $total = (int) ($max['posts'] ?? 0);
        } elseif ($job['phase'] === 'postmeta') {
            $total = (int) ($max['postmeta'] ?? 0);
        }
        return ['phase' => (string) $job['phase'], 'done' => (int) $job['cursor'], 'total' => $total];
    }

    /** elementor/elementor.php → elementor, hello.php → hello */
    public static function pluginSlug(string $file): string
    {
        $dir = dirname($file);
        return $dir === '.' ? basename($file, '.php') : $dir;
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private static function next(array $state, string $phase): array
    {
        $state['phase']  = $phase;
        $state['cursor'] = 0;
        return $state;
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private static function advance(array $state, int $cursor): array
    {
        $state['cursor'] = $cursor;
        return $state;
    }

    /**
     * @param array<string, array{count: int, bytes: int}> $into
     * @param array<string, array{count: int, bytes: int}> $add
     * @return array<string, array{count: int, bytes: int}>
     */
    private static function merge(array $into, array $add): array
    {
        foreach ($add as $key => $s) {
            $key        = (string) $key;
            $into[$key] = [
                'count' => (int) ($into[$key]['count'] ?? 0) + (int) $s['count'],
                'bytes' => (int) ($into[$key]['bytes'] ?? 0) + (int) $s['bytes'],
            ];
        }
        return $into;
    }

    /**
     * @param list<array{slug: string, name: string, version: string, active: bool}> $components
     * @param array<string, array{files: int, bytes: int}>                           $buckets
     * @return list<array{slug: string, name: string, version: string, active: bool, files: int, bytes: int}>
     */
    private static function withSizes(array $components, array $buckets, string $prefix): array
    {
        $out = [];
        foreach ($components as $c) {
            $b     = $buckets[$prefix . $c['slug']] ?? ['files' => 0, 'bytes' => 0];
            $out[] = [
                'slug'    => (string) $c['slug'],
                'name'    => (string) $c['name'],
                'version' => (string) $c['version'],
                'active'  => (bool) $c['active'],
                'files'   => (int) $b['files'],
                'bytes'   => (int) $b['bytes'],
            ];
        }
        return $out;
    }
}
