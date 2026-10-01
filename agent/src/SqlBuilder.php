<?php
namespace WpSync;

defined('ABSPATH') || exit;

final class SqlBuilder
{
    /**
     * @param list<array<int|string, string|null>> $rows
     * @param callable(string): string             $escape
     */
    public static function inserts(string $table, array $rows, callable $escape, int $batch = 200): string
    {
        $sql    = '';
        $values = [];
        foreach ($rows as $row) {
            $cells = [];
            foreach ($row as $value) {
                $cells[] = $value === null ? 'NULL' : "'" . $escape((string) $value) . "'";
            }
            $values[] = '(' . implode(',', $cells) . ')';
            if (count($values) >= $batch) {
                $sql   .= self::insert($table, $values);
                $values = [];
            }
        }
        if ($values) {
            $sql .= self::insert($table, $values);
        }
        return $sql;
    }

    public static function preamble(string $table, string $createStatement): string
    {
        return 'DROP TABLE IF EXISTS `' . $table . "`;\n" . $createStatement . ";\n";
    }

    /**
     * Keyset (Primärschlüssel > letzter Wert) belastet die DB bei jedem Chunk gleich stark;
     * LIMIT offset nur als Rückfall für Tabellen ohne einspaltigen Primärschlüssel (Spike B26).
     * Mit $join wird die Tabelle als „t“ angesprochen; STRAIGHT_JOIN hält die Leserichtung
     * entlang des Primärschlüssels von t, statt die Join-Tabelle zuerst zu scannen.
     *
     * @param callable(string): string $escape
     */
    public static function select(
        string $table,
        string $where,
        ?string $keysetColumn,
        ?string $after,
        int $offset,
        int $limit,
        callable $escape,
        string $join = ''
    ): string {
        $alias      = $join !== '' ? 't.' : '';
        $conditions = $where !== '' ? [$where] : [];
        if ($keysetColumn !== null && $after !== null) {
            $conditions[] = $alias . '`' . $keysetColumn . "` > '" . $escape($after) . "'";
        }

        $sql = $join !== ''
            ? 'SELECT STRAIGHT_JOIN t.* FROM `' . $table . '` t ' . $join
            : 'SELECT * FROM `' . $table . '`';
        if ($conditions) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }
        if ($keysetColumn !== null) {
            return $sql . ' ORDER BY ' . $alias . '`' . $keysetColumn . '` LIMIT ' . $limit;
        }
        return $sql . ' LIMIT ' . $offset . ', ' . $limit;
    }

    /**
     * @param list<string> $values
     */
    private static function insert(string $table, array $values): string
    {
        return 'INSERT INTO `' . $table . '` VALUES ' . implode(",\n", $values) . ";\n";
    }
}
