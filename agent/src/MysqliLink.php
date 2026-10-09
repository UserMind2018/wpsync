<?php
namespace WpSync;

defined('ABSPATH') || defined('WPSYNC_RESCUE') || exit;

/**
 * RescueLink auf mysqli (Spec Content-Push P3 §6.2): verbindet wie wpdb::db_connect() –
 * mysqli_real_connect(host, user, password, null, port, socket, flags) –, mit kurzen Wartezeiten
 * und ohne jede Ausgabe. mysqli meldet Fehler hier nie selbst (MYSQLI_REPORT_OFF), und jeder
 * Aufruf ist still: die Warnung eines gescheiterten Verbindungsaufbaus nennte Benutzer und Host
 * und stünde sonst im Fehlerprotokoll. Eine echte Datenbank sieht diese Klasse nur im E2E.
 */
final class MysqliLink implements RescueLink
{
    public const CONNECT_SECONDS = 5;
    public const READ_SECONDS    = 30;

    /** @var \mysqli */
    private $handle;

    private function __construct(\mysqli $handle)
    {
        $this->handle = $handle;
    }

    /**
     * @param array<string, mixed> $db host, port, socket, user, password, flags – geprüft von RescueContent::check()
     */
    public static function open(array $db): ?self
    {
        if (!function_exists('mysqli_init') || !function_exists('mysqli_real_connect')) {
            return null;
        }
        try {
            mysqli_report(MYSQLI_REPORT_OFF);
            $handle = @mysqli_init();
            if (!$handle instanceof \mysqli) {
                return null;
            }
            @mysqli_options($handle, MYSQLI_OPT_CONNECT_TIMEOUT, self::CONNECT_SECONDS);
            if (defined('MYSQLI_OPT_READ_TIMEOUT')) {
                @mysqli_options($handle, (int) constant('MYSQLI_OPT_READ_TIMEOUT'), self::READ_SECONDS);
            }
            $port   = isset($db['port']) && is_int($db['port']) ? $db['port'] : null;
            $socket = isset($db['socket']) && is_string($db['socket']) ? $db['socket'] : null;
            $ok     = @mysqli_real_connect($handle, (string) ($db['host'] ?? ''), (string) ($db['user'] ?? ''), (string) ($db['password'] ?? ''), null, $port, $socket, (int) ($db['flags'] ?? 0));
            if (!$ok || mysqli_connect_errno() !== 0) {
                return null;
            }
            return new self($handle);
        } catch (\Throwable $e) {
            return null; // nie die Meldung: sie nennt Benutzer und Host
        }
    }

    /** Für ContentSql::note(): die Fehlernummer fürs Protokoll kommt von hier. */
    public function handle(): \mysqli
    {
        return $this->handle;
    }

    public function query(string $sql)
    {
        try {
            $result = @mysqli_query($this->handle, $sql);
            if (!$result instanceof \mysqli_result) {
                return $result === true;
            }
            $rows = [];
            while (is_array($row = mysqli_fetch_assoc($result))) {
                $rows[] = $row;
            }
            mysqli_free_result($result);
            return $rows;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function escape(string $value): string
    {
        return mysqli_real_escape_string($this->handle, $value);
    }

    public function errno(): int
    {
        try {
            return (int) @mysqli_errno($this->handle);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    public function charset(string $charset): bool
    {
        try {
            return (bool) @mysqli_set_charset($this->handle, $charset);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function select(string $database): bool
    {
        try {
            return (bool) @mysqli_select_db($this->handle, $database);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function close(): void
    {
        try {
            @mysqli_close($this->handle);
        } catch (\Throwable $e) {
            // schon geschlossen
        }
    }
}
