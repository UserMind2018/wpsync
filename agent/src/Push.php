<?php
namespace WpSync;

defined('ABSPATH') || exit;

/**
 * Ablauf eines Code-Pushs (Spec Stufe 2, 5.3): begin → upload → commit → confirm, dazu
 * rollback und list. Der Zustand liegt im Arbeitsordner wp-content/wpsync-push-<zufall>/<id>/:
 *   plan.json    Soll-Manifest, benötigte und übernommene Dateien, Hash des Rollback-Schlüssels
 *   stage/<n>/   hochgeladene Dateien der n-ten Einheit
 *   new/<n>/     fertig gebautes Verzeichnis vor dem Tausch
 *   old/<n>/     Snapshot nach dem Tausch
 *   rescue.json  Tausch-Paare und Status – die Wahrheit, auch für rescue.php
 */
final class Push
{
    public const CRON       = 'wpsync_push_prune';
    public const UPLOADING  = 'uploading';
    public const FAILED     = 'failed';
    public const EXPIRED    = 'expired';

    /** Ein Push ohne Aktivität gibt die Sperre nach 10 Minuten frei. */
    public const LOCK_TTL   = 600;
    /** Ein Upload, der nie abgeschlossen wurde, verfällt nach 30 Minuten. */
    public const UPLOAD_TTL = 1800;
    /** Snapshots: die letzten 3 bestätigten Pushes, höchstens 14 Tage (P8). */
    public const KEEP       = 3;
    public const MAX_AGE    = 1209600;
    public const MAX_UNITS  = 50;
    /** Rohdaten pro Upload-Request – Base64 und JSON bleiben unter post_max_size 8 MB (U13). */
    public const MAX_UPLOAD = 4194304;

    /** @var string */
    private static $pluginDir = '';

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

    /** Deaktivieren entfernt Arbeitsordner und Snapshots; bestätigte Stände bleiben live. */
    public static function uninstall(): void
    {
        foreach ((array) glob(self::content() . '/wpsync-push-*', GLOB_ONLYDIR) as $dir) {
            PushSwap::remove((string) $dir);
        }
    }

    public static function maintain(): void
    {
        Store::install();
        self::sync();
        self::prune(time());
    }

    /**
     * Seiten, die der Health-Check der CLI vor und nach dem Tausch aufruft (P7, P15).
     *
     * @return list<string>
     */
    public static function healthUrls(): array
    {
        $urls = [home_url('/'), wp_login_url()];
        if (function_exists('wc_get_page_permalink')) {
            foreach (['shop', 'cart', 'checkout'] as $page) {
                $url = wc_get_page_permalink($page);
                if (is_string($url) && $url !== '') {
                    $urls[] = $url;
                }
            }
        }
        return array_values(array_unique($urls));
    }

