<?php
namespace WpSync;

defined('ABSPATH') || defined('WPSYNC_RESCUE') || exit;

/**
 * Liest die sieben Inhaltstabellen einer Site in Keyset-Schritten und liefert je Zeile bzw. je
 * Meta-Paar und je Zuordnung einer Taxonomie einen Datensatz {t, k, h[, row]} in normalisierter,
 * kanonischer Form (Spec Content-Push §4, §6.1). Dieselbe Datei rechnet auf Live (Manifest) und in
 * der Arbeitskopie (wpsync content export, C1) – sie benutzt deshalb nur das übergebene
 * $wpdb-Objekt und keine WordPress-Funktion. Meta und Zuordnungen werden objektweise gelesen, nie
 * die ganze Tabelle (Messung Q3). Ein Datensatz ohne Abdruck trägt h null und why:
 * unnormalizable, key_encoding oder – nur mit $hidePseudonymized – pseudonymized.
 */
final class ContentReader
{
    public const POSTS_PER_STEP     = 200;
    public const ROWS_PER_STEP      = 500;
    public const OBJECTS_PER_STEP   = 25;
    public const RELATIONS_PER_STEP = 200;

    /** @var object $wpdb */
    private $db;
    /** @var string */
    private $prefix;
    /** @var ContentOrigin */
    private $origin;
    /** @var list<string> */
    private $excluded;
    /** @var bool */
    private $hidePseudonymized;

    /**
     * @param object       $db                $wpdb der Site
     * @param string       $prefix            Tabellen-Präfix (auf Staging das der Kopie)
     * @param list<string> $excludedPostTypes Beitragstypen, die der Pull-Scope auslässt
     * @param bool         $hidePseudonymized Zeilen, die der Pull pseudonymisiert (Anonymizer::touches),
     *                                        bekommen weder Abdruck noch Werte: h null, why "pseudonymized".
     *                                        Für das Manifest eines pseudonymisierenden Pulls – ein Abdruck
     *                                        des echten Werts liesse sich offline erraten. Der lokale Export
     *                                        liest schon Pseudonyme und lässt den Schalter aus.
     */
    public function __construct($db, string $prefix, ContentOrigin $origin, array $excludedPostTypes = [], bool $hidePseudonymized = false)
    {
        $this->db       = $db;
        $this->prefix   = $prefix;
        $this->origin   = $origin;
        $this->excluded = array_values($excludedPostTypes);
        $this->hidePseudonymized = $hidePseudonymized;
    }

    /**
     * Liest bis zum Ende, bis $deadline oder bis $limit Datensätze ausgegeben sind – mindestens
     * einen Schritt, und jeden angefangenen Schritt zu Ende: der Cursor liegt immer auf einer
     * Schrittgrenze, eine Seite hat höchstens einen Schritt mehr als $limit.
     *
     * @param array{t?: mixed, a?: mixed}|null   $cursor null: von vorn
     * @param callable(array<string, mixed>): void $emit
     * @param list<string>|null                  $tables Tabellen ohne Präfix; null: alle sieben
     * @param int                                $limit  Datensätze je Aufruf; 0: nur $deadline begrenzt
     * @return array{t: int, a: string}|null Cursor für den nächsten Aufruf; null: fertig
     * @throws \RuntimeException bei einem Datenbankfehler
     */
    public function read(?array $cursor, float $deadline, bool $rows, callable $emit, ?array $tables = null, int $limit = 0): ?array
    {
        $t     = max(0, (int) ($cursor['t'] ?? 0));
        $after = (string) ($cursor['a'] ?? '');
        $count = 0;
        $counted = static function (array $record) use ($emit, &$count): void {
            $count++;
            $emit($record);
        };
        for (; $t < count(Canon::TABLES); $t++, $after = '') {
            $table = Canon::TABLES[$t];
            if ($tables !== null && !in_array($table, $tables, true)) {
                continue;
            }
            while (true) {
                $next = $this->step($table, (int) $after, $rows, $counted);
                if ($next === null) {
                    break;
                }
                $after = $next;
                if (microtime(true) > $deadline || ($limit > 0 && $count >= $limit)) {
                    return ['t' => $t, 'a' => $after];
                }
            }
        }
        return null;
    }

