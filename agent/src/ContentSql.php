<?php
namespace WpSync;

defined('ABSPATH') || defined('WPSYNC_RESCUE') || exit;

/**
 * ContentStore auf $wpdb (Spec Content-Push §7.3, §11): schreibt nur in die sieben
 * Inhaltstabellen, deren volle Namen der Aufrufer nennt – für Live Präfix plus fester Name, für
 * die Staging-Kopie Namen, die StagingGuard::table() geprüft hat. Jeder Wert geht durch
 * $wpdb->prepare(), Bezeichner stehen in Backticks und stammen nie aus dem Paket: Spaltennamen
 * kommen aus der Datenbank selbst (SELECT *) oder aus den festen Listen. Meta-Schlüssel und
 * Taxonomien werden bytegenau verglichen (BINARY), wie Manifest und Export sie gruppieren.
 *
 * Eine Transaktion trägt eine Sitzungsmarke (@wpsync_tx): $wpdb baut eine verlorene Verbindung
 * von selbst neu auf und wiederholt die Abfrage – die Transaktion und ihre Sperren sind dann weg,
 * und was folgt, liefe einzeln im Autocommit. Die neue Verbindung kennt die Marke nicht. alive()
 * fragt sie ab, und jede schreibende Anweisung in einer Transaktion trägt sie als Bedingung: auf
 * einer neuen Verbindung schreibt sie nichts. Die Frage trägt jedes Mal einen anderen Kommentar,
 * damit kein Abfrage-Cache sie beantwortet.
 *
 * Kein Fehler der Datenbank geht in die Antwort oder ins Fehlerprotokoll: mit WP_DEBUG und
 * WP_DEBUG_DISPLAY gäbe $wpdb ihn samt der Abfrage – und damit samt der Werte des Pakets – als
 * HTML aus, vor dem JSON, und wpdb::print_error() schreibt die ganze Abfrage in jedem Fall per
 * error_log() ins Protokoll des Servers. Jede Abfrage läuft deshalb mit abgeschalteter Ausgabe
 * und unterdrücktem Fehler (silent()); $wpdb->last_error bleibt gesetzt. Im Protokoll steht nur
 * die Fehlernummer der Datenbank, nie die Abfrage.
 */
final class ContentSql implements ContentStore
{
    private const NAME  = '/^[A-Za-z0-9_$]{1,64}\z/';
    /** Schlüssel je Abfrage. */
    private const CHUNK = 100;
    private const PAIRS = 50;

    private const META = ['postmeta' => 'post_id', 'termmeta' => 'term_id'];

    /**
     * So lange wartet eine Transaktion des Kanals höchstens auf eine Sperre (statt der 50 Sekunden,
     * die der Server sonst vorgibt): hält jemand eine der Zeilen, endet der Push nach dieser Zeit
     * mit content_failed, statt Request und PHP-Worker festzuhalten.
     */
    public const LOCK_WAIT_SECONDS = 10;

    /** @var object $wpdb */
    private $db;
    /** @var array<string, string> Tabelle ohne Präfix → voller Name */
    private $tables;
    /** @var string voller Name der Tabelle comments des Ziels – nur zum Lesen (attached()); '' wenn es sie nicht gibt */
    private $comments;
    /** @var string|null Marke der laufenden Transaktion; null ausserhalb */
    private $mark = null;

    /**
     * @param object                $db     $wpdb der Site
     * @param array<string, string> $tables alle sieben Inhaltstabellen: Name ohne Präfix → voller Name
     * @param string                $comments voller Name der Tabelle comments des Ziels; gelesen, nie geschrieben
     * @throws \InvalidArgumentException wenn eine Tabelle fehlt oder ein Name kein Bezeichner ist
     */
    public function __construct($db, array $tables, string $comments = '')
    {
        if ($comments !== '' && preg_match(self::NAME, $comments) !== 1) {
            throw new \InvalidArgumentException('invalid content table comments');
        }
        $this->comments = $comments;
        foreach (Canon::TABLES as $name) {
            if (!is_string($tables[$name] ?? null) || preg_match(self::NAME, $tables[$name]) !== 1) {
                throw new \InvalidArgumentException('invalid content table ' . $name);
            }
        }
        $this->db     = $db;
        $this->tables = array_intersect_key($tables, array_flip(Canon::TABLES));
    }