    /**
     * Prüft Einheiten, Konflikte, Rechte und Platz. Mit dry nur Auskunft; sonst legt es den Push
     * an und nimmt die Sperre.
     *
     * @param array<string, mixed> $params
     * @return \WP_REST_Response|\WP_Error
     */
    public static function begin(array $params, string $keyId)
    {
        $content = self::content();
        if ($content === '' || rtrim(wp_normalize_path((string) realpath(dirname(self::$pluginDir, 2))), '/') !== $content) {
            return self::error('wpsync_layout', 'Push braucht das Standardlayout wp-content/plugins/wpsync-agent.', 400);
        }
        if (($params['target'] ?? 'live') !== 'live') {
            return self::error('wpsync_push_target', 'Unbekanntes Ziel – dieser Agent kennt nur „live“.', 400);
        }
        $units = self::parseUnits($params['units'] ?? null);
        if ($units instanceof \WP_Error) {
            return $units;
        }
        $now = time();
        self::sync();
        self::prune($now);

        $plans      = [];
        $conflicted = [];
        $readonly   = [];
        $bytes      = 0;
        foreach ($units as $unit) {
            $dir       = $content . '/' . $unit['path'];
            $exists    = is_dir($dir);
            $conflicts = PushManifest::conflicts($exists ? PushManifest::stamps($dir, $unit['path']) : [], $unit['base']);
            $writable  = is_writable(dirname($dir)) && (!$exists || is_writable($dir));
            $plans[]   = [
                'path'      => $unit['path'],
                'exists'    => $exists,
                'version'   => PushUnits::version($dir, $unit['path']),
                'conflicts' => $conflicts,
                'need'      => PushManifest::need($dir, $unit['files']),
                'writable'  => $writable,
            ];
            if ($conflicts !== []) {
                $conflicted[] = $unit['path'];
            }
            if (!$writable) {
                $readonly[] = $unit['path'];
            }
            foreach ($unit['files'] as $file) {
                $bytes += $file['size'];
            }
        }
        $pending = self::pending();
        $answer  = [
            'push_id'       => '',
            'agent_version' => WPSYNC_VERSION,
            'health_urls'   => self::healthUrls(),
            'window_open'   => PushWindow::open(Store::pushUntil($keyId), $now),
            'pending'       => $pending,
            'units'         => $plans,
            // Die URL schon im Probelauf: die CLI prüft den Rückweg, bevor sie einen Push anlegt (AC-66).
            'rescue'        => ['url' => plugins_url('rescue.php', self::$pluginDir . '/wpsync-agent.php'), 'salt' => ''],
        ];
        if (!empty($params['dry'])) {
            return new \WP_REST_Response($answer);
        }

        if (!$answer['window_open']) {
            return self::windowClosed();
        }
        if ($pending !== null) {
            return self::error('wpsync_push_pending', 'Push ' . $pending['push_id'] . ' ist getauscht, aber nicht bestätigt.', 409);
        }
        if ($conflicted !== [] && empty($params['force'])) {
            return self::error('wpsync_push_conflict', 'Auf dem Server seit dem letzten Pull geändert: ' . implode(', ', $conflicted), 409);
        }
        if ($readonly !== []) {
            return self::error('wpsync_push_perms', 'Der Webserver darf diese Verzeichnisse nicht ersetzen: ' . implode(', ', $readonly), 409);
        }
        $free = function_exists('disk_free_space') ? @disk_free_space($content) : false;
        if ($free !== false && $free < 2 * $bytes) {
            return self::error('wpsync_push_space', 'Zu wenig freier Speicherplatz für Push und Snapshot.', 507);
        }
        $work = self::workDir();
        foreach ($units as $unit) {
            if (!PushSwap::probe($work, dirname($content . '/' . $unit['path']))) {
                return self::error('wpsync_push_perms', 'Verzeichnisse lassen sich nicht umbenennen (Rechte oder anderes Dateisystem): ' . dirname($unit['path']), 409);
            }
        }

        $pushId = PushRescue::newId($now);
        if (!self::acquire($pushId, $keyId, $now)) {
            return self::error('wpsync_push_locked', 'Auf dieser Site läuft bereits ein Push.', 423);
        }
        $salt     = bin2hex(random_bytes(16));
        $planned  = [];
        $summary  = [];
        foreach ($units as $i => $unit) {
            $dir       = $content . '/' . $unit['path'];
            $planned[] = [
                'path'        => $unit['path'],
                'files'       => $unit['files'],
                'need'        => $plans[$i]['need'],
                'carried'     => $plans[$i]['exists'] ? PushManifest::carried($dir, $unit['path']) : [],
                'exists'      => $plans[$i]['exists'],
                'old_version' => $plans[$i]['version'],
            ];
            $summary[] = [
                'path'        => $unit['path'],
                'exists'      => $plans[$i]['exists'],
                'old_version' => $plans[$i]['version'],
                'new_version' => '',
                'files'       => count($unit['files']),
                'uploaded'    => count($plans[$i]['need']),
            ];
        }
        wp_mkdir_p($work . '/' . $pushId . '/stage');
        $plan = [
            'push_id'  => $pushId,
            'key_id'   => $keyId,
            'key_hash' => hash('sha256', PushRescue::key((string) Store::secretFor($keyId), $pushId, $salt)),
            'units'    => $planned,
        ];
        $stored = false !== file_put_contents($work . '/' . $pushId . '/plan.json', (string) wp_json_encode($plan))
            && Store::addPush([
                'push_id' => $pushId,
                'key_id'  => $keyId,
                'device'  => Store::deviceFor($keyId),
                'target'  => 'live',
                'status'  => self::UPLOADING,
                'forced'  => empty($params['force']) ? 0 : 1,
                'units'   => (string) wp_json_encode($summary),
                'created' => $now,
            ]);
        if (!$stored) {
            PushSwap::remove($work . '/' . $pushId);
            self::release($pushId);
            return self::error('wpsync_push_store', 'Push konnte nicht angelegt werden.', 500);
        }

        $answer['push_id']        = $pushId;
        $answer['rescue']['salt'] = $salt;
        return new \WP_REST_Response($answer);
    }