    /** @return string|null letzter gelesener Schlüssel; null: die Tabelle ist zu Ende */
    private function step(string $table, int $after, bool $rows, callable $emit): ?string
    {
        switch ($table) {
            case 'posts':
                return $this->posts($after, $rows, $emit);
            case 'postmeta':
                return $this->meta('postmeta', 'post_id', $after, $rows, $emit);
            case 'termmeta':
                return $this->meta('termmeta', 'term_id', $after, $rows, $emit);
            case 'terms':
                return $this->simple('terms', 'term_id', Canon::TERMS, $after, $rows, $emit);
            case 'term_taxonomy':
                return $this->simple('term_taxonomy', 'term_taxonomy_id', Canon::TAXONOMY, $after, $rows, $emit);
            case 'term_relationships':
                return $this->relationships($after, $rows, $emit);
            case 'options':
                return $this->options($after, $rows, $emit);
        }
        return null;
    }

    private function posts(int $after, bool $rows, callable $emit): ?string
    {
        list($where, $args) = $this->typeFilter('`post_type`');
        $found = $this->results(
            'SELECT `ID`, `' . implode('`, `', Canon::POSTS) . '` FROM ' . $this->table('posts')
            . ' WHERE `ID` > %d' . $where . ' ORDER BY `ID` LIMIT %d',
            array_merge([$after], $args, [self::POSTS_PER_STEP])
        );
        foreach ($found as $row) {
            $emit($this->hidden('posts', (string) $row['ID'], $row) ?? $this->columns('posts', (string) $row['ID'], Canon::POSTS, $row, $rows));
        }
        return count($found) < self::POSTS_PER_STEP ? null : (string) $found[count($found) - 1]['ID'];
    }

    /** @param list<string> $names */
    private function simple(string $table, string $pk, array $names, int $after, bool $rows, callable $emit): ?string
    {
        $found = $this->results(
            'SELECT `' . $pk . '`, `' . implode('`, `', array_diff($names, [$pk])) . '` FROM ' . $this->table($table)
            . ' WHERE `' . $pk . '` > %d ORDER BY `' . $pk . '` LIMIT %d',
            [$after, self::ROWS_PER_STEP]
        );
        foreach ($found as $row) {
            $emit($this->hidden($table, (string) $row[$pk], $row) ?? $this->columns($table, (string) $row[$pk], $names, $row, $rows));
        }
        return count($found) < self::ROWS_PER_STEP ? null : (string) $found[count($found) - 1][$pk];
    }

    private function options(int $after, bool $rows, callable $emit): ?string
    {
        $found = $this->results(
            'SELECT `option_id`, `option_name`, `option_value` FROM ' . $this->table('options')
            . ' WHERE `option_id` > %d AND `option_name` NOT LIKE %s AND `option_name` NOT LIKE %s AND `option_name` NOT LIKE %s'
            . ' ORDER BY `option_id` LIMIT %d',
            [$after, 'wpsync\\_%', '\\_transient\\_%', '\\_site\\_transient\\_%', self::ROWS_PER_STEP]
        );
        foreach ($found as $row) {
            $emit($this->hidden('options', (string) $row['option_name'], ['option_name' => $row['option_name']])
                ?? $this->columns('options', (string) $row['option_name'], ['option_value'], $row, $rows));
        }
        return count($found) < self::ROWS_PER_STEP ? null : (string) $found[count($found) - 1]['option_id'];
    }

    private function meta(string $table, string $column, int $after, bool $rows, callable $emit): ?string
    {
        $join = '';
        $where = '';
        $args = [];
        if ($table === 'postmeta' && $this->excluded !== []) {
            // Wie /db: Meta nur mit Eltern-Beitrag eines gewählten Typs (Scope::rowFilter).
            list($where, $args) = $this->typeFilter('p.`post_type`');
            $join = ' JOIN ' . $this->table('posts') . ' p ON p.`ID` = m.`post_id`';
        }
        $m   = $this->table($table) . ' m';
        $col = 'm.`' . $column . '`';
        $ids = $this->column(
            'SELECT DISTINCT ' . $col . ' FROM ' . $m . $join . ' WHERE ' . $col . ' > %d' . $where . ' ORDER BY ' . $col . ' LIMIT %d',
            array_merge([$after], $args, [self::OBJECTS_PER_STEP])
        );
        if ($ids === []) {
            return null;
        }
        $last  = (int) $ids[count($ids) - 1];
        $found = $this->results(
            'SELECT ' . $col . ' AS o, m.`meta_key` AS k, m.`meta_value` AS v FROM ' . $m . $join
            . ' WHERE ' . $col . ' > %d AND ' . $col . ' <= %d' . $where . ' ORDER BY ' . $col . ', m.`meta_id`',
            array_merge([$after, $last], $args)
        );
        foreach ($this->group($found, 'o', 'k', 'v') as $pair) {
            $emit($this->hidden($table, Canon::pairKey($pair[0], $pair[1]), [$column => $pair[0], 'meta_key' => $pair[1]])
                ?? $this->set($table, $pair[0], $pair[1], $pair[2], true, $rows));
        }
        return count($ids) < self::OBJECTS_PER_STEP ? null : (string) $last;
    }

