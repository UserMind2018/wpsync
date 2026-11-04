<?php
namespace WpSync;

defined('ABSPATH') || exit;

/**
 * Datenbank-Schnappschuss für den Pull (Spec 0.11.0): begin legt einen Job an, antwortet sofort und
 * dumpt danach im selben Prozess über eine eigene Verbindung in einer Transaktion (SnapshotDump) in
 * eine verschlüsselte Datei (SnapshotFile). status, blocks und delete bedienen das CLI. Ein Job je
 * Site; Zustand in wpsync_state (STATE), Schlüssel nur dort.
 */
final class Snapshot
{
    public const STATE     = 'snapshot';
    public const DIR_STATE = 'snapshot_dir';
    public const CRON      = 'wpsync_snapshot_cleanup';
    public const DOWNLOAD_BLOCKS = 16;
    /** Reserve über der Schätzung, bevor no_space gemeldet wird (D7). */
    public const SPACE_RESERVE = 67108864;

    private const FILE_RE = '/^wpsync-snap-[a-f0-9]{32}\.bin\z/';
    private const DENY    = "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n";

    public static function register(): void
    {
        add_action(self::CRON, [self::class, 'cleanup']);
        add_action('init', static function (): void {
            if (!wp_next_scheduled(self::CRON)) {
                wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', self::CRON);
            }
        });
    }

    public static function unschedule(): void
    {
        wp_clear_scheduled_hook(self::CRON);
    }

    /** Deaktivierung: Job und Datei weg, Reste ebenso. */
    public static function uninstall(): void
    {
        $job = Store::getState(self::STATE);
        if (is_array($job)) {
            @unlink((string) ($job['file'] ?? ''));
        }
        Store::setState(self::STATE, null);
        self::removeLeftovers(PHP_INT_MAX, null);
    }

    /**
     * @param array<string, mixed> $params signierter JSON-Body
     * @return \WP_REST_Response|\WP_Error
     */
    public static function begin(array $params, string $keyId)
    {
        $id = (string) ($params['id'] ?? '');
        if (preg_match(SnapshotJob::ID, $id) !== 1) {
            return new \WP_Error('wpsync_snapshot_id', 'invalid snapshot id', ['status' => 400]);
        }
        $job = Store::getState(self::STATE);
        if (is_array($job) && ($job['id'] ?? '') === $id) {
            return self::status(['id' => $id]); // Wiederholung (Backoff nach 502/504): derselbe Job (B1)
        }
        try {
            $scope = Scope::fromArray($params['scope'] ?? null);
        } catch (\InvalidArgumentException $e) {
            return new \WP_Error('wpsync_scope', 'invalid scope: ' . $e->getMessage(), ['status' => 400]);
        }
        self::cleanup();
        if (is_array(Store::getState(self::STATE))) {
            return new \WP_Error('wpsync_snapshot_busy', 'another snapshot is running', ['status' => 409]);
        }
        if (!self::lockFree(Store::lockName('push'))) {
            return new \WP_Error('wpsync_snapshot_busy', 'a push is running', ['status' => 409]); // C9
        }
        $reason = self::unsupported();
        $dir    = $reason === null ? self::dir() : null;
        if ($reason === null && $dir === null) {
            $reason = 'no_dir';
        }
        if ($reason === null && !self::enoughSpace((string) $dir, $scope)) {
            $reason = 'no_space';
        }
        if ($reason !== null) {
            return new \WP_Error('wpsync_snapshot_unsupported', $reason, ['status' => 422]);
        }

        $tables = [];
        $names  = array_values(array_intersect(array_filter((array) ($params['tables'] ?? []), 'is_string'), Store::dataTables()));
        foreach ($names as $name) {
            $mode = $scope->tableMode($name);
            if ($mode !== Scope::SKIP) {
                $tables[] = ['name' => $name, 'mode' => $mode];
            }
        }
        $limit = (int) ($params['limit'] ?? 0);
        $limit = min(20000, max(1, $limit > 0 ? $limit : SnapshotDump::CHUNK));
        $key   = random_bytes(32);
        $file  = $dir . '/wpsync-snap-' . bin2hex(random_bytes(16)) . '.bin';
        $job   = SnapshotJob::start($id, $keyId, $file, $key, count($tables), time());
        if (!Store::setState(self::STATE, $job)) {
            return new \WP_Error('wpsync_snapshot_state', 'snapshot state not stored', ['status' => 500]);
        }

        ignore_user_abort(true);
        $released = self::release(SnapshotJob::view($job, time())); // C1: Antwort jetzt, Arbeit danach
        self::work($job, $tables, $scope, $limit, $key);
        if ($released) {
            exit;
        }
        $final = Store::getState(self::STATE);
        return new \WP_REST_Response(SnapshotJob::view(is_array($final) ? $final : $job, time()));
    }

