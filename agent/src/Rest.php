<?php
namespace WpSync;

defined('ABSPATH') || exit;

final class Rest
{
    public const NS = 'wpsync/v1';

    /** Mindestabstand zwischen zwei Einlöseversuchen, global (SEC-09). */
    private const PAIR_INTERVAL = 1;

    /** @var string */
    private static $pluginDir = '';

    /** @var \WP_REST_Request|null */
    private static $authRequest = null;

    /** @var true|\WP_Error|null */
    private static $authResult = null;

    /** @var Anonymizer|null */
    private static $anonymizer = null;

    /** @var string Key-ID des authentifizierten Aufrufers – Pushes gehören einem Pairing. */
    private static $keyId = '';

    public static function register(string $pluginDir): void
    {
        self::$pluginDir = $pluginDir;
        add_action('rest_api_init', [self::class, 'routes']);
    }

    public static function routes(): void
    {
        if (self::inStagingCopy()) {
            return;
        }
        register_rest_route(self::NS, '/pair', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'pair'],
            'permission_callback' => '__return_true', // geschützt durch Pairing-Code
        ]);
        $signed = [
            'ping'              => 'ping',
            'infosheet'         => 'infosheet',
            'infosheet/refresh' => 'infosheetRefresh',
            'delta'             => 'delta',
            'db-bundle'         => 'dbBundle',
            'db'                => 'dbChunk',
            'files'             => 'files',
            'push/begin'        => 'pushBegin',
            'push/upload'       => 'pushUpload',
            'push/commit'       => 'pushCommit',
            'push/confirm'      => 'pushConfirm',
            'push/rollback'     => 'pushRollback',
            'push/list'         => 'pushList',
            // Staging (Spec 2b 5.2): dieselbe Signaturprüfung; begin und login zusätzlich nur bei offenem
            // Push-Fenster (S6, U44). /staging/login macht den Aufrufer zum Administrator der Kopie – es gibt
            // dafür keinen anderen Weg als diesen. step setzt nur einen Job fort, den begin angelegt hat.
            'staging/begin'     => 'stagingBegin',
            'staging/step'      => 'stagingStep',
            'staging/status'    => 'stagingStatus',
            'staging/login'     => 'stagingLogin',
        ];
        foreach ($signed as $route => $method) {
            register_rest_route(self::NS, '/' . $route, [
                'methods'             => 'POST',
                'callback'            => [self::class, $method],
                'permission_callback' => [self::class, 'auth'],
            ]);
        }
    }

    /**
     * WordPress ruft die Permission-Callback pro Request zweimal auf (Allow-Header). Der zweite
     * Lauf scheiterte bisher an „nonce reused“ und verdoppelte alle Queries (CR-10).
     *
     * @return true|\WP_Error
     */
    public static function auth(\WP_REST_Request $request)
    {
        if (self::inStagingCopy()) {
            return self::stagingCopyError();
        }
        if (self::$authRequest !== $request) {
            self::$authRequest = $request;
            self::$authResult  = self::authenticate($request);
        }
        return self::$authResult;
    }

    /**
     * Ein Agent läuft nie in einer Staging-Kopie (Spec 2b 5.10) – dort gäbe es sonst einen Weg zurück
     * nach Live. wpsync-agent.php bricht dort schon ab; das hier gilt, falls die Klassen doch geladen werden.
     */
    private static function inStagingCopy(): bool
    {
        return defined('WPSYNC_STAGING');
    }

    private static function stagingCopyError(): \WP_Error
    {
        return new \WP_Error('wpsync_staging_copy', 'Der wpsync Agent antwortet in einer Staging-Kopie nicht.', ['status' => 403]);
    }

    /** Ohne TLS liefen Secret (Pairing) und Dumps im Klartext (SEC-03). */
    private static function requireTls(): ?\WP_Error
    {
        if (is_ssl() || (defined('WPSYNC_ALLOW_HTTP') && WPSYNC_ALLOW_HTTP)) {
            return null;
        }
        return new \WP_Error(
            'wpsync_https',
            "wpsync erfordert HTTPS. Nur für lokale Umgebungen: define('WPSYNC_ALLOW_HTTP', true); in wp-config.php.",
            ['status' => 400]
        );
    }

    /** @return true|\WP_Error */
    private static function authenticate(\WP_REST_Request $request)
    {
        $tls = self::requireTls();
        if ($tls !== null) {
            return $tls;
        }
        Store::install();
        $keyId  = (string) $request->get_header('x-wpsync-key');
        $secret = preg_match('/^[a-f0-9]{16}\z/', $keyId) ? Store::secretFor($keyId) : null;
        if ($secret === null) {
            return new \WP_Error('wpsync_unpaired', 'unknown or revoked pairing', ['status' => 401]);
        }

        $nonce     = (string) $request->get_header('x-wpsync-nonce');
        $timestamp = Signature::timestamp((string) $request->get_header('x-wpsync-timestamp'));
        if ($timestamp === null) {
            return new \WP_Error('wpsync_auth', 'invalid timestamp', ['status' => 401]);
        }
        $error = Signature::check(
            $secret,
            $request->get_method(),
            $request->get_route(),
            $timestamp,
            $nonce,
            $request->get_body(),
            (string) $request->get_header('x-wpsync-signature'),
            time()
        );
        if ($error !== null) {
            return new \WP_Error('wpsync_auth', 'invalid ' . $error, ['status' => 401]);
        }
        if (!Store::claimNonce($nonce, time())) {
            return new \WP_Error('wpsync_auth', 'nonce reused', ['status' => 401]);
        }
        Store::touchPairing($keyId);
        self::$keyId = $keyId;
        return true;
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function pair(\WP_REST_Request $request)
    {
        global $wpdb;
        if (self::inStagingCopy()) {
            return self::stagingCopyError();
        }
        $tls = self::requireTls();
        if ($tls !== null) {
            return $tls;
        }
        if (is_multisite()) {
            return new \WP_Error('wpsync_multisite', 'Multisite wird nicht unterstützt.', ['status' => 400]); // AC-31
        }
        if (strpos(wp_normalize_path(WP_CONTENT_DIR) . '/', wp_normalize_path(ABSPATH)) !== 0) {
            return new \WP_Error('wpsync_layout', 'wp-content liegt ausserhalb von ABSPATH – nicht unterstützt.', ['status' => 400]);
        }
        Store::install();

        // Lesen, Prüfen und Zurückschreiben des Codes müssen ein Schritt sein, sonst lösen
        // parallele Requests denselben Code mehrfach ein (SEC-04).
        $lock = Store::lockName('pair');
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $lock)) !== 1) {
            return new \WP_Error('wpsync_busy', 'Bitte gleich noch einmal versuchen.', ['status' => 429]);
        }
        try {
            return self::redeem($request, time());
        } finally {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    /**
     * Läuft nur unter der Pairing-Sperre.
     *
     * @return \WP_REST_Response|\WP_Error
     */
    private static function redeem(\WP_REST_Request $request, int $now)
    {
        $invalid = new \WP_Error('wpsync_code', 'Pairing-Code ungültig oder abgelaufen.', ['status' => 403]);
        $stored  = Store::getState('pairing_code');
        if ($stored === null) {
            return $invalid; // kein offener Code: anonyme Requests lösen keinen Schreibzugriff aus (SEC-09)
        }
        $last = Store::getState('pair_last');
        if ($last !== null && $now - (int) ($last['t'] ?? 0) < self::PAIR_INTERVAL) {
            return new \WP_Error('wpsync_rate', 'Zu viele Versuche – bitte kurz warten.', ['status' => 429]);
        }
        Store::setState('pair_last', ['t' => $now]);

        list($ok, $next) = Pairing::redeem($stored, self::text($request, 'code'), $now);
        if (!$ok) {
            Store::setState('pairing_code', $next);
            return $invalid;
        }

        $keyId  = Pairing::newKeyId();
        $secret = Pairing::newSecret();
        $device = Pairing::device(sanitize_text_field(self::text($request, 'device')));
        if (!Store::addPairing($keyId, $secret, $device)) {
            // Der Code bleibt gültig: der Client hat kein Pairing bekommen (CR-01).
            return new \WP_Error('wpsync_store', 'Pairing konnte nicht gespeichert werden.', ['status' => 500]);
        }
        Store::setState('pairing_code', null);
        Infosheet::start(); // Spec 4.4: Inventar nach dem Pairing

        return new \WP_REST_Response([
            'key_id'        => $keyId,
            'secret'        => $secret,
            'home'          => home_url(),
            'agent_version' => WPSYNC_VERSION,
        ]);
    }

    public static function ping(): \WP_REST_Response
    {
        return new \WP_REST_Response(self::env());
    }

    /** Vorberechnetes Inventar in einem Request (AC-7); sheet ist null, solange keins existiert. */
    public static function infosheet(): \WP_REST_Response
    {
        return new \WP_REST_Response(['sheet' => Store::getState('infosheet'), 'job' => Infosheet::status()]);
    }

    /** Stösst eine Erhebung an (start) und arbeitet ein Häppchen ab – Fallback ohne WP-Cron (AC-9). */
    public static function infosheetRefresh(\WP_REST_Request $request): \WP_REST_Response
    {
        if (self::param($request, 'start')) {
            Infosheet::begin();
        }
        $seconds = min(Infosheet::SLICE, (float) Budget::seconds((int) ini_get('max_execution_time')));
        return new \WP_REST_Response(Infosheet::run($seconds));
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function pushBegin(\WP_REST_Request $request)
    {
        return Push::begin(self::json($request), self::$keyId);
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function pushUpload(\WP_REST_Request $request)
    {
        return Push::upload(self::json($request), self::$keyId);
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function pushCommit(\WP_REST_Request $request)
    {
        return Push::commit(self::json($request), self::$keyId);
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function pushConfirm(\WP_REST_Request $request)
    {
        return Push::confirm(self::json($request), self::$keyId);
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function pushRollback(\WP_REST_Request $request)
    {
        return Push::rollback(self::json($request), self::$keyId);
    }

    public static function pushList(): \WP_REST_Response
    {
        return Push::index();
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function stagingBegin(\WP_REST_Request $request)
    {
        return Staging::begin(self::json($request), self::$keyId);
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function stagingStep(\WP_REST_Request $request)
    {
        return Staging::step(self::json($request));
    }

    public static function stagingStatus(): \WP_REST_Response
    {
        return Staging::status();
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function stagingLogin()
    {
        return Staging::login(self::$keyId);
    }

    /**
     * Der ganze signierte JSON-Body (SEC-07: nie der Query-String).
     *
     * @return array<string, mixed>
     */
    private static function json(\WP_REST_Request $request): array
    {
        $json = $request->get_json_params();
        return is_array($json) ? $json : [];
    }

    /** @return array<string, mixed> */
    public static function env(): array
    {
        global $wpdb;
        return [
            'php_version'        => PHP_VERSION,
            'wp_version'         => get_bloginfo('version'),
            'db_server'          => $wpdb->db_server_info(),
            'db_charset'         => $wpdb->charset,
            'table_prefix'       => $wpdb->base_prefix,
            'siteurl'            => get_option('siteurl'),
            'home'               => get_option('home'),
            'max_execution_time' => (int) ini_get('max_execution_time'),
            'memory_limit'       => (string) ini_get('memory_limit'),
            'active_plugins'     => array_values((array) get_option('active_plugins', [])),
            'agent_version'      => WPSYNC_VERSION,
            'anon'               => Anonymizer::id(Store::anonKey()),
            'health_urls'        => Push::healthUrls(),
            'staging'            => Staging::summary(),
        ];
    }

    /**
     * Seitenweise: zuerst Tabellen (Checksummen), dann Dateien – beides nur im Umfang des Profils
     * (Spec 5.3 Nr. 3). Tabellen im Modus structure brauchen keine Checksumme, skip fehlt ganz.
     * Cursor als JSON {"phase","i"}.
     *
     * @return \WP_REST_Response|\WP_Error
     */
    public static function delta(\WP_REST_Request $request)
    {
        $scope = self::scope($request);
        if ($scope instanceof \WP_Error) {
            return $scope;
        }
        $cursor = json_decode(self::text($request, 'cursor'), true);
        if (!is_array($cursor) || !in_array($cursor['phase'] ?? '', ['tables', 'files'], true)) {
            $cursor = ['phase' => 'tables', 'i' => 0];
        }
        $index    = max(0, (int) ($cursor['i'] ?? 0));
        $deadline = microtime(true) + Budget::seconds((int) ini_get('max_execution_time'));
        $out      = ['tables' => [], 'files' => [], 'skipped' => [], 'next' => null];

        if ($cursor['phase'] === 'tables') {
            if ($index === 0) {
                $out['env'] = self::env();
            }
            $tables = self::tables();
            for ($i = $index; $i < count($tables); $i++) {
                if ($i > $index && microtime(true) > $deadline) {
                    $out['next'] = wp_json_encode(['phase' => 'tables', 'i' => $i]);
                    return new \WP_REST_Response($out);
                }
                $mode = $scope->tableMode($tables[$i]);
                if ($mode !== Scope::SKIP) {
                    $out['tables'][] = self::tableInfo($tables[$i], $mode === Scope::FULL, $scope);
                }
            }
            $index = 0;
            if (microtime(true) > $deadline) {
                $out['next'] = wp_json_encode(['phase' => 'files', 'i' => 0]);
                return new \WP_REST_Response($out);
            }
        }

        $walker         = new FileWalker(ABSPATH, WP_CONTENT_DIR, self::$pluginDir, $scope);
        $page           = $walker->page($index, $deadline);
        $out['files']   = $page['files'];
        $out['skipped'] = $page['skipped'];
        if ($page['next'] !== null) {
            $out['next'] = wp_json_encode(['phase' => 'files', 'i' => $page['next']]);
        }
        return new \WP_REST_Response($out);
    }

    /**
     * Mehrere kleine Tabellen – vollständig, gefiltert oder nur als Struktur; endet vor Ablauf
     * des Budgets, der Client fordert den Rest an.
     *
     * @return \WP_Error|void
     */
    public static function dbBundle(\WP_REST_Request $request)
    {
        $scope = self::scope($request);
        if ($scope instanceof \WP_Error) {
            return $scope;
        }
        $names    = array_values(array_intersect(array_filter((array) self::param($request, 'tables'), 'is_string'), self::tables()));
        $limit    = self::limit($request);
        $deadline = microtime(true) + Budget::seconds((int) ini_get('max_execution_time'));

        self::beginRaw('application/octet-stream');
        foreach ($names as $i => $table) {
            if ($i > 0 && microtime(true) > $deadline) {
                break;
            }
            $mode = $scope->tableMode($table);
            if ($mode === Scope::SKIP) {
                continue;
            }
            $sql  = '';
            $rows = 0;
            if ($mode === Scope::STRUCTURE) {
                $sql = self::structureSql($table); // Schema ohne Daten (Spec 5.2)
            } else {
                $after  = null;
                $offset = 0;
                do {
                    $chunk   = self::tableSql($table, $after, $offset, $limit, $scope);
                    $sql    .= $chunk['sql'];
                    $rows   += $chunk['rows'];
                    $after   = $chunk['next'];
                    $offset += $chunk['rows'];
                } while ($chunk['rows'] === $limit);
            }
            echo Frames::table($table, $rows, strlen($sql)) . $sql . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
            flush();
        }
        echo Frames::end(); // phpcs:ignore WordPress.Security.EscapeOutput
        exit;
    }

    /** Ein Chunk einer grossen Tabelle; Keyset, wenn ein einspaltiger Primärschlüssel existiert. */
    public static function dbChunk(\WP_REST_Request $request)
    {
        $scope = self::scope($request);
        if ($scope instanceof \WP_Error) {
            return $scope;
        }
        $table = self::text($request, 'table');
        if (!in_array($table, self::tables(), true)) {
            return new \WP_Error('wpsync_table', 'unknown table', ['status' => 404]);
        }
        // Wie /db-bundle: abgewählte Tabellen verlassen den Server nicht (CR-06, Spec 5.2).
        $mode = $scope->tableMode($table);
        if ($mode === Scope::SKIP) {
            return new \WP_Error('wpsync_scope', 'table excluded by scope', ['status' => 400]);
        }
        $after  = self::param($request, 'after');
        $after  = is_scalar($after) ? (string) $after : null;
        $offset = max(0, (int) self::param($request, 'offset'));
        if ($mode === Scope::STRUCTURE) {
            $chunk = [
                'sql'    => ($after === null && $offset === 0) ? self::structureSql($table) : '',
                'rows'   => 0,
                'next'   => null,
                'keyset' => false,
            ];
        } else {
            $chunk = self::tableSql($table, $after, $offset, self::limit($request), $scope);
        }

        header('X-Wpsync-Rows: ' . $chunk['rows']);
        header('X-Wpsync-Mode: ' . ($chunk['keyset'] ? 'keyset' : 'offset'));
        if ($chunk['next'] !== null) {
            header('X-Wpsync-Next: ' . base64_encode($chunk['next']));
        }
        self::beginRaw('application/sql; charset=utf-8');
        echo $chunk['sql']; // phpcs:ignore WordPress.Security.EscapeOutput
        exit;
    }

    /**
     * Mehrere Dateien gerahmt, gestreamt ohne Output-Buffer (Spike B21). Es gelten dieselben
     * festen Ausschlüsse wie in der Dateiliste, geprüft am aufgelösten Pfad (SEC-02).
     *
     * @return \WP_Error|void
     */
    public static function files(\WP_REST_Request $request)
    {
        $rootReal = realpath(WP_CONTENT_DIR);
        if ($rootReal === false) {
            // Ohne aufgelösten Root wäre der Präfixvergleich unten wirkungslos (SEC-11).
            return new \WP_Error('wpsync_layout', 'wp-content ist nicht auflösbar.', ['status' => 500]);
        }
        $base = wp_normalize_path(ABSPATH);
        $root = wp_normalize_path($rootReal) . '/';
        $self = wp_normalize_path(self::$pluginDir) . '/';

        if (function_exists('set_time_limit')) {
            @set_time_limit(0); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- auf manchen Hosts deaktiviert
        }
        nocache_headers();
        header('Content-Type: application/octet-stream');
        while (ob_get_level() > 0) {
            ob_end_clean(); // fremde Pufferinhalte gehören nicht in den Stream (CR-07)
        }

        foreach ((array) self::param($request, 'paths') as $path) {
            if (!is_string($path)) {
                continue;
            }
            if (preg_match('/[\x00-\x1f\x7f]/', $path) === 1) {
                echo Frames::missing($path); // phpcs:ignore WordPress.Security.EscapeOutput -- realpath() würfe bei \0 (CR-07)
                continue;
            }
            $full = wp_normalize_path((string) realpath($base . $path));
            $ok   = strpos($path, 'wp-content/') === 0
                && strpos($full, $root) === 0
                && strpos($full, $self) !== 0
                && !is_link($base . $path)
                && is_file($full)
                && is_readable($full)
                && Excludes::path(substr($full, strlen($root)), (int) filesize($full)) === null;
            $in   = $ok ? fopen($full, 'rb') : false;
            if ($in === false) {
                echo Frames::missing($path); // phpcs:ignore WordPress.Security.EscapeOutput
                continue;
            }
            clearstatcache(true, $full);
            $size = (int) filesize($full);
            echo Frames::file($path, $size, (int) filemtime($full)); // phpcs:ignore WordPress.Security.EscapeOutput
            $sent = self::send($in, $size);
            fclose($in);
            if ($sent !== $size) {
                exit; // Datei wurde währenddessen kürzer: ohne E enden, der Client bricht ab und holt neu
            }
            echo "\n";
            flush();
        }
        echo Frames::end(); // phpcs:ignore WordPress.Security.EscapeOutput
        exit;
    }

    /**
     * Sendet höchstens $size Bytes – wächst die Datei währenddessen, bleibt der Rahmen intakt (CR-07).
     *
     * @param resource $in
     */
    private static function send($in, int $size): int
    {
        $sent = 0;
        while ($sent < $size) {
            $chunk = fread($in, min(1048576, $size - $sent));
            if ($chunk === false || $chunk === '') {
                break;
            }
            echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput
            $sent += strlen($chunk);
            flush();
        }
        return $sent;
    }

    /** @return list<string> */
    private static function tables(): array
    {
        return Store::dataTables();
    }

    /**
     * anonymized: Die Tabelle kommt mit Daten und pseudonymisiert – daran erkennt das CLI, dass es
     * sie nach einem Wechsel von Regeln, Schlüssel oder --no-anonymize neu laden muss (Spec 11.3).
     *
     * @return array{name: string, checksum: string|null, rows: int, bytes: int, primary_key: string|null, anonymized: bool}
     */
    private static function tableInfo(string $table, bool $withData, Scope $scope): array
    {
        global $wpdb;
        $status   = (array) $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS LIKE %s', $wpdb->esc_like($table)), ARRAY_A);
        $checksum = $withData ? (array) $wpdb->get_row('CHECKSUM TABLE `' . $table . '`', ARRAY_A) : [];
        return [
            'name'        => $table,
            'checksum'    => isset($checksum['Checksum']) ? (string) $checksum['Checksum'] : null,
            'rows'        => (int) ($status['Rows'] ?? 0),
            'bytes'       => (int) ($status['Data_length'] ?? 0) + (int) ($status['Index_length'] ?? 0),
            'primary_key' => self::singlePrimaryKey($table),
            'anonymized'  => $withData && $scope->anonymize() && Anonymizer::covers($table, (string) $wpdb->base_prefix),
        ];
    }

    /** Name des Primärschlüssels, wenn er aus genau einer Spalte besteht – auch für Staging (StagingDb). */
    public static function singlePrimaryKey(string $table): ?string
    {
        global $wpdb;
        $columns = $wpdb->get_col('SHOW KEYS FROM `' . $table . "` WHERE Key_name = 'PRIMARY'", 4);
        return count($columns) === 1 ? (string) $columns[0] : null;
    }

    /**
     * @return array{sql: string, rows: int, next: string|null, keyset: bool}
     */
    private static function tableSql(string $table, ?string $after, int $offset, int $limit, Scope $scope): array
    {
        global $wpdb;
        $pk     = self::singlePrimaryKey($table);
        $dbh    = $wpdb->dbh;
        $escape = static function (string $value) use ($dbh): string {
            return mysqli_real_escape_string($dbh, $value);
        };
        // Abgewählte Post-Typen bleiben samt Metadaten auf dem Server (Spec 5.2, AC-14).
        $filter = $scope->rowFilter($table, [
            'posts'              => $wpdb->posts,
            'postmeta'           => $wpdb->postmeta,
            'term_relationships' => $wpdb->term_relationships,
            'comments'           => $wpdb->comments,
        ], $escape);
        // Eigene Optionen (Altlast Spike) verlassen den Server nie (AC-27).
        $where = implode(' AND ', array_filter([
            $table === $wpdb->options ? "option_name NOT LIKE 'wpsync\\_%'" : '',
            $filter['where'],
        ]));
        $rows = (array) $wpdb->get_results(SqlBuilder::select($table, $where, $pk, $after, $offset, $limit, $escape, $filter['join']), ARRAY_A);
        $last = end($rows); // Cursor aus den echten Werten, bevor Spalten ersetzt werden
        if ($scope->anonymize()) {
            // Personenbezogene Werte verlassen den Server nur als Pseudonym (Spec 11, AC-32).
            $rows = self::anonymizer()->rows($table, $rows);
        }

        $sql  = ($after === null && $offset === 0) ? self::structureSql($table) : '';
        $sql .= SqlBuilder::inserts($table, array_map('array_values', $rows), $escape);

        return [
            'sql'    => $sql,
            'rows'   => count($rows),
            'next'   => ($pk !== null && is_array($last)) ? (string) $last[$pk] : null,
            'keyset' => $pk !== null,
        ];
    }

    private static function anonymizer(): Anonymizer
    {
        global $wpdb;
        if (self::$anonymizer === null) {
            self::$anonymizer = new Anonymizer(Store::anonKey(), (string) $wpdb->base_prefix);
        }
        return self::$anonymizer;
    }

    /**
     * Parameter ausschliesslich aus dem JSON-Body: nur der ist signiert (SEC-07).
     * get_param() läse auch den Query-String.
     *
     * @return mixed null, wenn der Parameter fehlt
     */
    private static function param(\WP_REST_Request $request, string $name)
    {
        $json = $request->get_json_params();
        return is_array($json) && array_key_exists($name, $json) ? $json[$name] : null;
    }

    private static function text(\WP_REST_Request $request, string $name): string
    {
        $value = self::param($request, $name);
        return is_scalar($value) ? (string) $value : '';
    }

    /** @return Scope|\WP_Error */
    private static function scope(\WP_REST_Request $request)
    {
        try {
            return Scope::fromArray(self::param($request, 'scope'));
        } catch (\InvalidArgumentException $e) {
            return new \WP_Error('wpsync_scope', 'invalid scope: ' . $e->getMessage(), ['status' => 400]);
        }
    }

    private static function structureSql(string $table): string
    {
        global $wpdb;
        $create = (array) $wpdb->get_row('SHOW CREATE TABLE `' . $table . '`', ARRAY_N);
        return SqlBuilder::preamble($table, (string) $create[1]);
    }

    private static function limit(\WP_REST_Request $request): int
    {
        $limit = (int) self::param($request, 'limit');
        return min(20000, max(1, $limit > 0 ? $limit : 2000));
    }

    /** Rohausgabe, gzip wenn der Client es anbietet und der Server nicht selbst komprimiert. */
    private static function beginRaw(string $contentType): void
    {
        nocache_headers();
        header('Content-Type: ' . $contentType);
        $acceptsGzip = strpos((string) ($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''), 'gzip') !== false;
        if ($acceptsGzip && !ini_get('zlib.output_compression') && function_exists('ob_gzhandler')) {
            ob_start('ob_gzhandler');
        }
    }
}