    /**
     * Nimmt angeforderte Dateien entgegen, grosse in Stücken (offset). Jede vollständige Datei
     * wird gegen Grösse und sha256 des Manifests geprüft.
     *
     * @param array<string, mixed> $params
     * @return \WP_REST_Response|\WP_Error
     */
    public static function upload(array $params, string $keyId)
    {
        $now = time();
        if (!PushWindow::open(Store::pushUntil($keyId), $now)) {
            self::abandon($params, $keyId);
            return self::windowClosed();
        }
        $open = self::open($params, $keyId);
        if ($open instanceof \WP_Error) {
            return $open;
        }
        list($pushId, $plan, $work) = $open;
        $index = is_int($params['unit'] ?? null) ? $params['unit'] : -1;
        $unit  = $plan['units'][$index] ?? null;
        if (!is_array($unit)) {
            return self::error('wpsync_push_unit', 'Einheit gehört nicht zu diesem Push.', 400);
        }
        $need     = array_fill_keys(array_map('strval', $unit['need']), true);
        $stage    = $work . '/' . $pushId . '/stage/' . $index;
        $received = 0;
        $total    = 0;
        foreach ((array) ($params['files'] ?? []) as $file) {
            $rel  = is_array($file) && is_string($file['path'] ?? null) ? $file['path'] : '';
            $data = is_array($file) && is_string($file['data'] ?? null) ? base64_decode($file['data'], true) : false;
            if (!isset($need[$rel]) || $data === false) {
                return self::error('wpsync_push_file', 'Datei nicht angefordert oder nicht lesbar.', 400);
            }
            $total += strlen($data);
            if ($total > self::MAX_UPLOAD) {
                return self::error('wpsync_push_size', 'Upload-Request zu gross.', 413);
            }
            $want   = $unit['files'][$rel];
            $to     = $stage . '/' . $rel;
            $offset = is_int($file['offset'] ?? null) ? $file['offset'] : 0;
            wp_mkdir_p(dirname($to));
            clearstatcache(true, $to);
            $have = $offset === 0 ? 0 : (is_file($to) ? (int) filesize($to) : -1);
            if ($offset !== $have || $offset + strlen($data) > (int) $want['size']) {
                return self::error('wpsync_push_offset', 'Stück passt nicht an die Datei: ' . $rel, 409);
            }
            if (file_put_contents($to, $data, $offset === 0 ? 0 : FILE_APPEND) !== strlen($data)) {
                return self::error('wpsync_push_store', 'Datei konnte nicht geschrieben werden.', 500);
            }
            if ($offset + strlen($data) < (int) $want['size']) {
                continue; // weitere Stücke folgen
            }
            if (!hash_equals((string) $want['sha256'], (string) hash_file('sha256', $to))) {
                @unlink($to);
                return self::error('wpsync_push_hash', 'Inhalt passt nicht zum Manifest: ' . $rel, 400);
            }
            touch($to, (int) $want['mtime']);
            $received++;
        }
        self::touchLock($pushId, $now);
        return new \WP_REST_Response(['received' => $received]);
    }

