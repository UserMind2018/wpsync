<?php
namespace WpSync;

final class Excludes
{
    /** Nur direkt unter wp-content/ – Plugins haben gleichnamige Unterordner (Spike B12). */
    public const TOP_DIRS = [
        'cache', 'upgrade', 'upgrade-temp-backup', 'wflogs',
        // Backup-Plugins: riesig und enthalten die komplette Site inkl. DB (Spike B21)
        'duplicator-backups', 'backups-dup-pro', 'backups-dup-lite', 'ai1wm-backups', 'updraft',
        'backupwpup', 'wpvividbackups', 'backup-guard', 'infinitewp',
    ];

    /** Backup-Ordner direkt unter wp-content/ – im Infosheet als Auffälligkeit gemeldet (AC-8). */
    public const BACKUP_DIRS = [
        'duplicator-backups', 'backups-dup-pro', 'backups-dup-lite', 'ai1wm-backups', 'updraft',
        'backupwpup', 'wpvividbackups', 'backup-guard', 'infinitewp',
    ];

    public const ANY_DIRS = ['.git'];

    public const MAX_FILE_BYTES = 268435456; // 256 MB

    public static function dir(string $name, bool $isTop): bool
    {
        return in_array($name, self::ANY_DIRS, true) || ($isTop && in_array($name, self::TOP_DIRS, true));
    }

    /**
     * @return string|null Grund des Ausschlusses oder null
     */
    public static function file(string $name, int $size): ?string
    {
        if (strpbrk($name, "\t\n\r") !== false) {
            return 'unsafe_name'; // würde die Stream-Rahmen brechen
        }
        if (substr($name, -4) === '.log') {
            return 'log';
        }
        if ($size > self::MAX_FILE_BYTES) {
            return 'too_large';
        }
        return null;
    }
}
