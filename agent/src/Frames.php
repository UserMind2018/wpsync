<?php
namespace WpSync;

/**
 * Rahmen für gestreamte Antworten:
 *   "F <path>\t<size>\t<mtime>\n" <bytes> "\n"   Datei
 *   "M <path>\n"                                 Datei fehlt/ungültig
 *   "T <table>\t<rows>\t<bytes>\n" <sql> "\n"   Tabelle
 *   "E\n"                                        Ende
 */
final class Frames
{
    public static function file(string $path, int $size, int $mtime): string
    {
        return 'F ' . $path . "\t" . $size . "\t" . $mtime . "\n";
    }

    public static function missing(string $path): string
    {
        return 'M ' . $path . "\n";
    }

    public static function table(string $name, int $rows, int $bytes): string
    {
        return 'T ' . $name . "\t" . $rows . "\t" . $bytes . "\n";
    }

    public static function end(): string
    {
        return "E\n";
    }
}
