<?php
namespace WpSync;

defined('ABSPATH') || defined('WPSYNC_RESCUE') || exit;

/**
 * Baut das neue Verzeichnis einer Einheit neben dem alten und tauscht per rename (Spec Stufe 2,
 * 5.3). Reine Dateisystem-Arbeit ohne WordPress – rescue.php nutzt restore() auch dann, wenn
 * der gepushte Code WordPress lahmlegt.
 */
final class PushSwap
{
    /**
     * Legt die Dateien des Soll-Manifests nach $new: hochgeladene aus $stage, alle anderen aus
     * $old. Danach die übernommenen Dateien (U4). Jede Manifest-Datei wird gegen Grösse und
     * sha256 geprüft (AC-60). Arbeitet bis $deadline, mindestens aber eine Datei.
     *
     * @param array<string, array{size: int, sha256: string}> $files
     * @param list<string>                                    $carried
     * @return int|null Index, bei dem der nächste Aufruf fortsetzt; null, wenn alles liegt
     * @throws \RuntimeException wenn eine Datei fehlt oder nicht dem Manifest entspricht
     */
    public static function build(string $old, string $stage, string $new, array $files, array $carried, int $from, float $deadline): ?int
    {
        $rels  = array_merge(array_map('strval', array_keys($files)), $carried);
        $count = count($files);
        if ($from === 0) {
            self::mkdir($new, is_dir($old) ? fileperms($old) & 0777 : 0755);
        }
        for ($i = $from; $i < count($rels); $i++) {
            if ($i > $from && microtime(true) > $deadline) {
                return $i;
            }
            $rel    = $rels[$i];
            $before = $old . '/' . $rel;
            $to     = $new . '/' . $rel;
            self::mkdir(dirname($to), is_dir(dirname($before)) ? fileperms(dirname($before)) & 0777 : 0755);

            if ($i >= $count) {
                self::carry($before, $to);
                continue;
            }
            $staged = $stage . '/' . $rel;
            $source = is_file($staged) ? $staged : $before;
            if (!is_file($source) || is_link($source) || !@copy($source, $to)) {
                throw new \RuntimeException('cannot place ' . $rel);
            }
            $want = $files[$rel];
            if ((int) filesize($to) !== (int) $want['size'] || !hash_equals((string) $want['sha256'], (string) hash_file('sha256', $to))) {
                throw new \RuntimeException('content differs from the manifest: ' . $rel);
            }
            chmod($to, is_file($before) && !is_link($before) ? fileperms($before) & 0777 : 0644);
            touch($to, (int) filemtime($source));
        }
        return null;
    }

    /**
     * Zwei rename: alt → Snapshot, neu → Ziel. Dazwischen fehlt das Ziel für einen Moment.
     *
     * @throws \RuntimeException – das alte Verzeichnis liegt dann wieder an seinem Platz
     */
    public static function swap(string $target, string $new, ?string $snapshot): void
    {
        if ($snapshot !== null && !@rename($target, $snapshot)) {
            throw new \RuntimeException('cannot move the current directory aside');
        }
        if (!@rename($new, $target)) {
            if ($snapshot !== null) {
                @rename($snapshot, $target);
            }
            throw new \RuntimeException('cannot move the new directory into place');
        }
    }

    /**
     * Macht swap() rückgängig. Ohne Snapshot (neue Einheit) verschwindet das Ziel nach $discard.
     */
    public static function restore(string $target, ?string $snapshot, string $discard): bool
    {
        if ($snapshot !== null && !is_dir($snapshot)) {
            return false;
        }
        if (is_dir($target) && !@rename($target, $discard)) {
            return false;
        }
        if ($snapshot !== null && !@rename($snapshot, $target)) {
            @rename($discard, $target);
            return false;
        }
        return true;
    }

    /**
     * Die Einträge von $dir, deren Name auf $pattern passt – volle Pfade, nach Namen sortiert, nie
     * „.“ und „..“. Ohne glob(): dort sind [ ] * ? auch im Pfad davor Muster, und unter einem
     * Webroot wie /kunden/[alt]/htdocs fände es nichts.
     *
     * @param string $pattern regulärer Ausdruck für den Namen allein
     * @return list<string>
     */
    public static function entries(string $dir, string $pattern): array
    {
        $names = @scandir($dir);
        $out   = [];
        foreach ($names === false ? [] : $names as $name) {
            if ($name !== '.' && $name !== '..' && preg_match($pattern, $name) === 1) {
                $out[] = $dir . '/' . $name;
            }
        }
        return $out;
    }

    /** Löscht rekursiv; Symlinks werden entfernt, nie verfolgt. */
    public static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        $entries = @scandir($path);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $name) {
            if ($name !== '.' && $name !== '..') {
                self::remove($path . '/' . $name);
            }
        }
        @rmdir($path);
    }

    /**
     * Darf der Agent ein Verzeichnis aus dem Arbeitsordner nach $parent umbenennen und zurück?
     * Deckt fehlende Rechte und verschiedene Dateisysteme ab (P14).
     */
    public static function probe(string $workDir, string $parent): bool
    {
        $name = '.wpsync-probe-' . bin2hex(random_bytes(4));
        $here = $workDir . '/' . $name;
        $there = $parent . '/' . $name;
        if (!@mkdir($here)) {
            return false;
        }
        $ok = @rename($here, $there) && @rename($there, $here);
        @rmdir($here);
        @rmdir($there);
        return $ok;
    }

    /** Nach einem Tausch liegt unter demselben Pfad anderer Code – alter Bytecode muss weg. */
    public static function resetCaches(): void
    {
        clearstatcache(true);
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
    }

    private static function mkdir(string $dir, int $mode): void
    {
        if (is_dir($dir)) {
            return;
        }
        if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('cannot create ' . basename($dir));
        }
        chmod($dir, $mode);
    }

    private static function carry(string $from, string $to): void
    {
        if (is_link($from)) {
            @symlink((string) readlink($from), $to);
            return;
        }
        if (is_file($from) && @copy($from, $to)) {
            chmod($to, fileperms($from) & 0777);
            touch($to, (int) filemtime($from));
        }
    }
}