    public function engines(array $tables): array
    {
        $out = [];
        foreach ($tables as $name) {
            $rows       = $this->results($this->db->prepare('SHOW TABLE STATUS LIKE %s', $this->db->esc_like($this->name($name))));
            $out[$name] = (string) ($rows[0]['Engine'] ?? '');
        }
        return $out;
    }

    public function idMax(string $table): int
    {
        if (!isset(ContentState::PK[$table])) {
            throw new \InvalidArgumentException('no counter on content table ' . $table);
        }
        try {
            return self::counter($this->db, $this->name($table), ContentState::PK[$table], self::status($this->db, $this->name($table)));
        } catch (ContentException $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            throw new ContentException(ContentException::FAILED, 'Die Datenbank liess sich nicht lesen.');
        }
    }

    /**
     * SHOW TABLE STATUS einer Tabelle – auch für den Manifest-Kopf (ContentManifest::head()).
     *
     * @param object $db    $wpdb
     * @param string $table voller Name
     * @return array<string, mixed> leer, wenn es die Tabelle nicht gibt
     * @throws \RuntimeException wenn sich der Status nicht lesen lässt
     */
    public static function status($db, string $table): array
    {
        $sql              = $db->prepare('SHOW TABLE STATUS LIKE %s', $db->esc_like($table));
        list($rows, $bad) = self::silent($db, static function () use ($db, $sql): array {
            return [(array) $db->get_results($sql, 'ARRAY_A'), (string) $db->last_error !== ''];
        });
        if ($bad) {
            throw new \RuntimeException('content read failed');
        }
        return is_array($rows[0] ?? null) ? $rows[0] : [];
    }

    /**
     * Höchste vergebene ID einer Zähler-Tabelle: max(MAX(id), AUTO_INCREMENT − 1). Eine Rechnung
     * für id_max im Manifest-Kopf und für die Grenze neuer IDs (ContentCheck::ID_HEADROOM) – ein
     * Wert aus einer gescheiterten Abfrage läge unter dem, was das Ziel schon vergeben hat (B8).
     *
     * @param object               $db     $wpdb
     * @param string               $table  voller Name, ein Bezeichner
     * @param string               $column Primärschlüssel
     * @param array<string, mixed> $status aus status()
     * @throws \RuntimeException wenn sich die Tabelle nicht lesen lässt
     */
    public static function counter($db, string $table, string $column, array $status): int
    {
        if (preg_match(self::NAME, $table) !== 1 || preg_match(self::NAME, $column) !== 1) {
            throw new \InvalidArgumentException('invalid content table ' . $table);
        }
        list($max, $bad) = self::silent($db, static function () use ($db, $table, $column): array {
            return [(int) $db->get_var('SELECT MAX(`' . $column . '`) FROM `' . $table . '`'), (string) $db->last_error !== ''];
        });
        if ($bad) {
            throw new \RuntimeException('content read failed');
        }
        return max($max, (int) ($status['Auto_increment'] ?? 0) - 1);
    }

    public function read(string $table, array $keys, bool $lock): array
    {
        $keys = array_values(array_unique(array_map('strval', $keys)));
        $out  = array_fill_keys($keys, null);
        $tail = $lock ? ' FOR UPDATE' : '';
        if (isset(self::META[$table])) {
            return $this->readMeta($table, $keys, $out, $tail);
        }
        if ($table === 'term_relationships') {
            return $this->readRelations($keys, $out, $tail);
        }
        $column = $table === 'options' ? 'option_name' : (ContentState::PK[$table] ?? '');
        if ($column === '') {
            throw new \InvalidArgumentException('unknown content table ' . $table);
        }
        $mark = $table === 'options' ? '%s' : '%d';
        foreach (array_chunk($keys, self::CHUNK) as $chunk) {
            $sql = 'SELECT * FROM ' . $this->quoted($table) . ' WHERE `' . $column . '` IN (' . implode(',', array_fill(0, count($chunk), $mark)) . ')' . $tail;
            foreach ($this->results($this->db->prepare($sql, ...$chunk)) as $row) {
                $key = (string) $row[$column];
                // Bytegenau: was die Datenbank grosszügiger gleichsetzt (Gross/klein), meldet aliases().
                if (array_key_exists($key, $out)) {
                    $out[$key] = $row;
                }
            }
        }
        return $out;
    }

