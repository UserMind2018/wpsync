<?php
namespace WpSync;

defined('ABSPATH') || exit;

/**
 * RescueLink über $wpdb: /db und /db-bundle lesen damit über dieselbe TableDump wie
 * ein Datenbank-Schnappschuss. Escaping wie bisher in Rest::tableSql – mysqli_real_escape_string auf der
 * Verbindung von wpdb, nie wpdb::_real_escape() (das ersetzt % durch Platzhalter). Zeichensatz,
 * Datenbank und Schliessen verwaltet wpdb selbst.
 */
final class WpdbLink implements RescueLink
{
    /** @var object */
    private $wpdb;

    /** @param object $wpdb */
    public function __construct($wpdb)
    {
        $this->wpdb = $wpdb;
    }

    public function query(string $sql)
    {
        $rows = $this->wpdb->get_results($sql, defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A');
        if ((string) ($this->wpdb->last_error ?? '') !== '' || $rows === null) {
            return false;
        }
        $out = [];
        foreach ((array) $rows as $row) {
            $out[] = (array) $row;
        }
        return $out;
    }

    public function escape(string $value): string
    {
        $dbh = $this->wpdb->dbh ?? null;
        return $dbh instanceof \mysqli ? mysqli_real_escape_string($dbh, $value) : addslashes($value);
    }

    public function errno(): int
    {
        $dbh = $this->wpdb->dbh ?? null;
        if ($dbh instanceof \mysqli) {
            return (int) mysqli_errno($dbh);
        }
        return (string) ($this->wpdb->last_error ?? '') !== '' ? 1 : 0;
    }

    public function charset(string $charset): bool
    {
        return true;
    }

    public function select(string $database): bool
    {
        return true;
    }

    public function close(): void
    {
    }
}
