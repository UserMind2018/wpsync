<?php
namespace WpSync;

defined('ABSPATH') || defined('WPSYNC_RESCUE') || exit;

/**
 * Die Fläche von $wpdb, die ContentSql benutzt, ohne WordPress (Spec Content-Push P3 §6): prepare
 * (%s, %d), esc_like, get_results, get_var, query, last_error, hide_errors/show_errors/
 * suppress_errors und dbh – auf einer eigenen Verbindung (RescueLink). ContentSql bleibt die
 * einzige SQL-Implementierung des Inhaltskanals (R3); dieser Adapter verhält sich deshalb in dem,
 * worauf sie baut, wie wpdb:
 *   - Eine verlorene Verbindung (Fehler 2006, 2013) wird einmal neu aufgebaut, samt Sitzung, und
 *     die Abfrage einmal wiederholt (R5). Die Sitzungsmarke von ContentSql macht Schreibzugriffe
 *     auf der neuen Verbindung wirkungslos.
 *   - Die Sitzung trägt Zeichensatz, Kollation und sql_mode der wpdb-Sitzung (R6).
 * Kein Fehlertext des Servers verlässt die Klasse: last_error ist '' oder 'error'.
 */
final class RescueDb
{
    /** MySQL server has gone away / Lost connection to MySQL server during query. */
    public const GONE = [2006, 2013];

    private const NAME = '/^[A-Za-z0-9_]{1,64}\z/';
    private const MODE = '/^[A-Z0-9_,]*\z/';
    private const INT  = '/^-?[0-9]{1,19}\z/';

    /** @var string '' oder 'error' – nie der Text des Servers (er nennt Werte, Benutzer und Host) */
    public $last_error = '';
    /** @var \mysqli|null für ContentSql::note(): nur die Fehlernummer geht ins Protokoll */
    public $dbh = null;
    /** @var RescueLink */
    private $link;
    /** @var (callable(): ?RescueLink)|null baut Verbindung und Sitzung neu auf */
    private $dial;

    /** @param (callable(): ?RescueLink)|null $dial */
    public function __construct(RescueLink $link, ?callable $dial = null)
    {
        $this->dial = $dial;
        $this->adopt($link);
    }

    /**
     * Verbindet und baut die Sitzung auf – in dieser Reihenfolge, jeder Fehlschlag ⇒ null:
     * Verbindung, Zeichensatz (mysqli_set_charset, dann SET NAMES … COLLATE … wie
     * wpdb::set_charset()), sql_mode, Datenbank.
     *
     * @param array<string, mixed>                          $db   Feld db des Umschlags
     * @param (callable(array<string, mixed>): mixed)|null $open für Tests: liefert die Verbindung anstelle von MysqliLink::open()
     */
    public static function connect(array $db, ?callable $open = null): ?self
    {
        $open = $open ?? [MysqliLink::class, 'open'];
        $dial = static function () use ($open, $db): ?RescueLink {
            try {
                $link = $open($db);
            } catch (\Throwable $e) {
                return null;
            }
            if (!$link instanceof RescueLink) {
                return null;
            }
            if (!self::session($link, $db)) {
                $link->close();
                return null;
            }
            return $link;
        };
        $link = $dial();
        return $link === null ? null : new self($link, $dial);
    }

    /**
     * Setzt die Werte in einem Durchgang ein: %s ⇒ Literal in einfachen Anführungszeichen, %d ⇒
     * die Zahl. Ein Wert, der selbst %s enthält, wird nicht erneut gedeutet. Dieselbe Garantie wie
     * $wpdb->prepare() (Escaping, keine serverseitigen Prepared Statements); sie hängt am
     * Zeichensatz der Verbindung – ohne gesetzten Zeichensatz gibt es keine Sitzung.
     *
     * @param mixed ...$args
     * @throws \InvalidArgumentException bei einem anderen Platzhalter, einer falschen Zahl von Werten oder einem Wert, der keine Zahl ist
     */
    public function prepare(string $sql, ...$args): string
    {
        $link = $this->link;
        $out  = preg_replace_callback('/%(.?)/s', static function (array $m) use (&$args, $link): string {
            if ($m[1] !== 's' && $m[1] !== 'd') {
                throw new \InvalidArgumentException('unsupported placeholder');
            }
            if ($args === []) {
                throw new \InvalidArgumentException('too few values');
            }
            $value = array_shift($args);
            if (!is_string($value) && !is_int($value)) {
                throw new \InvalidArgumentException('unsupported value');
            }
            if ($m[1] === 'd') {
                if (preg_match(self::INT, (string) $value) !== 1) {
                    throw new \InvalidArgumentException('not a number');
                }
                return (string) $value;
            }
            return "'" . $link->escape((string) $value) . "'";
        }, $sql);
        if (!is_string($out) || $args !== []) {
            throw new \InvalidArgumentException('too many values');
        }
        return $out;
    }

