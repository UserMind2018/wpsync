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
                'big'        => [],
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
                $data   = ['buckets' => [], 'large' => [], 'big' => [], 'after' => ''] + $data;
                $cursor = 0;
            }
            // Ein Job, den ein Agent < 0.10.0 begonnen hat, kennt 'big' noch nicht.
            $page = $this->sizes->page((string) $data['after'], $deadline, $data['buckets'], $data['large'], $data['big'] ?? []);
            $state['data']['buckets'] = $page['buckets'];
            $state['data']['large']   = $page['large'];
            $state['data']['big']     = $page['big'];
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
                $findings[] = ['kind' => 'backup_dir', 'path' => 'wp-content/' . $dir, 'bytes' => (int) $buckets['top/' . $dir]['bytes'], 'transferred' => false, 'reason' => 'excluded_dir'];
            }
        }
        foreach ($d['large'] as $f) {
            $findings[] = ['kind' => 'large_file', 'path' => (string) $f['path'], 'bytes' => (int) $f['bytes'], 'transferred' => false, 'reason' => 'too_large'];
        }
        foreach ($d['drop_ins'] as $path) {
            // advanced-cache.php und object-cache.php löscht das CLI lokal (RemoveDropIns), maintenance.php bleibt.
            $removed    = in_array(basename((string) $path), ['advanced-cache.php', 'object-cache.php'], true);
            $findings[] = ['kind' => 'drop_in', 'path' => (string) $path, 'bytes' => 0, 'transferred' => !$removed] + ($removed ? ['reason' => 'drop_in'] : []);
        }

        // Kandidaten zum Ausschliessen (Spec 0.10.0 D1, D2)
        $dirs = [];
        foreach ($buckets as $key => $b) {
            $key = (string) $key;
            if (strpos($key, 'top/') === 0 && $key !== 'top/.' && !in_array(substr($key, 4), ['plugins', 'themes', 'mu-plugins', 'uploads'], true)) {
                $name   = substr($key, 4);
                $dirs[] = self::candidate('wp-content/' . $name, (int) $b['files'], (int) $b['bytes'], Excludes::dir($name, true) ? 'excluded_dir' : null);
            } elseif (strpos($key, 'dir/uploads/') === 0) {
                $name   = substr($key, 12);
                $dirs[] = self::candidate('wp-content/uploads/' . $name, (int) $b['files'], (int) $b['bytes'], Excludes::dir($name, false) ? 'excluded_dir' : null);
            }
        }
        usort($dirs, static function (array $a, array $b): int {
            return [$b['bytes'], $a['path']] <=> [$a['bytes'], $b['path']];
        });
        $big = $d['big'] ?? [];
        usort($big, static function (array $a, array $b): int {
            return $b['bytes'] <=> $a['bytes'];
        });
        $bigFiles = [];
        foreach ($big as $f) {
            $rel        = substr((string) $f['path'], strlen('wp-content/'));
            $bigFiles[] = self::candidate((string) $f['path'], 1, (int) $f['bytes'], Excludes::path($rel, (int) $f['bytes']));
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
            'dirs'         => $dirs,
            'big_files'    => $bigFiles,
        ];
    }

    /** @return array{path: string, files: int, bytes: int, excluded: bool, reason?: string} */
    private static function candidate(string $path, int $files, int $bytes, ?string $reason): array
    {
        $out = ['path' => $path, 'files' => $files, 'bytes' => $bytes, 'excluded' => $reason !== null];
        if ($reason !== null) {
            $out['reason'] = $reason;
        }
        return $out;
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
