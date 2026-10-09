<?php
namespace WpSync;

defined('ABSPATH') || defined('WPSYNC_RESCUE') || exit;

/**
 * Rücknahme der Inhalte eines Pushs ohne WordPress (Spec Content-Push P3 §4.2, §5, §7.2): der
 * Einstieg, den PushRescue nach bestandener Schlüsselprüfung lädt. Kennt die feste Liste der
 * Klassen, die rescue.php dafür braucht, prüft den Inhalt des versiegelten Umschlags, baut daraus
 * das Ziel und ruft dieselbe ContentRollback wie der Agent. Unter WordPress legt der Begin eines
 * Pushs mit Inhalten über prepare() den Umschlag an – geprüft mit derselben check() und mit einer
 * Probe über genau die Verbindung, die rescue.php später nimmt (R13).
 *
 * Kein Parameter eines Requests erreicht diese Klasse ausser der Push-ID und dem Schlüssel:
 * Tabellen, Schlüssel und Werte kommen aus dem Umschlag und dem authentisierten Vorher-Abbild.
 */
final class RescueContent
{
    /** Was load() lädt, in dieser Reihenfolge – nichts sonst, kein Autoloader (§4.2). */
    public const CLASSES = [
        'Canon', 'SerializedWalker', 'StagingReplace', 'ContentOrigin', 'ContentException', 'ContentStore', 'ContentState', 'ContentLists',
        'ContentReader', 'ContentImage', 'ContentTarget', 'ContentSql', 'ContentRepair', 'ContentRollback', 'RescueSeal', 'RescueLink',
        'MysqliLink', 'RescueDb',
    ];

    /** Ausgang von run(). */
    public const DONE    = 'rolled_back';
    public const NOTHING = 'nothing';
    public const KEPT    = 'kept';

    /** Gründe, aus denen die Inhalte stehen bleiben, ohne dass ContentRollback lief (§7.6). */
    public const UNAVAILABLE = 'rescue_db_unavailable';
    public const UNREACHABLE = 'db_unreachable';

    /** Gründe, aus denen der Begin keinen Umschlag anlegt (§5.3). */
    public const NO_CRYPTO    = 'no_crypto';
    public const DRIVER       = 'driver';
    public const NO_IMAGE_KEY = 'no_image_key';
    public const PROBE_FAILED = 'probe_failed';
    public const WRITE_FAILED = 'write_failed';

    /** Von MYSQL_CLIENT_FLAGS nur: MYSQLI_CLIENT_COMPRESS (32), …_SSL_DONT_VERIFY_SERVER_CERT (64), …_SSL (2048). */
    public const FLAGS = 32 | 64 | 2048;

    /** Fehlercodes, die eine Antwort von rescue.php für ContentRollback nennen darf. */
    private const CODES = [ContentException::CHANGED, ContentException::IMAGE, ContentException::ENGINE, ContentException::FAILED];

    private const WORD   = '/^[A-Za-z0-9_]{1,64}\z/';
    private const MODE   = '/^[A-Z0-9_,]{0,1024}\z/';
    /** Präfix der Tabellen: mit dem längsten der sieben Namen bleibt es ein Bezeichner von höchstens 64 Zeichen. */
    private const PREFIX = '/^[A-Za-z0-9_$]{0,46}\z/';
    /** Wie StagingGuard::PREFIX_RE, das rescue.php nicht lädt. */
    private const STAGING_PREFIX = '/^stg[a-f0-9]{6}_\z/';

    /**
     * @var (callable(array<string, mixed>, string): (ContentTarget|null))|null für Tests: liefert das Ziel
     *      anstelle der Verbindung zur Datenbank – geprüfter Umschlag und wp-content; null: nicht erreichbar
     */
    public static $resolve = null;

    /** Lädt die Klassen der Liste, die noch fehlen. */
    public static function load(): void
    {
        foreach (self::CLASSES as $name) {
            if (!class_exists(__NAMESPACE__ . '\\' . $name, false) && !interface_exists(__NAMESPACE__ . '\\' . $name, false)) {
                require_once __DIR__ . '/' . $name . '.php';
            }
        }
    }