    /**
     * @param array<string, mixed> $params
     * @return \WP_REST_Response|\WP_Error
     */
    public static function status(array $params)
    {
        $job = self::job($params);
        if ($job === null) {
            return new \WP_Error('wpsync_snapshot_unknown', 'unknown snapshot', ['status' => 404]);
        }
        $now = time();
        if (SnapshotJob::stalled($job, self::lockFree(Store::lockName('snapshot')), $now)) {
            $job = SnapshotJob::failed($job, 'stalled', 'snapshot process ended without result', $now);
            Store::setState(self::STATE, $job);
            @unlink((string) $job['file']);
        }
        return new \WP_REST_Response(SnapshotJob::view($job, $now));
    }

    /**
     * Klartext-Blöcke from … from+count-1, so viele das Budget erlaubt (B4).
     *
     * @param array<string, mixed> $params
     * @return \WP_Error|void
     */
    public static function blocks(array $params)
    {
        $job = self::job($params);
        $now = time();
        if ($job === null || ($job['state'] ?? '') !== SnapshotJob::DONE || SnapshotJob::expired($job, true, $now)) {
            return new \WP_Error('wpsync_snapshot_state', 'snapshot not ready', ['status' => 409]);
        }
        $total = (int) $job['blocks'];
        $from  = (int) ($params['from'] ?? -1);
        $count = min(self::DOWNLOAD_BLOCKS, max(1, (int) ($params['count'] ?? 1)));
        if ($from < 0 || $from >= $total) {
            return new \WP_Error('wpsync_snapshot_range', 'block out of range', ['status' => 400]);
        }
        $key      = (string) base64_decode((string) $job['key'], true);
        $deadline = microtime(true) + Budget::seconds((int) ini_get('max_execution_time'));
        $blocks   = [];
        for ($i = $from; $i < min($total, $from + $count); $i++) {
            if ($i > $from && microtime(true) > $deadline) {
                break;
            }
            $plain = SnapshotFile::block((string) $job['file'], $key, (string) $job['id'], $i, $total);
            if ($plain === null) {
                if ($i === $from) {
                    return new \WP_Error('wpsync_snapshot_corrupt', 'snapshot block unreadable', ['status' => 500]);
                }
                break;
            }
            $blocks[] = $plain;
        }
        $body = implode('', $blocks);
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        nocache_headers();
        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . strlen($body));
        header('X-Wpsync-Block-From: ' . $from);
        header('X-Wpsync-Blocks: ' . count($blocks));
        echo $body; // phpcs:ignore WordPress.Security.EscapeOutput
        exit;
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function delete(array $params): \WP_REST_Response
    {
        $job = self::job($params);
        if ($job === null) {
            return new \WP_REST_Response(['deleted' => false]);
        }
        if (($job['state'] ?? '') === SnapshotJob::RUNNING && !self::lockFree(Store::lockName('snapshot'))) {
            return new \WP_REST_Response(['deleted' => false]); // läuft noch; das Aufräumen holt ihn später
        }
        @unlink((string) $job['file']);
        Store::setState(self::STATE, null);
        return new \WP_REST_Response(['deleted' => true]);
    }

    /** Stündlich und vor jedem begin: abgelaufene Jobs und Reste ohne Datensatz (D6). */
    public static function cleanup(): void
    {
        $now = time();
        $job = Store::getState(self::STATE);
        if (is_array($job) && SnapshotJob::expired($job, self::lockFree(Store::lockName('snapshot')), $now)) {
            @unlink((string) ($job['file'] ?? ''));
            Store::setState(self::STATE, null);
            $job = null;
        }
        self::removeLeftovers($now - SnapshotJob::MAX_AGE, is_array($job) ? (string) ($job['file'] ?? '') : null);
    }

    /** Warum es hier keinen Schnappschuss gibt; null = möglich (B3). Die Ablage prüft begin. */
    public static function unsupported(): ?string
    {
        if (defined('WPSYNC_NO_SNAPSHOT') && WPSYNC_NO_SNAPSHOT) {
            return 'disabled';
        }
        if (!SnapshotFile::available()) {
            return 'no_crypto_or_zlib';
        }
        if (!function_exists('mysqli_init') || self::connection() === null) {
            return 'driver';
        }
        return null;
    }

