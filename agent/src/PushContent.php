<?php
namespace WpSync;

defined('ABSPATH') || exit;

/**
 * Der dritte Kanal eines Pushs: Inhalte (Spec Content-Push §7). Verbindet Push mit den
 * Content-Klassen – Ablage des Pakets (/content/stage), Auflösen des Ziels, Prüfen, Anwenden,
 * Rücknahme, Nacharbeiten. Das Paket ist keine Einheit in units: es liegt vor dem Push auf dem
 * Server, adressiert über seine sha256, und der Begin nennt nur diese. So prüft schon der
 * Probelauf – ohne Push-Fenster – genau das Paket, das später angewandt wird.
 */
final class PushContent
{
    /** Name im Protokoll der Pushes und im Ergebnis der CLI. */
    public const UNIT = 'content';
    /** Ein abgelegtes Paket verfällt nach 24 Stunden. */
    public const TTL = 86400;
    /** Höchstens so viele abgelegte Pakete je Kopplung. */
    public const KEEP = 5;
    /** Höchstens so viele Seiten nennt der Begin für den Health-Check (§7.4). */
    public const MAX_HEALTH = 10;

    private const DIR = 'packages';
    private const HEX = '/^[a-f0-9]{64}\z/';
    private const KEY = '/^[a-f0-9]{16}\z/';

    /** @var (callable(string, string): ContentTarget)|null für Tests: liefert das Ziel anstelle von target() */
    public static $resolve = null;
    /** @var (callable(string, string): (array<string, mixed>|string))|null für Tests: liefert, was rescueData() sammelt */
    public static $rescueData = null;

    /**
     * POST /content/stage: nimmt ein Paket in Stücken an. Ohne data nur Auskunft, wie viel schon
     * liegt – ein Paket, das vollständig da ist, wird nicht noch einmal übertragen.
     *
     * @param array<string, mixed> $params sha256, size, offset, data (base64)
     * @param string               $work   Arbeitsordner der Pushes von Live
     * @return \WP_REST_Response|\WP_Error
     */
    public static function stage(array $params, string $keyId, string $work, int $now)
    {
        $sha  = self::sha256($params);
        $size = $params['size'] ?? null;
        if ($sha === null || preg_match(self::KEY, $keyId) !== 1 || !is_int($size) || $size < 1) {
            return new \WP_Error('wpsync_content_stage', 'sha256 oder size fehlt.', ['status' => 400]);
        }
        if ($size > ContentPackage::STAGE_BYTES) {
            return (new ContentException(ContentException::TOO_LARGE, 'Das Paket ist zu gross für einen Push: ' . $size . ' Bytes.', [], ['limits' => ContentPackage::limits(), 'bytes' => $size]))->toError();
        }
        self::expire($work, $now);
        $dir   = $work . '/' . self::DIR . '/' . $keyId;
        $final = $dir . '/' . $sha . '.jsonl';
        $part  = $dir . '/' . $sha . '.part';
        if (is_link($final) || is_link($part) || is_link($dir)) {
            return new \WP_Error('wpsync_content_store', 'Das Paket liess sich nicht ablegen.', ['status' => 500]);
        }
        clearstatcache(true);
        if (is_file($final)) {
            return new \WP_REST_Response(['sha256' => $sha, 'received' => (int) filesize($final), 'complete' => true]);
        }
        $have = is_file($part) ? (int) filesize($part) : 0;
        if (!array_key_exists('data', $params)) {
            return new \WP_REST_Response(['sha256' => $sha, 'received' => $have, 'complete' => false]);
        }
        $data   = is_string($params['data']) ? base64_decode($params['data'], true) : false;
        $offset = $params['offset'] ?? null;
        if ($data === false || $data === '' || !is_int($offset) || $offset < 0) {
            return new \WP_Error('wpsync_content_stage', 'data oder offset fehlt.', ['status' => 400]);
        }
        if (strlen($data) > Push::MAX_UPLOAD) {
            return new \WP_Error('wpsync_content_size', 'Stück zu gross.', ['status' => 413]);
        }
        if ($offset === 0) {
            $have = 0; // von vorn: ein abgebrochener Versuch wird überschrieben
        }
        if ($offset !== $have || $offset + strlen($data) > $size) {
            return new \WP_Error('wpsync_content_offset', 'Stück passt nicht an das Paket.', ['status' => 409, 'received' => $have]);
        }
        wp_mkdir_p($dir);
        if ($offset === 0) {
            self::trim($dir, self::KEEP - 1); // Platz für dieses: nie mehr als KEEP Dateien je Kopplung
        }
        // Nur für den Besitzer lesbar, und ohne PHP-Warnung (sie trüge den Pfad ins Fehlerprotokoll).
        if ($offset === 0 && !is_link($part)) {
            @unlink($part);
            @touch($part);
        }
        @chmod($part, 0600);
        // Mit Sperre: zwei Uploads desselben Pakets schreiben nie ineinander.
        if (@file_put_contents($part, $data, ($offset === 0 ? 0 : FILE_APPEND) | LOCK_EX) !== strlen($data)) {
            return new \WP_Error('wpsync_content_store', 'Das Paket liess sich nicht ablegen.', ['status' => 500]);
        }
        $have = $offset + strlen($data);
        if ($have < $size) {
            return new \WP_REST_Response(['sha256' => $sha, 'received' => $have, 'complete' => false]);
        }
        if (!hash_equals($sha, (string) hash_file('sha256', $part))) {
            @unlink($part);
            return new \WP_Error('wpsync_content_hash', 'Der Inhalt passt nicht zur Prüfsumme des Pakets.', ['status' => 400]);
        }
        if (!@rename($part, $final)) {
            return new \WP_Error('wpsync_content_store', 'Das Paket liess sich nicht ablegen.', ['status' => 500]);
        }
        return new \WP_REST_Response(['sha256' => $sha, 'received' => $size, 'complete' => true]);
    }

