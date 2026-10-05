<?php
namespace WpSync;

defined('ABSPATH') || exit;

/**
 * Was ein Push anfassen darf (Spec Stufe 2, 5.4). Fest im Agent, nicht per CLI änderbar:
 * eine Einheit ist ein ganzes Plugin, ein ganzes Theme oder mu-plugins – nie der Agent selbst.
 */
final class PushUnits
{
    public const MU = 'mu-plugins';

    public static function valid(string $unit): bool
    {
        if ($unit === self::MU) {
            return true;
        }
        if (preg_match('#^(plugins|themes)/[A-Za-z0-9][A-Za-z0-9._-]*\z#', $unit) !== 1) {
            return false;
        }
        return strtolower($unit) !== 'plugins/wpsync-agent';
    }

    /** Pfad einer Datei relativ zur Einheit, wie ihn der Client im Manifest und im Upload nennt. */
    public static function validFile(string $unit, string $rel): bool
    {
        if ($rel === '' || strlen($rel) > 1024 || preg_match('/[\x00-\x1f\x7f\\\\]/', $rel) === 1) {
            return false;
        }
        foreach (explode('/', $rel) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || in_array(strtolower($segment), Excludes::ANY_DIRS, true)) {
                return false;
            }
        }
        return !self::isProtected($unit, $rel);
    }

    /**
     * Der lokale Mail-Riegel würde auf Live den Mailversand blockieren; wpsync-eigene Dateien
     * gehören dem Agent (P11). Beides bleibt beim Tausch unverändert stehen.
     */
    public static function isProtected(string $unit, string $rel): bool
    {
        if ($unit !== self::MU) {
            return false;
        }
        $top = strtolower(explode('/', $rel)[0]);
        return $top === '00-local-mailguard.php' || strpos($top, 'wpsync') === 0;
    }

    /** Version aus dem Plugin-Kopf bzw. der style.css; leer, wenn es keinen gibt. */
    public static function version(string $dir, string $unit): string
    {
        if ($unit === self::MU || !is_dir($dir)) {
            return '';
        }
        $isTheme    = strpos($unit, 'themes/') === 0;
        $marker     = $isTheme ? 'Theme Name:' : 'Plugin Name:';
        $candidates = $isTheme ? [$dir . '/style.css'] : (glob($dir . '/*.php') ?: []);
        foreach ($candidates as $file) {
            $head = (string) @file_get_contents($file, false, null, 0, 8192);
            if (stripos($head, $marker) !== false && preg_match('/^[ \t\/*#@]*Version:[ \t]*(\S+)/mi', $head, $m) === 1) {
                return $m[1];
            }
        }
        return '';
    }
}
