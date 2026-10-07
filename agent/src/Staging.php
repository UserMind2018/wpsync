<?php
namespace WpSync;

defined('ABSPATH') || exit;

/**
 * Staging-Kopie auf dem Server (Spec Stufe 2b). Ein Datensatz pro Site in wpsync_state; Anlegen,
 * Auffrischen und Löschen laufen als Job in Schritten über /staging/step (Leitplanke 8). Jede
 * schreibende Operation geht durch StagingGuard (Leitplanke 2). Während eines Jobs und nach einem
 * Abbruch ist die Kopie ganz gesperrt (V7); geöffnet wird sie erst als Letztes der letzten Phase.
 *
 * Nach jedem Schritt wird der Zustand gespeichert, bevor der nächste beginnt: die Schritte von
 * StagingDb sind nicht wiederholbar (doppelte Zeilen, doppelt gehashte Pseudonyme). Lässt sich
 * der Zustand nicht speichern, endet der Aufruf mit einem Fehler.
 */
final class Staging
{
    public const STATE      = 'staging';
    /** Datei- und Tabellenliste des laufenden Jobs – getrennt vom Datensatz, der nach jedem Schritt geschrieben wird. */
    public const PLAN       = 'staging_plan';
    public const CRON       = 'wpsync_staging_daily';
    public const CREATING   = 'creating';
    public const READY      = 'ready';
    public const REFRESHING = 'refreshing';
    public const LOCKED     = 'locked';
    public const FAILED     = 'failed';
    public const DELETING   = 'deleting';
    /** Ein Job ohne Schritt seit 10 Minuten gilt als abgebrochen – etwa weil die CLI beendet wurde. */
    public const JOB_TTL = 600;

    private const RIEGEL = 'wp-content/mu-plugins/00-wpsync-staging.php';

    /**
     * Phasen je Operation (Spec 5.2): anonymisiert wird vor dem Umschreiben der URLs, beides vor
     * „settings“, das als Letztes den Zugang öffnet (V7). cleanup-* räumt nach einem Abbruch auf
     * und sperrt zuerst wieder (V12).
     */
    private const PHASES = [
        'create'          => ['probe', 'files', 'tables', 'anonymize', 'fixup', 'urls', 'settings', 'done'],
        'refresh'         => ['drop', 'tables', 'anonymize', 'fixup', 'urls', 'settings', 'done'],
        'refresh-code'    => ['drop', 'remove-code', 'files', 'tables', 'anonymize', 'fixup', 'urls', 'settings', 'done'],
        'delete'          => ['lock', 'drop', 'remove', 'gone'],
        'cleanup-create'  => ['lock', 'drop', 'remove', 'failed'],
        'cleanup-refresh' => ['lock', 'drop', 'failed'],
    ];

    /** @var string */
    private static $pluginDir = '';
    /** @var array<string, mixed>|null Listen des Jobs, dessen ID unter „job“ steht */
    private static $plan = null;

