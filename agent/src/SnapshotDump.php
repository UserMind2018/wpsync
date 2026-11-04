<?php
namespace WpSync;

defined('ABSPATH') || exit;

/**
 * Liest die Tabellen eines Schnappschusses in einer Transaktion (Spec 0.11.0 C4): START TRANSACTION
 * WITH CONSISTENT SNAPSHOT auf einer eigenen Verbindung; jede Abfrage danach sieht denselben Stand,
 * auch die Keyset-Häppchen einer grossen Tabelle. Schreibt Rahmen wie /db-bundle – eine grosse
 * Tabelle in aufeinanderfolgenden Rahmen, der erste mit DROP/CREATE – und am Ende Frames::end().
 */
final class SnapshotDump
{
    public const CHUNK = 2000;

    /**
     * @param list<array{name: string, mode: string}> $tables Modus FULL oder STRUCTURE (SKIP ist schon weg)
     * @param callable(string, int): void           $progress Tabelle, fertige Tabellen – nach jedem Häppchen
     * @param callable(): float                     $clock    microtime(true) bzw. Test-Uhr
     * @return array<string, int> Zeilen je Tabelle
     * @throws SnapshotException
     */
    public static function run(
        RescueLink $link,
        TableDump $dump,
        array $tables,
        SnapshotSink $out,
        float $deadline,
        callable $progress,
        callable $clock,
        int $limit = self::CHUNK
    ): array {
        self::exec($link, 'SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ', 'isolation_failed');
        self::exec($link, 'START TRANSACTION WITH CONSISTENT SNAPSHOT', 'begin_failed');
        $rows = [];
        try {
            foreach ($tables as $i => $table) {
                $name        = $table['name'];
                $rows[$name] = 0;
                if ($table['mode'] === Scope::STRUCTURE) {
                    $sql = $dump->structure($name);
                    if ($sql === null) {
                        throw SnapshotException::read($name, $link->errno());
                    }
                    $out->write(Frames::table($name, 0, strlen($sql)) . $sql . "\n");
                } else {
                    $pk     = $dump->primaryKey($name);
                    $after  = null;
                    $offset = 0;
                    do {
                        if ($clock() > $deadline) {
                            throw SnapshotException::timeout($name);
                        }
                        $chunk = $dump->chunk($name, $pk, $after, $offset, $limit);
                        if ($chunk === null) {
                            throw SnapshotException::read($name, $link->errno());
                        }
                        $out->write(Frames::table($name, $chunk['rows'], strlen($chunk['sql'])) . $chunk['sql'] . "\n");
                        $rows[$name] += $chunk['rows'];
                        $after        = $chunk['next'];
                        $offset      += $chunk['rows'];
                        $progress($name, $i);
                    } while ($chunk['rows'] === $limit);
                }
                $progress($name, $i + 1);
            }
            $out->write(Frames::end());
        } finally {
            // Nur gelesen: ROLLBACK beendet die Transaktion wie COMMIT, auch nach einem Fehler.
            $link->query('ROLLBACK');
        }
        return $rows;
    }

    /**
     * Tabellen unter $names ohne InnoDB: sie werden mitgelesen, aber nicht im Schnappschuss-Zeitpunkt (C8).
     *
     * @param list<string> $names
     * @return list<string>|null null, wenn die Engines nicht lesbar sind
     */
    public static function nonTransactional(RescueLink $link, array $names): ?array
    {
        $rows = $link->query('SELECT TABLE_NAME AS name, ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()');
        if (!is_array($rows)) {
            return null;
        }
        $wanted = array_flip($names);
        $out    = [];
        foreach ($rows as $row) {
            $name = (string) ($row['name'] ?? '');
            if (isset($wanted[$name]) && strcasecmp((string) ($row['engine'] ?? ''), 'InnoDB') !== 0) {
                $out[] = $name;
            }
        }
        return $out;
    }

    /** @throws SnapshotException */
    private static function exec(RescueLink $link, string $sql, string $reason): void
    {
        if ($link->query($sql) === false) {
            throw SnapshotException::failed($reason, $sql . ' failed (errno ' . $link->errno() . ')');
        }
    }
}