    /**
     * Baut die neuen Verzeichnisse (in Schritten mit Cursor) und tauscht dann alle Einheiten.
     *
     * @param array<string, mixed> $params
     * @return \WP_REST_Response|\WP_Error
     */
    public static function commit(array $params, string $keyId)
    {
        $now = time();
        if (!PushWindow::open(Store::pushUntil($keyId), $now)) {
            self::abandon($params, $keyId);
            return self::windowClosed();
        }
        $open = self::open($params, $keyId);
        if ($open instanceof \WP_Error) {
            return $open;
        }
        list($pushId, $plan, $work) = $open;
        $content  = self::content();
        $base     = $work . '/' . $pushId;
        $cursor   = is_array($params['cursor'] ?? null) ? $params['cursor'] : [];
        $u        = max(0, (int) ($cursor['u'] ?? 0));
        $i        = max(0, (int) ($cursor['i'] ?? 0));
        $deadline = microtime(true) + Budget::seconds((int) ini_get('max_execution_time'));
        self::touchLock($pushId, $now);

        try {
            for (; $u < count($plan['units']); $u++, $i = 0) {
                $unit = $plan['units'][$u];
                if ($i === 0) {
                    foreach ($unit['need'] as $rel) {
                        if (!is_file($base . '/stage/' . $u . '/' . $rel)) {
                            throw new \RuntimeException('not uploaded: ' . $rel);
                        }
                    }
                    PushSwap::remove($base . '/new/' . $u); // Rest eines abgebrochenen Versuchs
                    wp_mkdir_p($base . '/new');
                }
                $next = PushSwap::build($content . '/' . $unit['path'], $base . '/stage/' . $u, $base . '/new/' . $u, $unit['files'], $unit['carried'], $i, $deadline);
                if ($next !== null) {
                    return new \WP_REST_Response(['next' => ['u' => $u, 'i' => $next], 'stamps' => new \stdClass()]);
                }
            }
            // Der Cursor kommt vom Client: vor dem Tausch muss jede Manifest-Datei wirklich liegen.
            foreach ($plan['units'] as $n => $unit) {
                foreach ($unit['files'] as $rel => $want) {
                    $built = $base . '/new/' . $n . '/' . $rel;
                    if (!is_file($built) || (int) filesize($built) !== (int) $want['size']) {
                        throw new \RuntimeException('not built: ' . $unit['path'] . '/' . $rel);
                    }
                }
            }
        } catch (\RuntimeException $e) {
            self::discard($pushId, self::FAILED);
            return self::error('wpsync_push_build', 'Push abgebrochen, nichts getauscht: ' . $e->getMessage(), 409);
        }

        $pairs = [];
        foreach ($plan['units'] as $n => $unit) {
            $pairs[] = [
                'unit'     => $unit['path'],
                'target'   => $content . '/' . $unit['path'],
                'snapshot' => $unit['exists'] ? $base . '/old/' . $n : null,
                'discard'  => $base . '/discard/' . $n,
            ];
        }
        wp_mkdir_p($base . '/old');
        // Vor dem ersten rename: stirbt PHP mitten im Tausch, kann rescue.php zurücktauschen.
        PushRescue::write($work, $pushId, (string) $plan['key_hash'], $pairs, PushRescue::COMMITTED);
        $swapped = [];
        try {
            foreach ($pairs as $n => $pair) {
                PushSwap::swap($pair['target'], $base . '/new/' . $n, $pair['snapshot']);
                $swapped[] = $pair;
            }
        } catch (\RuntimeException $e) {
            foreach (array_reverse($swapped) as $pair) {
                wp_mkdir_p(dirname($pair['discard']));
                PushSwap::restore($pair['target'], $pair['snapshot'], $pair['discard']);
            }
            PushSwap::resetCaches();
            self::discard($pushId, self::FAILED);
            return self::error('wpsync_push_swap', 'Tausch fehlgeschlagen, der alte Stand liegt wieder an seinem Platz: ' . $e->getMessage(), 500);
        }
        PushRescue::supersede($work, $pushId, array_column($pairs, 'unit'));
        PushSwap::resetCaches();
        PushSwap::remove($base . '/stage');

        $stamps  = [];
        $summary = [];
        foreach ($plan['units'] as $unit) {
            $dir                   = $content . '/' . $unit['path'];
            $stamps[$unit['path']] = (object) PushManifest::stamps($dir, $unit['path']);
            $summary[]             = [
                'path'        => $unit['path'],
                'exists'      => $unit['exists'],
                'old_version' => $unit['old_version'],
                'new_version' => PushUnits::version($dir, $unit['path']),
                'files'       => count($unit['files']),
                'uploaded'    => count($unit['need']),
            ];
        }
        Store::updatePush($pushId, ['status' => PushRescue::COMMITTED, 'committed' => time(), 'units' => (string) wp_json_encode($summary)]);
        self::touchLock($pushId, time());
        return new \WP_REST_Response(['next' => null, 'stamps' => (object) $stamps]);
    }

