<?php
namespace WpSync;

defined('ABSPATH') || exit;

/**
 * Zustand eines Schnappschuss-Jobs (Spec 0.11.0 C5–C7, D6) als Array in wpsync_state. Rein: keine
 * Datenbank, kein Dateisystem – Snapshot speichert und räumt auf.
 */
final class SnapshotJob
{
    public const ID      = '/^[a-f0-9]{32}\z/';
    public const RUNNING = 'running';
    public const DONE    = 'done';
    public const FAILED  = 'failed';

    /** Ohne Sperre und ohne Herzschlag so lange gilt ein laufender Job als tot. */
    public const STALL_SECONDS = 60;
    /** So lange bleibt ein fertiger Schnappschuss abrufbar. */
    public const DONE_TTL = 3600;
    /** Länger lebt keine Datei. */
    public const MAX_AGE = 21600;
    /** Längste Dauer eines Dumps (und damit der Lese-Transaktion). */
    public const MAX_SECONDS = 900;

    /** @return array<string, mixed> */
    public static function start(string $id, string $keyId, string $file, string $key, int $total, int $now): array
    {
        return [
            'id' => $id, 'key_id' => $keyId, 'state' => self::RUNNING, 'file' => $file, 'key' => base64_encode($key),
            'created' => $now, 'heartbeat' => $now, 'finished' => 0,
            'tables_total' => $total, 'tables_done' => 0, 'table' => '', 'bytes_plain' => 0,
            'blocks' => 0, 'size' => 0, 'sha256' => '', 'rows' => [], 'inconsistent' => [], 'code' => '', 'error' => '',
        ];
    }

    /**
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    public static function progress(array $job, string $table, int $done, int $bytes, int $now): array
    {
        $job['table']       = $table;
        $job['tables_done'] = $done;
        $job['bytes_plain'] = $bytes;
        $job['heartbeat']   = $now;
        return $job;
    }

    /**
     * @param array<string, mixed>                                      $job
     * @param array{blocks: int, size: int, sha256: string, plain: int} $result
     * @param array<string, int>                                        $rows
     * @param list<string>                                              $inconsistent
     * @return array<string, mixed>
     */
    public static function done(array $job, array $result, array $rows, array $inconsistent, int $now): array
    {
        $job['state']        = self::DONE;
        $job['tables_done']  = (int) $job['tables_total'];
        $job['blocks']       = $result['blocks'];
        $job['size']         = $result['size'];
        $job['sha256']       = $result['sha256'];
        $job['bytes_plain']  = $result['plain'];
        $job['rows']         = $rows;
        $job['inconsistent'] = $inconsistent;
        $job['heartbeat']    = $now;
        $job['finished']     = $now;
        return $job;
    }

    /**
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    public static function failed(array $job, string $code, string $error, int $now): array
    {
        $job['state']     = self::FAILED;
        $job['code']      = $code;
        $job['error']     = $error;
        $job['heartbeat'] = $now;
        $job['finished']  = $now;
        return $job;
    }

    /** @param array<string, mixed> $job */
    public static function stalled(array $job, bool $lockFree, int $now): bool
    {
        return ($job['state'] ?? '') === self::RUNNING && $lockFree && (int) ($job['heartbeat'] ?? 0) + self::STALL_SECONDS < $now;
    }

    /** Darf weg: gescheitert, abgelaufen, gestorben oder zu alt. @param array<string, mixed> $job */
    public static function expired(array $job, bool $lockFree, int $now): bool
    {
        $state = $job['state'] ?? '';
        if ((int) ($job['created'] ?? 0) + self::MAX_AGE < $now || $state === self::FAILED) {
            return true;
        }
        if ($state === self::DONE) {
            return (int) ($job['finished'] ?? 0) + self::DONE_TTL < $now;
        }
        return self::stalled($job, $lockFree, $now);
    }

    /**
     * Was /status zeigt – nie Schlüssel, Datei oder Pfad (Spec I3).
     *
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    public static function view(array $job, int $now): array
    {
        $end = (int) ($job['finished'] ?? 0) > 0 ? (int) $job['finished'] : $now;
        return [
            'id'           => (string) $job['id'],
            'state'        => (string) $job['state'],
            'tables_done'  => (int) $job['tables_done'],
            'tables_total' => (int) $job['tables_total'],
            'table'        => (string) $job['table'],
            'bytes_plain'  => (int) $job['bytes_plain'],
            'blocks'       => (int) $job['blocks'],
            'block_size'   => SnapshotFile::BLOCK,
            'size'         => (int) $job['size'],
            'sha256'       => (string) $job['sha256'],
            'rows'         => (object) (array) $job['rows'],
            'inconsistent' => array_values((array) $job['inconsistent']),
            'code'         => (string) $job['code'],
            'error'        => (string) $job['error'],
            'seconds'      => (float) max(0, $end - (int) $job['created']),
        ];
    }
}
