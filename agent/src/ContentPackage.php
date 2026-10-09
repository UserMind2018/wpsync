<?php
namespace WpSync;

defined('ABSPATH') || exit;

/**
 * Ein Inhalts-Paket (Spec Content-Push §7.1): package.jsonl mit einer Kopfzeile {"head": {…}} und
 * je Zeile {op, table, key, expected, row}. Zeilenende fest "\n"; sha256 im Kopf gilt für alle
 * Bytes nach der Kopfzeile. read() prüft Form, Prüfsumme und Grenzen (§7.2 Nr. 1 und 2) und hält
 * die Zeilen dekodiert – Werte sind normalisiert und tragen Platzhalter statt einer Domain.
 * Ob eine Zeile geschrieben werden darf, entscheidet ContentCheck.
 */
final class ContentPackage
{
    /** Grenzen eines Pakets (§7.5): eine Transaktion, ein Request. */
    public const MAX_ROWS  = 5000;
    public const MAX_BYTES = 8388608;
    /** So viel nimmt /content/stage an – darüber gibt es keinen Probelauf mehr, nur die Ablehnung. */
    public const STAGE_BYTES = 16777216;
    /** Meta, die op trash am Beitrag schreibt – wie wp_trash_post() und wp_add_trashed_suffix_to_post_name_for_post(). */
    public const TRASH_META = ['_wp_trash_meta_status', '_wp_trash_meta_time', '_wp_desired_post_slug'];
    /** Länge der Kopfzeile. */
    private const HEAD_BYTES = 65536;

    private const ID   = ContentLists::OBJECT_ID;
    private const DATE = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/';
    private const HEX  = '/^[a-f0-9]{64}\z/';

    /** @var array<string, mixed> */
    private $head;
    /** @var list<array{op: string, table: string, key: string, expected: string, row: array<string, mixed>|null}> */
    private $rows;
    /** @var int */
    private $bytes;

    /**
     * @param array<string, mixed>              $head
     * @param list<array<string, mixed>>        $rows
     */
    private function __construct(array $head, array $rows, int $bytes)
    {
        $this->head  = $head;
        $this->rows  = $rows;
        $this->bytes = $bytes;
    }

    /** @return array{max_rows: int, max_bytes: int, budget_seconds: int, id_headroom: int, max_state_bytes: int} was der Agent in einem Request annimmt */
    public static function limits(): array
    {
        return [
            'max_rows'         => self::MAX_ROWS,
            'max_bytes'        => self::MAX_BYTES,
            'budget_seconds'   => Budget::seconds((int) ini_get('max_execution_time')),
            'id_headroom'      => ContentCheck::ID_HEADROOM,
            'max_state_bytes'  => ContentCheck::$maxStateBytes,
        ];
    }

    /**
     * @throws ContentException package_invalid, baseline_outdated oder package_too_large
     */
    public static function read(string $file): self
    {
        $handle = is_file($file) && !is_link($file) ? @fopen($file, 'rb') : false;
        if ($handle === false) {
            throw new ContentException(ContentException::MISSING, 'Das Paket liegt nicht auf dem Server.');
        }
        try {
            $line = fgets($handle, self::HEAD_BYTES + 2);
            if (!is_string($line) || substr($line, -1) !== "\n") {
                throw self::invalid('Die Kopfzeile fehlt oder ist zu lang.');
            }
            $first = json_decode($line, true);
            if (!is_array($first) || array_keys($first) !== ['head'] || !is_array($first['head'])) {
                throw self::invalid('Die erste Zeile ist kein Kopf.');
            }
            $head  = self::parseHead($first['head']);
            $bytes = (int) filesize($file) - strlen($line);
            if ($bytes > self::MAX_BYTES || $head['rows'] > self::MAX_ROWS) {
                throw new ContentException(
                    ContentException::TOO_LARGE,
                    'Das Paket ist zu gross für einen Push: ' . $head['rows'] . ' Zeilen, ' . $bytes . ' Bytes.',
                    [],
                    ['limits' => self::limits(), 'rows' => $head['rows'], 'bytes' => $bytes]
                );
            }
            $hash = hash_init('sha256');
            $rows    = [];
            $seen    = [];
            $trashed = [];
            while (($line = fgets($handle)) !== false) {
                hash_update($hash, $line);
                if (substr($line, -1) !== "\n" || substr($line, -2, 1) === "\r") {
                    throw self::invalid('Jede Zeile endet mit genau einem Zeilenvorschub.');
                }
                if (count($rows) >= $head['rows']) {
                    throw self::invalid('Das Paket hat mehr Zeilen, als der Kopf nennt.');
                }
                $row = self::parseRow(json_decode($line, true), count($rows) + 1);
                $id  = $row['table'] . "\0\0" . $row['key'];
                if (isset($seen[$id])) {
                    throw self::invalid('Zeile ' . (count($rows) + 1) . ': der Schlüssel kommt doppelt vor.');
                }
                $seen[$id] = true;
                $rows[]    = $row;
                if ($row['op'] === 'trash') {
                    $trashed[$row['key']] = true;
                }
            }
            if (count($rows) !== $head['rows']) {
                throw self::invalid('Das Paket hat weniger Zeilen, als der Kopf nennt.');
            }
            // Was der Papierkorb an einem Beitrag hinterlässt, schreibt der Agent selbst (wie wp_trash_post()).
            foreach ($rows as $n => $row) {
                if ($row['table'] !== 'postmeta') {
                    continue;
                }
                list($object, $name) = ContentState::split($row['key']);
                if (in_array($name, self::TRASH_META, true) && isset($trashed[$object])) {
                    throw self::invalid('Zeile ' . ($n + 1) . ': ' . $name . ' eines Beitrags mit op trash setzt der Agent selbst – die Zeile gehört nicht ins Paket (Papierkorb).');
                }
            }
            if (!hash_equals($head['sha256'], hash_final($hash))) {
                throw self::invalid('Die Prüfsumme des Pakets stimmt nicht.');
            }
            return new self($head, $rows, $bytes);
        } finally {
            fclose($handle);
        }
    }