    public static function register(string $pluginDir): void
    {
        self::$pluginDir = $pluginDir;
        add_action(self::CRON, [self::class, 'maintain']);
        add_action('init', static function (): void {
            if (!wp_next_scheduled(self::CRON)) {
                wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON);
            }
        });
    }

    public static function unschedule(): void
    {
        wp_clear_scheduled_hook(self::CRON);
    }

    /** @return array<string, mixed>|null */
    public static function record(): ?array
    {
        return Store::getState(self::STATE);
    }

    /**
     * Präfixe, die Live nie ausliefert (Leitplanke 3). Nur ein Präfix, das StagingGuard annähme:
     * ein beschädigter Datensatz darf keinen Live-Filter verstellen.
     *
     * @return list<string>
     */
    public static function hiddenPrefixes(): array
    {
        global $wpdb;
        $record = self::record();
        $prefix = $record !== null && is_string($record['prefix'] ?? null) ? $record['prefix'] : '';
        if (preg_match(StagingGuard::PREFIX_RE, $prefix) !== 1 || StagingGuard::overlaps((string) $wpdb->base_prefix, $prefix)) {
            return [];
        }
        return [$prefix];
    }

    /** @param array<string, mixed> $record */
    public static function url(array $record): string
    {
        return untrailingslashit(home_url()) . '/' . (string) ($record['dir'] ?? '');
    }

    /**
     * wp-content der Kopie, aufgelöst und ohne Slash am Ende, oder '' – für Pushes, die es schon
     * gibt. Hängt nur am Ordner, nicht am Status der Kopie.
     */
    public static function contentDir(): string
    {
        $record = self::record();
        if ($record === null) {
            return '';
        }
        try {
            $dir = self::guard($record)->root() . '/wp-content';
        } catch (StagingException $e) {
            return '';
        }
        return is_dir($dir) && !is_link($dir) ? $dir : '';
    }

    /** @return string|\WP_Error wp-content der Kopie für einen neuen Push (Spec 5.8, V9) */
    public static function pushContent()
    {
        $record = self::record();
        if ($record === null) {
            return self::error('wpsync_staging_missing', 'Es gibt keine Staging-Kopie – anlegen mit wpsync staging create.', 409);
        }
        if (self::running($record, time())) {
            return self::error('wpsync_staging_busy', 'Auf der Staging-Kopie läuft gerade ein Job.', 423);
        }
        $status = (string) ($record['status'] ?? '');
        if ($status === self::LOCKED) {
            return self::error('wpsync_staging_locked', 'Die Staging-Kopie ist nach 14 Tagen ohne Nutzung gesperrt – entsperren mit wpsync staging open.', 409);
        }
        if ($status !== self::READY || is_array($record['job'] ?? null)) {
            return self::error('wpsync_staging_state', 'Die Staging-Kopie ist im Status ' . $status . '.', 409);
        }
        $dir = self::contentDir();
        return $dir !== '' ? $dir : self::error('wpsync_staging_missing', 'Der Staging-Ordner fehlt – wpsync staging delete, dann create.', 409);
    }

    /** Liegt $path in der Kopie (StagingGuard::path)? */
    public static function inside(string $path): bool
    {
        $record = self::record();
        if ($record === null) {
            return false;
        }
        try {
            self::guard($record)->path($path);
            return true;
        } catch (StagingException $e) {
            return false;
        }
    }

    /** @return list<string> Seiten für den Health-Check eines Staging-Pushs (Spec 6.2) */
    public static function healthUrls(): array
    {
        $record = self::record();
        if ($record === null) {
            return [];
        }
        $home = untrailingslashit(home_url());
        $url  = self::url($record);
        $out  = [$url . '/', $url . '/wp-login.php'];
        foreach (Push::healthUrls() as $live) {
            if (strpos($live, $home . '/') === 0 && $live !== $home . '/' && strpos($live, 'wp-login.php') === false) {
                $out[] = $url . substr($live, strlen($home));
            }
        }
        return array_values(array_unique($out));
    }

    /** Ein Push nach Staging zählt als Nutzung (Spec 5.9). */
    public static function markUsed(): void
    {
        $record = self::record();
        if ($record === null || ($record['status'] ?? '') !== self::READY) {
            return;
        }
        try {
            self::access($record)->markUsed(time());
        } catch (\RuntimeException $e) {
            // Zustand nicht lesbar: der Verfall greift beim nächsten Cron
        }
    }

    /** @return array{status: string, url: string, last_used: int}|null für env, wpsync status und Admin */
    public static function summary(): ?array
    {
        $record = self::record();
        if ($record === null) {
            return null;
        }
        $last = 0;
        try {
            $last = self::access($record)->read()['last_used'];
        } catch (\RuntimeException $e) {
            // kein Zustand: 0
        }
        return ['status' => (string) ($record['status'] ?? ''), 'url' => self::url($record), 'last_used' => $last];
    }

    /**
     * Prüft alles, bevor etwas angelegt wird; mit dry nur Auskunft (V1).
     *
     * @param array<string, mixed> $params op, dry, code, scope
     * @return \WP_REST_Response|\WP_Error
     */
    public static function begin(array $params)
    {
        $op = $params['op'] ?? '';
        if (!is_string($op) || !in_array($op, ['create', 'refresh', 'delete'], true)) {
            return self::error('wpsync_staging_op', 'Unbekannte Staging-Operation.', 400);
        }
        Store::install();
        return self::locked(static function () use ($params, $op) {
            return self::beginLocked($params, $op);
        });
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function step(array $params)
    {
        return self::locked(static function () use ($params) {
            $record = self::record();
            if ($record !== null && is_array($record['job'] ?? null)) {
                try {
                    $record = self::work($record, $params, microtime(true) + Budget::seconds((int) ini_get('max_execution_time')));
                } catch (StagingException $e) {
                    return self::error('wpsync_staging_failed', $e->getMessage(), 500);
                }
            }
            return new \WP_REST_Response(self::progress($record));
        });
    }

    public static function status(): \WP_REST_Response
    {
        Push::sync();
        $record = self::record();
        if ($record === null) {
            return new \WP_REST_Response(['exists' => false]);
        }
        $lastUsed = 0;
        $bytes    = 0;
        try {
            $lastUsed = self::access($record)->read()['last_used'];
            $bytes    = StagingDb::stagingBytes(self::guard($record));
        } catch (\RuntimeException $e) {
            // Kopie beschädigt oder halb gelöscht: der Datensatz zählt
        }
        $pushes = [];
        foreach (Store::pushes(20) as $push) {
            if ($push['target'] === 'staging') {
                unset($push['key_id']);
                $pushes[] = $push;
            }
        }
        return new \WP_REST_Response([
            'exists'     => true,
            'status'     => (string) ($record['status'] ?? ''),
            'url'        => self::url($record),
            'prefix'     => (string) ($record['prefix'] ?? ''),
            'created'    => (int) ($record['created'] ?? 0),
            'copied_at'  => (int) ($record['copied_at'] ?? 0),
            // Wann der Code der Kopie zuletzt vollständig von Live kam (create, refresh mit Code);
            // ein reiner Datenbank-Refresh lässt ihn stehen. 0: Datensatz von vor diesem Feld.
            'code_copied_at' => (int) ($record['code_copied_at'] ?? 0),
            'last_used'  => $lastUsed,
            'anonymized' => (bool) ($record['anonymized'] ?? false),
            'db_bytes'   => $bytes,
            'job'        => is_array($record['job'] ?? null) ? self::progress($record) : null,
            'error'      => (string) ($record['error'] ?? ''),
            'pushes'     => $pushes,
        ]);
    }

    /**
     * Einmal-Link (T1); hebt die Sperre nach dem Verfall auf. Nur für den signierten Aufruf der
     * CLI – wer hier einen Link bekommt, ist Administrator der Kopie.
     *
     * @return \WP_REST_Response|\WP_Error
     */
    public static function login()
    {
        return self::locked(static function () {
            $record = self::record();
            if ($record === null) {
                return self::error('wpsync_staging_missing', 'Es gibt keine Staging-Kopie.', 409);
            }
            if (self::running($record, time())) {
                return self::error('wpsync_staging_busy', 'Auf der Staging-Kopie läuft gerade ein Job.', 423);
            }
            $status = (string) ($record['status'] ?? '');
            if (!in_array($status, [self::READY, self::LOCKED], true) || is_array($record['job'] ?? null)) {
                return self::error('wpsync_staging_state', 'Die Staging-Kopie ist im Status ' . $status . '.', 409);
            }
            try {
                $guard = self::guard($record);
                // Die .htaccess prüft nur, ob ein Cookie da ist – ob es gilt, prüft der Riegel.
                if (!is_file($guard->path($guard->root() . '/' . self::RIEGEL)) || !is_file($guard->path($guard->root() . '/wp-config.php'))) {
                    return self::error('wpsync_staging_state', 'Der Staging-Kopie fehlt der Riegel – wpsync staging refresh --code.', 409);
                }
                if ($status === self::LOCKED) {
                    self::write($guard, $guard->root() . '/.htaccess', StagingConfig::htaccess(self::urlPath($record), self::uploadsUrl()));
                    $record['status'] = self::READY;
                    self::save($record);
                }
                $token = self::access($record)->issueToken(time());
            } catch (\RuntimeException | \InvalidArgumentException $e) {
                return self::error('wpsync_staging_failed', 'Login-Link nicht möglich: ' . self::clean($e->getMessage()), 500);
            }
            return new \WP_REST_Response(['url' => self::url($record) . '/?wpsync_login=' . $token, 'expires' => time() + StagingAccess::TOKEN_TTL]);
        });
    }

    /** Täglich: nach 14 Tagen ohne Nutzung sperren (S4). Gelöscht wird nie von selbst. */
    public static function maintain(): void
    {
        self::locked(static function () {
            $record = self::record();
            if ($record === null || ($record['status'] ?? '') !== self::READY || is_array($record['job'] ?? null)) {
                return null;
            }
            try {
                if (!StagingAccess::expired(self::access($record)->read()['last_used'], time())) {
                    return null;
                }
                self::lockDown(self::guard($record));
                $record['status'] = self::LOCKED;
                self::save($record);
            } catch (\RuntimeException $e) {
                // nächster Lauf
            }
            return null;
        });
    }

    /** Deaktivieren des Agents löscht die Kopie samt Tabellen, Push-Arbeitsordnern und offenen Pushes (Spec 5.9). */
    public static function uninstall(): void
    {
        $record = self::record();
        if ($record === null) {
            return;
        }
        try {
            Push::dropTarget('staging'); // solange es den Ordner gibt: auch die Arbeitsordner der Pushes
        } catch (\Throwable $e) {
            // der Ordner geht gleich ganz
        }
        try {
            $guard = self::guard($record);
            StagingDb::dropAll($guard, INF);
            self::removeRoot($guard, INF);
        } catch (\Throwable $e) {
            // Reste bleiben; der Datensatz geht mit Store::uninstall
        }
        Store::setState(self::PLAN, null);
        Store::setState(self::STATE, null);
    }

    /** @return \WP_REST_Response|\WP_Error */
    private static function beginLocked(array $params, string $op)
    {
        $now    = time();
        $dry    = !empty($params['dry']);
        $record = self::record();
        if ($record !== null && self::running($record, $now)) {
            return self::error('wpsync_staging_busy', 'Auf der Staging-Kopie läuft gerade ein Job.', 423);
        }
        $status = $record === null ? '' : (string) ($record['status'] ?? '');
        if ($op === 'create' && $record !== null) {
            if ($status !== self::FAILED) {
                return self::error('wpsync_staging_exists', 'Es gibt schon eine Staging-Kopie: ' . self::url($record), 409);
            }
            // Ein neuer Datensatz bekäme einen neuen Ordner und ein neues Präfix – die alten Reste fände niemand mehr (V12).
            if (self::leftovers($record)) {
                return self::error('wpsync_staging_exists', 'Von einer abgebrochenen Staging-Kopie liegen noch Reste auf dem Server – erst wpsync staging delete.', 409);
            }
        }
        if ($op !== 'create') {
            if ($record === null) {
                return self::error('wpsync_staging_missing', 'Es gibt keine Staging-Kopie.', 409);
            }
            if (Push::pending('staging') !== null) {
                return self::error('wpsync_staging_pending', 'Ein Push nach Staging ist getauscht, aber nicht bestätigt – erst bestätigen oder zurückrollen.', 409);
            }
            if (Push::running('staging', $now)) {
                return self::error('wpsync_staging_busy', 'Gerade läuft ein Push nach Staging.', 423);
            }
        }
        if ($op === 'delete') {
            return $dry ? new \WP_REST_Response(['need' => null, 'probe' => null, 'url' => self::url($record)]) : self::startDelete($record, $now);
        }
        if ($op === 'refresh' && (!in_array($status, [self::READY, self::LOCKED, self::FAILED], true) || self::contentDir() === '')) {
            return self::error('wpsync_staging_state', 'Die Staging-Kopie ist im Status ' . $status . ' und lässt sich nicht auffrischen – wpsync staging delete, dann create.', 409);
        }
        $why = self::unsupported();
        if ($why !== null) {
            return self::error('wpsync_staging_unsupported', $why, 422);
        }
        try {
            $scope = Scope::fromArray($params['scope'] ?? null);
        } catch (\InvalidArgumentException $e) {
            return self::error('wpsync_scope', 'invalid scope: ' . $e->getMessage(), 400);
        }
        global $wpdb;
        $raw = is_array($params['scope'] ?? null) ? $params['scope'] : [];
        // Nach einem Abbruch mitten im Code gibt es keinen Stand, auf den eine reine Datenbank-Kopie passte.
        $code   = $op === 'create' || !empty($params['code']) || empty($record['code_ok']);
        $prefix = '';
        $dir    = '';
        try {
            if ($op === 'create') {
                $prefix = self::newPrefix();
                $dir    = StagingGuard::newDirName();
                $guard  = new StagingGuard((string) $wpdb->base_prefix, $prefix, ABSPATH, $dir);
            } else {
                $guard = self::guard($record);
            }
            $bad = self::tablePlan($guard, $scope)[1];
            if ($bad !== []) {
                return self::error('wpsync_staging_unsupported', self::badTables($bad), 422);
            }
            $need = self::need($scope, $code);
        } catch (StagingException $e) {
            return $e->reason() === StagingException::UNSUPPORTED
                ? self::error('wpsync_staging_unsupported', $e->getMessage(), 422)
                : self::error('wpsync_staging_failed', 'Staging lässt sich nicht vorbereiten: ' . self::clean($e->getMessage()), 500);
        }
        if ($need['code_bytes'] !== null && $need['disk_free'] !== null && $need['disk_free'] < 1.2 * $need['code_bytes']) {
            return self::error('wpsync_staging_space', 'Zu wenig freier Speicherplatz auf dem Server für den Code der Kopie.', 507);
        }
        if ($dry) {
            return new \WP_REST_Response(['need' => $need, 'probe' => null, 'url' => $record !== null && $op !== 'create' ? self::url($record) : '']);
        }
        return $op === 'create'
            ? self::startCreate($raw, $scope, $need, $now, $prefix, $dir)
            : self::startRefresh($record, $raw, $scope, $code, $need, $now);
    }

    /** @param array<string, mixed>|null $record */
    private static function save(?array $record): void
    {
        if (!Store::setState(self::STATE, $record)) {
            throw StagingException::failed('Der Zustand der Staging-Kopie liess sich nicht speichern.');
        }
    }

    /** @param array<string, mixed> $record */
    private static function guard(array $record): StagingGuard
    {
        global $wpdb;
        return new StagingGuard((string) $wpdb->base_prefix, (string) ($record['prefix'] ?? ''), ABSPATH, (string) ($record['dir'] ?? ''));
    }

    /** @param array<string, mixed> $record */
    private static function access(array $record): StagingAccess
    {
        $guard = self::guard($record);
        return new StagingAccess($guard->path($guard->root() . '/' . StagingAccess::FILE));
    }

    /**
     * Pfad der Kopie unter der Domain, z. B. /wpsync-staging-0123456789ab (mit home-Pfad davor),
     * ohne Slash am Ende – so vergleicht ihn der Riegel mit REQUEST_URI.
     *
     * @param array<string, mixed> $record
     */
    private static function urlPath(array $record): string
    {
        return rtrim((string) parse_url(home_url(), PHP_URL_PATH), '/') . '/' . (string) ($record['dir'] ?? '');
    }

    private static function uploadsUrl(): string
    {
        return untrailingslashit((string) wp_upload_dir(null, false)['baseurl']);
    }

    /** @param array<string, mixed> $record */
    private static function running(array $record, int $now): bool
    {
        return is_array($record['job'] ?? null) && $now - (int) ($record['job']['touched'] ?? 0) < self::JOB_TTL;
    }

    /**
     * Eine Sperre für alles, was den Datensatz ändert: zwei Jobs, ein Job und ein Login oder der
     * Cron dürfen sich nicht überholen.
     *
     * @param callable(): mixed $do
     * @return mixed|\WP_Error
     */
    private static function locked(callable $do)
    {
        global $wpdb;
        $lock = Store::lockName('staging');
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock)) !== 1) {
            return self::error('wpsync_staging_busy', 'Auf der Staging-Kopie wird gerade gearbeitet.', 423);
        }
        try {
            return $do();
        } finally {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    /** Was Staging auf diesem Server verhindert, oder null (Spec 3). Schreibt nichts. */
    private static function unsupported(): ?string
    {
        if (is_multisite()) {
            return 'Multisite wird für Staging nicht unterstützt.';
        }
        $home = untrailingslashit(home_url());
        if ($home !== untrailingslashit(site_url())) {
            return 'WordPress mit abweichender WordPress- und Website-Adresse wird für Staging nicht unterstützt.';
        }
        // Aufgelöst verglichen: auch ein wp-content, das nur ein Symlink nach woanders ist, liegt nicht im WordPress-Ordner.
        $abs     = realpath(ABSPATH);
        $content = realpath(WP_CONTENT_DIR);
        if ($abs === false || $content === false || rtrim(wp_normalize_path($content), '/') !== rtrim(wp_normalize_path($abs), '/') . '/wp-content') {
            return 'wp-content liegt nicht direkt im WordPress-Ordner – für Staging nicht unterstützt.';
        }
        $uploads = self::uploadsUrl();
        if (!StagingConfig::validUrl($uploads)) {
            return 'Die Upload-Adresse ' . self::clean($uploads) . ' lässt sich nicht in eine Weiterleitung schreiben.';
        }
        try {
            // Dieselben Prüfungen, an denen sonst erst die letzte Phase scheiterte.
            $sample = ['dir' => StagingGuard::DIR_PREFIX . '000000000000'];
            StagingConfig::htaccess(self::urlPath($sample), $uploads);
            new StagingReplace($home, '/' . $sample['dir']);
            if (!StagingConfig::validUrl($home)) {
                throw new \InvalidArgumentException('home');
            }
        } catch (\InvalidArgumentException $e) {
            return 'Die Website-Adresse ' . self::clean($home) . ' lässt sich nicht in die Regeln der Kopie schreiben.';
        }
        if (!is_writable(ABSPATH)) {
            return 'PHP darf im WordPress-Ordner keinen Ordner anlegen – Rechte prüfen.';
        }
        return null;
    }

    /**
     * Ein freies Staging-Präfix (V14). Zusätzlich ohne Rücksicht auf Gross- und Kleinschreibung
     * geprüft: auf manchen Servern unterscheidet MySQL Tabellennamen so nicht.
     *
     * @throws StagingException
     */
    private static function newPrefix(): string
    {
        global $wpdb;
        $live     = (string) $wpdb->base_prefix;
        $existing = array_map('strtolower', array_map('strval', (array) $wpdb->get_col('SHOW TABLES')));
        for ($try = 0; $try < 5; $try++) {
            $prefix = StagingGuard::newPrefix($live, $existing);
            if (!StagingGuard::overlaps(strtolower($live), $prefix)) {
                return $prefix;
            }
        }
        throw StagingException::unsupported('Das Tabellen-Präfix „' . self::clean($live) . '“ überschneidet sich mit dem Staging-Präfix stg…_ – Staging ist hier nicht möglich.');
    }

    /**
     * Liegt von dieser Kopie noch etwas auf dem Server – Ordner oder Tabellen? Im Zweifel ja.
     *
     * @param array<string, mixed> $record
     */
    private static function leftovers(array $record): bool
    {
        global $wpdb;
        try {
            $guard = self::guard($record);
        } catch (StagingException $e) {
            return false; // Ordnername oder Präfix unlesbar: es gibt nichts, was sich noch finden liesse
        }
        clearstatcache(true);
        if (is_link($guard->root()) || file_exists($guard->root())) {
            return true;
        }
        $tables = (array) $wpdb->get_col($guard->showTables());
        return $tables !== [] || (string) $wpdb->last_error !== '';
    }

    /**
     * Tabellen der Kopie. Eine Tabelle, die sich nicht abbilden lässt (Name ausserhalb von
     * [A-Za-z0-9_$] oder als Staging-Tabelle länger als 64 Zeichen), bleibt weg, wenn das Profil
     * ihre Daten ohnehin nicht kopiert – sonst steht sie in der zweiten Liste.
     *
     * @return array{0: list<array{live: string, stg: string, mode: string}>, 1: list<string>}
     */
    private static function tablePlan(StagingGuard $guard, Scope $scope): array
    {
        $plan = [];
        $bad  = [];
        foreach (Store::dataTables() as $live) {
            $mode = $scope->tableMode($live);
            try {
                $plan[] = ['live' => $live, 'stg' => $guard->stagingName($live), 'mode' => $mode];
            } catch (StagingException $e) {
                if ($mode === Scope::FULL) {
                    $bad[] = $live;
                }
            }
        }
        return [$plan, $bad];
    }

    /** @param list<string> $bad */
    private static function badTables(array $bad): string
    {
        $names = array_map(static function (string $name): string {
            return '„' . substr((string) preg_replace('/[^\x20-\x7e]/', '?', $name), 0, 80) . '“';
        }, array_slice($bad, 0, 5));
        return (count($bad) === 1 ? 'Die Tabelle ' : 'Die Tabellen ') . implode(', ', $names) . (count($bad) > 5 ? ' und weitere' : '')
            . (count($bad) === 1 ? ' lässt' : ' lassen') . ' sich nicht nach Staging kopieren: der Name enthält andere Zeichen als A–Z, a–z, 0–9, _ und $'
            . ' oder wird mit dem Staging-Präfix länger als 64 Zeichen. Im Profil abwählen oder umbenennen.';
    }

    /**
     * @return array{code_bytes: int|null, db_bytes: int, disk_free: int|null, warnings: list<string>}
     * @throws StagingException
     */
    private static function need(Scope $scope, bool $code): array
    {
        $warnings = [];
        $bytes    = null;
        if ($code) {
            $abs   = rtrim(ABSPATH, '/');
            $items = array_merge(StagingFiles::coreItems($abs), StagingFiles::contentItems(WP_CONTENT_DIR, $scope));
            $bytes = StagingFiles::size($abs, $items, microtime(true) + Budget::seconds((int) ini_get('max_execution_time')) / 2);
            if ($bytes === null) {
                $warnings[] = 'Grösse des Codes nicht ermittelt (Zeitlimit) – freier Platz auf dem Server nicht geprüft.';
            }
        }
        $free = function_exists('disk_free_space') ? @disk_free_space(ABSPATH) : false;
        if ($free === false) {
            $warnings[] = 'Freier Platz auf dem Server ist von PHP aus nicht abfragbar.';
        }
        return ['code_bytes' => $bytes, 'db_bytes' => StagingDb::bytes($scope), 'disk_free' => $free === false ? null : (int) $free, 'warnings' => $warnings];
    }

    /** @return \WP_REST_Response|\WP_Error */
    private static function startCreate(array $raw, Scope $scope, array $need, int $now, string $prefix, string $dir)
    {
        $token  = bin2hex(random_bytes(16));
        $record = [
            'status'     => self::CREATING,
            'dir'        => $dir,
            'prefix'     => $prefix,
            'created'    => $now,
            'copied_at'  => 0,
            'anonymized' => $scope->anonymize(),
            'code_ok'    => false,
            'error'      => '',
            'error_code' => '',
            'result'     => null,
            'job'        => self::job('create', $raw, $now) + ['probe_token' => $token],
        ];
        try {
            $guard = self::guard($record);
            $root  = $guard->path($guard->root());
            clearstatcache(true);
            if (file_exists($root) || is_link($root)) {
                return self::error('wpsync_staging_failed', 'Der gewürfelte Staging-Ordner existiert schon – noch einmal versuchen.', 500);
            }
            self::save($record); // vor dem ersten mkdir: auch ein halber Ordner wird beim Aufräumen gefunden
        } catch (StagingException $e) {
            return self::error('wpsync_staging_failed', 'Staging konnte nicht angelegt werden: ' . self::clean($e->getMessage()), 500);
        }
        try {
            if (!@mkdir($root, 0755)) {
                throw StagingException::failed('Der Staging-Ordner liess sich nicht anlegen.');
            }
            self::write($guard, $root . '/.htaccess', StagingConfig::htaccess(self::urlPath($record), self::uploadsUrl(), $token));
            self::write($guard, $root . '/' . StagingConfig::PROBE_DENY, "deny\n");
            self::write($guard, $root . '/' . StagingConfig::PROBE_TARGET, $token . "\n");
            self::write($guard, $root . '/' . StagingConfig::PROBE_FILES, "deny\n");
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            try {
                self::removeRoot($guard, INF);
            } catch (\RuntimeException $ignored) {
                // ein Rest bleibt: der Datensatz kennt den Ordner, delete räumt ihn weg
            }
            $record['status']     = self::FAILED;
            $record['job']        = null;
            $record['error']      = 'probe: ' . self::clean($e->getMessage());
            $record['error_code'] = StagingException::FAILED;
            Store::setState(self::STATE, $record);
            return self::error('wpsync_staging_failed', 'Staging konnte nicht angelegt werden: ' . self::clean($e->getMessage()), 500);
        }
        $url = self::url($record);
        return new \WP_REST_Response(['need' => $need, 'url' => $url, 'probe' => [
            'deny_url'    => $url . '/' . StagingConfig::PROBE_DENY,
            'rewrite_url' => $url . '/' . StagingConfig::PROBE_REWRITE,
            'files_url'   => $url . '/' . StagingConfig::PROBE_FILES,
            'token'       => $token,
        ]]);
    }

    /** @return \WP_REST_Response|\WP_Error */
    private static function startRefresh(array $record, array $raw, Scope $scope, bool $code, array $need, int $now)
    {
        try {
            self::lockDown(self::guard($record)); // zuerst sperren (V7)
            if ($code) {
                Push::dropTarget('staging');
                $record['code_ok'] = false;
            }
            $record['status']     = self::REFRESHING;
            $record['anonymized'] = $scope->anonymize();
            $record['error']      = '';
            $record['error_code'] = '';
            $record['result']     = null;
            $record['job']        = self::job($code ? 'refresh-code' : 'refresh', $raw, $now);
            self::save($record);
        } catch (\RuntimeException $e) {
            return self::error('wpsync_staging_failed', 'Staging lässt sich nicht sperren: ' . self::clean($e->getMessage()), 500);
        }
        return new \WP_REST_Response(['need' => $need, 'probe' => null, 'url' => self::url($record)]);
    }

    /** @return \WP_REST_Response|\WP_Error */
    private static function startDelete(array $record, int $now)
    {
        try {
            self::lockDown(self::guard($record));
        } catch (\RuntimeException $e) {
            // Ordner fehlt oder ist kaputt – gelöscht wird trotzdem
        }
        Push::dropTarget('staging'); // samt Arbeitsordnern in der Kopie, solange es sie gibt
        $record['status'] = self::DELETING;
        $record['job']    = self::job('delete', [], $now);
        try {
            self::save($record);
        } catch (StagingException $e) {
            return self::error('wpsync_staging_failed', $e->getMessage(), 500);
        }
        return new \WP_REST_Response(['need' => null, 'probe' => null, 'url' => self::url($record)]);
    }

    /** @return array<string, mixed> */
    private static function job(string $op, array $scope, int $now): array
    {
        return [
            'id' => bin2hex(random_bytes(8)), 'op' => $op, 'phase' => self::PHASES[$op][0], 'scope' => $scope, 'i' => 0, 'cursor' => null,
            'tables' => 0, 'chunk' => 2000, 'files' => 0, 'replaced' => [], 'skipped_values' => 0,
            'touched' => $now, 'error' => '', 'error_code' => '',
        ];
    }

    /**
     * Arbeitet bis $deadline, mindestens einen Schritt. Nach jedem Schritt steht der Zustand in
     * der Datenbank, bevor der nächste beginnt.
     *
     * @param array<string, mixed> $record
     * @return array<string, mixed>|null null: Kopie gelöscht
     * @throws StagingException wenn sich der Zustand nicht speichern lässt
     */
    private static function work(array $record, array $params, float $deadline): ?array
    {
        do {
            $job = $record['job'];
            try {
                $job = self::phase($record, $job, $params, $deadline);
            } catch (\Throwable $e) {
                $job = self::aborted($job, $e);
            }
            $job['touched'] = time();
            $record         = self::finish($record, $job);
            self::save($record);
        } while ($record !== null && is_array($record['job'] ?? null) && microtime(true) < $deadline);
        return $record;
    }

    /**
     * Ein Abbruch räumt auf (V12); scheitert das Aufräumen oder Löschen selbst, bleibt „failed“
     * mit Grund, und delete lässt sich wiederholen.
     *
     * @return array<string, mixed>
     */
    private static function aborted(array $job, \Throwable $e): array
    {
        $op      = (string) ($job['op'] ?? '');
        $message = (string) ($job['phase'] ?? '') . ': ' . self::clean($e->getMessage());
        $reason  = $e instanceof StagingException ? $e->reason() : StagingException::FAILED;
        if (strpos($op, 'cleanup') === 0 || $op === 'delete') {
            $job['error']      = (string) ($job['error'] ?? '') === '' ? $message : $job['error'] . ' | ' . $message;
            $job['error_code'] = (string) ($job['error_code'] ?? '') !== '' ? $job['error_code'] : $reason;
            $job['phase']      = 'failed';
            return $job;
        }
        $next               = self::job($op === 'create' ? 'cleanup-create' : 'cleanup-refresh', [], time());
        $next['error']      = $message;
        $next['error_code'] = $reason;
        return $next;
    }

    /**
     * Genau ein Schritt der aktuellen Phase. Die Schritte der Datenbank bekommen keine Zeit über
     * den einen Schritt hinaus: wie weit sie kamen, muss gespeichert sein, bevor der nächste läuft.
     *
     * @return array<string, mixed>
     */
    private static function phase(array $record, array $job, array $params, float $deadline): array
    {
        $phase = (string) $job['phase'];
        $guard = self::guard($record);
        if ($phase === 'probe') {
            if (($params['probe'] ?? '') !== 'ok') {
                throw StagingException::unsupported("Der Server wertet die .htaccess im Staging-Ordner nicht aus (nginx?). Staging braucht diese Regel:\n" . StagingConfig::nginxRule(self::urlPath($record)));
            }
            return self::finishProbe($guard, $job);
        }
        // Nur für das E2E (V13): löst nichts aus ausser dem Abbruch, den auch ein echter Fehler brächte.
        if (defined('WPSYNC_TEST_FAIL_PHASE') && WPSYNC_TEST_FAIL_PHASE === $phase && strpos((string) $job['op'], 'cleanup') !== 0) {
            throw StagingException::failed('Testabbruch (WPSYNC_TEST_FAIL_PHASE)');
        }
        switch ($phase) {
            case 'lock':
                try {
                    self::lockDown($guard);
                } catch (\RuntimeException $e) {
                    // aufgeräumt wird trotzdem
                    $job['error'] = ltrim($job['error'] . ' | ', ' |') . 'lock: ' . self::clean($e->getMessage());
                }
                return self::advance($job);
            case 'drop':
                return StagingDb::dropAll($guard, $deadline) ? self::advance($job) : $job;
            case 'remove':
                return self::removeRoot($guard, $deadline) ? self::advance($job) : $job;
            case 'remove-code':
                return self::removeCode($guard, $job, $deadline);
            case 'files':
                return self::files($guard, $job, $deadline);
            case 'tables':
                return self::tables($guard, $job);
            case 'anonymize':
                return self::anonymize($guard, $job);
            case 'fixup':
                StagingDb::fixPrefix($guard);
                return self::advance($job);
            case 'urls':
                return self::urls($record, $guard, $job);
            case 'settings':
                self::settings($record, $guard, $job);
                return self::advance($job);
        }
        throw StagingException::failed('unbekannte Phase ' . self::clean($phase));
    }

    /** Probe bestanden: Probedateien weg, Kopie bis zum Ende des Jobs ganz gesperrt (V7). */
    private static function finishProbe(StagingGuard $guard, array $job): array
    {
        $root = $guard->root();
        self::lockDown($guard);
        foreach ([StagingConfig::PROBE_DENY, StagingConfig::PROBE_TARGET, StagingConfig::PROBE_FILES] as $file) {
            @unlink($guard->path($root . '/' . $file));
        }
        return self::advance($job);
    }

    /** @return array<string, mixed> */
    private static function advance(array $job): array
    {
        $phases        = self::PHASES[$job['op']];
        $at            = array_search($job['phase'], $phases, true);
        $job['phase']  = $phases[$at === false ? count($phases) - 1 : min($at + 1, count($phases) - 1)];
        $job['i']      = 0;
        $job['cursor'] = null;
        return $job;
    }

    /** @return array<string, mixed> */
    private static function files(StagingGuard $guard, array $job, float $deadline): array
    {
        $abs   = rtrim(ABSPATH, '/');
        $items = self::planned($job, 'files');
        if ($items === null) {
            $items = array_merge(StagingFiles::coreItems($abs), StagingFiles::contentItems(WP_CONTENT_DIR, Scope::fromArray($job['scope'])));
            self::plan($job, 'files', $items);
        }
        $cursor = self::unpack($job['cursor']);
        $cursor = is_array($cursor) && count($cursor) === 2 ? [(int) $cursor[0], (string) $cursor[1]] : [0, ''];
        // Kopieren ist wiederholbar – hier darf ein Schritt die ganze Zeit nutzen.
        $result        = StagingFiles::copy($abs, $guard->root(), $items, $cursor, $deadline, [$guard, 'path']);
        $job['files'] += $result['files'];
        if ($result['next'] === null) {
            return self::advance($job);
        }
        $job['cursor'] = self::pack($result['next']);
        $job['i']      = $result['next'][0];
        return $job;
    }

    /** refresh --code: der kopierte Code geht, wp-config.php, Zustand und .htaccess bleiben bis „settings“. */
    private static function removeCode(StagingGuard $guard, array $job, float $deadline): array
    {
        $root  = $guard->root();
        $items = self::planned($job, 'remove');
        if ($items === null) {
            $items = array_merge(
                StagingFiles::coreItems($root),
                ['wp-content/index.php', 'wp-content/plugins', 'wp-content/themes', 'wp-content/mu-plugins', 'wp-content/languages', 'wp-content/' . Store::pushDirName()]
            );
            self::plan($job, 'remove', $items);
        }
        if ($job['i'] >= count($items)) {
            return self::advance($job);
        }
        if (StagingFiles::remove($root . '/' . $items[$job['i']], $deadline, [$guard, 'path'])) {
            $job['i']++;
        }
        return $job;
    }

    /** @return array<string, mixed> */
    private static function tables(StagingGuard $guard, array $job): array
    {
        $scope  = Scope::fromArray($job['scope']);
        $tables = self::planned($job, 'tables');
        if ($tables === null) {
            list($tables, $bad) = self::tablePlan($guard, $scope);
            if ($bad !== []) {
                throw StagingException::unsupported(self::badTables($bad));
            }
            if ($tables === []) {
                throw StagingException::failed('Keine Tabellen zum Kopieren gefunden.');
            }
            self::plan($job, 'tables', $tables);
        }
        $job['tables'] = count($tables);
        if ($job['i'] >= count($tables)) {
            return self::advance($job);
        }
        $cursor       = self::unpack($job['cursor']);
        $result       = StagingDb::copyTable($guard, $scope, $tables[$job['i']], is_array($cursor) ? $cursor : null, (int) $job['chunk'], 0.0, (float) Budget::seconds((int) ini_get('max_execution_time')));
        $job['chunk'] = $result['chunk'];
        if ($result['done']) {
            $job['i']++;
            $job['cursor'] = null;
        } else {
            $job['cursor'] = self::pack($result['cursor']);
        }
        return $job;
    }

    /** @return array<string, mixed> */
    private static function anonymize(StagingGuard $guard, array $job): array
    {
        if (!Scope::fromArray($job['scope'])->anonymize()) {
            return self::advance($job);
        }
        $tables = self::copied($job);
        if ($job['i'] >= count($tables)) {
            return self::advance($job);
        }
        $entry = $tables[$job['i']];
        if ($entry['mode'] === Scope::FULL) {
            $after  = self::unpack($job['cursor']);
            // Ob eine Regel greift, entscheidet StagingDb mit dem Staging-Präfix – nicht noch einmal hier.
            $result = StagingDb::anonymize($guard, Store::anonKey(), $entry, is_string($after) ? $after : null, 0.0);
            if (!$result['done']) {
                $job['cursor'] = self::pack($result['after']);
                return $job;
            }
        }
        $job['i']++;
        $job['cursor'] = null;
        return $job;
    }

    /** @return array<string, mixed> */
    private static function urls(array $record, StagingGuard $guard, array $job): array
    {
        $tables = self::copied($job);
        if ($job['i'] >= count($tables)) {
            return self::advance($job);
        }
        $entry   = $tables[$job['i']];
        $cursor  = self::unpack($job['cursor']);
        $at      = is_array($cursor) ? max(0, (int) ($cursor['c'] ?? 0)) : 0;
        $after   = is_array($cursor) && is_string($cursor['after'] ?? null) ? $cursor['after'] : null;
        $columns = $entry['mode'] === Scope::FULL ? StagingDb::textColumns($guard, [$entry]) : [];
        if ($at >= count($columns)) {
            $job['i']++;
            $job['cursor'] = null;
            return $job;
        }
        $replace = new StagingReplace(home_url(), '/' . $record['dir']);
        $result  = StagingDb::replaceUrls($guard, $replace, $columns[$at], $after, 0.0);
        $table   = (string) $columns[$at]['table'];
        if ($result['changed'] > 0) {
            $job['replaced'][$table] = (int) ($job['replaced'][$table] ?? 0) + $result['changed'];
        }
        $job['skipped_values']  += $replace->skipped();
        $job['cursor']           = self::pack($result['done'] ? ['c' => $at + 1, 'after' => null] : ['c' => $at, 'after' => $result['cursor']]);
        return $job;
    }

    /** Phase 8: Riegel, eigene wp-config.php, Optionen, Zustand – und erst zuletzt der Zugang (V7). */
    private static function settings(array $record, StagingGuard $guard, array $job): void
    {
        self::copied($job); // ohne kopierte Tabellen gibt es nichts zu öffnen
        $root = $guard->root();
        $mu   = $root . '/wp-content/mu-plugins';
        StagingFiles::mkdir($mu . '/wpsync-staging', [$guard, 'path']);
        self::copyFile($guard, self::$pluginDir . '/src/StagingAccess.php', $mu . '/wpsync-staging/StagingAccess.php');
        self::copyFile($guard, self::$pluginDir . '/src/StagingHosts.php', $mu . '/wpsync-staging/StagingHosts.php');
        self::copyFile($guard, self::$pluginDir . '/staging/00-wpsync-staging.php', $root . '/' . self::RIEGEL);
        self::write(
            $guard,
            $root . '/wp-config.php',
            StagingConfig::wpConfig(self::dbConstants(), $guard->stagingPrefix(), self::url($record), StagingConfig::salts(), self::extraConstants()),
            self::configMode()
        );
        StagingDb::settings($guard, Scope::fromArray($job['scope']));
        $now = time();
        self::access($record)->init(untrailingslashit(home_url()), self::uploadsUrl(), self::urlPath($record), $now, $now);
        self::write($guard, $root . '/.htaccess', StagingConfig::htaccess(self::urlPath($record), self::uploadsUrl()));
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>|null
     */
    private static function finish(array $record, array $job): ?array
    {
        switch ($job['phase']) {
            case 'gone':
                self::dropPlan();
                return null;
            case 'done':
                self::dropPlan();
                $record['status']     = self::READY;
                $record['copied_at']  = time();
                if (in_array($job['op'], ['create', 'refresh-code'], true)) {
                    // Nie derselbe Wert wie zuvor: wer sich den Stand des Codes gemerkt hat, erkennt jede neue Kopie.
                    $record['code_copied_at'] = max($record['copied_at'], (int) ($record['code_copied_at'] ?? 0) + 1);
                }
                $record['code_ok']    = true;
                $record['job']        = null;
                $record['error']      = '';
                $record['error_code'] = '';
                $record['result']     = [
                    'url'            => self::url($record),
                    'prefix'         => (string) $record['prefix'],
                    'anonymized'     => (bool) $record['anonymized'],
                    'replaced'       => $job['replaced'],
                    'skipped_values' => (int) $job['skipped_values'],
                    'files'          => (int) $job['files'],
                ];
                return $record;
            case 'failed':
                self::dropPlan();
                $record['status']     = self::FAILED;
                $record['job']        = null;
                $record['error']      = (string) $job['error'];
                $record['error_code'] = (string) $job['error_code'] !== '' ? (string) $job['error_code'] : StagingException::FAILED;
                return $record;
        }
        $record['job'] = $job;
        return $record;
    }

    /** @param array<string, mixed>|null $record */
    private static function progress(?array $record): array
    {
        if ($record === null) {
            return ['status' => 'deleted', 'phase' => '', 'done' => 0, 'total' => 0, 'error' => '', 'error_code' => '', 'result' => null];
        }
        $job = is_array($record['job'] ?? null) ? $record['job'] : null;
        $out = [
            'status'     => (string) ($record['status'] ?? ''),
            'phase'      => $job === null ? '' : (string) $job['phase'],
            'done'       => 0,
            'total'      => 0,
            'error'      => $job !== null && (string) $job['error'] !== '' ? (string) $job['error'] : (string) ($record['error'] ?? ''),
            'error_code' => $job !== null && (string) $job['error_code'] !== '' ? (string) $job['error_code'] : (string) ($record['error_code'] ?? ''),
            'result'     => null,
        ];
        if ($job !== null) {
            $lists        = ['files' => 'files', 'remove-code' => 'remove', 'tables' => 'tables', 'anonymize' => 'tables', 'urls' => 'tables'];
            $out['done']  = (int) $job['i'];
            $out['total'] = isset($lists[$job['phase']]) ? count(self::planned($job, $lists[$job['phase']]) ?? []) : 0;
        }
        if ($out['status'] === self::READY && is_array($record['result'] ?? null)) {
            $out['result'] = ['replaced' => (object) ($record['result']['replaced'] ?? [])] + $record['result'];
        }
        return $out;
    }

    /**
     * Eine Liste des Jobs (files, remove, tables) oder null, wenn sie noch nicht feststeht. Die
     * Listen eines anderen Jobs zählen nicht.
     *
     * @return list<mixed>|null
     */
    private static function planned(array $job, string $key): ?array
    {
        $id = (string) ($job['id'] ?? '');
        if (self::$plan === null || (self::$plan['job'] ?? '') !== $id) {
            $stored     = Store::getState(self::PLAN);
            self::$plan = $stored !== null && ($stored['job'] ?? '') === $id ? $stored : ['job' => $id];
        }
        $list = self::$plan[$key] ?? null;
        return is_array($list) ? self::unpack($list) : null;
    }

    /**
     * @param list<mixed> $list
     * @throws StagingException
     */
    private static function plan(array $job, string $key, array $list): void
    {
        self::planned($job, $key);
        self::$plan[$key] = self::pack(array_values($list));
        if (!Store::setState(self::PLAN, self::$plan)) {
            self::$plan = null;
            throw StagingException::failed('Der Plan des Staging-Jobs liess sich nicht speichern.');
        }
    }

    private static function dropPlan(): void
    {
        self::$plan = null;
        Store::setState(self::PLAN, null);
    }

    /**
     * Die Tabellen, die Phase „tables“ kopiert hat. Fehlt die Liste, bricht der Job ab: ohne sie
     * bliebe die Kopie still im Klartext (T2).
     *
     * @return list<array{live: string, stg: string, mode: string}>
     * @throws StagingException
     */
    private static function copied(array $job): array
    {
        $tables = self::planned($job, 'tables');
        if ($tables === null || $tables === [] || count($tables) !== (int) ($job['tables'] ?? 0)) {
            throw StagingException::failed('Die Tabellenliste des Staging-Jobs fehlt.');
        }
        return $tables;
    }

    /**
     * Cursor und Dateinamen sind Bytes aus Datenbank und Dateisystem; als JSON überstehen sie den
     * Zustand nur verpackt – ein veränderter Cursor übersprünge Zeilen.
     *
     * @param mixed $value
     * @return mixed
     */
    private static function pack($value)
    {
        if (is_string($value)) {
            return 'b:' . base64_encode($value);
        }
        return is_array($value) ? array_map([self::class, 'pack'], $value) : $value;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function unpack($value)
    {
        if (is_string($value)) {
            return strpos($value, 'b:') === 0 ? (string) base64_decode(substr($value, 2), true) : $value;
        }
        return is_array($value) ? array_map([self::class, 'unpack'], $value) : $value;
    }

    /** @return array<string, string> */
    private static function dbConstants(): array
    {
        $out = [];
        foreach (['DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST', 'DB_CHARSET', 'DB_COLLATE'] as $name) {
            $out[$name] = defined($name) ? (string) constant($name) : '';
        }
        return $out;
    }

    /** @return array<string, string> */
    private static function extraConstants(): array
    {
        $out = [];
        foreach (['WP_MEMORY_LIMIT', 'WP_MAX_MEMORY_LIMIT'] as $name) {
            if (defined($name) && is_scalar(constant($name))) {
                $out[$name] = (string) constant($name);
            }
        }
        return $out;
    }

    /** Rechte wie die wp-config.php von Live, sonst 0640 (Spec 5.3). */
    private static function configMode(): int
    {
        foreach ([ABSPATH . 'wp-config.php', dirname(ABSPATH) . '/wp-config.php'] as $file) {
            if (is_file($file)) {
                return fileperms($file) & 0777;
            }
        }
        return 0640;
    }

    /**
     * Alles 403 (V7), und kein Link und kein Cookie gilt mehr – der Riegel lehnt dann auch ab, wo
     * die .htaccess nicht wirkt. Beides wird versucht, auch wenn eines scheitert. Ohne Ordner gibt
     * es nichts zu sperren.
     *
     * @throws StagingException
     */
    private static function lockDown(StagingGuard $guard): void
    {
        $root = $guard->root();
        clearstatcache(true);
        if (!is_dir($root) || is_link($root)) {
            return;
        }
        $failed = null;
        try {
            self::write($guard, $root . '/.htaccess', StagingConfig::locked());
        } catch (StagingException $e) {
            $failed = $e;
        }
        try {
            $state = $guard->path($root . '/' . StagingAccess::FILE);
            if (is_file($state)) {
                (new StagingAccess($state))->lock();
            }
        } catch (\RuntimeException $e) {
            $failed = $failed ?? StagingException::failed('Die Zugänge der Kopie liessen sich nicht sperren.');
        }
        if ($failed !== null) {
            throw $failed;
        }
    }

    /**
     * Der Staging-Ordner samt Inhalt. Ist an seiner Stelle ein Symlink, geht nur der Symlink – der
     * Name ist geprüft, das Ziel wird nie betreten.
     *
     * @throws StagingException
     */
    private static function removeRoot(StagingGuard $guard, float $deadline): bool
    {
        $root = $guard->root();
        clearstatcache(true);
        if (is_link($root)) {
            if (!@unlink($root)) {
                throw StagingException::failed('Der Staging-Ordner ist ein Symlink und liess sich nicht entfernen.');
            }
            return true;
        }
        return StagingFiles::remove($root, $deadline, [$guard, 'path']);
    }

    /**
     * Schreibt ganz oder gar nicht (temporäre Datei, dann rename): eine halbe .htaccess wäre eine
     * offene, und ein volles Dateisystem liesse von der Sperre sonst eine leere Datei übrig.
     *
     * @throws StagingException
     */
    private static function write(StagingGuard $guard, string $file, string $content, ?int $mode = null): void
    {
        $file = $guard->path($file);
        foreach (glob($file . '.*.tmp') ?: [] as $stale) {
            @unlink($guard->path((string) $stale)); // Rest eines abgebrochenen Schreibens
        }
        $tmp = $guard->path($file . '.' . bin2hex(random_bytes(6)) . '.tmp');
        $ok  = @file_put_contents($tmp, $content) === strlen($content);
        if ($ok && $mode !== null) {
            @chmod($tmp, $mode); // vor dem rename: die Datei liegt nie mit weiteren Rechten an ihrem Platz
        }
        if (!$ok || !@rename($tmp, $file)) {
            @unlink($tmp);
            throw StagingException::failed('Datei nicht schreibbar: ' . basename($file));
        }
    }

    /** @throws StagingException */
    private static function copyFile(StagingGuard $guard, string $from, string $to): void
    {
        $content = @file_get_contents($from);
        if ($content === false || $content === '') {
            throw StagingException::failed('Agent-Datei fehlt: ' . basename($from));
        }
        self::write($guard, $to, $content);
    }

    /** Fehlertexte landen im Zustand (JSON) und in Antworten: begrenzt und gültiges UTF-8. */
    private static function clean(string $message): string
    {
        $message = substr($message, 0, 600);
        return preg_match('//u', $message) === 1 ? $message : (string) preg_replace('/[^\x20-\x7e]/', '?', $message);
    }

    private static function error(string $code, string $message, int $status): \WP_Error
    {
        return new \WP_Error($code, $message, ['status' => $status]);
    }
}