    private function relationships(int $after, bool $rows, callable $emit): ?string
    {
        $join = '';
        $where = '';
        $args = [];
        if ($this->excluded !== []) {
            // Wie /db: Zuordnungen ohne Beitrag (Links) bleiben, die abgewählter Typen nicht.
            list($filter, $args) = $this->typeFilter('p.`post_type`');
            $join  = ' LEFT JOIN ' . $this->table('posts') . ' p ON p.`ID` = r.`object_id`';
            $where = ' AND (p.`ID` IS NULL OR (1 = 1' . $filter . '))';
        }
        $r   = $this->table('term_relationships') . ' r';
        $ids = $this->column(
            'SELECT DISTINCT r.`object_id` FROM ' . $r . $join . ' WHERE r.`object_id` > %d' . $where . ' ORDER BY r.`object_id` LIMIT %d',
            array_merge([$after], $args, [self::RELATIONS_PER_STEP])
        );
        if ($ids === []) {
            return null;
        }
        $last  = (int) $ids[count($ids) - 1];
        $found = $this->results(
            'SELECT r.`object_id` AS o, r.`term_taxonomy_id` AS tt, r.`term_order` AS ord, x.`taxonomy` AS tax FROM ' . $r
            . ' JOIN ' . $this->table('term_taxonomy') . ' x ON x.`term_taxonomy_id` = r.`term_taxonomy_id`' . $join
            . ' WHERE r.`object_id` > %d AND r.`object_id` <= %d' . $where . ' ORDER BY r.`object_id`, r.`term_taxonomy_id`',
            array_merge([$after, $last], $args)
        );
        foreach ($found as $i => $row) {
            $found[$i]['entry'] = $row['tt'] . ':' . $row['ord'];
        }
        foreach ($this->group($found, 'o', 'tax', 'entry') as $pair) {
            $emit($this->hidden('term_relationships', Canon::pairKey($pair[0], $pair[1]), ['object_id' => $pair[0]])
                ?? $this->set('term_relationships', $pair[0], $pair[1], $pair[2], false, $rows));
        }
        return count($ids) < self::RELATIONS_PER_STEP ? null : (string) $last;
    }

    /**
     * Der Datensatz ohne Abdruck für eine Zeile, die der Pull pseudonymisiert – null, wenn der
     * Schalter aus ist oder keine Regel des Anonymizers die Zeile trifft.
     *
     * @param array<string, string|null> $row die Spalten, an denen die Regeln die Zeile erkennen
     * @return array<string, mixed>|null
     */
    private function hidden(string $table, string $key, array $row): ?array
    {
        return $this->hidePseudonymized && Anonymizer::touches($table, $row) ? self::without($table, $key, 'pseudonymized') : null;
    }

    /**
     * Datensatz einer Zeile mit festen Spalten (posts, terms, term_taxonomy, options).
     *
     * @param list<string>               $names
     * @param array<string, string|null> $row
     * @return array<string, mixed>
     */
    public function columns(string $table, string $key, array $names, array $row, bool $rows): array
    {
        $normal = [];
        foreach ($names as $name) {
            $value = $row[$name] ?? null;
            if ($value !== null) {
                $value = $this->normal((string) $value);
                if ($value === null) {
                    return self::without($table, $key, 'unnormalizable');
                }
            }
            $normal[$name] = $value;
        }
        return self::record($table, $key, Canon::columns($names, $normal), $rows ? array_map([self::class, 'encode'], $normal) : null);
    }

