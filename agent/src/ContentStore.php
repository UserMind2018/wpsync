<?php
namespace WpSync;

defined('ABSPATH') || exit;

/**
 * Lesen und Schreiben der sieben Inhaltstabellen eines Ziels (Spec Content-Push §7.3) mit einem
 * einheitlichen Rohzustand je Schlüssel – Schlüssel wie in Manifest und Paket:
 *   posts, terms, term_taxonomy, options   die ganze Zeile, Spalte → Wert, wie die Datenbank sie liefert
 *   postmeta, termmeta                     ['values' => [alle meta_value des Paars, nach meta_id]]
 *   term_relationships                     ['values' => ['<term_taxonomy_id>:<term_order>', …]] dieser Taxonomie
 * null heisst: den Schlüssel gibt es nicht. Das Vorher-Abbild eines Pushs ist genau dieser
 * Rohzustand; die Rücknahme schreibt ihn zurück. ContentSql spricht mit $wpdb, die Unit-Tests
 * mit einer Attrappe im Speicher.
 */
interface ContentStore
{
    /**
     * @param list<string> $tables Inhaltstabellen ohne Präfix
     * @return array<string, string> Tabelle → Engine ('' wenn unbekannt)
     * @throws ContentException
     */
    public function engines(array $tables): array;

    /**
     * Höchste ID, die eine Zähler-Tabelle (posts, terms, term_taxonomy) des Ziels schon vergeben
     * hat: max(MAX(id), AUTO_INCREMENT − 1) – wie id_max im Manifest-Kopf.
     *
     * @throws ContentException
     * @throws \InvalidArgumentException wenn die Tabelle keinen Zähler hat
     */
    public function idMax(string $table): int;

    /**
     * @param list<string> $keys
     * @param bool         $lock SELECT … FOR UPDATE – nur innerhalb von transaction()
     * @return array<string, array<string, mixed>|null> Schlüssel → Rohzustand, null wenn es ihn nicht gibt
     * @throws ContentException
     */
    public function read(string $table, array $keys, bool $lock): array;

    /**
     * Setzt den Rohzustand eines Schlüssels; null löscht ihn. Eine Zeile wird angelegt oder mit
     * allen genannten Spalten überschrieben, ein Paar bzw. die Zuordnungen einer Taxonomie werden
     * als ganze Menge ersetzt.
     *
     * @param array<string, mixed>|null $state
     * @throws ContentException
     */
    public function write(string $table, string $key, ?array $state): void;

    /**
     * @param list<string> $termIds
     * @return array<string, list<string>> term_id → Taxonomien des Terms
     * @throws ContentException
     */
    public function taxonomies(array $termIds): array;

    /**
     * Schlüssel, zu denen es auf dem Ziel einen Zwilling gibt: für die Datenbank gleich (Kollation,
     * etwa Gross/klein), in den Bytes verschieden. Über so einen Alias träfe ein Paket eine Zeile,
     * die die bytegenauen Listen nie gesehen haben. Nur postmeta, termmeta und options kennen das.
     *
     * @param list<string> $keys
     * @return list<string> die betroffenen Schlüssel
     * @throws ContentException
     */
    public function aliases(string $table, array $keys): array;

    /**
     * Alle Zuordnungen der Objekte, roh: auch die ohne term_taxonomy-Zeile (verwaist), die read()
     * keiner Taxonomie zuordnen kann.
     *
     * @param list<string> $objectIds
     * @param bool         $lock      wie bei read()
     * @return array<string, list<string>> object_id → term_taxonomy_ids
     * @throws ContentException
     */
    public function relations(array $objectIds, bool $lock): array;

    /**
     * Was an Objekten hängt – für die Rücknahme, bevor sie ein eingefügtes Objekt samt Anhang
     * löscht (purge()): sie vergleicht es mit dem, was der Push selbst geschrieben hat. Je ID:
     *   posts          meta: Meta-Schlüssel; relations: Schlüssel <ID>\0<Taxonomie> seiner Zuordnungen
     *                  (<ID>\0 für verwaiste, ohne term_taxonomy-Zeile); comments: Zahl seiner
     *                  Kommentare; children: IDs der Beiträge mit diesem post_parent (auch Revisionen)
     *   terms          meta: Meta-Schlüssel; children: term_taxonomy_ids des Terms
     *   term_taxonomy  relations: Schlüssel <object_id>\0<Taxonomie> jeder Zuordnung auf diese Zeile;
     *                  children: term_taxonomy_ids der Kind-Terme (parent = term_id, dieselbe Taxonomie)
     *
     * @param list<string> $ids
     * @param bool         $lock wie bei read()
     * @return array<string, array{meta: list<string>, relations: list<string>, comments: int, children: list<string>}>
     * @throws ContentException
     */
    public function attached(string $table, array $ids, bool $lock): array;

    /**
     * Was an einem eingefügten Objekt hängt, wenn es wieder verschwindet: posts → alle Meta und
     * Zuordnungen des Beitrags, terms → alle Meta des Terms, term_taxonomy → alle Zuordnungen.
     *
     * @throws ContentException
     */
    public function purge(string $table, string $key): void;

    /**
     * Setzt count der genannten term_taxonomy-Zeilen auf die Zahl ihrer Zuordnungen.
     *
     * @param list<string> $termTaxonomyIds
     * @throws ContentException
     */
    public function recount(array $termTaxonomyIds): void;

    /**
     * Löscht jede postmeta-Zeile mit diesem Schlüssel (Cache-Meta von Elementor in der Kopie).
     *
     * @throws ContentException
     */
    public function dropMeta(string $metaKey): void;

    /**
     * Führt $do in einer Transaktion aus: COMMIT, wenn es zurückkehrt, ROLLBACK, wenn es wirft.
     * Kein COMMIT, wenn die Transaktion nicht mehr lebt (alive()). Ging die Verbindung im COMMIT
     * selbst verloren, wirft es ContentException::UNCLEAR.
     *
     * @param callable(): mixed $do
     * @return mixed was $do liefert
     * @throws ContentException
     */
    public function transaction(callable $do);

    /**
     * Läuft die Transaktion noch auf der Verbindung, auf der sie begann? false heisst: der Server
     * hat alles verworfen, was sie bis dahin geschrieben hat; höchstens der eine Schreibzugriff
     * unmittelbar davor lief ausserhalb und steht für sich. Ausserhalb einer Transaktion false.
     */
    public function alive(): bool;
}
