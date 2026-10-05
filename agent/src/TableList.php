<?php
namespace WpSync;

defined('ABSPATH') || exit;

/**
 * Welche Tabellen den Server verlassen dürfen: nur echte Tabellen mit dem eigenen Präfix.
 * Views würden beim Import durch die View in die Basistabelle schreiben (CR-02). Eine zweite
 * Installation in derselben Datenbank, deren Präfix mit dem eigenen beginnt (wp_ / wp_stg_),
 * und jede wpsync-Tabelle – dort liegen Secrets – bleiben draussen (SEC-05).
 */
final class TableList
{
    private const OWN = '/wpsync_(pairings|nonces|state|pushes)\z/';

    /**
     * @param list<array{0: string, 1: string}> $rows Zeilen aus SHOW FULL TABLES: Name, Typ
     * @return list<string>
     */
    public static function filter(array $rows, string $prefix): array
    {
        $names = [];
        foreach ($rows as $row) {
            $name = (string) $row[0];
            if (strtoupper((string) $row[1]) === 'BASE TABLE' && ($prefix === '' || strpos($name, $prefix) === 0)) {
                $names[] = $name;
            }
        }

        $foreign = self::nestedPrefixes($names, $prefix);
        $out     = [];
        foreach ($names as $name) {
            if (preg_match(self::OWN, $name) === 1) {
                continue;
            }
            foreach ($foreign as $nested) {
                if (strpos($name, $nested) === 0) {
                    continue 2;
                }
            }
            $out[] = $name;
        }
        return $out;
    }

    /**
     * Präfixe verschachtelter Installationen: es gibt <p>options, <p>posts und <p>postmeta.
     *
     * @param list<string> $names
     * @return list<string>
     */
    private static function nestedPrefixes(array $names, string $prefix): array
    {
        $known = array_flip($names);
        $out   = [];
        foreach ($names as $name) {
            if (substr($name, -7) !== 'options') {
                continue;
            }
            $candidate = substr($name, 0, -7);
            if (strlen($candidate) > strlen($prefix) && isset($known[$candidate . 'posts'], $known[$candidate . 'postmeta'])) {
                $out[] = $candidate;
            }
        }
        return $out;
    }
}