    /** @return array<string, mixed> der geprüfte Kopf */
    public function head(): array
    {
        return $this->head;
    }

    /**
     * dates: nur bei op trash und nur, wenn die Zeile sie trägt – post_date und post_date_gmt, die der
     * Beitrag im Papierkorb bekommt. row ist bei op trash immer null.
     *
     * @return list<array{op: string, table: string, key: string, expected: string, row: array<string, mixed>|null, dates: array{post_date: string, post_date_gmt: string}|null}>
     */
    public function rows(): array
    {
        return $this->rows;
    }

    /** Bytes nach der Kopfzeile. */
    public function bytes(): int
    {
        return $this->bytes;
    }

    /** @return array<string, int> Zeilen je Tabelle */
    public function counts(): array
    {
        $out = [];
        foreach ($this->rows as $row) {
            $out[$row['table']] = ($out[$row['table']] ?? 0) + 1;
        }
        return $out;
    }

    /**
     * @param array<mixed> $raw
     * @return array<string, mixed>
     */
    private static function parseHead(array $raw): array
    {
        $known = ['list_version', 'extensions', 'corridor', 'canon_version', 'variants', 'home', 'map_id', 'local_host', 'rows', 'sha256'];
        if (array_diff(array_keys($raw), $known) !== [] || array_diff($known, array_keys($raw)) !== []) {
            throw self::invalid('Der Kopf hat nicht genau die erwarteten Felder.');
        }
        if (!is_int($raw['canon_version']) || !is_int($raw['list_version']) || !is_array($raw['variants'])) {
            throw self::invalid('Der Kopf nennt keine gültigen Versionen.');
        }
        if ($raw['canon_version'] !== Canon::VERSION || $raw['variants'] !== ContentOrigin::VARIANTS) {
            throw new ContentException(ContentException::OUTDATED, 'Das Paket stammt aus einem Pull mit einer anderen kanonischen Form – erneut ziehen.');
        }
        $extensions = ContentLists::extensions($raw['extensions']);
        if ($extensions === null) {
            throw self::invalid('Die Projekt-Erweiterungen im Kopf sind ungültig.');
        }
        $corridor = is_array($raw['corridor']) ? $raw['corridor'] : [];
        $ranges   = [];
        foreach (array_keys(ContentState::PK) as $table) {
            $range = $corridor[$table] ?? null;
            if (!is_array($range) || count($range) !== 2 || !is_int($range[0] ?? null) || !is_int($range[1] ?? null) || $range[0] < 1 || $range[1] < $range[0]) {
                throw self::invalid('Der ID-Korridor im Kopf ist ungültig.');
            }
            $ranges[$table] = [$range[0], $range[1]];
        }
        if (!is_int($corridor['offset'] ?? null) || $corridor['offset'] < 1 || count($corridor) !== 4) {
            throw self::invalid('Der ID-Korridor im Kopf ist ungültig.');
        }
        $home = $raw['home'];
        $host = $raw['local_host'];
        if (!is_string($home) || preg_match('#^https?://[^/\s]+(/[^\s]*[^/\s])?\z#i', $home) !== 1) {
            throw self::invalid('home im Kopf ist keine Adresse.');
        }
        if (!is_string($host) || preg_match('/^[a-z0-9]([a-z0-9.-]{0,251}[a-z0-9])?(:[0-9]{1,5})?\z/', $host) !== 1) {
            throw self::invalid('local_host im Kopf ist kein Hostname.');
        }
        if (!is_string($raw['map_id']) || preg_match(self::HEX, $raw['map_id']) !== 1 || !is_string($raw['sha256']) || preg_match(self::HEX, $raw['sha256']) !== 1) {
            throw self::invalid('map_id oder sha256 im Kopf sind ungültig.');
        }
        if (!is_int($raw['rows']) || $raw['rows'] < 1) {
            throw self::invalid('Das Paket nennt keine Zeilen.');
        }
        return [
            'list_version'  => $raw['list_version'],
            'extensions'    => $extensions,
            'corridor'      => ['offset' => $corridor['offset']] + $ranges,
            'canon_version' => $raw['canon_version'],
            'variants'      => $raw['variants'],
            'home'          => $home,
            'map_id'        => $raw['map_id'],
            'local_host'    => $host,
            'rows'          => $raw['rows'],
            'sha256'        => $raw['sha256'],
        ];
    }

