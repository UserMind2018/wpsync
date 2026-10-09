<?php
namespace WpSync;

defined('ABSPATH') || exit;

/**
 * Rohzustand eines Schlüssels (ContentStore) ↔ Fingerabdruck und Ablage. Der Abdruck entsteht
 * über dieselben Builder wie Manifest und Export (ContentReader::columns()/set(), C1) – aus dem,
 * was die Datenbank liefert, normalisiert mit der Origin des Ziels.
 */
final class ContentState
{
    /** Zähler-Tabellen mit ihrem Primärschlüssel – nur für sie gilt der ID-Korridor (§7.1). */
    public const PK = ['posts' => 'ID', 'terms' => 'term_id', 'term_taxonomy' => 'term_taxonomy_id'];

    /** Tabellen mit festen Spalten und die Spalten ihres Abdrucks. */
    public const COLUMNS = [
        'posts'         => Canon::POSTS,
        'terms'         => Canon::TERMS,
        'term_taxonomy' => Canon::TAXONOMY,
        'options'       => ['option_value'],
    ];

    /** Tabellen, deren Schlüssel ein Paar ist und deren Zustand eine Menge. */
    public const SETS = ['postmeta', 'termmeta', 'term_relationships'];

    /** Reihenfolge beim Schreiben: erst die Objekte, dann was an ihnen hängt, zuletzt Optionen. */
    public const ORDER = ['posts', 'terms', 'term_taxonomy', 'postmeta', 'termmeta', 'term_relationships', 'options'];

    public static function isSet(string $table): bool
    {
        return in_array($table, self::SETS, true);
    }

    /** @return array{0: string, 1: string} Objekt-ID und Name (Meta-Schlüssel bzw. Taxonomie) eines Paars */
    public static function split(string $key): array
    {
        $cut = strpos($key, "\0");
        return $cut === false ? [$key, ''] : [(string) substr($key, 0, $cut), (string) substr($key, $cut + 1)];
    }

    /**
     * Datensatz {t, k, h} des Rohzustands – oder {t, k, h: null, why}, wenn er sich nicht
     * normalisieren lässt. null: den Schlüssel gibt es nicht.
     *
     * @param array<string, mixed>|null $raw
     * @return array<string, mixed>|null
     */
    public static function record(ContentReader $reader, string $table, string $key, ?array $raw): ?array
    {
        if ($raw === null) {
            return null;
        }
        if (self::isSet($table)) {
            list($object, $name) = self::split($key);
            return $reader->set($table, $object, $name, array_values((array) ($raw['values'] ?? [])), $table !== 'term_relationships', false);
        }
        return $reader->columns($table, $key, self::COLUMNS[$table], $raw, false);
    }

    /**
     * Abdruck der normalisierten Zeile, wie sie im Paket steht (schon dekodiert).
     *
     * @param array<string, mixed> $row Spalte → Wert bzw. ['values' => […]]
     */
    public static function desired(string $table, string $key, array $row): string
    {
        $body = self::isSet($table)
            ? Canon::set(array_values((array) ($row['values'] ?? [])))
            : Canon::columns(self::COLUMNS[$table], $row);
        return Canon::hash($table, $key, $body);
    }

    /**
     * Rohzustand für before.json: jeder Wert base64, damit auch Binäres und ungültiges UTF-8 in
     * JSON passt.
     *
     * @param array<string, mixed>|null $raw
     * @return array<string, mixed>|null
     */
    public static function encode(string $table, ?array $raw): ?array
    {
        if ($raw === null) {
            return null;
        }
        $pack = static function ($value): ?string {
            return $value === null ? null : base64_encode((string) $value);
        };
        if (self::isSet($table)) {
            return ['values' => array_map($pack, array_values((array) ($raw['values'] ?? [])))];
        }
        return array_map($pack, $raw);
    }

    /**
     * Umkehr von encode().
     *
     * @param mixed $packed
     * @return array<string, mixed>|null
     * @throws ContentException wenn die Ablage nicht die erwartete Form hat
     */
    public static function decode(string $table, $packed): ?array
    {
        if ($packed === null) {
            return null;
        }
        $unpack = static function ($value): ?string {
            if ($value === null) {
                return null;
            }
            $raw = is_string($value) ? base64_decode($value, true) : false;
            if ($raw === false) {
                throw new ContentException(ContentException::FAILED, 'Das Vorher-Abbild ist nicht lesbar.');
            }
            return $raw;
        };
        if (!is_array($packed) || (self::isSet($table) && !is_array($packed['values'] ?? null))) {
            throw new ContentException(ContentException::FAILED, 'Das Vorher-Abbild ist nicht lesbar.');
        }
        if (self::isSet($table)) {
            return ['values' => array_map($unpack, array_values($packed['values']))];
        }
        $out = [];
        foreach ($packed as $column => $value) {
            if (preg_match('/^[A-Za-z0-9_]{1,64}\z/', (string) $column) !== 1) {
                throw new ContentException(ContentException::FAILED, 'Das Vorher-Abbild ist nicht lesbar.');
            }
            $out[(string) $column] = $unpack($value);
        }
        return $out;
    }
}