    /**
     * Nach bestandenem Health-Check. Braucht kein offenes Fenster.
     *
     * @param array<string, mixed> $params
     * @return \WP_REST_Response|\WP_Error
     */
    public static function confirm(array $params, string $keyId)
    {
        self::sync();
        $push = self::own($params, $keyId);
        if ($push instanceof \WP_Error) {
            return $push;
        }
        if ($push['status'] !== PushRescue::CONFIRMED) {
            if ($push['status'] !== PushRescue::COMMITTED) {
                return self::error('wpsync_push_state', 'Push ist im Status ' . $push['status'] . '.', 409);
            }
            PushRescue::setStatus(self::workDir(), $push['push_id'], PushRescue::CONFIRMED);
            Store::updatePush($push['push_id'], ['status' => PushRescue::CONFIRMED, 'finished' => time()]);
            self::release($push['push_id']);
            self::prune(time());
        }
        return new \WP_REST_Response(['ok' => true, 'status' => PushRescue::CONFIRMED]);
    }

    /**
     * REST-Weg. Ein unbestätigter Push (nach dem Tausch, vor confirm) lässt sich immer
     * zurückrollen – der Notfall. Ein bestätigter nur bei offenem Push-Fenster des Geräts (U18):
     * sonst könnte ein entwendetes Secret jederzeit alten Code zurück auf die Site bringen.
     *
     * @param array<string, mixed> $params
     * @return \WP_REST_Response|\WP_Error
     */
    public static function rollback(array $params, string $keyId)
    {
        self::sync();
        $push = self::own($params, $keyId);
        if ($push instanceof \WP_Error) {
            return $push;
        }
        if ($push['status'] === PushRescue::CONFIRMED && !PushWindow::open(Store::pushUntil($keyId), time())) {
            return self::error(
                'wpsync_push_window',
                'Push ' . $push['push_id'] . ' ist bestätigt und das Push-Fenster geschlossen. Ein Administrator muss im WP-Admin unter Werkzeuge → wpsync das Push-Fenster öffnen oder den Push dort selbst zurückrollen.',
                403
            );
        }
        return self::rollbackPush($push['push_id']);
    }

    /**
     * Ohne Fensterprüfung – auch für die Admin-Seite, deren Administratoren vertrauenswürdig sind.
     *
     * @return \WP_REST_Response|\WP_Error
     */
    public static function rollbackPush(string $pushId)
    {
        self::sync();
        $push = Store::getPush($pushId);
        if ($push === null) {
            return self::error('wpsync_push_unknown', 'Unbekannter Push.', 404);
        }
        if ($push['status'] !== PushRescue::ROLLED_BACK) {
            if ($push['pruned'] || !in_array($push['status'], [PushRescue::COMMITTED, PushRescue::CONFIRMED], true)) {
                return self::error('wpsync_push_state', 'Für diesen Push gibt es keinen Snapshot (Status ' . $push['status'] . ').', 409);
            }
            list($status, $body) = PushRescue::rollback(self::content(), self::workDir(), $pushId);
            if ($status !== 200) {
                $why = ($body['error'] ?? '') === 'superseded'
                    ? 'Zuerst den späteren Push ' . ($body['by'] ?? '') . ' zurückrollen.'
                    : 'Rollback fehlgeschlagen: ' . ($body['error'] ?? 'unbekannt');
                return self::error('wpsync_push_rollback', $why, $status === 500 ? 500 : 409);
            }
            self::finishRollback($pushId);
        }
        return new \WP_REST_Response(['ok' => true, 'status' => PushRescue::ROLLED_BACK]);
    }