    /**
     * @param mixed $raw
     * @return array{op: string, table: string, key: string, expected: string, row: array<string, mixed>|null, dates: array{post_date: string, post_date_gmt: string}|null}
     */
    private static function parseRow($raw, int $n): array
    {
        $bad = static function (string $why) use ($n): ContentException {
            return self::invalid('Zeile ' . $n . ': ' . $why);
        };
        if (!is_array($raw) || array_diff(array_keys($raw), ['op', 'table', 'key', 'expected', 'row']) !== []) {
            throw $bad('kein Objekt {op, table, key, expected, row}.');
        }
        $op       = $raw['op'] ?? null;
        $table    = $raw['table'] ?? null;
        $key      = $raw['key'] ?? null;
        $expected = $raw['expected'] ?? null;
        if (!in_array($op, ['update', 'insert', 'trash'], true) || !is_string($table) || !in_array($table, Canon::TABLES, true)) {
            throw $bad('op oder table unbekannt.');
        }
        if (!is_string($key) || !self::validKey($table, $key)) {
            throw $bad('der Schlüssel passt nicht zur Tabelle.');
        }
        if (!is_string($expected) || ($op === 'insert') !== ($expected === 'absent') || ($expected !== 'absent' && preg_match(self::HEX, $expected) !== 1)) {
            throw $bad('expected passt nicht zu op.');
        }
        if ($op === 'trash') {
            if ($table !== 'posts') {
                throw $bad('trash gibt es nur für posts.');
            }
            // WordPress gibt einem nie veröffentlichten Entwurf beim Weg in den Papierkorb das Datum des
            // Verschiebens: die Zeile darf genau diese beiden Spalten tragen, sonst nichts.
            $dates = ($raw['row'] ?? null) === null ? null : self::dates($raw['row']);
            if (($raw['row'] ?? null) !== null && $dates === null) {
                throw $bad('row eines trash trägt genau post_date und post_date_gmt (JJJJ-MM-TT hh:mm:ss) oder fehlt.');
            }
            return ['op' => $op, 'table' => $table, 'key' => $key, 'expected' => $expected, 'row' => null, 'dates' => $dates];
        }
        if (!is_array($raw['row'] ?? null)) {
            throw $bad('row fehlt.');
        }
        $row = ContentState::isSet($table) ? self::set($table, $raw['row']) : self::columns($table, $raw['row']);
        if ($row === null) {
            throw $bad('row hat nicht die Form der Tabelle.');
        }
        if (ContentState::isSet($table) && $op === 'insert' && $row['values'] === []) {
            throw $bad('ein neues Paar braucht mindestens einen Wert.');
        }
        if ($table === 'posts' && $op === 'insert' && $row['post_status'] === 'trash') {
            throw $bad('ein neuer Beitrag kann nicht im Papierkorb liegen.');
        }
        return ['op' => $op, 'table' => $table, 'key' => $key, 'expected' => $expected, 'row' => $row, 'dates' => null];
    }