    public function aliases(string $table, array $keys): array
    {
        $keys = array_values(array_unique(array_map('strval', $keys)));
        $out  = [];
        if ($table === 'options') {
            foreach (array_chunk($keys, self::PAIRS) as $chunk) {
                $where = [];
                foreach ($chunk as $key) {
                    $where[] = $this->db->prepare('(`option_name` = %s AND BINARY `option_name` <> %s)', $key, $key);
                }
                foreach ($this->results('SELECT `option_name` AS k FROM ' . $this->quoted('options') . ' WHERE ' . implode(' OR ', $where)) as $row) {
                    $out = array_merge($out, self::twins($chunk, '', (string) $row['k']));
                }
            }
            return array_values(array_unique($out));
        }
        if (!isset(self::META[$table])) {
            return [];
        }
        $column = self::META[$table];
        foreach (array_chunk($keys, self::PAIRS) as $chunk) {
            $where = [];
            foreach ($chunk as $key) {
                list($object, $name) = ContentState::split($key);
                $where[] = $this->db->prepare('(`' . $column . '` = %d AND `meta_key` = %s AND BINARY `meta_key` <> %s)', $object, $name, $name);
            }
            $sql = 'SELECT DISTINCT `' . $column . '` AS o, `meta_key` AS k FROM ' . $this->quoted($table) . ' WHERE ' . implode(' OR ', $where);
            foreach ($this->results($sql) as $row) {
                $out = array_merge($out, self::twins($chunk, (string) $row['o'], (string) $row['k']));
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Die gefragten Schlüssel, deren Zwilling $found ist: dasselbe Objekt, ohne Gross/klein gleich.
     * Setzt die Datenbank noch mehr gleich (Akzente), gelten alle gefragten Schlüssel des Objekts.
     *
     * @param list<string> $asked
     * @return list<string>
     */
    private static function twins(array $asked, string $object, string $found): array
    {
        $same = [];
        $all  = [];
        foreach ($asked as $key) {
            list($o, $name) = $object === '' ? ['', $key] : ContentState::split($key);
            if ($o !== $object) {
                continue;
            }
            $all[] = $key;
            if (strcasecmp($name, $found) === 0) {
                $same[] = $key;
            }
        }
        return $same !== [] ? $same : $all;
    }

    public function write(string $table, string $key, ?array $state): void
    {
        if (isset(self::META[$table])) {
            $this->writeMeta($table, $key, $state);
            return;
        }
        if ($table === 'term_relationships') {
            $this->writeRelations($key, $state);
            return;
        }
        $column = $table === 'options' ? 'option_name' : (ContentState::PK[$table] ?? '');
        if ($column === '') {
            throw new \InvalidArgumentException('unknown content table ' . $table);
        }
        // Optionen bytegenau: der Index findet die Zeile, BINARY schliesst den Zwilling aus.
        $where = $table === 'options'
            ? $this->db->prepare(' WHERE `option_name` = %s AND BINARY `option_name` = %s', $key, $key)
            : $this->db->prepare(' WHERE `' . $column . '` = %d', $key);
        if ($state === null) {
            $this->exec('DELETE FROM ' . $this->quoted($table) . $where . $this->guard());
            return;
        }
        unset($state['option_id']); // vergibt die Datenbank
        $state[$column] = $key;
        $exists         = $this->results('SELECT `' . $column . '` FROM ' . $this->quoted($table) . $where) !== [];
        $names          = [];
        $values         = [];
        $sets           = [];
        foreach ($state as $name => $value) {
            $name     = $this->column((string) $name);
            $literal  = $this->literal($value);
            $names[]  = $name;
            $values[] = $literal;
            if ($name !== '`' . $column . '`') {
                $sets[] = $name . ' = ' . $literal;
            }
        }
        if ($exists) {
            if ($sets !== []) {
                $this->exec('UPDATE ' . $this->quoted($table) . ' SET ' . implode(', ', $sets) . $where . $this->guard());
            }
            return;
        }
        $this->insert($table, implode(', ', $names), implode(', ', $values));
    }

    public function taxonomies(array $termIds): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique(array_map('strval', $termIds))), self::CHUNK) as $chunk) {
            $sql = 'SELECT `term_id`, `taxonomy` FROM ' . $this->quoted('term_taxonomy')
                . ' WHERE `term_id` IN (' . implode(',', array_fill(0, count($chunk), '%d')) . ') ORDER BY `term_taxonomy_id`';
            foreach ($this->results($this->db->prepare($sql, ...$chunk)) as $row) {
                $out[(string) $row['term_id']][] = (string) $row['taxonomy'];
            }
        }
        return $out;
    }

    public function relations(array $objectIds, bool $lock): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique(array_map('strval', $objectIds))), self::CHUNK) as $chunk) {
            $sql = 'SELECT `object_id` AS o, `term_taxonomy_id` AS tt FROM ' . $this->quoted('term_relationships')
                . ' WHERE `object_id` IN (' . implode(',', array_fill(0, count($chunk), '%d')) . ')' . ($lock ? ' FOR UPDATE' : '');
            foreach ($this->results($this->db->prepare($sql, ...$chunk)) as $row) {
                $out[(string) $row['o']][] = (string) $row['tt'];
            }
        }
        return $out;
    }

    public function attached(string $table, array $ids, bool $lock): array
    {
        if (!isset(ContentState::PK[$table])) {
            throw new \InvalidArgumentException('nothing hangs on ' . $table);
        }
        $ids  = array_values(array_unique(array_map('strval', $ids)));
        $out  = array_fill_keys($ids, ['meta' => [], 'relations' => [], 'comments' => 0, 'children' => []]);
        $tail = $lock ? ' FOR UPDATE' : '';
        // Trägt eine Zeile in Spalte o eine der gefragten IDs, kommt $value einmal in ihre Liste $field.
        $add = static function (array $row, string $field, string $value) use (&$out): void {
            $id = (string) $row['o'];
            if (isset($out[$id]) && !in_array($value, $out[$id][$field], true)) {
                $out[$id][$field][] = $value;
            }
        };
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $in = ' IN (' . implode(',', array_fill(0, count($chunk), '%d')) . ')';
            $meta = ['posts' => 'postmeta', 'terms' => 'termmeta'][$table] ?? null;
            if ($meta !== null) {
                $column = self::META[$meta];
                $sql    = 'SELECT `' . $column . '` AS o, `meta_key` AS k FROM ' . $this->quoted($meta) . ' WHERE `' . $column . '`' . $in . ' ORDER BY `meta_id`' . $tail;
                foreach ($this->results($this->db->prepare($sql, ...$chunk)) as $row) {
                    $add($row, 'meta', (string) $row['k']);
                }
            }
            if ($table === 'posts') {
                $sql = 'SELECT r.`object_id` AS o, x.`taxonomy` AS tax FROM ' . $this->quoted('term_relationships') . ' r LEFT JOIN ' . $this->quoted('term_taxonomy')
                    . ' x ON x.`term_taxonomy_id` = r.`term_taxonomy_id` WHERE r.`object_id`' . $in . ' ORDER BY r.`object_id`, r.`term_taxonomy_id`' . $tail;
                foreach ($this->results($this->db->prepare($sql, ...$chunk)) as $row) {
                    $add($row, 'relations', Canon::pairKey((string) $row['o'], (string) ($row['tax'] ?? '')));
                }
                $sql = 'SELECT `post_parent` AS o, `ID` AS c FROM ' . $this->quoted('posts') . ' WHERE `post_parent`' . $in . ' ORDER BY `ID`' . $tail;
                foreach ($this->results($this->db->prepare($sql, ...$chunk)) as $row) {
                    $add($row, 'children', (string) $row['c']);
                }
                if ($this->comments !== '') {
                    $sql = 'SELECT `comment_post_ID` AS o FROM `' . $this->comments . '` WHERE `comment_post_ID`' . $in . $tail;
                    foreach ($this->results($this->db->prepare($sql, ...$chunk)) as $row) {
                        if (isset($out[(string) $row['o']])) {
                            $out[(string) $row['o']]['comments']++;
                        }
                    }
                }
            } elseif ($table === 'terms') {
                $sql = 'SELECT `term_id` AS o, `term_taxonomy_id` AS c FROM ' . $this->quoted('term_taxonomy') . ' WHERE `term_id`' . $in . ' ORDER BY `term_taxonomy_id`' . $tail;
                foreach ($this->results($this->db->prepare($sql, ...$chunk)) as $row) {
                    $add($row, 'children', (string) $row['c']);
                }
            } else {
                $sql = 'SELECT r.`term_taxonomy_id` AS o, r.`object_id` AS obj, x.`taxonomy` AS tax FROM ' . $this->quoted('term_relationships') . ' r JOIN ' . $this->quoted('term_taxonomy')
                    . ' x ON x.`term_taxonomy_id` = r.`term_taxonomy_id` WHERE r.`term_taxonomy_id`' . $in . ' ORDER BY r.`object_id`' . $tail;
                foreach ($this->results($this->db->prepare($sql, ...$chunk)) as $row) {
                    $add($row, 'relations', Canon::pairKey((string) $row['obj'], (string) $row['tax']));
                }
                $sql = 'SELECT p.`term_taxonomy_id` AS o, c.`term_taxonomy_id` AS c FROM ' . $this->quoted('term_taxonomy') . ' p JOIN ' . $this->quoted('term_taxonomy')
                    . ' c ON c.`parent` = p.`term_id` AND BINARY c.`taxonomy` = BINARY p.`taxonomy` WHERE p.`term_taxonomy_id`' . $in . ' ORDER BY c.`term_taxonomy_id`' . $tail;
                foreach ($this->results($this->db->prepare($sql, ...$chunk)) as $row) {
                    $add($row, 'children', (string) $row['c']);
                }
            }
        }
        return $out;
    }

    public function purge(string $table, string $key): void
    {
        switch ($table) {
            case 'posts':
                $this->exec($this->db->prepare('DELETE FROM ' . $this->quoted('postmeta') . ' WHERE `post_id` = %d', $key) . $this->guard());
                $this->exec($this->db->prepare('DELETE FROM ' . $this->quoted('term_relationships') . ' WHERE `object_id` = %d', $key) . $this->guard());
                return;
            case 'terms':
                $this->exec($this->db->prepare('DELETE FROM ' . $this->quoted('termmeta') . ' WHERE `term_id` = %d', $key) . $this->guard());
                return;
            case 'term_taxonomy':
                $this->exec($this->db->prepare('DELETE FROM ' . $this->quoted('term_relationships') . ' WHERE `term_taxonomy_id` = %d', $key) . $this->guard());
                return;
        }
        throw new \InvalidArgumentException('nothing hangs on ' . $table);
    }

    public function recount(array $termTaxonomyIds): void
    {
        foreach (array_chunk(array_values(array_unique(array_map('strval', $termTaxonomyIds))), self::CHUNK) as $chunk) {
            $sql = 'UPDATE ' . $this->quoted('term_taxonomy') . ' x SET x.`count` = (SELECT COUNT(*) FROM ' . $this->quoted('term_relationships')
                . ' r WHERE r.`term_taxonomy_id` = x.`term_taxonomy_id`) WHERE x.`term_taxonomy_id` IN (' . implode(',', array_fill(0, count($chunk), '%d')) . ')';
            $this->exec($this->db->prepare($sql, ...$chunk) . $this->guard());
        }
    }

    public function dropMeta(string $metaKey): void
    {
        // Erst über den Index, dann bytegenau.
        $this->exec($this->db->prepare('DELETE FROM ' . $this->quoted('postmeta') . ' WHERE `meta_key` = %s AND BINARY `meta_key` = %s', $metaKey, $metaKey) . $this->guard());
    }

    public function transaction(callable $do)
    {
        self::uncached();
        // Erst die Marke, dann die Transaktion: baut $wpdb die Verbindung dazwischen oder danach neu
        // auf, hat die neue entweder beides oder keine Marke – nie eine Marke ohne Transaktion.
        $this->mark = bin2hex(random_bytes(8));
        try {
            $this->exec((string) $this->db->prepare('SET @wpsync_tx = %s', $this->mark));
            // Ein Versuch: kennt der Server die Variable nicht oder gibt er sie nicht her, gilt seine Wartezeit.
            $this->quiet(function (): void {
                $this->db->query('SET SESSION innodb_lock_wait_timeout = ' . self::LOCK_WAIT_SECONDS);
            });
            $this->exec('START TRANSACTION');
            try {
                $result = $do();
                if (!$this->alive()) {
                    throw new ContentException(ContentException::FAILED, 'Die Verbindung zur Datenbank ging während der Transaktion verloren – nichts wurde übernommen.');
                }
                $this->exec('COMMIT');
            } catch (\Throwable $e) {
                $this->quiet(function (): void {
                    $this->db->query('ROLLBACK');
                });
                throw $e;
            }
            // Ging die Verbindung im COMMIT selbst verloren, hat $wpdb ihn auf einer neuen wiederholt –
            // dort war er leer. Ob der erste ankam, weiss hier niemand: der Aufrufer sieht nach.
            if (!$this->alive()) {
                throw new ContentException(ContentException::UNCLEAR, 'Die Verbindung zur Datenbank ging beim Abschluss der Transaktion verloren.');
            }
            return $result;
        } finally {
            $this->mark = null;
            $this->quiet(function (): void {
                $this->db->query('SET @wpsync_tx = NULL');
            });
            // Was der Request danach fragt (Nacharbeiten, Plugins), wartet wieder wie von der Site vorgesehen.
            $this->quiet(function (): void {
                $this->db->query('SET SESSION innodb_lock_wait_timeout = DEFAULT');
            });
        }
    }

    public function alive(): bool
    {
        if ($this->mark === null) {
            return false;
        }
        // Jede Frage ein anderer Text: ein Abfrage-Cache (Drop-in, DB-Cache eines Plugins) kann sie nie
        // aus dem Speicher beantworten. Der Kommentar ist ein hier erzeugter Hex-Wert, keine Eingabe.
        $sql = 'SELECT @wpsync_tx /* ' . bin2hex(random_bytes(8)) . ' */';
        return (string) $this->quiet(function () use ($sql) {
            return $this->db->get_var($sql);
        }) === $this->mark;
    }

    /**
     * Bittet Cache-Plugins, für diesen Request keine Datenbankabfragen zwischenzuspeichern
     * (DONOTCACHEDB, die Konvention von W3 Total Cache und anderen): Lesen unter Sperre und die
     * Frage nach der Sitzungsmarke müssen die Datenbank erreichen. Eine fremde wpdb-Klasse lehnt
     * der Kanal deshalb nicht ab – Query Monitor und andere erweitern wpdb zu Recht.
     */
    public static function uncached(): void
    {
        defined('DONOTCACHEDB') || define('DONOTCACHEDB', true);
    }

    /** Bedingung jeder schreibenden Anweisung in einer Transaktion: nur auf der Verbindung, die sie begann. */
    private function guard(): string
    {
        return $this->mark === null ? '' : (string) $this->db->prepare(' AND @wpsync_tx = %s', $this->mark);
    }

    /**
     * INSERT einer Zeile; in einer Transaktion als INSERT … SELECT mit der Marke als Bedingung.
     *
     * @param string $columns Bezeichner in Backticks, mit Komma getrennt
     * @param string $values  fertige Literale, mit Komma getrennt
     */
    private function insert(string $table, string $columns, string $values): void
    {
        $head = 'INSERT INTO ' . $this->quoted($table) . ' (' . $columns . ') ';
        $this->exec($this->mark === null
            ? $head . 'VALUES (' . $values . ')'
            : $head . 'SELECT ' . $values . ' FROM DUAL WHERE ' . substr($this->guard(), 5));
    }

    /**
     * @param list<string>                              $keys
     * @param array<string, array<string, mixed>|null> $out
     * @return array<string, array<string, mixed>|null>
     */
    private function readMeta(string $table, array $keys, array $out, string $tail): array
    {
        $column = self::META[$table];
        foreach (array_chunk($keys, self::PAIRS) as $chunk) {
            $where = [];
            foreach ($chunk as $key) {
                list($object, $name) = ContentState::split($key);
                $where[] = $this->db->prepare('(`' . $column . '` = %d AND BINARY `meta_key` = %s)', $object, $name);
            }
            $sql = 'SELECT `' . $column . '` AS o, `meta_key` AS k, `meta_value` AS v FROM ' . $this->quoted($table)
                . ' WHERE ' . implode(' OR ', $where) . ' ORDER BY `meta_id`' . $tail;
            foreach ($this->results($sql) as $row) {
                $key = Canon::pairKey((string) $row['o'], (string) $row['k']);
                if (array_key_exists($key, $out)) {
                    $out[$key]['values'][] = $row['v'];
                }
            }
        }
        return $out;
    }

    /**
     * @param list<string>                              $keys
     * @param array<string, array<string, mixed>|null> $out
     * @return array<string, array<string, mixed>|null>
     */
    private function readRelations(array $keys, array $out, string $tail): array
    {
        $objects = [];
        foreach ($keys as $key) {
            $objects[ContentState::split($key)[0]] = true;
        }
        foreach (array_chunk(array_map('strval', array_keys($objects)), self::CHUNK) as $chunk) {
            $sql = 'SELECT r.`object_id` AS o, r.`term_taxonomy_id` AS tt, r.`term_order` AS ord, x.`taxonomy` AS tax FROM '
                . $this->quoted('term_relationships') . ' r JOIN ' . $this->quoted('term_taxonomy') . ' x ON x.`term_taxonomy_id` = r.`term_taxonomy_id`'
                . ' WHERE r.`object_id` IN (' . implode(',', array_fill(0, count($chunk), '%d')) . ') ORDER BY r.`object_id`, r.`term_taxonomy_id`' . $tail;
            foreach ($this->results($this->db->prepare($sql, ...$chunk)) as $row) {
                $key = Canon::pairKey((string) $row['o'], (string) $row['tax']);
                if (array_key_exists($key, $out)) {
                    $out[$key]['values'][] = $row['tt'] . ':' . $row['ord'];
                }
            }
        }
        return $out;
    }

    /** @param array<string, mixed>|null $state */
    private function writeMeta(string $table, string $key, ?array $state): void
    {
        $column = self::META[$table];
        list($object, $name) = ContentState::split($key);
        $this->exec($this->db->prepare('DELETE FROM ' . $this->quoted($table) . ' WHERE `' . $column . '` = %d AND BINARY `meta_key` = %s', $object, $name) . $this->guard());
        foreach ((array) ($state['values'] ?? []) as $value) {
            $this->insert($table, '`' . $column . '`, `meta_key`, `meta_value`', $this->db->prepare('%d, %s', $object, $name) . ', ' . $this->literal($value));
        }
    }

    /** @param array<string, mixed>|null $state */
    private function writeRelations(string $key, ?array $state): void
    {
        list($object, $taxonomy) = ContentState::split($key);
        $this->exec($this->db->prepare(
            'DELETE r FROM ' . $this->quoted('term_relationships') . ' r JOIN ' . $this->quoted('term_taxonomy')
            . ' x ON x.`term_taxonomy_id` = r.`term_taxonomy_id` WHERE r.`object_id` = %d AND BINARY x.`taxonomy` = %s',
            $object,
            $taxonomy
        ) . $this->guard());
        foreach ((array) ($state['values'] ?? []) as $entry) {
            $parts = explode(':', (string) $entry);
            $this->insert('term_relationships', '`object_id`, `term_taxonomy_id`, `term_order`', (string) $this->db->prepare('%d, %d, %d', $object, $parts[0], $parts[1] ?? 0));
        }
    }

    private function name(string $table): string
    {
        if (!isset($this->tables[$table])) {
            throw new \InvalidArgumentException('unknown content table ' . $table);
        }
        return $this->tables[$table];
    }

    private function quoted(string $table): string
    {
        return '`' . $this->name($table) . '`';
    }

    /** Ein Spaltenname als Bezeichner; er kommt aus der Datenbank oder aus dem Vorher-Abbild. */
    private function column(string $name): string
    {
        if (preg_match(self::NAME, $name) !== 1) {
            throw new ContentException(ContentException::FAILED, 'Ungültiger Spaltenname.');
        }
        return '`' . $name . '`';
    }

    /** @param mixed $value */
    private function literal($value): string
    {
        return $value === null ? 'NULL' : (string) $this->db->prepare('%s', (string) $value);
    }

    /**
     * Fragt die Datenbank, ohne dass $wpdb einen Fehler in die Antwort oder – samt der Abfrage und
     * ihrer Werte – ins Fehlerprotokoll schreibt (wpdb::print_error()); danach gilt wieder die
     * Einstellung der Site. $wpdb->last_error bleibt gesetzt: wer fragt, prüft ihn selbst. Ein
     * Fehler steht mit seiner Nummer im Protokoll, ohne Abfrage.
     *
     * @param object            $db  $wpdb
     * @param callable(): mixed $ask
     * @return mixed was $ask liefert
     */
    public static function silent($db, callable $ask)
    {
        $shown      = is_object($db) && method_exists($db, 'hide_errors') && (bool) $db->hide_errors();
        $suppressed = is_object($db) && method_exists($db, 'suppress_errors') ? (bool) $db->suppress_errors(true) : null;
        try {
            $result = $ask();
            self::note($db);
            return $result;
        } finally {
            if ($suppressed !== null) {
                $db->suppress_errors($suppressed);
            }
            if ($shown) {
                $db->show_errors();
            }
        }
    }

    /**
     * Protokolliert einen Datenbankfehler ohne Abfrage und ohne Meldung – beide können Werte tragen
     * („Duplicate entry '…'“). Nur die Fehlernummer, soweit die Verbindung sie nennt.
     *
     * @param object $db $wpdb
     */
    private static function note($db): void
    {
        if (!is_object($db) || (string) ($db->last_error ?? '') === '') {
            return;
        }
        $link = $db->dbh ?? null;
        try {
            $errno = $link instanceof \mysqli ? (int) mysqli_errno($link) : 0;
        } catch (\Throwable $e) {
            $errno = 0; // eine Verbindung, die keine mehr ist: das Protokollieren wirft nie
        }
        if ($errno > 0) {
            error_log('wpsync: a query of the content channel failed (MySQL error ' . $errno . '); query and values are withheld');
        }
    }

    /** @return mixed was $ask liefert */
    private function quiet(callable $ask)
    {
        return self::silent($this->db, $ask);
    }

    private function exec(string $sql): void
    {
        $done = $this->quiet(function () use ($sql) {
            return $this->db->query($sql);
        });
        if ($done === false) {
            throw new ContentException(ContentException::FAILED, 'Die Datenbank hat einen Schreibzugriff abgelehnt – nichts wurde übernommen.');
        }
    }

    /** @return list<array<string, string|null>> */
    private function results(string $sql): array
    {
        list($rows, $error) = $this->quiet(function () use ($sql): array {
            return [$this->db->get_results($sql, 'ARRAY_A'), (string) $this->db->last_error];
        });
        if ($error !== '') {
            throw new ContentException(ContentException::FAILED, 'Die Datenbank liess sich nicht lesen.');
        }
        return is_array($rows) ? array_values($rows) : [];
    }
}