    /** Wie wpdb::esc_like(): für ein LIKE-Muster, das danach noch durch prepare() geht. */
    public function esc_like(string $text): string
    {
        return addcslashes($text, '_%\\');
    }

    /**
     * @param mixed $output nur 'ARRAY_A' – etwas anderes benutzt ContentSql nicht
     * @return list<array<string, string|null>>|null null bei einem Fehler
     */
    public function get_results(string $sql, $output = 'ARRAY_A')
    {
        $result = $this->run($sql);
        if ($result === false) {
            return null;
        }
        return is_array($result) ? $result : [];
    }

    /** @return string|null erste Spalte der ersten Zeile; null ohne Zeile, bei NULL und bei einem Fehler */
    public function get_var(string $sql)
    {
        $result = $this->run($sql);
        if (!is_array($result) || !is_array($result[0] ?? null)) {
            return null;
        }
        $value = array_values($result[0])[0] ?? null;
        return $value === null ? null : (string) $value;
    }

    /** @return int|bool false bei einem Fehler; sonst true bzw. die Zahl der Zeilen einer Ergebnismenge */
    public function query(string $sql)
    {
        $result = $this->run($sql);
        if ($result === false) {
            return false;
        }
        return is_array($result) ? count($result) : true;
    }

    /** Es gibt hier nichts auszugeben: die drei Schalter von wpdb sind ohne Wirkung. */
    public function hide_errors(): bool
    {
        return false;
    }

    public function show_errors(bool $show = true): bool
    {
        return false;
    }

    public function suppress_errors(bool $suppress = true): bool
    {
        return true;
    }

    public function close(): void
    {
        $this->link->close();
        $this->dbh = null;
    }

    /**
     * @return list<array<string, string|null>>|bool
     */
    private function run(string $sql)
    {
        $this->last_error = '';
        $result           = $this->link->query($sql);
        if ($result === false && $this->dial !== null && in_array($this->link->errno(), self::GONE, true)) {
            // Wie wpdb: neu verbinden und dieselbe Abfrage noch einmal – einmal. Transaktion und
            // Sperren der alten Verbindung sind weg; das merkt ContentSql an seiner Sitzungsmarke.
            $fresh = ($this->dial)();
            if ($fresh !== null) {
                $this->link->close();
                $this->adopt($fresh);
                $result = $this->link->query($sql);
            }
        }
        if ($result === false) {
            $this->last_error = 'error';
        }
        return $result;
    }

    private function adopt(RescueLink $link): void
    {
        $this->link = $link;
        $this->dbh  = $link instanceof MysqliLink ? $link->handle() : null;
    }

    /** @param array<string, mixed> $db */
    private static function session(RescueLink $link, array $db): bool
    {
        $charset = $db['charset'] ?? '';
        $collate = $db['collate'] ?? '';
        $mode    = $db['sql_mode'] ?? null;
        $name    = $db['name'] ?? null;
        if (!is_string($charset) || !is_string($collate) || !is_string($mode) || !is_string($name) || $name === ''
            || ($charset !== '' && preg_match(self::NAME, $charset) !== 1)
            || ($collate !== '' && preg_match(self::NAME, $collate) !== 1)
            || preg_match(self::MODE, $mode) !== 1) {
            return false;
        }
        // Ohne Zeichensatz setzt auch wpdb nichts: die Verbindung behält den des Servers, und
        // escape() rechnet mit genau dem.
        if ($charset !== '') {
            if (!$link->charset($charset)) {
                return false;
            }
            $names = "SET NAMES '" . $charset . "'" . ($collate === '' ? '' : " COLLATE '" . $collate . "'");
            if ($link->query($names) === false) {
                return false;
            }
        }
        if ($link->query("SET SESSION sql_mode = '" . $mode . "'") === false) {
            return false;
        }
        return $link->select($name);
    }
}
