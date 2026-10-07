<?php
namespace WpSync;

defined('ABSPATH') || exit;

/**
 * Größen unter wp-content nach Gruppen für das Infosheet: plugins/<slug>, themes/<slug>,
 * uploads/<Jahr>, uploads/other, top/<Ordner>, top/. – seitenweise mit Pfad-Cursor.
 * Anders als FileWalker zählt SizeScan Backup- und Cache-Ordner mit (Auffälligkeiten, AC-8);
 * nur Symlinks, .git und eine Staging-Kopie (wpsync-staging-*) bleiben aussen vor.
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
     * $after ist der Pfad der zuletzt gezählten Datei: alles davor wird weder betreten noch per
     * stat geprüft – ein Zähler als Cursor kostete pro Aufruf die ganze bisherige Strecke.
     *
     * @param array<string, array{files: int, bytes: int}> $buckets bisheriger Stand
     * @param list<array{path: string, bytes: int}>       $large   bisheriger Stand
     * @return array{buckets: array<string, array{files: int, bytes: int}>, large: list<array{path: string, bytes: int}>, next: string|null, files: int}
     */
    public function page(string $after, float $deadline, array $buckets = [], array $large = []): array
    {
        $files = 0;
        foreach ($this->walk($this->contentDir, $after) as $full) {
            if ($files > 0 && microtime(true) > $deadline) {
                return ['buckets' => $buckets, 'large' => $large, 'next' => $after, 'files' => $files];
            }
            $after = substr($full, strlen($this->contentDir) + 1);
            $bytes = (int) @filesize($full);
            $key   = self::bucket($after);
            if (!isset($buckets[$key])) {
                $buckets[$key] = ['files' => 0, 'bytes' => 0];
            }
            $buckets[$key]['files']++;
            $buckets[$key]['bytes'] += $bytes;
            if ($bytes > Excludes::MAX_FILE_BYTES) {
                $large[] = ['path' => 'wp-content/' . $after, 'bytes' => $bytes];
            }
            $files++;
        }
        return ['buckets' => $buckets, 'large' => $large, 'next' => null, 'files' => $files];
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
     * Dateien in Byte-Reihenfolge der Pfade, nur die hinter $after (relativ zu $dir).
     *
     * @return \Generator<int, string>
     */
    private function walk(string $dir, string $after): \Generator
    {
        $entries = @scandir($dir);
        if ($entries === false) {
            return;
        }
        sort($entries, SORT_STRING); // scandir sortiert je nach Locale – der Cursor vergleicht mit strcmp
        $parts = explode('/', $after, 2);
        foreach ($entries as $name) {
            if ($name === '.' || $name === '..' || $name === '.git') {
                continue;
            }
            $cmp = $after === '' ? 1 : strcmp($name, $parts[0]);
            if ($cmp < 0) {
                continue;
            }
            $full = $dir . '/' . $name;
            if (is_link($full)) {
                continue;
            }
            if (is_dir($full)) {
                if (strpos(strtolower($name), Excludes::STAGING_DIR_PREFIX) === 0) {
                    continue; // Spec Stufe 2b 5.10: die Kopie taucht in keinem Inventar auf
                }
                yield from $this->walk($full, $cmp === 0 ? ($parts[1] ?? '') : '');
                continue;
            }
            if ($cmp > 0) {
                yield $full;
            }
        }
    }
}
