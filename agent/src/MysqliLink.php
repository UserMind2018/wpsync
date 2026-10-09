<?php
namespace WpSync;

defined('ABSPATH') || defined('WPSYNC_RESCUE') || exit;

/**
 * RescueLink auf mysqli (Spec Content-Push P3 §6.2): verbindet wie wpdb::db_connect() –
 * mysqli_real_connect(host, user, password, null, port, socket, flags) –, mit kurzen Wartezeiten
 * und ohne jede Ausgabe. mysqli meldet Fehler hier nie selbst (MYSQLI_REPORT_OFF), und jeder
 * Aufruf ist still (quiet()): die Warnung eines gescheiterten Verbindungsaufbaus nennte Benutzer
 * und Host und stünde sonst im Fehlerprotokoll. Eine echte Datenbank sieht diese Klasse nur im E2E.
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
     * Was vor dem Verbindungsaufbau gesetzt wird: kurze Wartezeiten – und LOAD DATA LOCAL INFILE aus.
     * Über diese Verbindung liest kein Datenbankserver eine Datei des Webservers, auch kein fremder,
     * auf den ein veränderter DNS-Eintrag oder ein übernommener Host zeigt; rescue.php schickt nie
     * eine solche Anweisung.
     *
     * @return array<int, int> Option von mysqli_options() → Wert
     */
    public static function options(): array
    {
        $options = [MYSQLI_OPT_LOCAL_INFILE => 0, MYSQLI_OPT_CONNECT_TIMEOUT => self::CONNECT_SECONDS];
        if (defined('MYSQLI_OPT_READ_TIMEOUT')) {
            $options[(int) constant('MYSQLI_OPT_READ_TIMEOUT')] = self::READ_SECONDS;
        }
        return $options;
    }

    /**
     * @param array<string, mixed> $db host, port, socket, user, password, flags – geprüft von RescueContent::check()
     */
    public static function open(array $db): ?self
    {
        if (!function_exists('mysqli_init') || !function_exists('mysqli_real_connect')) {
            return null;
        }
        $handle = self::quiet(static function () use ($db) {
            mysqli_report(MYSQLI_REPORT_OFF);
            $handle = mysqli_init();
            if (!$handle instanceof \mysqli) {
                return null;
            }
            foreach (self::options() as $option => $value) {
                // Ohne das Verbot von LOCAL INFILE keine Verbindung; eine Wartezeit, die der Treiber nicht kennt, hält nicht auf.
                if (!mysqli_options($handle, $option, $value) && $option === MYSQLI_OPT_LOCAL_INFILE) {
                    return null;
                }
            }
            $port   = isset($db['port']) && is_int($db['port']) ? $db['port'] : null;
            $socket = isset($db['socket']) && is_string($db['socket']) ? $db['socket'] : null;
            $flags  = isset($db['flags']) && is_int($db['flags']) ? $db['flags'] : 0;
            $ok     = mysqli_real_connect($handle, (string) ($db['host'] ?? ''), (string) ($db['user'] ?? ''), (string) ($db['password'] ?? ''), null, $port, $socket, $flags);
            return $ok && mysqli_connect_errno() === 0 ? $handle : null;
        });
        return $handle instanceof \mysqli ? new self($handle) : null;
    }

    /** Für ContentSql::note(): die Fehlernummer fürs Protokoll kommt von hier. */
    public function handle(): \mysqli
    {
        return $this->handle;
    }

    public function query(string $sql)
    {
        $handle = $this->handle;
        $rows   = self::quiet(static function () use ($handle, $sql) {
            $result = mysqli_query($handle, $sql);
            if (!$result instanceof \mysqli_result) {
                return $result === true;
            }
            $rows = [];
            while (is_array($row = mysqli_fetch_assoc($result))) {
                $rows[] = $row;
            }
            mysqli_free_result($result);
            return $rows;
        });
        return is_array($rows) ? $rows : $rows === true;
    }

    public function escape(string $value): string
    {
        return mysqli_real_escape_string($this->handle, $value);
    }

    public function errno(): int
    {
        $handle = $this->handle;
        return (int) self::quiet(static function () use ($handle): int {
            return (int) mysqli_errno($handle);
        });
    }

    public function charset(string $charset): bool
    {
        $handle = $this->handle;
        return true === self::quiet(static function () use ($handle, $charset): bool {
            return (bool) mysqli_set_charset($handle, $charset);
        });
    }

    public function select(string $database): bool
    {
        $handle = $this->handle;
        return true === self::quiet(static function () use ($handle, $database): bool {
            return (bool) mysqli_select_db($handle, $database);
        });
    }

    public function close(): void
    {
        $handle = $this->handle;
        self::quiet(static function () use ($handle): bool {
            return mysqli_close($handle);
        });
    }

    /**
     * Ruft mysqli, ohne dass eine Warnung den Prozess verlässt: nicht in die Antwort, nicht ins
     * Fehlerprotokoll, nicht in error_get_last() und nicht zu einem Fehler-Handler, den ein Plugin
     * gesetzt hat (ein @ allein hielte sie von den beiden letzten nicht fern). Die Meldung eines
     * gescheiterten Verbindungsaufbaus nennt Benutzer und Host.
     *
     * @param callable(): mixed $call
     * @return mixed was $call liefert; null, wenn es wirft
     */
    private static function quiet(callable $call)
    {
        set_error_handler(static function (): bool {
            return true;
        });
        try {
            return $call();
        } catch (\Throwable $e) {
            return null;
        } finally {
            restore_error_handler();
        }
    }
}