    /**
     * Legt den Umschlag eines Pushs an (R1, R13) – oder sagt, warum es keinen gibt.
     *
     * @param string                      $contentDir wp-content des Ziels
     * @param string                      $key        der Rescue-Key des Pushs; wird hier nur zum Versiegeln benutzt
     * @param array<string, mixed>|string $collected  target, db, prefix, home, siteurl, staging, image_keys
     *                                                (PushContent::rescueData()) – oder schon der Grund, aus dem es keinen gibt
     * @param bool                        $real       echter Begin; sonst nur Auskunft, ohne Probe und ohne Datei
     * @return array{ok: bool, reason?: string}
     */
    public static function prepare(string $workDir, string $pushId, string $contentDir, string $key, $collected, bool $real): array
    {
        $no = static function (string $reason): array {
            return ['ok' => false, 'reason' => $reason];
        };
        if (RescueSeal::method() === null) {
            return $no(self::NO_CRYPTO);
        }
        if (!is_array($collected)) {
            return $no(is_string($collected) && preg_match('/^[a-z_]{1,32}\z/', $collected) === 1 ? $collected : self::PROBE_FAILED);
        }
        $data = ['v' => 1, 'push_id' => $pushId, 'created' => time()] + $collected;
        // Was rescue.php später ablehnte, gilt schon jetzt nicht: dieselbe Prüfung.
        $checked = self::check($data, $pushId, $contentDir);
        if ($checked === null) {
            return $no(self::PROBE_FAILED);
        }
        if (!$real) {
            return ['ok' => true];
        }
        if (!self::probe($checked, $contentDir)) {
            return $no(self::PROBE_FAILED);
        }
        $sealed = RescueSeal::seal($data, $key, $pushId);
        if ($sealed === null || !RescueSeal::put($workDir, $pushId, $sealed)) {
            RescueSeal::forget($workDir, $pushId);
            return $no(self::WRITE_FAILED);
        }
        return ['ok' => true];
    }

    /**
     * Schritt 6 von rescue.php (§7.2): Umschlag öffnen und prüfen, verbinden, Ziel bauen,
     * ContentRollback mit den Dateischlüsseln aus dem Umschlag – und mit dem Schalter „Fremdes
     * stehen lassen“ (R15). Wirft nie; keine Antwort trägt einen Wert oder Text des Servers.
     *
     * @param string $contentDir wp-content, in dem der Datensatz des Pushs liegt
     * @param string $key        der Rescue-Key, wie die CLI ihn geschickt hat – schon gegen seinen Hash geprüft
     * @return array{state: string, wrote: bool, error?: array<string, mixed>, left?: list<array{table: string, key: string}>, left_total?: int}
     *         wrote: es wurden Zeilen zurückgeschrieben (nicht bei nothing)
     */
    public static function run(string $contentDir, string $workDir, string $pushId, string $key): array
    {
        $kept = static function (string $code, array $more = []): array {
            return ['state' => self::KEPT, 'wrote' => false, 'error' => ['code' => $code] + $more];
        };
        self::limits();
        $raw  = RescueSeal::read($workDir, $pushId);
        $data = $raw === null ? null : RescueSeal::open($raw, $key, $pushId);
        $data = $data === null ? null : self::check($data, $pushId, $contentDir);
        unset($raw);
        if ($data === null) {
            return $kept(self::UNAVAILABLE);
        }
        $db = null;
        try {
            if (self::$resolve !== null) {
                $target = (self::$resolve)($data, $contentDir);
            } else {
                $db     = RescueDb::connect($data['db']);
                $target = $db === null ? null : self::target($data, $db);
            }
            if (!$target instanceof ContentTarget) {
                return $kept(self::UNREACHABLE);
            }
            ContentImage::$fileKeys = $data['image_keys'];
            $back                   = ContentRollback::run($target, $workDir . '/' . $pushId . '/content', true);
            $left                   = array_values((array) ($back['left'] ?? []));
            $out                    = ['state' => $back['state'] === ContentRollback::DONE ? self::DONE : self::NOTHING, 'wrote' => $back['state'] === ContentRollback::DONE];
            if ($left !== []) {
                $out['left']       = array_slice($left, 0, ContentException::MAX_KEYS);
                $out['left_total'] = count($left);
            }
            return $out;
        } catch (ContentException $e) {
            $error = $e->toArray();
            $code  = in_array($e->reason(), self::CODES, true) ? $e->reason() : ContentException::FAILED;
            $more  = [];
            if (isset($error['keys'])) {
                $more = ['keys' => $error['keys'], 'total' => (int) ($error['total'] ?? count($error['keys']))];
            }
            if (!empty($error['unrestored'])) {
                $more['unrestored'] = true;
            }
            return $kept($code, $more);
        } catch (\Throwable $e) {
            // Auch das Unvorhergesehene: die Transaktion hat zurückgenommen, was sie angefangen hat.
            return $kept(ContentException::FAILED);
        } finally {
            ContentImage::$fileKeys = null;
            if ($db !== null) {
                $db->close();
            }
        }
    }

