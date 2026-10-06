<?php
namespace WpSync;

defined('ABSPATH') || exit;

/**
 * Feste, serverseitige Ausschlüsse. path() ist die einzige Entscheidung und gilt für die
 * Dateiliste (/delta) und den Abruf (/files) gleichermassen (SEC-02). Alle Vergleiche sind
 * case-insensitiv, weil Dateisysteme es oft auch sind (SEC-08).
 */
final class Excludes
{
    /** Nur direkt unter wp-content/ – Plugins haben gleichnamige Unterordner (Spike B12). */
    public const TOP_DIRS = [
        'cache', 'upgrade', 'upgrade-temp-backup', 'wflogs', 'backup', 'backups',
        // Backup-Plugins: riesig und enthalten die komplette Site inkl. DB (Spike B21)
        'duplicator-backups', 'backups-dup-pro', 'backups-dup-lite', 'ai1wm-backups', 'updraft',
        'backupwpup', 'wpvividbackups', 'backup-guard', 'infinitewp',
    ];

    /** Backup-Ordner direkt unter wp-content/ – im Infosheet als Auffälligkeit gemeldet (AC-8). */
    public const BACKUP_DIRS = [
        'duplicator-backups', 'backups-dup-pro', 'backups-dup-lite', 'ai1wm-backups', 'updraft',
        'backupwpup', 'wpvividbackups', 'backup-guard', 'infinitewp',
    ];

    public const ANY_DIRS = ['.git', '.svn', '.hg'];

    /** Dateien mit Zugangsdaten – überall ausgeschlossen, zusätzlich alles, was mit „.env.“ beginnt. */
    public const SECRET_FILES = ['.htpasswd', '.env'];

    /** Direkt unter wp-content/ sind Archive und Dumps Backups. */
    public const TOP_ARCHIVE_SUFFIXES = ['.zip', '.tar', '.tgz', '.gz', '.bz2', '.7z', '.rar', '.sql', '.wpress', '.daf'];

    /** Unterhalb dieser Ordner liefern Plugins und Themes SQL-Dateien als Code aus. */
    public const CODE_DIRS = ['plugins', 'themes', 'mu-plugins'];

    public const MAX_FILE_BYTES = 268435456; // 256 MB

    public static function dir(string $name, bool $isTop): bool
    {
        $name = strtolower($name);
        if (in_array($name, self::ANY_DIRS, true)) {
            return true;
        }
        // wpsync-push-<zufall>: Arbeitsordner mit Snapshots und Rollback-Datensätzen (Spec Stufe 2, AC-71)
        return $isTop && (in_array($name, self::TOP_DIRS, true) || strpos($name, 'backup-') === 0 || strpos($name, 'wpsync-push-') === 0);
    }

    /**
     * @return string|null Grund des Ausschlusses oder null
     */
    public static function file(string $name, int $size): ?string
    {
        if (preg_match('/[\x00-\x1f\x7f]/', $name) === 1) {
            return 'unsafe_name'; // würde die Stream-Rahmen brechen
        }
        $lower = strtolower($name);
        if (substr($lower, -4) === '.log') {
            return 'log';
        }
        if (in_array($lower, self::SECRET_FILES, true) || strpos($lower, '.env.') === 0) {
            return 'secret';
        }
        if ($size > self::MAX_FILE_BYTES) {
            return 'too_large';
        }
        return null;
    }

    /**
     * @param string $rel Pfad relativ zu wp-content, mit „/“ getrennt, ohne führenden Slash
     * @return string|null Grund des Ausschlusses oder null
     */
    public static function path(string $rel, int $size): ?string
    {
        if (preg_match('/[\x00-\x1f\x7f]/', $rel) === 1) {
            return 'unsafe_name';
        }
        $dirs = explode('/', $rel);
        $name = (string) array_pop($dirs);
        foreach ($dirs as $depth => $dir) {
            if (self::dir($dir, $depth === 0)) {
                return 'excluded_dir';
            }
        }
        $lower  = strtolower($name);
        $isDump = substr($lower, -4) === '.sql' || substr($lower, -7) === '.sql.gz';
        $inCode = $dirs !== [] && in_array(strtolower($dirs[0]), self::CODE_DIRS, true);
        if ($isDump && !$inCode) {
            return 'dump';
        }
        if ($dirs === []) {
            foreach (self::TOP_ARCHIVE_SUFFIXES as $suffix) {
                if (substr($lower, -strlen($suffix)) === $suffix) {
                    return 'backup';
                }
            }
        }
        return self::file($name, $size);
    }
}
