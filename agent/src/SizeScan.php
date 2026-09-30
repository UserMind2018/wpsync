<?php
namespace WpSync;

/**
 * Größen unter wp-content nach Gruppen für das Infosheet: plugins/<slug>, themes/<slug>,
 * uploads/<Jahr>, uploads/other, top/<Ordner>, top/. – seitenweise mit Index-Cursor.
 * Anders als FileWalker zählt SizeScan Backup- und Cache-Ordner mit (Auffälligkeiten, AC-8);
 * nur Symlinks und .git bleiben aussen vor.
 */
final class SizeScan
{
    /** @var string */
    private $contentDir;

    public function __construct(string $contentDir)
    {
        $this->contentDir = rtrim(str_replace('\\', '/', $contentDir), '/');
    }

    /**
     * Pro Aufruf mindestens eine Datei, danach bis $deadline (Fortschrittsgarantie wie FileWalker).
     *
     * @param array<string, array{files: int, bytes: int}> $buckets bisheriger Stand
     * @param list<array{path: string, bytes: int}>       $large   bisheriger Stand
     * @return array{buckets: array<string, array{files: int, bytes: int}>, large: list<array{path: string, bytes: int}>, next: int|null}
     */
    public function page(int $offset, float $deadline, array $buckets = [], array $large = []): array
    {
        $index = 0;
        foreach ($this->walk($this->contentDir) as $full) {
            if ($index < $offset) {
                $index++;
                continue;
            }
            if ($index > $offset && microtime(true) > $deadline) {
                return ['buckets' => $buckets, 'large' => $large, 'next' => $index];
            }
            $rel   = substr($full, strlen($this->contentDir) + 1);
            $bytes = (int) @filesize($full);
            $key   = self::bucket($rel);
            if (!isset($buckets[$key])) {
                $buckets[$key] = ['files' => 0, 'bytes' => 0];
            }
            $buckets[$key]['files']++;
            $buckets[$key]['bytes'] += $bytes;
            if ($bytes > Excludes::MAX_FILE_BYTES) {
                $large[] = ['path' => 'wp-content/' . $rel, 'bytes' => $bytes];
            }
            $index++;
        }
        return ['buckets' => $buckets, 'large' => $large, 'next' => null];
    }

    /** Gruppe einer Datei, Pfad relativ zu wp-content. */
    public static function bucket(string $rel): string
    {
        $parts = explode('/', $rel);
        if (count($parts) === 1) {
            return 'top/.';
        }
        if ($parts[0] === 'plugins' || $parts[0] === 'themes') {
            $slug = count($parts) === 2 ? (string) preg_replace('/\.php$/', '', $parts[1]) : $parts[1];
            return $parts[0] . '/' . $slug;
        }
        if ($parts[0] === 'uploads') {
            return count($parts) > 2 && preg_match('/^\d{4}$/', $parts[1]) === 1 ? 'uploads/' . $parts[1] : 'uploads/other';
        }
        return 'top/' . $parts[0];
    }

    /**
     * @return \Generator<int, string>
     */
    private function walk(string $dir): \Generator
    {
        $entries = @scandir($dir);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $name) {
            if ($name === '.' || $name === '..' || $name === '.git') {
                continue;
            }
            $full = $dir . '/' . $name;
            if (is_link($full)) {
                continue;
            }
            if (is_dir($full)) {
                yield from $this->walk($full);
                continue;
            }
            yield $full;
        }
    }
}
