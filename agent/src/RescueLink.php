<?php
namespace WpSync;

defined('ABSPATH') || defined('WPSYNC_RESCUE') || exit;

/**
 * Eine Verbindung zur Datenbank, wie RescueDb sie braucht (Spec Content-Push P3 §6.1): MysqliLink
 * spricht mit mysqli, die Unit-Tests mit einer Attrappe. Keine Methode wirft, keine gibt etwas
 * aus, und keine nennt je den Text eines Fehlers – nur seine Nummer.
 */
interface RescueLink
{
    /**
     * @return list<array<string, string|null>>|bool die Zeilen einer Ergebnismenge; true, wenn die
     *         Anweisung keine liefert; false bei einem Fehler
     */
    public function query(string $sql);

    /** Der Wert für ein Literal in einfachen Anführungszeichen – im Zeichensatz der Verbindung. */
    public function escape(string $value): string;

    /** Nummer des letzten Fehlers; 0 ohne. */
    public function errno(): int;

    /** Setzt den Zeichensatz der Verbindung (mysqli_set_charset): davon hängt escape() ab. */
    public function charset(string $charset): bool;

    /** Wählt die Datenbank (mysqli_select_db). */
    public function select(string $database): bool;

    public function close(): void;
}
