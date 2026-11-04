<?php
namespace WpSync;

defined('ABSPATH') || exit;

/**
 * SQL einer Tabelle im Umfang des Pull-Scopes – die eine Stelle für /db, /db-bundle und den
 * Datenbank-Schnappschuss: abgewählte Post-Typen bleiben samt Metadaten auf dem Server
 * (Spec 5.2, AC-14), eigene Optionen nie (AC-27), Personendaten nur als Pseudonym (Spec 11, AC-32).
 * Liest über eine RescueLink: $wpdb (WpdbLink) oder die eigene Verbindung eines Datenbank-Schnappschusses.
 */
final class TableDump
{
    /** @var RescueLink */
    private $link;
    /** @var Scope */
    private $scope;
    /** @var array{posts: string, postmeta: string, term_relationships: string, comments: string} */
    private $core;
    /** @var string */
    private $options;
    /** @var Anonymizer|null */
    private $anonymizer;

    /**
     * @param array{posts: string, postmeta: string, term_relationships: string, comments: string} $core
     * @param Anonymizer|null $anonymizer null = Klartext (plain_pii)
     */
    public function __construct(RescueLink $link, Scope $scope, array $core, string $options, ?Anonymizer $anonymizer)
    {
        $this->link       = $link;
        $this->scope      = $scope;
        $this->core       = $core;
        $this->options    = $options;
        $this->anonymizer = $anonymizer;
    }

    /** DROP TABLE IF EXISTS + CREATE TABLE; null bei einem Lesefehler. */
    public function structure(string $table): ?string
    {
        $rows = $this->link->query('SHOW CREATE TABLE `' . $table . '`');
        if (!is_array($rows) || !isset($rows[0]) || !is_array($rows[0])) {
            return null;
        }
        $row    = array_values($rows[0]);
        $create = isset($row[1]) ? (string) $row[1] : '';
        return $create === '' ? null : SqlBuilder::preamble($table, $create);
    }

    public function primaryKey(string $table): ?string
    {
        return self::primaryKeyOf($this->link, $table);
    }

    /** Name des Primärschlüssels, wenn er aus genau einer Spalte besteht. */
    public static function primaryKeyOf(RescueLink $link, string $table): ?string
    {
        $rows = $link->query('SHOW KEYS FROM `' . $table . "` WHERE Key_name = 'PRIMARY'");
        if (!is_array($rows) || count($rows) !== 1) {
            return null;
        }
        $column = $rows[0]['Column_name'] ?? null;
        return is_string($column) && $column !== '' ? $column : null;
    }

    /**
     * Ein Häppchen; Keyset, wenn $pk gesetzt ist, sonst LIMIT offset (Spike B26). Das erste
     * Häppchen beginnt mit der Struktur.
     *
     * @return array{sql: string, rows: int, next: string|null, keyset: bool}|null null bei einem Lesefehler
     */
    public function chunk(string $table, ?string $pk, ?string $after, int $offset, int $limit): ?array
    {
        $link   = $this->link;
        $escape = static function (string $value) use ($link): string {
            return $link->escape($value);
        };
        $filter = $this->scope->rowFilter($table, $this->core, $escape);
        $where  = implode(' AND ', array_filter([
            $table === $this->options ? "option_name NOT LIKE 'wpsync\\_%'" : '',
            $filter['where'],
        ]));
        $rows = $link->query(SqlBuilder::select($table, $where, $pk, $after, $offset, $limit, $escape, $filter['join']));
        if (!is_array($rows)) {
            return null;
        }
        $last = end($rows); // Cursor aus den echten Werten, bevor Spalten ersetzt werden
        if ($this->anonymizer !== null) {
            $rows = $this->anonymizer->rows($table, $rows);
        }
        $sql = '';
        if ($after === null && $offset === 0) {
            $structure = $this->structure($table);
            if ($structure === null) {
                return null;
            }
            $sql = $structure;
        }
        $sql .= SqlBuilder::inserts($table, array_map('array_values', $rows), $escape);
        return [
            'sql'    => $sql,
            'rows'   => count($rows),
            'next'   => ($pk !== null && is_array($last)) ? (string) $last[$pk] : null,
            'keyset' => $pk !== null,
        ];
    }
}