    /**
     * Prüft den Inhalt eines geöffneten Umschlags (§5.2) gegen den Push und gegen den Ordner, in
     * dem sein Datensatz liegt: ein Umschlag von Live schreibt nie in Tabellen einer Kopie, einer
     * der Kopie nur in Tabellen mit ihrem Präfix – und nur, wenn er im wp-content genau der Kopie
     * liegt, die er nennt (der Guard ohne WordPress, §10).
     *
     * @param array<string, mixed> $data
     * @return array{target: string, db: array<string, mixed>, prefix: string, home: string, siteurl: string, staging: array{dir: string, live_home: string, live_prefix: string}|null, image_keys: array<string, list<string>>}|null
     *         image_keys roh; null: der Umschlag gilt nicht
     */
    public static function check(array $data, string $pushId, string $contentDir): ?array
    {
        $db      = $data['db'] ?? null;
        $target  = $data['target'] ?? null;
        $prefix  = $data['prefix'] ?? null;
        $home    = $data['home'] ?? null;
        $siteurl = $data['siteurl'] ?? null;
        $staging = $data['staging'] ?? null;
        $keys    = $data['image_keys'] ?? null;
        $folder  = basename(dirname(rtrim(str_replace('\\', '/', $contentDir), '/')));
        $copy    = preg_match(PushRescue::STAGING_DIR, $folder) === 1;
        if (($data['v'] ?? null) !== 1 || ($data['push_id'] ?? null) !== $pushId || preg_match(PushRescue::ID, $pushId) !== 1
            || !is_array($db) || !is_string($prefix) || !is_string($home) || !is_string($siteurl) || !is_array($keys)
            || !in_array($target, ['live', 'staging'], true) || ($target === 'staging') !== $copy
            || preg_match(self::PREFIX, $prefix) !== 1) {
            return null;
        }
        $port   = $db['port'] ?? null;
        $socket = $db['socket'] ?? null;
        $flags  = $db['flags'] ?? null;
        foreach (['host', 'user', 'password', 'name', 'charset', 'collate', 'sql_mode'] as $field) {
            if (!is_string($db[$field] ?? null) || strlen($db[$field]) > 1024 || strpos($db[$field], "\0") !== false) {
                return null;
            }
        }
        if (($port !== null && (!is_int($port) || $port < 1 || $port > 65535))
            || ($socket !== null && (!is_string($socket) || $socket === '' || strlen($socket) > 1024 || strpos($socket, "\0") !== false))
            || !is_int($flags) || $flags < 0 || ($flags & ~self::FLAGS) !== 0
            || $db['name'] === ''
            || ($db['charset'] !== '' && preg_match(self::WORD, $db['charset']) !== 1)
            || ($db['collate'] !== '' && preg_match(self::WORD, $db['collate']) !== 1)
            || preg_match(self::MODE, $db['sql_mode']) !== 1) {
            return null;
        }
        $copyOf = null;
        try {
            if ($target === 'staging') {
                $dir        = is_array($staging) ? ($staging['dir'] ?? null) : null;
                $liveHome   = is_array($staging) ? ($staging['live_home'] ?? null) : null;
                $livePrefix = is_array($staging) ? ($staging['live_prefix'] ?? null) : null;
                // Leitplanke 3 des Guards: keins der Präfixe beginnt mit dem anderen; ein leeres von Live überschneidet sich mit allem.
                if (!is_string($dir) || !is_string($liveHome) || !is_string($livePrefix) || $dir !== $folder || preg_match(PushRescue::STAGING_DIR, $dir) !== 1
                    || preg_match(self::STAGING_PREFIX, $prefix) !== 1 || preg_match(self::PREFIX, $livePrefix) !== 1
                    || $livePrefix === '' || strpos($prefix, $livePrefix) === 0 || strpos($livePrefix, $prefix) === 0) {
                    return null;
                }
                new StagingReplace($liveHome, '/' . $dir);
                $copyOf = ['dir' => $dir, 'live_home' => $liveHome, 'live_prefix' => $livePrefix];
            } elseif ($staging !== null || preg_match('/^stg[a-f0-9]{6}_/', $prefix) === 1) {
                return null; // ein Datensatz von Live nie in Tabellen, die wie die einer Kopie heissen
            }
            new ContentOrigin($home);
        } catch (\InvalidArgumentException $e) {
            return null;
        }
        $files = [];
        foreach ([ContentImage::BEFORE, ContentImage::AFTER] as $name) {
            $list = $keys[$name] ?? null;
            if (!is_array($list) || count($list) < 1 || count($list) > 2) {
                return null; // ohne Dateischlüssel lägen die Abbilder als Klartext da: aus denen schreibt rescue.php nie zurück
            }
            foreach ($list as $encoded) {
                $raw = is_string($encoded) ? base64_decode($encoded, true) : false;
                if (!is_string($raw) || strlen($raw) !== 32) {
                    return null;
                }
                $files[$name][] = $raw;
            }
        }
        if (count($keys) !== 2) {
            return null;
        }
        return [
            'target'  => $target,
            'db'      => [
                'host' => $db['host'], 'port' => $port, 'socket' => $socket, 'user' => $db['user'], 'password' => $db['password'], 'name' => $db['name'],
                'flags' => $flags, 'charset' => $db['charset'], 'collate' => $db['collate'], 'sql_mode' => $db['sql_mode'],
            ],
            'prefix'     => $prefix,
            'home'       => rtrim($home, '/'),
            'siteurl'    => $siteurl,
            'staging'    => $copyOf,
            'image_keys' => $files,
        ];
    }

