<?php
namespace WpSync;

defined('ABSPATH') || exit;

/**
 * Fehler eines Schnappschusses mit Code für den Status (Spec 0.11.0 C5). Die Meldung nennt Tabelle
 * und Fehlernummer, nie Text der Datenbank, Pfad oder Zugangsdaten.
 */
final class SnapshotException extends \RuntimeException
{
    /** @var string read_failed, timeout, begin_failed, isolation_failed, connect_failed, busy, write_failed */
    public $reason;

    private function __construct(string $reason, string $message)
    {
        parent::__construct($message);
        $this->reason = $reason;
    }

    public static function read(string $table, int $errno): self
    {
        return new self('read_failed', 'read of ' . $table . ' failed (errno ' . $errno . ')');
    }

    public static function timeout(string $table): self
    {
        return new self('timeout', 'time limit reached at ' . $table);
    }

    public static function failed(string $reason, string $message): self
    {
        return new self($reason, $message);
    }
}