    /**
     * row einer Zeile mit op trash: genau post_date und post_date_gmt, base64, als Datum mit Zeit.
     *
     * @param mixed $raw
     * @return array{post_date: string, post_date_gmt: string}|null null: nicht diese Form
     */
    private static function dates($raw): ?array
    {
        $names = ['post_date', 'post_date_gmt'];
        if (!is_array($raw) || count($raw) !== 2 || array_diff($names, array_keys($raw)) !== []) {
            return null;
        }
        $out = [];
        foreach ($names as $name) {
            $value = is_string($raw[$name]) ? base64_decode($raw[$name], true) : false;
            if ($value === false || preg_match(self::DATE, $value) !== 1) {
                return null;
            }
            $out[$name] = $value;
        }
        return $out;
    }

    private static function validKey(string $table, string $key): bool
    {
        if (preg_match('//u', $key) !== 1) {
            return false;
        }
        if (isset(ContentState::PK[$table])) {
            return preg_match(self::ID, $key) === 1;
        }
        if ($table === 'options') {
            return $key !== '' && strlen($key) <= 191 && preg_match('/[\x00-\x1f\x7f]/', $key) !== 1;
        }
        list($object, $name) = ContentState::split($key);
        if (preg_match(self::ID, $object) !== 1 || $name === '' || strlen($name) > 255 || preg_match('/[\x00-\x1f\x7f]/', $name) === 1) {
            return false;
        }
        return $table !== 'term_relationships' || preg_match('/^[a-z0-9_-]{1,32}\z/', $name) === 1;
    }

    /**
     * @param array<mixed> $raw
     * @return array{values: list<string|null>}|null
     */
    private static function set(string $table, array $raw): ?array
    {
        if (array_keys($raw) !== ['values'] || !is_array($raw['values']) || array_values($raw['values']) !== $raw['values']) {
            return null;
        }
        $values = [];
        foreach ($raw['values'] as $packed) {
            if ($packed === null && $table !== 'term_relationships') {
                $values[] = null;
                continue;
            }
            $value = is_string($packed) ? base64_decode($packed, true) : false;
            if ($value === false || ($table === 'term_relationships' && preg_match('/^[1-9][0-9]{0,17}:[0-9]{1,10}\z/', $value) !== 1)) {
                return null;
            }
            $values[] = $value;
        }
        return ['values' => $values];
    }

    /**
     * @param array<mixed> $raw
     * @return array<string, string>|null
     */
    private static function columns(string $table, array $raw): ?array
    {
        $names = ContentState::COLUMNS[$table];
        if (count($raw) !== count($names) || array_diff($names, array_keys($raw)) !== []) {
            return null;
        }
        $row = [];
        foreach ($names as $name) {
            $value = is_string($raw[$name]) ? base64_decode($raw[$name], true) : false; // null: nie – auch Optionen werden nie gelöscht (§7.1)
            if ($value === false) {
                return null;
            }
            $row[$name] = $value;
        }
        $fits = static function (array $rules) use ($row): bool {
            foreach ($rules as $name => $pattern) {
                if (preg_match($pattern, $row[$name]) !== 1) {
                    return false;
                }
            }
            return true;
        };
        switch ($table) {
            case 'posts':
                return $fits([
                    'post_date' => self::DATE, 'post_date_gmt' => self::DATE, 'post_status' => '/^[a-z0-9_-]{1,20}\z/',
                    'post_type' => '/^[a-z0-9_-]{1,20}\z/', 'post_parent' => '/^[0-9]{1,18}\z/', 'menu_order' => '/^-?[0-9]{1,10}\z/',
                    'comment_status' => '/^[a-z0-9_-]{0,20}\z/', 'ping_status' => '/^[a-z0-9_-]{0,20}\z/',
                ]) && strlen($row['post_name']) <= 200 && strlen($row['post_mime_type']) <= 100 && strlen($row['post_password']) <= 255 ? $row : null;
            case 'terms':
                return $fits(['term_group' => '/^[0-9]{1,10}\z/']) && strlen($row['name']) <= 200 && strlen($row['slug']) <= 200 ? $row : null;
            case 'term_taxonomy':
                return $fits(['term_id' => self::ID, 'taxonomy' => '/^[a-z0-9_-]{1,32}\z/', 'parent' => '/^[0-9]{1,18}\z/']) ? $row : null;
        }
        return $row;
    }

    private static function invalid(string $why): ContentException
    {
        return new ContentException(ContentException::INVALID, 'Das Paket ist ungültig: ' . $why);
    }
}
