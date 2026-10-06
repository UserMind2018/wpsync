<?php
namespace WpSync;

defined('ABSPATH') || exit;

/**
 * Code der Staging-Kopie (Spec Stufe 2b 5.2 Phase 3): WordPress-Core und die Einheiten des
 * Pull-Profils – nie wp-config.php, Drop-ins, uploads, den Agent, Symlinks, VCS-Ordner, Logs oder
 * Dateien mit Zugangsdaten. Kopieren und Löschen arbeiten bis zu einer Deadline, mindestens aber
 * eine Datei, und setzen an einem Cursor fort. Jeder Zielpfad läuft durch $check (StagingGuard::path).
 */
final class StagingFiles
{
    public const CORE_DIRS = ['wp-admin', 'wp-includes'];
    /** Ohne wp-config.php in der Kopie wäre der Installer ein offenes Scheunentor (V7). */
    public const NEVER = ['wp-admin/setup-config.php'];
    private const MU_SKIP = ['00-local-mailguard.php', '00-wpsync-staging.php', 'wpsync-staging'];

    /** @return list<string> relativ zu $absPath, sortiert */
    public static function coreItems(string $absPath): array
    {
        $out = [];
        foreach (self::entries($absPath) as $name) {
            $full = $absPath . '/' . $name;
            if (is_link($full)) {
                continue;
            }
            $isCoreFile = $name === 'index.php' || $name === 'xmlrpc.php' || (preg_match('/^wp-[a-z-]+\.php\z/', $name) === 1 && $name !== 'wp-config.php');
            if ((is_file($full) && $isCoreFile) || (is_dir($full) && in_array($name, self::CORE_DIRS, true))) {
                $out[] = $name;
            }
        }
        return $out;
    }

    /**
     * Einheiten unter wp-content nach Umfang des Profils, relativ zu ABSPATH.
     *
     * @return list<string>
     */
    public static function contentItems(string $contentDir, Scope $scope): array
    {
        $out = [];
        if (is_file($contentDir . '/index.php')) {
            $out[] = 'wp-content/index.php';
        }
        foreach (['plugins', 'themes'] as $top) {
            foreach (self::entries($contentDir . '/' . $top) as $name) {
                $full = $contentDir . '/' . $top . '/' . $name;
                if (is_link($full) || ($top === 'plugins' && strtolower($name) === 'wpsync-agent') || $scope->excludesPath($top . '/' . $name, is_dir($full))) {
                    continue;
                }
                $out[] = 'wp-content/' . $top . '/' . $name;
            }
        }
        foreach (self::entries($contentDir . '/mu-plugins') as $name) {
            if (!is_link($contentDir . '/mu-plugins/' . $name) && !in_array(strtolower($name), self::MU_SKIP, true)) {
                $out[] = 'wp-content/mu-plugins/' . $name;
            }
        }
        if (is_dir($contentDir . '/languages') && !is_link($contentDir . '/languages')) {
            $out[] = 'wp-content/languages';
        }
        return $out;
    }

    /**
     * @param list<string>            $items  relativ zu $from
     * @param array{0: int, 1: string} $cursor [Item, zuletzt kopierte Datei relativ zum Item]
     * @param callable(string): string $check  prüft jeden Zielpfad
     * @return array{next: array{0: int, 1: string}|null, files: int}
     */
    public static function copy(string $from, string $to, array $items, array $cursor, float $deadline, callable $check): array
    {
        $files = 0;
        for ($i = $cursor[0]; $i < count($items); $i++) {
            $after = $i === $cursor[0] ? $cursor[1] : '';
            $src   = $from . '/' . $items[$i];
            if (is_link($src)) {
                continue;
            }
            if (is_file($src)) {
                if ($files > 0 && microtime(true) > $deadline) {
                    return ['next' => [$i, ''], 'files' => $files];
                }
                if ($after === '') {
                    self::file($src, $to . '/' . $items[$i], $check);
                    $files++;
                }
                continue;
            }
            $last = $after;
            foreach (self::walk($src, $after) as $rel) {
                if (in_array($items[$i] . '/' . $rel, self::NEVER, true)) {
                    continue;
                }
                if ($files > 0 && microtime(true) > $deadline) {
                    return ['next' => [$i, $last], 'files' => $files];
                }
                self::file($src . '/' . $rel, $to . '/' . $items[$i] . '/' . $rel, $check);
                $last = $rel;
                $files++;
            }
        }
        return ['next' => null, 'files' => $files];
    }