    /** @return \WP_REST_Response */
    public static function index()
    {
        self::sync();
        $pushes = [];
        foreach (Store::pushes(20) as $push) {
            unset($push['key_id']);
            $pushes[] = $push;
        }
        return new \WP_REST_Response(['pushes' => $pushes]);
    }

    /** Übernimmt Rollbacks, die rescue.php an WordPress vorbei ausgeführt hat. */
    public static function sync(): void
    {
        $work = self::content() . '/' . Store::pushDirName();
        foreach (Store::pushes(50) as $push) {
            if ($push['pruned'] || !in_array($push['status'], [PushRescue::COMMITTED, PushRescue::CONFIRMED], true)) {
                continue;
            }
            $record = PushRescue::read($work, $push['push_id']);
            if ($record !== null && $record['status'] === PushRescue::ROLLED_BACK) {
                self::finishRollback($push['push_id']);
            }
        }
    }

    /** Räumt verfallene Uploads und alte Snapshots weg. Unbestätigte Pushes bleiben immer (AC-69). */
    public static function prune(int $now): void
    {
        $kept = 0;
        foreach (Store::pushes(200) as $push) {
            if ($push['pruned']) {
                continue;
            }
            if ($push['status'] === self::UPLOADING && $now - $push['created'] > self::UPLOAD_TTL) {
                self::discard($push['push_id'], self::EXPIRED);
            } elseif ($push['status'] === PushRescue::CONFIRMED) {
                $kept++;
                if ($kept > self::KEEP || $now - $push['created'] > self::MAX_AGE) {
                    self::discard($push['push_id'], PushRescue::CONFIRMED);
                }
            }
        }
    }

    private static function content(): string
    {
        return rtrim(wp_normalize_path((string) realpath(WP_CONTENT_DIR)), '/');
    }

    private static function workDir(): string
    {
        $dir = self::content() . '/' . Store::pushDirName();
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
            file_put_contents($dir . '/.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
            file_put_contents($dir . '/index.php', "<?php\n// Silence is golden.\n");
        }
        return $dir;
    }

    /**
     * @param mixed $raw
     * @return list<array{path: string, files: array<string, array{size: int, sha256: string, mtime: int}>, base: array<string, mixed>}>|\WP_Error
     */
    private static function parseUnits($raw)
    {
        if (!is_array($raw) || $raw === [] || count($raw) > self::MAX_UNITS) {
            return self::error('wpsync_push_units', 'units fehlt oder enthält zu viele Einheiten.', 400);
        }
        $out  = [];
        $seen = [];
        foreach ($raw as $unit) {
            $path = is_array($unit) && is_string($unit['path'] ?? null) ? $unit['path'] : '';
            if (!PushUnits::valid($path) || isset($seen[strtolower($path)])) {
                return self::error('wpsync_push_unit', 'Einheit nicht erlaubt: ' . self::printable($path), 400);
            }
            $seen[strtolower($path)] = true;
            $files = [];
            foreach ((array) ($unit['files'] ?? []) as $rel => $file) {
                $rel = (string) $rel;
                $ok  = PushUnits::validFile($path, $rel) && is_array($file)
                    && is_int($file['size'] ?? null) && $file['size'] >= 0 && $file['size'] <= Excludes::MAX_FILE_BYTES
                    && is_string($file['sha256'] ?? null) && preg_match('/^[a-f0-9]{64}\z/', $file['sha256']) === 1
                    && is_int($file['mtime'] ?? null) && $file['mtime'] > 0;
                if (!$ok) {
                    return self::error('wpsync_push_file', 'Datei nicht erlaubt: ' . $path . '/' . self::printable($rel), 400);
                }
                $files[$rel] = ['size' => $file['size'], 'sha256' => $file['sha256'], 'mtime' => $file['mtime']];
            }
            if ($files === []) {
                return self::error('wpsync_push_unit', 'Einheit ohne Dateien: ' . $path, 400);
            }
            $out[] = ['path' => $path, 'files' => $files, 'base' => is_array($unit['base'] ?? null) ? $unit['base'] : []];
        }
        return $out;
    }

    private static function printable(string $value): string
    {
        return substr((string) preg_replace('/[^\x20-\x7e]/', '?', $value), 0, 200);
    }