    /**
     * sha256 eines Pakets aus einem Request: aus /content/stage oder aus content des Begin.
     *
     * @param mixed $params
     */
    public static function sha256($params): ?string
    {
        $sha = is_array($params) ? ($params['sha256'] ?? null) : null;
        return is_string($sha) && preg_match(self::HEX, $sha) === 1 ? $sha : null;
    }

    /** Datei eines vollständig abgelegten, nicht verfallenen Pakets dieser Kopplung; sonst null. */
    public static function staged(string $work, string $keyId, string $sha256, int $now): ?string
    {
        if (preg_match(self::KEY, $keyId) !== 1 || preg_match(self::HEX, $sha256) !== 1) {
            return null;
        }
        $file = $work . '/' . self::DIR . '/' . $keyId . '/' . $sha256 . '.jsonl';
        clearstatcache(true, $file);
        return is_file($file) && !is_link($file) && $now - (int) filemtime($file) <= self::TTL ? $file : null;
    }

    /**
     * Übernimmt das abgelegte Paket in den Arbeitsordner eines Pushs: was der Begin geprüft hat,
     * wendet der Commit an – auch wenn die Ablage inzwischen verfallen ist.
     */
    public static function take(string $staged, string $pushDir, string $sha256): bool
    {
        $dir  = $pushDir . '/' . self::UNIT;
        $file = $dir . '/package.jsonl';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true); // hier liegen auch die Abbilder des Pushs (ContentImage)
        }
        // Erst die Rechte, dann der Inhalt.
        return !is_link($file) && @touch($file) && @chmod($file, 0600) && @copy($staged, $file) && hash_equals($sha256, (string) @hash_file('sha256', $file));
    }

    /** Das Paket eines Pushs, wenn es noch genau das geprüfte ist; sonst null. */
    public static function taken(string $pushDir, string $sha256): ?string
    {
        $file = $pushDir . '/' . self::UNIT . '/package.jsonl';
        return is_file($file) && !is_link($file) && hash_equals($sha256, (string) hash_file('sha256', $file)) ? $file : null;
    }

    /** Nach dem bestätigten Push nach Live braucht niemand die Ablage mehr. */
    public static function forget(string $work, string $keyId, string $sha256): void
    {
        if (preg_match(self::KEY, $keyId) === 1 && preg_match(self::HEX, $sha256) === 1) {
            @unlink($work . '/' . self::DIR . '/' . $keyId . '/' . $sha256 . '.jsonl');
        }
    }

    /** Löscht abgelegte Pakete und angefangene Uploads, die älter als TTL sind. */
    public static function expire(string $work, int $now): void
    {
        $root = $work . '/' . self::DIR;
        if (is_link($root) || !is_dir($root)) {
            return;
        }
        foreach ((array) @scandir($root) as $key) {
            $dir = $root . '/' . $key;
            if (!is_string($key) || preg_match(self::KEY, $key) !== 1 || is_link($dir) || !is_dir($dir)) {
                continue;
            }
            foreach ((array) @scandir($dir) as $name) {
                $file = $dir . '/' . $name;
                if (is_string($name) && preg_match('/^[a-f0-9]{64}\.(jsonl|part)\z/', $name) === 1 && !is_link($file) && $now - (int) @filemtime($file) > self::TTL) {
                    @unlink($file);
                }
            }
            @rmdir($dir); // nur, wenn leer
        }
    }

    /**
     * Das Ziel eines Inhalts-Pushs: Live mit seinem Präfix, oder die Staging-Kopie mit den
     * Tabellennamen, die ihr Guard geprüft hat (§7.8).
     *
     * @param string $content wp-content des Ziels
     * @throws ContentException
     */
    public static function target(string $name, string $content): ContentTarget
    {
        global $wpdb;
        ContentSql::uncached(); // ab hier fragt der Kanal die Datenbank selbst – nie einen Cache
        if (self::$resolve !== null) {
            return (self::$resolve)($name, $content);
        }
        // Lesen unter Sperre und Schreiben müssen auf derselben Verbindung landen. Ein Drop-in, das
        // Abfragen auf mehrere Server verteilt, garantiert das nicht.
        foreach (['hyperdb', 'LudicrousDB'] as $proxy) {
            if (is_object($wpdb) && is_a($wpdb, $proxy)) {
                throw new ContentException(ContentException::ENGINE, 'Diese Site verteilt Datenbankabfragen über ' . $proxy . ' – Inhalte lassen sich so nicht sicher übertragen.', [], ['tables' => []]);
            }
        }
        $home     = rtrim((string) get_option('home'), '/');
        $siteurl  = (string) get_option('siteurl');
        $autoload = version_compare((string) get_bloginfo('version'), '6.6', '>=') ? 'auto' : 'yes'; // S4
        try {
            if ($name === 'staging') {
                $copy = Staging::contentTarget();
                if ($copy === null) {
                    throw new ContentException(ContentException::FAILED, 'Die Tabellen der Staging-Kopie sind nicht benutzbar.');
                }
                $target = new ContentTarget(
                    'staging',
                    new ContentSql($wpdb, $copy['tables'], self::existing((string) ($copy['comments'] ?? ''))),
                    new ContentOrigin($home, $copy['replace']),
                    $home,
                    $siteurl,
                    $copy['url'],
                    $copy['prefix'],
                    self::uploadsDir('staging', $content),
                    $copy['url'] . '/wp-content/uploads',
                    $autoload
                );
                $target->indexables  = self::existing($copy['indexables']);
                $target->objectTypes = [self::class, 'objectTypes']; // die Kopie läuft mit dem Code von Live
                $target->userExists  = [self::class, 'userExists'];
                return $target;
            }
            $tables = [];
            foreach (Canon::TABLES as $table) {
                $tables[$table] = (string) $wpdb->prefix . $table;
            }
            $target = new ContentTarget(
                'live',
                new ContentSql($wpdb, $tables, self::existing((string) $wpdb->prefix . 'comments')),
                new ContentOrigin($home),
                $home,
                $siteurl,
                $home,
                (string) $wpdb->prefix,
                self::uploadsDir('live', $content),
                rtrim((string) (wp_upload_dir(null, false)['baseurl'] ?? ''), '/'),
                $autoload
            );
            $target->indexables = self::existing((string) $wpdb->prefix . 'yoast_indexable');
            $target->objectTypes = [self::class, 'objectTypes'];
            $target->userExists  = [self::class, 'userExists'];
            // Wie wp_insert_post() beim Weg in den Papierkorb. Nur auf Live: die Funktion liest dessen Tabellen.
            $target->slug = static function (string $name, string $id, string $type, string $parent): string {
                return function_exists('wp_unique_post_slug') ? (string) wp_unique_post_slug($name, (int) $id, 'trash', $type, (int) $parent) : $name;
            };
            return $target;
        } catch (\InvalidArgumentException $e) {
            throw new ContentException(ContentException::ORIGIN, 'Die Adresse oder die Tabellen dieser Site lassen sich nicht bestimmen.');
        }
    }

    /**
     * Was der Umschlag für rescue.php trägt (Spec Content-Push P3 §5.2) – aus dem laufenden
     * WordPress, nie aus wp-config.php gelesen (R1): die Verbindung so, wie wpdb::db_connect() sie
     * aufbaut, die Sitzung, wie sie gerade ist, Präfix und Adressen des Ziels und die Dateischlüssel
     * der beiden Abbilder dieses Pushs.
     *
     * @return array<string, mixed>|string die Felder target, db, prefix, home, siteurl, staging, image_keys –
     *         oder der Grund, aus dem es keinen Umschlag gibt (driver, no_image_key, probe_failed)
     */
    public static function rescueData(string $name, string $pushId)
    {
        global $wpdb;
        if (self::$rescueData !== null) {
            return (self::$rescueData)($name, $pushId);
        }
        // Nur die Verbindung, die rescue.php nachbauen kann: mysqli, direkt, mit den Konstanten der Installation.
        if (!is_object($wpdb) || !(($wpdb->dbh ?? null) instanceof \mysqli) || !method_exists($wpdb, 'parse_db_host')
            || is_a($wpdb, 'hyperdb') || is_a($wpdb, 'LudicrousDB')
            || !defined('DB_HOST') || !defined('DB_USER') || !defined('DB_PASSWORD') || !defined('DB_NAME')) {
            return RescueContent::DRIVER;
        }
        // Ohne Schlüssel der Installation lägen die Abbilder als Klartext da: aus denen schreibt rescue.php nie zurück.
        $before = ContentImage::fileKeys($pushId, ContentImage::BEFORE);
        $after  = ContentImage::fileKeys($pushId, ContentImage::AFTER);
        if ($before === [] || $after === []) {
            return RescueContent::NO_IMAGE_KEY;
        }
        // Wie wpdb::db_connect(): Host, Port und Socket aus DB_HOST; IPv6 mit mysqlnd in eckigen Klammern.
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
        // Die sql_mode der wpdb-Sitzung, gelesen statt nachgerechnet (R6).
        list($mode, $bad) = ContentSql::silent($wpdb, static function () use ($wpdb): array {
            return [$wpdb->get_var('SELECT @@SESSION.sql_mode'), (string) $wpdb->last_error !== ''];
        });
        if ($bad) {
            return RescueContent::PROBE_FAILED;
        }
        $data = [
            'target'     => $name,
            'db'         => [
                'host'     => (string) $host,
                'port'     => $port === null || (int) $port === 0 ? null : (int) $port,
                'socket'   => $socket === null || (string) $socket === '' ? null : (string) $socket,
                'user'     => (string) constant('DB_USER'),
                'password' => (string) constant('DB_PASSWORD'),
                'name'     => (string) constant('DB_NAME'),
                'flags'    => (defined('MYSQL_CLIENT_FLAGS') ? (int) constant('MYSQL_CLIENT_FLAGS') : 0) & RescueContent::FLAGS,
                'charset'  => (string) ($wpdb->charset ?? ''),
                'collate'  => (string) ($wpdb->collate ?? ''),
                'sql_mode' => (string) $mode,
            ],
            'prefix'     => (string) $wpdb->prefix,
            'home'       => rtrim((string) get_option('home'), '/'),
            'siteurl'    => (string) get_option('siteurl'),
            'staging'    => null,
            'image_keys' => [
                ContentImage::BEFORE => array_map('base64_encode', $before),
                ContentImage::AFTER  => array_map('base64_encode', $after),
            ],
        ];
        if ($name === 'staging') {
            $copy = Staging::contentTarget();
            if ($copy === null) {
                return RescueContent::PROBE_FAILED;
            }
            $data['prefix']  = $copy['prefix'];
            $data['staging'] = ['dir' => $copy['dir'], 'live_home' => $copy['live_home'], 'live_prefix' => $copy['live_prefix']];
        }
        return $data;
    }

    /**
     * Der Teil rescue.db der Antwort des Begin (P3 §5.3): gibt es für diesen Push den Umschlag, mit
     * dem rescue.php die Inhalte ohne WordPress zurücknimmt? Im echten Begin legt das ihn an – nach
     * einer Probe über genau diese Verbindung (R13) und nur, wenn sich der Push sperren lässt (R10).
     *
     * @param string      $work   Arbeitsordner im wp-content des Ziels
     * @param string|null $pushId der angelegte Push; null: Probelauf – nur Auskunft, ohne Probe und ohne Datei
     * @param string      $key    der Rescue-Key des Pushs; im Probelauf ''
     * @return array{ok: bool, reason?: string}
     */
    public static function rescueDb(string $name, string $content, string $work, ?string $pushId, string $key): array
    {
        $real = $pushId !== null;
        $id   = $pushId ?? 'p_00000000_000000000000';
        try {
            if ($real) {
                // Ohne Sperre nähme rescue.php die Inhalte nicht zurück – dann lieber gleich kein Umschlag.
                $lock   = PushRescue::lock($work, $id);
                $locked = is_resource($lock);
                PushRescue::unlock($lock);
                if (!$locked) {
                    return ['ok' => false, 'reason' => RescueContent::PROBE_FAILED];
                }
            }
            return RescueContent::prepare($work, $id, $content, $key, self::rescueData($name, $id), $real);
        } catch (\Throwable $e) {
            return ['ok' => false, 'reason' => RescueContent::PROBE_FAILED];
        }
    }

    /** Gibt es diesen Benutzer auf der Site? Ohne WordPress-Funktion: nicht zu prüfen, also ja. */
    public static function userExists(int $id): bool
    {
        return !function_exists('get_userdata') || get_userdata($id) !== false;
    }

    /**
     * Objekttypen, für die eine Taxonomie auf dieser Site registriert ist; null: nicht registriert.
     *
     * @return list<string>|null
     */
    public static function objectTypes(string $taxonomy): ?array
    {
        $object = function_exists('get_taxonomy') ? get_taxonomy($taxonomy) : false;
        return is_object($object) ? array_values(array_map('strval', (array) ($object->object_type ?? []))) : null;
    }

    /**
     * Ordner der Uploads des Ziels: auf Live der, den WordPress benutzt (basedir – auch mit UPLOADS
     * oder upload_path), in der Kopie wp-content/uploads wie bei der Einheit uploads (PushUploads::layout()).
     *
     * @param string $content wp-content des Ziels
     */
    public static function uploadsDir(string $name, string $content): string
    {
        if ($name === 'live') {
            $base = (string) (wp_upload_dir(null, false)['basedir'] ?? '');
            if ($base !== '') {
                return rtrim(wp_normalize_path(is_dir($base) ? (string) realpath($base) : $base), '/');
            }
        }
        return $content . '/' . PushUploads::UNIT;
    }

    /**
     * Der Teil content der Antwort des Begin (§7.2): im Probelauf steht eine Ablehnung hier, ohne
     * dass der Request scheitert.
     *
     * @param string|null          $file    das abgelegte Paket; null: es liegt nicht (mehr) da
     * @param array<string, mixed> $uploads Dateien der Einheit uploads desselben Pushs (nur die Schlüssel zählen)
     * @param bool                 $real    echter Begin: neue Beiträge brauchen den Öffner des Fensters (§9)
     * @param bool                 $window  das Push-Fenster dieser Kopplung ist offen; sonst prüft der Probelauf nur
     *                                      teilweise (partial) und verrät nicht, welche Objekte und Dateien es gibt:
     *                                      was er deshalb offen lässt, steht in unchecked ({table, key, check} –
     *                                      ContentCheck::unchecked()), höchstens MAX_KEYS Einträge, unchecked_total
     *                                      nennt alle. Mit Fenster leer und 0.
     * @return array{ok: bool, error: array<string, mixed>|null, rows: object, limits: array<string, int>, conflicts: list<array<string, string>>, health_urls: list<string>, partial: bool, unchecked: list<array<string, string>>, unchecked_total: int, extensions?: array<string, list<string>>}
     */
    public static function plan(?string $file, string $name, string $content, array $uploads, bool $real, ?int $opener, bool $window = true): array
    {
        $out = ['ok' => false, 'error' => null, 'rows' => new \stdClass(), 'limits' => ContentPackage::limits(), 'conflicts' => [], 'health_urls' => [], 'partial' => !$window, 'unchecked' => [], 'unchecked_total' => 0];
        $check = null;
        try {
            if ($file === null) {
                throw new ContentException(ContentException::MISSING, 'Das Paket liegt nicht auf dem Server – zuerst über /content/stage ablegen.');
            }
            $package     = ContentPackage::read($file);
            $out['rows'] = (object) $package->counts();
            $used        = ContentLists::used($package->head()['extensions']);
            if ($used !== null) {
                $out['extensions'] = $used; // sichtbar im Probelauf und im Push-Datensatz (Einheit content)
            }
            $target      = self::target($name, $content);
            $check       = new ContentCheck($package, $target);
            $check->run($uploads, false, !$window);
            foreach ($package->rows() as $row) {
                if ($real && $row['op'] === 'insert' && $row['table'] === 'posts' && ($opener === null || $opener < 1)) {
                    throw new ContentException(ContentException::AUTHOR, 'Neue Beiträge brauchen einen Autor: das Push-Fenster muss im WP-Admin geöffnet sein, nicht per WP-CLI.');
                }
            }
            $out['health_urls'] = self::healthUrls($target, $check->published());
            $out['ok']          = true;
        } catch (ContentException $e) {
            $out['error'] = $e->toArray();
            if ($e->reason() === ContentException::CONFLICT) {
                $out['conflicts'] = array_slice($e->keys(), 0, ContentException::MAX_KEYS);
            }
        } catch (\Throwable $e) {
            // Kein vorgesehener Grund: auch der steht in der Antwort, statt den Begin scheitern zu lassen.
            $out['error'] = (new ContentException(ContentException::FAILED, 'Das Paket liess sich nicht prüfen.'))->toArray();
        }
        // Neben einer Ablehnung, nicht statt ihrer: ungeprüft ist kein Fehler.
        $unchecked              = $check === null ? [] : $check->unchecked();
        $out['unchecked']       = array_slice($unchecked, 0, ContentException::MAX_KEYS);
        $out['unchecked_total'] = count($unchecked);
        return $out;
    }

    /**
     * Wendet das Paket eines Pushs an – der letzte Schritt des Commits (§7.3).
     *
     * @param string                  $pushDir Arbeitsordner des Pushs
     * @param (callable(): bool)|null $gate    Naht vor COMMIT (ContentApply::run())
     * @return array{rows: int, after: list<array<string, mixed>>, changes: array<string, mixed>, seconds: float}
     * @throws ContentException
     */
    public static function apply(string $file, string $name, string $content, string $pushDir, ?int $author, ?callable $gate = null): array
    {
        $started           = microtime(true);
        $now               = time();
        $local             = function_exists('wp_date') ? (string) wp_date('Y-m-d H:i:s', $now) : gmdate('Y-m-d H:i:s', $now);
        $result            = ContentApply::run(ContentPackage::read($file), self::target($name, $content), $pushDir . '/' . self::UNIT, $author, $now, $local, $gate);
        $result['seconds'] = round(microtime(true) - $started, 3);
        return $result;
    }

    /**
     * Nimmt den DB-Anteil eines Pushs zurück (§7.6).
     *
     * @return array{state: string, changes: array<string, mixed>|null}
     * @throws ContentException changed_since_push: nichts wurde zurückgenommen
     */
    public static function rollback(string $name, string $content, string $pushDir): array
    {
        return ContentRollback::run(self::target($name, $content), $pushDir . '/' . self::UNIT);
    }

    /**
     * Räumt auf, was rescue.php an den eingefügten Objekten eines Pushs stehen liess (P3 R15,
     * ContentRollback::sweep()) – solange es die Objekte weiter nicht gibt.
     *
     * @return int an so vielen Objekten hing etwas, das entfernt wurde
     * @throws ContentException
     */
    public static function sweep(string $name, string $content, string $pushDir): int
    {
        return ContentRollback::sweep(self::target($name, $content), $pushDir . '/' . self::UNIT);
    }

    /**
     * Nacharbeiten nach dem Anwenden oder der Rücknahme (§7.7); nie ein Fehler des Pushs.
     *
     * @param array<string, mixed>|null $changes
     * @return list<array{step: string, ok: bool}>
     */
    public static function postActions(string $name, string $content, ?array $changes): array
    {
        global $wpdb;
        if ($changes === null) {
            return [];
        }
        try {
            $target = self::target($name, $content);
            if ($name === 'staging') {
                $uploads = Staging::inside($target->uploadsDir) ? $target->uploadsDir : '';
                return ContentPostActions::staging($changes, $target->store, $wpdb, $target->indexables, $uploads, class_exists('\Elementor\Plugin', false));
            }
            return ContentPostActions::live($changes, $wpdb, $target->indexables);
        } catch (\Throwable $e) {
            return [['step' => 'post_actions', 'ok' => false]];
        }
    }

    /**
     * Adressen der veröffentlichten Beiträge, die das Paket ändert – auf der Kopie unter ihrer Adresse.
     *
     * @param list<int> $postIds
     * @return list<string>
     */
    public static function healthUrls(ContentTarget $target, array $postIds): array
    {
        $urls = [];
        foreach (array_slice($postIds, 0, 5 * self::MAX_HEALTH) as $id) {
            $link = function_exists('get_permalink') ? get_permalink($id) : false;
            if (!is_string($link) || strpos($link, $target->home . '/') !== 0) {
                continue;
            }
            $urls[$target->name === 'staging' ? $target->url . substr($link, strlen($target->home)) : $link] = true;
            if (count($urls) >= self::MAX_HEALTH) {
                break;
            }
        }
        return array_map('strval', array_keys($urls));
    }

    /** Behält die neuesten $keep Dateien einer Kopplung – abgelegte Pakete wie angefangene Uploads. */
    private static function trim(string $dir, int $keep): void
    {
        $files = [];
        foreach ((array) @scandir($dir) as $name) {
            if (is_string($name) && preg_match('/^[a-f0-9]{64}\.(jsonl|part)\z/', $name) === 1 && !is_link($dir . '/' . $name)) {
                $files[$dir . '/' . $name] = (int) @filemtime($dir . '/' . $name);
            }
        }
        arsort($files);
        foreach (array_slice(array_keys($files), $keep) as $file) {
            @unlink($file);
        }
    }

    /** Der Tabellenname, wenn es die Tabelle gibt; sonst ''. */
    private static function existing(string $table): string
    {
        global $wpdb;
        if ($table === '') {
            return '';
        }
        return (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table ? $table : '';
    }
}