    /**
     * Datensatz einer Menge: alle Werte eines Meta-Paars oder alle Zuordnungen einer Taxonomie.
     *
     * @param list<string|null> $values
     * @param bool              $normalize Meta-Werte ja, Zuordnungen (<tt_id>:<order>) nein
     * @return array<string, mixed>
     */
    public function set(string $table, string $objectId, string $name, array $values, bool $normalize, bool $rows): array
    {
        $key = Canon::pairKey($objectId, $name);
        if ($normalize) {
            foreach ($values as $i => $value) {
                if ($value === null) {
                    continue;
                }
                $values[$i] = $this->normal((string) $value);
                if ($values[$i] === null) {
                    return self::without($table, $key, 'unnormalizable');
                }
            }
        }
        $encoded = null;
        if ($rows) {
            $sorted = $values;
            usort($sorted, static function ($a, $b): int {
                return strcmp(Canon::value($a), Canon::value($b));
            });
            $encoded = ['values' => array_map([self::class, 'encode'], $sorted)];
        }
        return self::record($table, $key, Canon::set($values), $encoded);
    }

    /**
     * Normalisiert einen Wert. Scheitert das mit einem Fehler statt mit null, kostet es trotzdem nur
     * den Abdruck dieser Zeile – nie die Seite. Was der Fehler war, bleibt hier.
     */
    private function normal(string $value): ?string
    {
        try {
            return $this->origin->normalize($value);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** @param string|null $value */
    public static function encode($value): ?string
    {
        return $value === null ? null : base64_encode((string) $value);
    }

    /**
     * @param array<string, mixed>|null $row
     * @return array<string, mixed>
     */
    private static function record(string $table, string $key, string $body, ?array $row): array
    {
        if (preg_match('//u', $key) !== 1) {
            return self::without($table, $key, 'key_encoding');
        }
        $record = ['t' => $table, 'k' => $key, 'h' => Canon::hash($table, $key, $body)];
        if ($row !== null) {
            $record['row'] = $row;
        }
        return $record;
    }

    /** @return array<string, mixed> Datensatz ohne Abdruck – für den Push gesperrt */
    private static function without(string $table, string $key, string $why): array
    {
        return ['t' => $table, 'k' => $key, 'h' => null, 'why' => $why];
    }

    /**
     * Fasst Zeilen je (Objekt, Name) zusammen, in der Reihenfolge des ersten Auftretens. Namen
     * werden bytegenau verglichen – PHP macht aus "123" einen int-Schlüssel, deshalb der Cast.
     *
     * @param list<array<string, string|null>> $rows
     * @return list<array{0: string, 1: string, 2: list<string|null>}>
     */
    private function group(array $rows, string $object, string $name, string $value): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $groups[(string) $row[$object]][(string) $row[$name]][] = $row[$value];
        }
        $out = [];
        foreach ($groups as $id => $names) {
            foreach ($names as $n => $values) {
                $out[] = [(string) $id, (string) $n, $values];
            }
        }
        return $out;
    }

    /** @return array{0: string, 1: list<string>} SQL-Zusatz „ AND <spalte> NOT IN (…)“ und seine Werte */
    private function typeFilter(string $column): array
    {
        if ($this->excluded === []) {
            return ['', []];
        }
        return [' AND ' . $column . ' NOT IN (' . implode(',', array_fill(0, count($this->excluded), '%s')) . ')', $this->excluded];
    }

    private function table(string $name): string
    {
        return '`' . str_replace('`', '``', $this->prefix . $name) . '`';
    }

    /**
     * @param list<mixed> $args
     * @return list<array<string, string|null>>
     */
    private function results(string $sql, array $args): array
    {
        $rows = $this->db->get_results($this->db->prepare($sql, ...$args), 'ARRAY_A');
        $this->check();
        return is_array($rows) ? array_values($rows) : [];
    }

    /**
     * @param list<mixed> $args
     * @return list<string>
     */
    private function column(string $sql, array $args): array
    {
        $values = $this->db->get_col($this->db->prepare($sql, ...$args));
        $this->check();
        return array_map('strval', array_values((array) $values));
    }

    private function check(): void
    {
        if ((string) $this->db->last_error !== '') {
            throw new \RuntimeException('content read failed');
        }
    }
}