    /**
     * Push im Status uploading, der diesem Pairing gehört, samt Plan.
     *
     * @param array<string, mixed> $params
     * @return array{0: string, 1: array<string, mixed>, 2: string}|\WP_Error
     */
    private static function open(array $params, string $keyId)
    {
        $push = self::own($params, $keyId);
        if ($push instanceof \WP_Error) {
            return $push;
        }
        if ($push['status'] !== self::UPLOADING) {
            return self::error('wpsync_push_state', 'Push ist im Status ' . $push['status'] . '.', 409);
        }
        $work = self::workDir();
        $plan = json_decode((string) @file_get_contents($work . '/' . $push['push_id'] . '/plan.json'), true);
        if (!is_array($plan) || !is_array($plan['units'] ?? null)) {
            return self::error('wpsync_push_state', 'Der Plan dieses Pushs fehlt.', 409);
        }
        return [$push['push_id'], $plan, $work];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|\WP_Error
     */
    private static function own(array $params, string $keyId)
    {
        $pushId = is_string($params['push_id'] ?? null) ? $params['push_id'] : '';
        $push   = preg_match(PushRescue::ID, $pushId) === 1 ? Store::getPush($pushId) : null;
        if ($push === null || $push['key_id'] !== $keyId) {
            return self::error('wpsync_push_unknown', 'Unbekannter Push.', 404);
        }
        return $push;
    }

    /** @return array{push_id: string, device: string, created: int}|null */
    private static function pending(): ?array
    {
        foreach (Store::pushes(50) as $push) {
            if (!$push['pruned'] && $push['status'] === PushRescue::COMMITTED) {
                return ['push_id' => $push['push_id'], 'device' => $push['device'], 'created' => $push['created']];
            }
        }
        return null;
    }

    /** Schliesst das Fenster mitten im Upload, bleibt nichts zurück (AC-52). */
    private static function abandon(array $params, string $keyId): void
    {
        $push = self::own($params, $keyId);
        if (!($push instanceof \WP_Error) && $push['status'] === self::UPLOADING) {
            self::discard($push['push_id'], self::EXPIRED);
        }
    }

    private static function discard(string $pushId, string $status): void
    {
        PushSwap::remove(self::content() . '/' . Store::pushDirName() . '/' . $pushId);
        $fields = ['status' => $status, 'pruned' => 1];
        $push   = Store::getPush($pushId);
        if ($push !== null && $push['finished'] === null) {
            $fields['finished'] = time();
        }
        Store::updatePush($pushId, $fields);
        self::release($pushId);
    }

    private static function finishRollback(string $pushId): void
    {
        self::discard($pushId, PushRescue::ROLLED_BACK);
    }

    private static function acquire(string $pushId, string $keyId, int $now): bool
    {
        global $wpdb;
        $name = Store::lockName('push');
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $name)) !== 1) {
            return false;
        }
        try {
            $lock = Store::getState('push_lock');
            if ($lock !== null && $now - (int) ($lock['touched'] ?? 0) < self::LOCK_TTL) {
                return false;
            }
            Store::setState('push_lock', ['push_id' => $pushId, 'key_id' => $keyId, 'touched' => $now]);
            return true;
        } finally {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
        }
    }

    private static function touchLock(string $pushId, int $now): void
    {
        $lock = Store::getState('push_lock');
        if ($lock !== null && ($lock['push_id'] ?? '') === $pushId) {
            $lock['touched'] = $now;
            Store::setState('push_lock', $lock);
        }
    }

    private static function release(string $pushId): void
    {
        $lock = Store::getState('push_lock');
        if ($lock !== null && ($lock['push_id'] ?? '') === $pushId) {
            Store::setState('push_lock', null);
        }
    }

    private static function windowClosed(): \WP_Error
    {
        return self::error('wpsync_push_window', 'Das Push-Fenster ist geschlossen – im WP-Admin unter Werkzeuge → wpsync öffnen.', 403);
    }

    private static function error(string $code, string $message, int $status): \WP_Error
    {
        return new \WP_Error($code, $message, ['status' => $status]);
    }
}