    /**
     * Das Ziel der Rücknahme aus dem geprüften Umschlag (§7.2 Nr. 3): das Präfix plus die sieben
     * festen Namen, comments nur, wenn es die Tabelle gibt, die Origin wie PushContent::target().
     * slug, objectTypes und userExists braucht die Rücknahme nicht.
     *
     * @param array<string, mixed> $data aus check()
     * @return ContentTarget|null null: die Datenbank antwortet nicht
     * @throws \InvalidArgumentException wenn ein Tabellenname kein Bezeichner ist
     */
    public static function target(array $data, RescueDb $db): ?ContentTarget
    {
        $prefix = (string) $data['prefix'];
        $tables = [];
        foreach (Canon::TABLES as $name) {
            $tables[$name] = $prefix . $name;
        }
        $comments = $db->get_var($db->prepare('SHOW TABLES LIKE %s', $db->esc_like($prefix . 'comments')));
        if ($db->last_error !== '') {
            return null;
        }
        $copy   = $data['staging'];
        $home   = (string) $data['home'];
        $origin = is_array($copy) ? new ContentOrigin($home, new StagingReplace((string) $copy['live_home'], '/' . $copy['dir'])) : new ContentOrigin($home);
        return new ContentTarget(
            (string) $data['target'],
            new ContentSql($db, $tables, $comments === $prefix . 'comments' ? $prefix . 'comments' : ''),
            $origin,
            $home,
            (string) $data['siteurl'],
            is_array($copy) ? rtrim((string) $copy['live_home'], '/') . '/' . $copy['dir'] : $home,
            $prefix,
            '',
            ''
        );
    }

    /**
     * Die Probe beim Begin (R13): eine zweite Verbindung mit genau den Daten des Umschlags – die
     * Datenbank, die Sitzung und die sieben Tabellen müssen stimmen.
     *
     * @param array<string, mixed> $data aus check()
     */
    public static function probe(array $data, string $contentDir): bool
    {
        if (self::$resolve !== null) {
            try {
                return (self::$resolve)($data, $contentDir) instanceof ContentTarget;
            } catch (\Throwable $e) {
                return false;
            }
        }
        $db = null;
        try {
            $db = RescueDb::connect($data['db']);
            if ($db === null) {
                return false;
            }
            // Gross/klein: mit lower_case_table_names nennt der Server Namen anders, als sie in wp-config.php stehen.
            if (strcasecmp((string) $db->get_var('SELECT DATABASE()'), (string) $data['db']['name']) !== 0
                || $db->get_var('SELECT @@SESSION.sql_mode') !== (string) $data['db']['sql_mode']) {
                return false;
            }
            foreach (Canon::TABLES as $name) {
                $found = $db->get_var($db->prepare('SHOW TABLES LIKE %s', $db->esc_like($data['prefix'] . $name)));
                if (!is_string($found) || strcasecmp($found, $data['prefix'] . $name) !== 0) {
                    return false;
                }
            }
            return true;
        } catch (\Throwable $e) {
            return false;
        } finally {
            if ($db !== null) {
                $db->close();
            }
        }
    }

    /**
     * Zeit und Speicher für die Rücknahme (§6.2): 60 Sekunden, weiter auch wenn der Aufrufer geht,
     * und 256 MB, wenn weniger erlaubt ist – das Vorher-Abbild darf 64 MB haben. Nach Möglichkeit;
     * nicht auf der Kommandozeile (dort liefe die Uhr des aufrufenden Prozesses).
     */
    private static function limits(): void
    {
        if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
            return;
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(60);
        }
        if (function_exists('ignore_user_abort')) {
            @ignore_user_abort(true);
        }
        $limit = trim((string) @ini_get('memory_limit'));
        $bytes = (float) $limit;
        switch (strtolower(substr($limit, -1))) {
            case 'g':
                $bytes *= 1024;
                // no break
            case 'm':
                $bytes *= 1024;
                // no break
            case 'k':
                $bytes *= 1024;
        }
        if ($limit !== '' && $limit !== '-1' && $bytes < 268435456) {
            @ini_set('memory_limit', '256M');
        }
    }
}
