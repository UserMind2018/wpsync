<?php
namespace WpSync;

defined('ABSPATH') || exit;

/**
 * Dateien unter wp-content, relativ zu ABSPATH, in fester Reihenfolge und seitenweise.
 * Symlinks werden übersprungen (zeigen typischerweise aus der Site heraus, z. B. local-mailguard).
 */
final class FileWalker
{
    /** @var string */
    private $absPath;
    /** @var string */
    private $contentDir;
    /** @var string */
    private $skipDir;
    /** @var Scope|null */
    private $scope;

    public function __construct(string $absPath, string $contentDir, string $skipDir, ?Scope $scope = null)
    {
        $this->absPath    = rtrim(str_replace('\\', '/', $absPath), '/');
        $this->contentDir = rtrim(str_replace('\\', '/', $contentDir), '/');
        $this->skipDir    = rtrim(str_replace('\\', '/', $skipDir), '/');
        $this->scope      = $scope;

        if (strpos($this->contentDir . '/', $this->absPath . '/') !== 0) {
            throw new \InvalidArgumentException('wp-content must be inside ABSPATH');
        }
    }

    /**
     * @return array{files: list<array{path: string, size: int, mtime: int}>, skipped: list<array{path: string, size: int}>, next: int|null}
     */
    public function page(int $offset, float $deadline, int $limit = 20000): array
    {
        $files   = [];
        $skipped = [];
        $index   = 0;

        foreach ($this->walk($this->contentDir, true) as $full) {
            if ($index < $offset) {
                $index++;
                continue;
            }
            $delivered = count($files) + count($skipped);
            if ($delivered > 0 && ($delivered >= $limit || microtime(true) > $deadline)) {
                return ['files' => $files, 'skipped' => $skipped, 'next' => $index];
            }

            $size   = (int) @filesize($full);
            $path   = substr($full, strlen($this->absPath) + 1);
            $reason = Excludes::path(substr($full, strlen($this->contentDir) + 1), $size);
            if ($reason === 'too_large') {
                $skipped[] = ['path' => $path, 'size' => $size];
            } elseif ($reason === null) {
                $files[] = ['path' => $path, 'size' => $size, 'mtime' => (int) @filemtime($full)];
            }
            $index++;
        }

        return ['files' => $files, 'skipped' => $skipped, 'next' => null];
    }

    /**
     * @return \Generator<int, string>
     */
    private function walk(string $dir, bool $isTop): \Generator
    {
        $entries = @scandir($dir);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $full = $dir . '/' . $name;
            if (is_link($full)) {
                continue;
            }
            // Profil-Umfang: abgewählte Plugins/Themes und Upload-Jahre (Spec 5.2)
            if ($this->scope !== null && $this->scope->excludesPath(substr($full, strlen($this->contentDir) + 1), is_dir($full))) {
                continue;
            }
            if (is_dir($full)) {
                if ($full === $this->skipDir || Excludes::dir($name, $isTop)) {
                    continue;
                }
                yield from $this->walk($full, false);
                continue;
            }
            yield $full;
        }
    }
}
