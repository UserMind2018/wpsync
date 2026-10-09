<?php
namespace WpSync;

defined('ABSPATH') || exit;

/**
 * Ablauf eines Code-Pushs (Spec Stufe 2, 5.3): begin → upload → commit → confirm, dazu
 * rollback und list. Der Zustand liegt im Arbeitsordner wp-content/wpsync-push-<zufall>/<id>/
 * – im wp-content des Ziels, bei einem Push nach Staging also in der Kopie (Spec 2b 5.8):
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
    /** Ziele eines Pushs (Spec 2b 5.8). Das Ziel steht im Datensatz des Pushs und wechselt nie. */
    public const TARGETS    = ['live', 'staging'];

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

    /** Deaktivieren entfernt Arbeitsordner, Snapshots und Rescue-Stubs; bestätigte Stände bleiben live. */
    public static function uninstall(): void
    {
        foreach ((array) glob(self::content() . '/wpsync-push-*', GLOB_ONLYDIR) as $dir) {
            PushSwap::remove((string) $dir);
        }
        if (self::content() !== '') {
            PushRescueStub::remove(dirname(self::content()));
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
     * Nimmt ein Inhalts-Paket in Stücken entgegen (Spec Content-Push §7.5) – immer in den
     * Arbeitsordner von Live, auch für einen späteren Push nach Staging. Braucht kein offenes
     * Push-Fenster: der Probelauf soll das Paket prüfen können, und angewandt wird es erst im Commit.
     *
     * @param array<string, mixed> $params
     * @return \WP_REST_Response|\WP_Error
     */
    public static function stage(array $params, string $keyId)
    {
        $live = self::content();
        if ($live === '' || rtrim(wp_normalize_path((string) realpath(dirname(self::$pluginDir, 2))), '/') !== $live) {
            return self::error('wpsync_layout', 'Push braucht das Standardlayout wp-content/plugins/wpsync-agent.', 400);
        }
        return PushContent::stage($params, $keyId, self::workDir($live), time());
    }

    /**
     * Prüft Einheiten, Konflikte, Rechte und Platz. Mit dry nur Auskunft; sonst legt es den Push
     * an und nimmt die Sperre. Ziel live oder staging (Spec 2b 5.8): der Client nennt nur das Wort,
     * das Verzeichnis kommt aus dem Staging-Datensatz.
     *
     * @param array<string, mixed> $params
     * @return \WP_REST_Response|\WP_Error
     */
    public static function begin(array $params, string $keyId)
    {
        $live = self::content();
        if ($live === '' || rtrim(wp_normalize_path((string) realpath(dirname(self::$pluginDir, 2))), '/') !== $live) {
            return self::error('wpsync_layout', 'Push braucht das Standardlayout wp-content/plugins/wpsync-agent.', 400);
        }
        $target = $params['target'] ?? 'live';
        if (!is_string($target) || !in_array($target, self::TARGETS, true)) {
            return self::error('wpsync_push_target', 'Unbekanntes Ziel – erlaubt sind „live“ und „staging“.', 400);
        }
        $content = $target === 'staging' ? self::stagingContent() : $live;
        if ($content instanceof \WP_Error) {
            return $content;
        }
        // Inhalte (Spec Content-Push §7): kein Eintrag in units, sondern die sha256 eines Pakets, das
        // vorher über /content/stage abgelegt wurde. Ein Satz nur aus Inhalten hat keine Einheit.
        $contentSha = null;
        if (array_key_exists('content', $params)) {
            $contentSha = PushContent::sha256($params['content']);
            if ($contentSha === null) {
                return self::error('wpsync_push_content', 'content nennt keine gültige sha256 eines Pakets.', 400);
            }
        }
        $units = self::parseUnits($params['units'] ?? null, $contentSha !== null);
        if ($units instanceof \WP_Error) {
            return $units;
        }
        $now = time();
        self::sync();
        self::prune($now);

        $plans       = [];
        $conflicted  = [];
        $readonly    = [];
        $upConflicts = [];
        $bytes       = 0;
        foreach ($units as $unit) {
            if ($unit['path'] === PushUploads::UNIT) {
                $upPlan = self::uploadsPlan($target, $content, $unit);
                if ($upPlan instanceof \WP_Error) {
                    return $upPlan;
                }
                $plans[]     = $upPlan;
                $upConflicts = $upPlan['conflicts'];
                if (!$upPlan['writable']) {
                    $readonly[] = $unit['path'];
                }
                foreach ($upPlan['need'] as $rel) {
                    $bytes += $unit['files'][$rel]['size'];
                }
                continue;
            }
            $dir = $content . '/' . $unit['path'];
            if (!self::confined($target, $content, $dir)) {
                return self::error('wpsync_push_unit', 'Einheit liegt nicht im wp-content des Ziels: ' . $unit['path'], 400);
            }
            $exists    = is_dir($dir);
            $base      = $target === 'staging' ? self::copyBase($live . '/' . $unit['path'], $dir, $unit['path'], $unit['base']) : $unit['base'];
            $conflicts = PushManifest::conflicts($exists ? PushManifest::stamps($dir, $unit['path']) : [], $base);
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
        // Das Paket wird im Probelauf wie im echten Begin vollständig geprüft (§7.2) – ohne zu schreiben.
        $staged      = null;
        $contentPlan = null;
        if ($contentSha !== null) {
            $uploadFiles = [];
            foreach ($units as $unit) {
                if ($unit['path'] === PushUploads::UNIT) {
                    $uploadFiles = $unit['files'];
                }
            }
            $staged      = PushContent::staged($live . '/' . Store::pushDirName(), $keyId, $contentSha, $now);
            $contentPlan = PushContent::plan($staged, $target, $content, $uploadFiles, empty($params['dry']), Store::pushOpener($keyId));
        }
        $pending = self::pending();
        $open    = PushWindow::open(Store::pushUntil($keyId), $now);
        $answer  = [
            'push_id'       => '',
            'target'        => $target,
            'agent_version' => WPSYNC_VERSION,
            'health_urls'   => $target === 'staging' ? Staging::healthUrls() : self::healthUrls(),
            'window_open'   => $open,
            'pending'       => $pending,
            'units'         => $plans,
            // Die URL schon im Probelauf: die CLI prüft den Rückweg, bevor sie einen Push anlegt (AC-66).
            // Mit rescue_stub ein Stub im Webroot, falls PHP unter plugins/ gesperrt ist (Spec 12, R3) –
            // nur bei offenem Fenster: sonst bricht die CLI vor dem Ping ab, der Stub läge umsonst da.
            'rescue'        => [
                'url'       => self::rescueUrl(!empty($params['rescue_stub']) && $open, $now),
                'salt'      => '',
                'hardening' => PushRescueStub::hardening(self::activePlugins()),
            ],
        ];
        if ($contentPlan !== null) {
            $answer['content'] = $contentPlan;
        }
        if (!empty($params['dry'])) {
            return new \WP_REST_Response($answer);
        }

        if (!$answer['window_open']) {
            return self::windowClosed();
        }
        if ($pending !== null) {
            return self::error('wpsync_push_pending', 'Push ' . $pending['push_id'] . ' ist getauscht, aber nicht bestätigt.', 409);
        }
        // Was der Probelauf als content.error nennt, lehnt der echte Begin ab – mit denselben Einzelheiten.
        if ($contentPlan !== null && !$contentPlan['ok']) {
            return ContentException::fromArray((array) $contentPlan['error'])->toError();
        }
        // Ein Push ersetzt nie eine Datei unter uploads – auch nicht mit force (Spec Content-Push §8.2, W3).
        if ($upConflicts !== []) {
            $shown = array_map(static function (string $rel): string {
                return self::printable($rel);
            }, array_slice($upConflicts, 0, 10));
            return self::error('wpsync_upload_exists', 'Auf dem Ziel liegt am selben Pfad eine andere Datei: ' . implode(', ', $shown), 409);
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
        $work = self::workDir($content);
        foreach ($units as $unit) {
            // uploads: die Dateien kommen in den Ordner selbst – fehlt er, nach wp-content.
            $parent = $unit['path'] === PushUploads::UNIT && is_dir($content . '/' . PushUploads::UNIT)
                ? $content . '/' . PushUploads::UNIT
                : dirname($content . '/' . $unit['path']);
            if (!PushSwap::probe($work, $parent)) {
                return self::error('wpsync_push_perms', 'Verzeichnisse lassen sich nicht umbenennen (Rechte oder anderes Dateisystem): ' . dirname($unit['path']), 409);
            }
        }

        $pushId = PushRescue::newId($now);
        if (!self::acquire($pushId, $keyId, $target, $now)) {
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
                'carried'     => $plans[$i]['exists'] && $unit['path'] !== PushUploads::UNIT ? PushManifest::carried($dir, $unit['path']) : [],
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
        // Das geprüfte Paket kommt in den Arbeitsordner des Pushs (bei Staging: in die Kopie) – der
        // Commit wendet genau diese Datei an, auch wenn die Ablage inzwischen verfallen ist.
        $taken = true;
        if ($contentSha !== null) {
            $rows            = (int) array_sum((array) $contentPlan['rows']);
            $plan['content'] = ['sha256' => $contentSha, 'rows' => $rows];
            $summary[]       = ['path' => PushContent::UNIT, 'exists' => true, 'old_version' => '', 'new_version' => '', 'files' => $rows, 'uploaded' => $rows];
            $taken           = $staged !== null && PushContent::take($staged, $work . '/' . $pushId, $contentSha);
        }
        $stored = $taken && false !== file_put_contents($work . '/' . $pushId . '/plan.json', (string) wp_json_encode($plan))
            && Store::addPush([
                'push_id' => $pushId,
                'key_id'  => $keyId,
                'device'  => Store::deviceFor($keyId),
                'target'  => $target,
                'status'  => self::UPLOADING,
                'forced'  => empty($params['force']) ? 0 : 1,
                'units'   => (string) wp_json_encode($summary),
                'created' => $now,
                'opened_by' => Store::pushOpener($keyId),
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
            // Erst jetzt liegt der Inhalt vor: er muss zur Endung passen (Spec Content-Push §8.3).
            if ($unit['path'] === PushUploads::UNIT && !PushUploads::allowedContent($to, $rel)) {
                @unlink($to);
                return self::error('wpsync_upload_type_blocked', 'Inhalt passt nicht zum Dateityp: ' . self::printable($rel), 400);
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
        list($pushId, $plan, $work, $content, $target) = $open;
        $base     = $work . '/' . $pushId;
        $cursor   = is_array($params['cursor'] ?? null) ? $params['cursor'] : [];
        $u        = max(0, (int) ($cursor['u'] ?? 0));
        $i        = max(0, (int) ($cursor['i'] ?? 0));
        $deadline = microtime(true) + Budget::seconds((int) ini_get('max_execution_time'));
        self::touchLock($pushId, $now);

        try {
            for (; $u < count($plan['units']); $u++, $i = 0) {
                $unit = $plan['units'][$u];
                if ($unit['path'] === PushUploads::UNIT) {
                    // Kein neues Verzeichnis: jede Datei kommt einzeln an ihren Platz, nach rescue.json.
                    foreach ($unit['need'] as $rel) {
                        if (!is_file($base . '/stage/' . $u . '/' . $rel)) {
                            throw new \RuntimeException('not uploaded: uploads/' . self::printable((string) $rel));
                        }
                    }
                    continue;
                }
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
                if ($unit['path'] === PushUploads::UNIT) {
                    if (!self::confined($target, $content, $content . '/' . PushUploads::UNIT) || PushUploads::layout($target, $content) !== true) {
                        throw new \RuntimeException('uploads outside its target');
                    }
                    foreach ($unit['need'] as $rel) {
                        $staged = $base . '/stage/' . $n . '/' . $rel;
                        if (!is_file($staged) || (int) filesize($staged) !== (int) $unit['files'][$rel]['size']) {
                            throw new \RuntimeException('not uploaded: uploads/' . self::printable((string) $rel));
                        }
                    }
                    continue;
                }
                // Und jede Einheit noch im wp-content ihres Ziels: nie Live bei einem Staging-Push, nie umgekehrt.
                if (!PushUnits::valid((string) $unit['path']) || !self::confined($target, $content, $content . '/' . $unit['path'])) {
                    throw new \RuntimeException('unit outside its target: ' . self::printable((string) $unit['path']));
                }
                foreach ($unit['files'] as $rel => $want) {
                    $built = $base . '/new/' . $n . '/' . $rel;
                    if (!is_file($built) || (int) filesize($built) !== (int) $want['size']) {
                        throw new \RuntimeException('not built: ' . $unit['path'] . '/' . $rel);
                    }
                }
            }
            if ($target === 'staging') {
                foreach (array_keys($plan['units']) as $n) {
                    self::keepTheGate($base . '/new/' . $n);
                }
            }
        } catch (\RuntimeException $e) {
            self::discard($pushId, self::FAILED);
            return self::error('wpsync_push_build', 'Push abgebrochen, nichts getauscht: ' . $e->getMessage(), 409);
        }

        $pairs   = [];
        $sources = []; // new/<n> je Paar – die Einheit uploads hat keins, die Indizes laufen auseinander
        $upIndex = null;
        foreach ($plan['units'] as $n => $unit) {
            if ($unit['path'] === PushUploads::UNIT) {
                $upIndex = $n;
                continue;
            }
            $pairs[]   = [
                'unit'     => $unit['path'],
                'target'   => $content . '/' . $unit['path'],
                'snapshot' => $unit['exists'] ? $base . '/old/' . $n : null,
                'discard'  => $base . '/discard/' . $n,
            ];
            $sources[] = $base . '/new/' . $n;
        }
        // Uploads vor dem Code (Spec Content-Push §1, AC-144). Liegt eine Zieldatei inzwischen da,
        // bricht der Satz ab, bevor irgendetwas angelegt oder getauscht ist.
        $uploads = ['added' => [], 'dirs' => []];
        if ($upIndex !== null) {
            try {
                $uploads = PushUploads::prepare($content, $plan['units'][$upIndex]['files'], $plan['units'][$upIndex]['need']);
            } catch (\RuntimeException $e) {
                self::discard($pushId, self::FAILED);
                return self::uploadFailure($e);
            }
        }
        wp_mkdir_p($base . '/old');
        // Vor dem ersten rename: stirbt PHP mitten im Anlegen oder Tausch, kann rescue.php zurücknehmen.
        PushRescue::write($work, $pushId, (string) $plan['key_hash'], $pairs, PushRescue::COMMITTED, $uploads);
        if ($upIndex !== null) {
            $placed = [];
            try {
                PushUploads::place($content, $base . '/stage/' . $upIndex, $uploads, $placed);
            } catch (\RuntimeException $e) {
                PushRescue::removeUploads($content, ['added' => $placed, 'dirs' => $uploads['dirs']]);
                self::discard($pushId, self::FAILED);
                return self::uploadFailure($e);
            }
        }
        $swapped = [];
        try {
            foreach ($pairs as $k => $pair) {
                PushSwap::swap($pair['target'], $sources[$k], $pair['snapshot']);
                $swapped[] = $pair;
            }
        } catch (\RuntimeException $e) {
            foreach (array_reverse($swapped) as $pair) {
                wp_mkdir_p(dirname($pair['discard']));
                PushSwap::restore($pair['target'], $pair['snapshot'], $pair['discard']);
            }
            PushRescue::removeUploads($content, $uploads); // der Satz bleibt ganz: ohne Code keine Uploads
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
            if ($unit['path'] === PushUploads::UNIT) {
                $stamps[PushUploads::UNIT] = (object) PushUploads::stamps($content, $uploads);
                $summary[]                 = ['path' => PushUploads::UNIT, 'exists' => true, 'old_version' => '', 'new_version' => '', 'files' => count($unit['files']), 'uploaded' => count($unit['need'])];
                continue;
            }
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
        if ($target === 'staging') {
            Staging::markUsed(); // ein Push zählt als Nutzung der Kopie (Spec 2b 5.9)
        }
        self::touchLock($pushId, time());
        self::touchStub(time());
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
            $dirs = self::dirs($push['target']);
            if ($dirs instanceof \WP_Error) {
                return $dirs;
            }
            PushRescue::setStatus($dirs[1], $push['push_id'], PushRescue::CONFIRMED);
            Store::updatePush($push['push_id'], ['status' => PushRescue::CONFIRMED, 'finished' => time()]);
            self::release($push['push_id']);
            // Die 10 Minuten des Stubs ab jetzt: eine verlorene confirm-Antwort braucht rescue.php (R5).
            self::touchStub(time());
            self::prune(time());
            self::scheduleTidy();
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
            $dirs = self::dirs($push['target']);
            if ($dirs instanceof \WP_Error) {
                return $dirs;
            }
            list($status, $body) = PushRescue::rollback($dirs[0], $dirs[1], $pushId);
            if ($status !== 200) {
                $why = ($body['error'] ?? '') === 'superseded'
                    ? 'Zuerst den späteren Push ' . ($body['by'] ?? '') . ' zurückrollen.'
                    : 'Rollback fehlgeschlagen: ' . ($body['error'] ?? 'unbekannt');
                return self::error('wpsync_push_rollback', $why, $status === 500 ? 500 : 409);
            }
            self::finishRollback($pushId);
            self::touchStub(time()); // wie bei confirm (R5)
            self::scheduleTidy();
            // Seit dem Push geänderte Uploads bleiben liegen und werden genannt (Spec Content-Push §8.4).
            if (isset($body['warnings'])) {
                return new \WP_REST_Response(['ok' => true, 'status' => PushRescue::ROLLED_BACK, 'warnings' => $body['warnings'], 'kept' => $body['kept'] ?? []]);
            }
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

    /** Übernimmt Rollbacks, die rescue.php an WordPress vorbei ausgeführt hat – auf beiden Zielen. */
    public static function sync(): void
    {
        $name = Store::pushDirName();
        foreach (Store::pushes(50) as $push) {
            if ($push['pruned'] || !in_array($push['status'], [PushRescue::COMMITTED, PushRescue::CONFIRMED], true)) {
                continue;
            }
            $content = self::content($push['target']);
            if ($content === '') {
                // Die Kopie ist weg (z. B. per FTP gelöscht), ihre Snapshots auch. Ohne das hier
                // bliebe ein unbestätigter Staging-Push ewig „pending“ und sperrte jeden Push nach Live.
                if ($push['target'] === 'staging') {
                    self::discard($push['push_id'], $push['status']);
                }
                continue;
            }
            $record = PushRescue::read($content . '/' . $name, $push['push_id']);
            if ($record !== null && $record['status'] === PushRescue::ROLLED_BACK) {
                self::finishRollback($push['push_id']);
            }
        }
    }

    /**
     * Räumt verfallene Uploads und alte Snapshots weg, getrennt pro Ziel (Spec 2b 5.8): Pushes nach
     * Staging verdrängen keinen Snapshot von Live. Unbestätigte Pushes bleiben immer (AC-69).
     */
    public static function prune(int $now): void
    {
        $kept = [];
        foreach (Store::pushes(200) as $push) {
            if ($push['pruned']) {
                continue;
            }
            if ($push['status'] === self::UPLOADING && $now - $push['created'] > self::UPLOAD_TTL) {
                self::discard($push['push_id'], self::EXPIRED);
            } elseif ($push['status'] === PushRescue::CONFIRMED) {
                $kept[$push['target']] = ($kept[$push['target']] ?? 0) + 1;
                if ($kept[$push['target']] > self::KEEP || $now - $push['created'] > self::MAX_AGE) {
                    self::discard($push['push_id'], PushRescue::CONFIRMED);
                }
            }
        }
        self::pruneOrphans($now);
        self::tidyStub($now);
    }

    /**
     * Arbeitsordner ohne aktive Zeile in wpsync_pushes (abgebrochener Push, gelöschte Zeile) nach
     * UPLOAD_TTL – nur was nachweislich fertig ist: nie getauscht (keine rescue.json), zurückgerollt
     * oder bestätigt. committed ist der einzige Snapshot eines getauschten Pushs, eine unlesbare
     * rescue.json ebenso möglich; beides bleibt (Spec Stufe 2, 12, R10). Bei einem DB-Fehler ist
     * eine fehlende Zeile kein Beweis – dann wird gar nichts gelöscht. Live und Kopie getrennt.
     */
    private static function pruneOrphans(int $now): void
    {
        foreach (self::TARGETS as $target) {
            $content = self::content($target);
            if ($content === '') {
                continue;
            }
            $work = $content . '/' . Store::pushDirName();
            if (is_link($work)) {
                continue;
            }
            foreach ((array) @scandir($work) as $name) {
                if (!is_string($name) || preg_match(PushRescue::ID, $name) !== 1) {
                    continue;
                }
                $dir = $work . '/' . $name;
                if (is_link($dir) || !is_dir($dir) || $now - (int) @filemtime($dir) < self::UPLOAD_TTL) {
                    continue;
                }
                $row = Store::getPush($name);
                if (!Store::dbOk()) {
                    return;
                }
                if ($row !== null && !$row['pruned']) {
                    continue;
                }
                if (file_exists(PushRescue::file($work, $name))) {
                    $record = PushRescue::read($work, $name);
                    if ($record === null || !in_array($record['status'], [PushRescue::ROLLED_BACK, PushRescue::CONFIRMED], true)) {
                        continue;
                    }
                }
                PushSwap::remove($dir);
            }
        }
    }

    /**
     * wp-content des Ziels, aufgelöst; '' wenn es das Ziel nicht (mehr) gibt. Für Staging nur ein
     * Ordner, den auch rescue.php fände: <webroot>/wpsync-staging-<zufall>/wp-content, kein Symlink,
     * nie Live selbst.
     */
    private static function content(string $target = 'live'): string
    {
        $live = rtrim(wp_normalize_path((string) realpath(WP_CONTENT_DIR)), '/');
        if ($target === 'live') {
            return $live;
        }
        if ($target !== 'staging' || $live === '') {
            return '';
        }
        $dir = rtrim(wp_normalize_path(Staging::contentDir()), '/');
        return $dir !== '' && $dir !== $live && in_array($dir, PushRescue::contentDirs($live), true) && Staging::inside($dir) ? $dir : '';
    }

    /**
     * wp-content der Kopie für begin, upload und commit: nur solange sie bereit ist und kein
     * Staging-Job läuft (V9) – geprüft bei jedem Aufruf, nicht nur beim Anlegen des Pushs.
     *
     * @return string|\WP_Error
     */
    private static function stagingContent()
    {
        $dir = Staging::pushContent();
        if ($dir instanceof \WP_Error) {
            return $dir;
        }
        if (!is_string($dir) || $dir === '' || rtrim(wp_normalize_path($dir), '/') !== self::content('staging')) {
            return self::error('wpsync_staging_missing', 'Der Ordner der Staging-Kopie ist nicht benutzbar.', 409);
        }
        return self::content('staging');
    }

    /** Liegt das Verzeichnis einer Einheit wirklich im wp-content ihres Ziels (Spec 2b 5.8)? */
    private static function confined(string $target, string $content, string $dir): bool
    {
        return PushRescue::confined($content, $dir) && ($target !== 'staging' || Staging::inside($dir));
    }

    private static function workDir(string $content): string
    {
        $dir = $content . '/' . Store::pushDirName();
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
            file_put_contents($dir . '/.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
            file_put_contents($dir . '/index.php', "<?php\n// Silence is golden.\n");
        }
        return $dir;
    }

    /**
     * wp-content und Arbeitsordner eines bestehenden Pushs – aus dem Ziel in seinem Datensatz.
     *
     * @param bool $ready für upload und commit: die Kopie muss bereit sein
     * @return array{0: string, 1: string}|\WP_Error
     */
    private static function dirs(string $target, bool $ready = false)
    {
        $content = $ready && $target === 'staging' ? self::stagingContent() : self::content($target);
        if ($content instanceof \WP_Error) {
            return $content;
        }
        if ($content === '') {
            return $target === 'staging'
                ? self::error('wpsync_staging_missing', 'Die Staging-Kopie dieses Pushs gibt es nicht mehr.', 409)
                : self::error('wpsync_push_state', 'Das Ziel dieses Pushs ist nicht benutzbar.', 409);
        }
        return [$content, self::workDir($content)];
    }

    /**
     * Die Baseline des Clients beschreibt Live; die Kopie lässt davon Dateien weg (StagingFiles:
     * .htaccess mit Rewrite-Direktiven, .user.ini, Varianten der wp-config.php). Was deshalb auf
     * Staging fehlt, ist kein Konflikt – entschieden an der Datei von Live, nicht am Namen allein.
     *
     * @param array<string, mixed> $base
     * @return array<string, mixed>
     */
    private static function copyBase(string $liveDir, string $dir, string $unit, array $base): array
    {
        foreach (array_keys($base) as $rel) {
            $rel  = (string) $rel;
            $from = $liveDir . '/' . $rel;
            // Der Pfad kommt vom Client: nur einer, den auch ein Manifest nennen dürfte.
            if (!PushUnits::validFile($unit, $rel) || file_exists($dir . '/' . $rel) || is_link($dir . '/' . $rel)) {
                continue;
            }
            if (is_file($from) && !is_link($from) && StagingFiles::leftOut('wp-content/' . $unit . '/' . $rel, $from)) {
                unset($base[$rel]);
            }
        }
        return $base;
    }

    /**
     * Vor dem Tausch in die Kopie: eine gepushte .htaccess, die ihrem Ordner die Cookie-Sperre nähme
     * (StagingFiles::liftsTheGate), bleibt draussen – wie beim Kopieren. Symlinks werden nicht betreten.
     *
     * @throws \RuntimeException wenn sich eine solche Datei nicht entfernen lässt
     */
    private static function keepTheGate(string $dir): void
    {
        foreach (@scandir($dir) ?: [] as $name) {
            $full = $dir . '/' . $name;
            if ($name === '.' || $name === '..' || is_link($full)) {
                continue;
            }
            if (is_dir($full)) {
                self::keepTheGate($full);
            } elseif (StagingFiles::liftsTheGate($full) && !@unlink($full)) {
                throw new \RuntimeException('cannot leave out ' . self::printable($name));
            }
        }
    }

    /**
     * Plan der Einheit uploads (Spec Content-Push §8.2, §8.3): Ziel, Layout und Dateityp, dann je
     * Datei need, same oder conflicts. Kein Verzeichnistausch, deshalb weder Stempel noch Version.
     *
     * @param array{path: string, files: array<string, array{size: int, sha256: string, mtime: int}>, base: array<string, mixed>} $unit
     * @return array<string, mixed>|\WP_Error
     */
    private static function uploadsPlan(string $target, string $content, array $unit)
    {
        $dir = $content . '/' . PushUploads::UNIT;
        if (!self::confined($target, $content, $dir)) {
            return self::error('wpsync_push_unit', 'Einheit liegt nicht im wp-content des Ziels: uploads', 400);
        }
        $layout = PushUploads::layout($target, $content);
        if ($layout instanceof \WP_Error) {
            return $layout;
        }
        foreach (array_keys($unit['files']) as $rel) {
            $rel = (string) $rel;
            if (PushUploads::blockedName($rel) || !PushUploads::allowedName($rel)) {
                return self::error('wpsync_upload_type_blocked', 'Dateityp für Uploads nicht erlaubt: ' . self::printable($rel), 400);
            }
        }
        $state = PushUploads::plan($content, $unit['files']);
        return [
            'path'      => PushUploads::UNIT,
            'exists'    => is_dir($dir),
            'version'   => '',
            'conflicts' => $state['conflicts'],
            'need'      => $state['need'],
            'same'      => $state['same'],
            'writable'  => is_dir($dir) ? is_writable($dir) : is_writable($content),
        ];
    }

    /**
     * @param mixed $raw
     * @param bool  $allowEmpty der Satz hat Inhalte: er darf ohne Code und ohne Uploads kommen
     * @return list<array{path: string, files: array<string, array{size: int, sha256: string, mtime: int}>, base: array<string, mixed>}>|\WP_Error
     */
    private static function parseUnits($raw, bool $allowEmpty = false)
    {
        if ($allowEmpty && ($raw === null || $raw === [])) {
            return [];
        }
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
            $uploads = $path === PushUploads::UNIT;
            if ($uploads && count((array) ($unit['files'] ?? [])) > PushUploads::MAX_FILES) {
                return self::error('wpsync_push_units', 'Zu viele Uploads in einem Push – höchstens ' . PushUploads::MAX_FILES . '.', 400);
            }
            $files = [];
            foreach ((array) ($unit['files'] ?? []) as $rel => $file) {
                $rel   = (string) $rel;
                $valid = $uploads ? PushUploads::validFile($rel) : PushUnits::validFile($path, $rel);
                if ($uploads && !$valid) {
                    return self::error('wpsync_upload_path', 'Upload-Pfad nicht erlaubt: ' . self::printable($rel), 400);
                }
                $ok = $valid && is_array($file)
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
     * Push im Status uploading, der diesem Pairing gehört, samt Plan und Verzeichnissen seines
     * Ziels. Das Ziel kommt aus dem Datensatz – ein „target“ im Request zählt hier nicht.
     *
     * @param array<string, mixed> $params
     * @return array{0: string, 1: array<string, mixed>, 2: string, 3: string, 4: string}|\WP_Error [ID, Plan, Arbeitsordner, wp-content, Ziel]
     */
    private static function open(array $params, string $keyId)
    {
        $push = self::own($params, $keyId);
        if ($push instanceof \WP_Error) {
            return $push;
        }
        if ($push['status'] !== self::UPLOADING || $push['pruned']) {
            return self::error('wpsync_push_state', 'Push ist im Status ' . $push['status'] . '.', 409);
        }
        $dirs = self::dirs($push['target'], true);
        if ($dirs instanceof \WP_Error) {
            return $dirs;
        }
        list($content, $work) = $dirs;
        $plan = json_decode((string) @file_get_contents($work . '/' . $push['push_id'] . '/plan.json'), true);
        if (!is_array($plan) || !is_array($plan['units'] ?? null)) {
            return self::error('wpsync_push_state', 'Der Plan dieses Pushs fehlt.', 409);
        }
        return [$push['push_id'], $plan, $work, $content, $push['target']];
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

    /**
     * Ein getauschter, unbestätigter Push – auf irgendeinem oder dem genannten Ziel (V9).
     *
     * @return array{push_id: string, device: string, created: int}|null
     */
    public static function pending(?string $target = null): ?array
    {
        foreach (Store::pushes(50) as $push) {
            if (!$push['pruned'] && $push['status'] === PushRescue::COMMITTED && ($target === null || $push['target'] === $target)) {
                return ['push_id' => $push['push_id'], 'device' => $push['device'], 'created' => $push['created']];
            }
        }
        return null;
    }

    /** Läuft gerade ein Push (Upload oder Tausch) mit diesem Ziel? Eine Sperre pro Site (V9). */
    public static function running(string $target, int $now): bool
    {
        $lock = Store::getState('push_lock');
        return $lock !== null && ($lock['target'] ?? 'live') === $target && $now - (int) ($lock['touched'] ?? 0) < self::LOCK_TTL;
    }

    /**
     * refresh --code und delete verwerfen die Pushes eines Ziels samt Snapshots (Spec 2b 5.8).
     * Ein offener Upload verfällt dabei – er dürfte sonst in die neue Kopie tauschen.
     */
    public static function dropTarget(string $target): void
    {
        if (!in_array($target, self::TARGETS, true)) {
            return;
        }
        foreach (Store::pushes(200) as $push) {
            if ($push['target'] === $target && !$push['pruned']) {
                self::discard($push['push_id'], $push['status'] === self::UPLOADING ? self::EXPIRED : $push['status']);
            }
        }
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
        $push    = Store::getPush($pushId);
        $content = self::content($push === null ? 'live' : $push['target']);
        if ($content !== '') {
            PushSwap::remove($content . '/' . Store::pushDirName() . '/' . $pushId);
        }
        $fields = ['status' => $status, 'pruned' => 1];
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

    private static function acquire(string $pushId, string $keyId, string $target, int $now): bool
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
            Store::setState('push_lock', ['push_id' => $pushId, 'key_id' => $keyId, 'target' => $target, 'touched' => $now]);
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

    /**
     * rescue.php über einen Stub im Webroot (Spec Stufe 2, 12) – nur auf Wunsch der CLI und nur,
     * wenn wp-content direkt im Webroot liegt; sonst die Plugin-URL wie bisher (R3, R6).
     */
    private static function rescueUrl(bool $stub, int $now): string
    {
        $plugin = plugins_url('rescue.php', self::$pluginDir . '/wpsync-agent.php');
        $base   = self::webrootUrl();
        if (!$stub || $base === '') {
            return $plugin;
        }
        $webroot = dirname(self::content());
        $state   = Store::getState('rescue_stub');
        $name    = is_string($state['name'] ?? null) && PushRescueStub::exists($webroot, $state['name'])
            ? $state['name']
            : PushRescueStub::create($webroot, self::$pluginDir);
        if ($name === null) {
            return $plugin;
        }
        Store::setState('rescue_stub', ['name' => $name, 'touched' => $now]);
        return $base . $name;
    }

    /** URL des Webroots mit Schrägstrich am Ende, wenn wp-content direkt darin liegt; sonst ''. */
    private static function webrootUrl(): string
    {
        $url = rtrim((string) content_url(), '/');
        $dir = self::content();
        return $dir !== '' && basename($dir) === 'wp-content' && substr($url, -11) === '/wp-content' ? substr($url, 0, -10) : '';
    }

    private static function touchStub(int $now): void
    {
        $state = Store::getState('rescue_stub');
        if ($state !== null) {
            $state['touched'] = $now;
            Store::setState('rescue_stub', $state);
        }
    }

    /**
     * Löscht den Stub, sobald ihn kein Push mehr braucht (R5): kein unbestätigter Push, keine
     * laufende Sperre und 10 Minuten seit dem letzten Begin oder Commit. Reste daneben immer.
     */
    private static function tidyStub(int $now): void
    {
        $content = self::content();
        if ($content === '') {
            return;
        }
        // Jeder Lesezugriff einzeln geprüft: bei einem DB-Fehler sähe alles nach „frei“ aus (M1).
        $state = Store::getState('rescue_stub');
        if (!Store::dbOk()) {
            return;
        }
        $lock = Store::getState('push_lock');
        if (!Store::dbOk()) {
            return;
        }
        $pending = self::pending();
        if (!Store::dbOk()) {
            return;
        }
        $name = is_string($state['name'] ?? null) ? $state['name'] : null;
        $busy = $pending !== null
            || ($lock !== null && $now - (int) ($lock['touched'] ?? 0) < self::LOCK_TTL)
            || ($name !== null && $now - (int) ($state['touched'] ?? 0) < self::LOCK_TTL);
        if ($busy) {
            if ($name !== null) {
                PushRescueStub::remove(dirname($content), $name);
            }
            return;
        }
        PushRescueStub::remove(dirname($content));
        if ($state !== null) {
            Store::setState('rescue_stub', null);
        }
    }

    /** Ein Aufräumlauf nach Ablauf der 10 Minuten, statt bis zum täglichen Cron zu warten (R5). */
    private static function scheduleTidy(): void
    {
        wp_schedule_single_event(time() + self::LOCK_TTL + 60, self::CRON);
    }

    /** @return list<mixed> Einträge aus active_plugins, bei Multisite samt netzwerkweit aktiven */
    private static function activePlugins(): array
    {
        $active = array_values((array) get_option('active_plugins', []));
        if (is_multisite()) {
            $active = array_merge($active, array_keys((array) get_site_option('active_sitewide_plugins', [])));
        }
        return $active;
    }

    /** Uploads liessen sich nicht anlegen: nichts getauscht, der Push ist verworfen. */
    private static function uploadFailure(\RuntimeException $e): \WP_Error
    {
        return $e->getCode() === PushUploads::EXISTS
            ? self::error('wpsync_upload_exists', 'Auf dem Ziel liegt inzwischen etwas am selben Pfad, nichts getauscht: ' . self::printable($e->getMessage()), 409)
            : self::error('wpsync_upload_place', 'Uploads liessen sich nicht anlegen, nichts getauscht: ' . self::printable($e->getMessage()), 500);
    }

    private static function windowClosed(): \WP_Error
    {
        return PushWindow::closed();
    }

    private static function error(string $code, string $message, int $status): \WP_Error
    {
        return new \WP_Error($code, $message, ['status' => $status]);
    }
}