    /**
     * Bytes der Dateien, die copy() kopieren würde; null, wenn die Zeit nicht reicht (V17).
     *
     * @param list<string> $items
     */
    public static function size(string $from, array $items, float $deadline): ?int
    {
        $bytes = 0;
        foreach ($items as $item) {
            $src = $from . '/' . $item;
            if (is_link($src)) {
                continue;
            }
            if (microtime(true) > $deadline) {
                return null;
            }
            if (is_file($src)) {
                $bytes += (int) @filesize($src);
                continue;
            }
            foreach (self::walk($src, '') as $rel) {
                if (microtime(true) > $deadline) {
                    return null;
                }
                $bytes += (int) @filesize($src . '/' . $rel);
            }
        }
        return $bytes;
    }

    /**
     * Löscht rekursiv bis $deadline (mindestens einen Eintrag); Symlinks werden entfernt, nie verfolgt.
     *
     * @param callable(string): string $check
     * @return bool true, wenn $path weg ist
     */
    public static function remove(string $path, float $deadline, callable $check): bool
    {
        $removed = 0;
        return self::removeBounded($path, $deadline, $check, $removed);
    }

    /** Legt einen Ordner samt Eltern an, geprüft. */
    public static function mkdir(string $dir, callable $check): void
    {
        $dir = $check($dir);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw StagingException::failed('Ordner nicht anlegbar: ' . basename($dir));
        }
    }

    private static function removeBounded(string $path, float $deadline, callable $check, int &$removed): bool
    {
        $path = $check($path);
        if (is_link($path) || is_file($path)) {
            if ($removed > 0 && microtime(true) > $deadline) {
                return false;
            }
            @unlink($path);
            $removed++;
            return true;
        }
        if (!is_dir($path)) {
            return true;
        }
        foreach (self::entries($path) as $name) {
            if (!self::removeBounded($path . '/' . $name, $deadline, $check, $removed)) {
                return false;
            }
        }
        if (!@rmdir($path) && is_dir($path)) {
            throw StagingException::failed('Ordner nicht löschbar: ' . basename($path));
        }
        $removed++;
        return true;
    }

    /** @throws StagingException */
    private static function file(string $src, string $dst, callable $check): void
    {
        $dst = $check($dst);
        self::mkdir(dirname($dst), $check);
        if (!@copy($src, $dst)) {
            $free = function_exists('disk_free_space') ? @disk_free_space(dirname($dst)) : false;
            if ($free !== false && $free < (int) @filesize($src) + 1048576) {
                throw StagingException::space('Kein Platz mehr auf dem Server beim Kopieren von ' . basename($src));
            }
            throw StagingException::failed('Datei nicht kopierbar: ' . basename($src));
        }
        @chmod($dst, fileperms($src) & 0777);
        @touch($dst, (int) filemtime($src));
    }

    /**
     * Dateien unter $dir in strcmp-Reihenfolge, relativ zu $dir, nur die hinter $after. Keine
     * Symlinks, keine VCS-Ordner, keine Logs und Dateien mit Zugangsdaten (Excludes::file).
     *
     * @return \Generator<int, string>
     */
    private static function walk(string $dir, string $after, string $prefix = ''): \Generator
    {
        $parts = explode('/', $after, 2);
        foreach (self::entries($dir) as $name) {
            $cmp = $after === '' ? 1 : strcmp($name, $parts[0]);
            if ($cmp < 0) {
                continue;
            }
            $full = $dir . '/' . $name;
            if (is_link($full)) {
                continue;
            }
            if (is_dir($full)) {
                if (!in_array(strtolower($name), Excludes::ANY_DIRS, true)) {
                    yield from self::walk($full, $cmp === 0 ? ($parts[1] ?? '') : '', $prefix . $name . '/');
                }
                continue;
            }
            if ($cmp > 0 && Excludes::file($name, (int) @filesize($full)) === null) {
                yield $prefix . $name;
            }
        }
    }

    /** @return list<string> Einträge ohne . und .., byteweise sortiert */
    private static function entries(string $dir): array
    {
        $entries = @scandir($dir);
        if ($entries === false) {
            return [];
        }
        $entries = array_values(array_diff($entries, ['.', '..']));
        sort($entries, SORT_STRING);
        return $entries;
    }
}