    /**
     * Ablage (D4): ausserhalb des Webroots, sonst wp-content/wpsync-snap-<zufall>; null = keine beschreibbar.
     */
    public static function dir(): ?string
    {
        foreach (self::dirCandidates() as $dir) {
            if (self::prepare($dir)) {
                return $dir;
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $job
     * @param list<array{name: string, mode: string}> $tables
     */
    private static function work(array $job, array $tables, Scope $scope, int $limit, string $key): void
    {
        global $wpdb;
        $started = microtime(true);
        if (function_exists('set_time_limit') && @set_time_limit(SnapshotJob::MAX_SECONDS + 60) !== false) {
            $deadline = $started + SnapshotJob::MAX_SECONDS;
        } else {
            $ini      = (int) ini_get('max_execution_time');
            $deadline = $started + ($ini > 0 ? max(10, $ini - 15) : SnapshotJob::MAX_SECONDS);
        }
        $lockName = Store::lockName('snapshot');
        $link     = null;
        $out      = null;
        try {
            $db   = self::connection();
            $link = $db === null ? null : MysqliLink::open($db);
            if ($link === null) {
                throw SnapshotException::failed('connect_failed', 'snapshot connection failed');
            }
            if (!$link->charset((string) $wpdb->charset) || !$link->select((string) $db['name'])) {
                throw SnapshotException::failed('connect_failed', 'snapshot charset or database not set');
            }
            $lock = $link->query("SELECT GET_LOCK('" . $link->escape($lockName) . "', 0) AS v");
            if (!is_array($lock) || (string) ($lock[0]['v'] ?? '') !== '1') {
                throw SnapshotException::failed('busy', 'snapshot lock held');
            }
            $full = [];
            foreach ($tables as $t) {
                if ($t['mode'] === Scope::FULL) {
                    $full[] = $t['name'];
                }
            }
            $inconsistent = SnapshotDump::nonTransactional($link, $full);
            $out          = SnapshotFile::create((string) $job['file'], $key, (string) $job['id']);
            $anonymizer   = $scope->anonymize() ? new Anonymizer(Store::anonKey(), (string) $wpdb->base_prefix) : null;
            $dump         = new TableDump($link, $scope, Rest::core(), (string) $wpdb->options, $anonymizer);
            $last         = 0.0;
            $file         = $out;
            $rows         = SnapshotDump::run($link, $dump, $tables, $out, $deadline, static function (string $table, int $done) use (&$job, &$last, $file): void {
                if (defined('WPSYNC_TEST_HOOKS') && WPSYNC_TEST_HOOKS) {
                    do_action('wpsync_snapshot_chunk', $table, $done); // nur E2E (C11)
                }
                $now = microtime(true);
                if ($now - $last >= 1.0) {
                    $last = $now;
                    $job  = SnapshotJob::progress($job, $table, $done, $file->plainBytes(), time());
                    Store::setState(self::STATE, $job); // über $wpdb, nicht in der Snapshot-Transaktion (C3)
                }
            }, static function (): float {
                return microtime(true);
            }, $limit);
            $result = $out->finish();
            $out    = null;
            $job    = SnapshotJob::done($job, $result, $rows, $inconsistent ?? [], time());
            Store::setState(self::STATE, $job);
        } catch (\Throwable $e) {
            if ($out !== null) {
                $out->abort();
            }
            $code = $e instanceof SnapshotException ? $e->reason : 'internal';
            $text = $e instanceof SnapshotException ? $e->getMessage() : get_class($e); // nie Pfade oder Zugangsdaten (I3)
            $job  = SnapshotJob::failed($job, $code, $text, time());
            Store::setState(self::STATE, $job);
            @unlink((string) $job['file']);
        } finally {
            if ($link !== null) {
                $link->query("SELECT RELEASE_LOCK('" . $link->escape($lockName) . "')");
                $link->close();
            }
        }
    }

    /**
     * Schickt die Antwort und trennt die Verbindung, wenn der Server das kann (PHP-FPM, LiteSpeed).
     *
     * @param array<string, mixed> $body
     */
    private static function release(array $body): bool
    {
        $finish = function_exists('fastcgi_finish_request') ? 'fastcgi_finish_request'
            : (function_exists('litespeed_finish_request') ? 'litespeed_finish_request' : null);
        if ($finish === null || headers_sent()) {
            return false;
        }
        $json = (string) wp_json_encode($body);
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Length: ' . strlen($json));
        echo $json; // phpcs:ignore WordPress.Security.EscapeOutput
        $finish();
        return true;
    }

    /**
     * Der Job zur id im Body; null, wenn es keinen mit dieser id gibt.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    private static function job(array $params): ?array
    {
        $id  = (string) ($params['id'] ?? '');
        $job = Store::getState(self::STATE);
        if (preg_match(SnapshotJob::ID, $id) !== 1 || !is_array($job) || ($job['id'] ?? '') !== $id) {
            return null;
        }
        return $job;
    }

    private static function lockFree(string $name): bool
    {
        global $wpdb;
        return (string) $wpdb->get_var($wpdb->prepare('SELECT IS_FREE_LOCK(%s)', $name)) === '1';
    }

    /**
     * Verbindung wie wpdb::db_connect() – nur mysqli direkt, wie PushContent::rescueData() (C3).
     *
     * @return array<string, mixed>|null
     */
    private static function connection(): ?array
    {
        global $wpdb;
        if (!is_object($wpdb) || !(($wpdb->dbh ?? null) instanceof \mysqli) || !method_exists($wpdb, 'parse_db_host')
            || is_a($wpdb, 'hyperdb') || is_a($wpdb, 'LudicrousDB')
            || !defined('DB_HOST') || !defined('DB_USER') || !defined('DB_PASSWORD') || !defined('DB_NAME')) {
            return null;
        }
        $host   = (string) constant('DB_HOST');
        $port   = null;
        $socket = null;
        $parsed = $wpdb->parse_db_host($host);
        if (is_array($parsed) && count($parsed) >= 4) {
            list($host, $port, $socket, $ipv6) = array_values($parsed);
            if ($ipv6 && extension_loaded('mysqlnd')) {
                $host = '[' . $host . ']';
            }
        }
        return [
            'host'     => (string) $host,
            'port'     => $port === null || (int) $port === 0 ? null : (int) $port,
            'socket'   => $socket === null || (string) $socket === '' ? null : (string) $socket,
            'user'     => (string) constant('DB_USER'),
            'password' => (string) constant('DB_PASSWORD'),
            'name'     => (string) constant('DB_NAME'),
            'flags'    => (defined('MYSQL_CLIENT_FLAGS') ? (int) constant('MYSQL_CLIENT_FLAGS') : 0) & RescueContent::FLAGS,
        ];
    }

    /** @return list<string> */
    private static function dirCandidates(): array
    {
        $name    = self::dirName();
        $out     = [];
        $outside = dirname(rtrim(ABSPATH, '/\\')) . '/' . $name;
        if (!self::insideDocroot($outside)) {
            $out[] = $outside;
        }
        $out[] = WP_CONTENT_DIR . '/' . $name;
        return $out;
    }

    /** Ordnername einmal gewürfelt, wie Store::pushDirName(). */
    private static function dirName(): string
    {
        $state = Store::getState(self::DIR_STATE);
        if (!is_array($state) || preg_match('/^wpsync-snap-[a-f0-9]{16}\z/', (string) ($state['name'] ?? '')) !== 1) {
            Store::setState(self::DIR_STATE, ['name' => Excludes::SNAPSHOT_DIR_PREFIX . bin2hex(random_bytes(8))]);
            $state = Store::getState(self::DIR_STATE);
        }
        return (string) ($state['name'] ?? '');
    }

    /** Unbekannter Webroot zählt als „drinnen“. */
    private static function insideDocroot(string $path): bool
    {
        $root   = isset($_SERVER['DOCUMENT_ROOT']) ? @realpath((string) $_SERVER['DOCUMENT_ROOT']) : false;
        $parent = @realpath(dirname($path));
        if (!is_string($root) || $root === '' || !is_string($parent)) {
            return true;
        }
        return strpos($parent . '/', rtrim($root, '/') . '/') === 0;
    }

    private static function prepare(string $dir): bool
    {
        if (basename($dir) === '' || strpos(basename($dir), Excludes::SNAPSHOT_DIR_PREFIX) !== 0) {
            return false;
        }
        if (@is_link($dir)) {
            return false;
        }
        if (!@is_dir($dir) && !@mkdir($dir, 0700)) {
            return false;
        }
        if (!@is_writable($dir)) {
            return false;
        }
        if (!@is_file($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', self::DENY);
        }
        if (!@is_file($dir . '/index.php')) {
            @file_put_contents($dir . '/index.php', "<?php\n// Silence is golden.\n");
        }
        return true;
    }

    /** D7: genug Platz für die vollen Tabellen plus Reserve? Unbekannt = ja. */
    private static function enoughSpace(string $dir, Scope $scope): bool
    {
        if (!function_exists('disk_free_space')) {
            return true;
        }
        $free = @disk_free_space($dir);
        if (!is_float($free) && !is_int($free)) {
            return true;
        }
        try {
            $need = StagingDb::bytes($scope);
        } catch (StagingException $e) {
            return true;
        }
        return $free >= $need + self::SPACE_RESERVE;
    }

    /** Dateien wpsync-snap-*.bin älter als $before in den Ablagen – ausser $keep (scandir, kein glob). */
    private static function removeLeftovers(int $before, ?string $keep): void
    {
        foreach (self::dirCandidates() as $dir) {
            $names = @scandir($dir);
            if (!is_array($names)) {
                continue;
            }
            foreach ($names as $name) {
                $path = $dir . '/' . $name;
                if (preg_match(self::FILE_RE, $name) === 1 && $path !== $keep && @is_file($path) && (int) @filemtime($path) < $before) {
                    @unlink($path);
                }
            }
        }
    }
}
