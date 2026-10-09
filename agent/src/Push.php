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
    /** So lange wartet der Commit vor COMMIT höchstens auf die Sperre des Pushs (Spec Content-Push P3 §7.1). */
    public const GATE_SECONDS = 15.0;
    /**
     * Schritt der Nacharbeiten nach einer Rücknahme durch rescue.php: was an den eingefügten Objekten
     * stehen blieb (R15), ist weggeräumt (ok) oder liess sich nicht wegräumen. Fehlt der Schritt, hing nichts.
     */
    public const LEFT_CLEANUP = 'left_cleanup';
    /** Der Umschlag für rescue.php (rescue.sealed) verfällt nach 24 Stunden (P3 R14). */
    public const RESCUE_DB_TTL = 86400;

    /** @var float|null für Tests: Wartezeit an der Naht anstelle von GATE_SECONDS */
    public static $gateWait = null;

    /** @var string */
    private static $pluginDir = '';

    public static function register(string $pluginDir): void
    {
        self::$pluginDir = $pluginDir;
        // Der Ordner, in dem der Agent wirklich liegt: als Einheit und als Schalter tabu, wie immer er heisst (S3).
        $folder = basename(rtrim(str_replace('\\', '/', $pluginDir), '/'));
        if ($folder !== '' && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*\z/', $folder) === 1) {
            PushUnits::$agent      = $folder;
            ContentPlugins::$agent = $folder;
        }
        add_action(self::CRON, [self::class, 'maintain']);
        // Früh: hat rescue.php einen Push zurückgenommen, holt der Agent nach, was WordPress braucht (P3 §8.1).
        add_action('init', [self::class, 'catchUp'], 1);
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
        foreach (self::content() === '' ? [] : PushSwap::entries(self::content(), PushRescue::WORK_DIR) as $dir) {
            if (is_dir($dir)) {
                PushSwap::remove($dir); // einen Symlink an der Stelle entfernt es, ohne ihm zu folgen
            }
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
        self::expireEnvelopes(time());
    }

    /**
     * Auf init, ohne Datenbankabfrage (Spec Content-Push P3 §8.1): liegt im Arbeitsordner von Live der
     * Marker rescue.pending, hat rescue.php einen Push an WordPress vorbei zurückgenommen – dann, und
     * nur dann, läuft sync(). Der Marker verschwindet vorher: ein Fehler in einer Nacharbeit
     * wiederholt sich so nicht in jedem Request. Für Pushes nach Staging liegt der Marker in der
     * Kopie; sie holt sync() beim nächsten REST-Aufruf oder im täglichen Cron nach.
     */
    public static function catchUp(): void
    {
        $live = self::content();
        if ($live === '') {
            return;
        }
        $found = false;
        foreach (PushRescue::workDirs($live) as $work) {
            $marker = PushRescue::pendingFile($work);
            if (is_file($marker) && !is_link($marker) && @unlink($marker)) {
                $found = true;
            }
        }
        if (!$found) {
            return;
        }
        try {
            self::sync();
        } catch (\Throwable $e) {
            // der tägliche Cron und der nächste REST-Aufruf holen es nach
        }
    }

    /** Löscht Umschläge, die älter als RESCUE_DB_TTL sind (R14) – auf beiden Zielen. Läuft der Cron, läuft WordPress, und der Agent kann zurücknehmen. */
    private static function expireEnvelopes(int $now): void
    {
        foreach (self::TARGETS as $target) {
            $content = self::content($target);
            $work    = $content . '/' . Store::pushDirName();
            if ($content === '' || is_link($work)) {
                continue;
            }
            foreach ((array) @scandir($work) as $name) {
                if (!is_string($name) || preg_match(PushRescue::ID, $name) !== 1 || is_link($work . '/' . $name)) {
                    continue;
                }
                $file = RescueSeal::file($work, $name);
                if (is_file($file) && !is_link($file) && $now - (int) @filemtime($file) > self::RESCUE_DB_TTL) {
                    RescueSeal::forget($work, $name);
                }
            }
        }
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
     * das Verzeichnis kommt aus dem Staging-Datensatz. Mit activate/deactivate trägt der Satz einen
     * Plugin-Zustand (Spec Content-Push P4 §4.2): die Antwort nennt ihn unter plugins, und ohne Umschlag
     * für rescue.php lehnt der echte Begin ab (A9).
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
        // Der Plugin-Zustand (Spec Content-Push P4 §4.2): ebenfalls keine Einheit, sondern ein Auftrag an den
        // Agent. Aus dem Request kommen nur Namen von Einheiten – nie ein Wert der Option, nie eine Datei, die zählt.
        try {
            $wish = PushPlugins::request($params);
        } catch (ContentException $e) {
            return $e->toError();
        }
        // Ein Satz mit DB-Anteil darf ohne Einheit kommen: nur Inhalte, oder nur --deactivate (§8.1).
        $units = self::parseUnits($params['units'] ?? null, $contentSha !== null || $wish !== null);
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
        $open = PushWindow::open(Store::pushUntil($keyId), $now);
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
            // Ohne offenes Fenster nur ein Teil der Prüfung: der Probelauf ist kein Weg, die Site auszufragen.
            $contentPlan = PushContent::plan($staged, $target, $content, $uploadFiles, empty($params['dry']), Store::pushOpener($keyId), $open);
        }
        // Auch der Plugin-Zustand wird im Probelauf wie im echten Begin geprüft – und auch ohne offenes
        // Fenster: die Liste der aktiven Plugins nennt Rest::env() einem gekoppelten Gerät ohnehin (§4.2).
        $pluginPlan = $wish === null ? null : PushPlugins::plan($wish, array_column($units, 'path'), $target, $content);
        $pending = self::pending();
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
                // Auch, was dieser Satz erst aktiviert: ein Sicherheits-Plugin sperrt rescue.php ab dem nächsten Request (H2).
                'hardening' => PushRescueStub::hardening(array_merge(self::activePlugins(), $wish === null ? [] : array_map(static function (string $unit): string {
                    return PushPlugins::slug($unit) . '/';
                }, $wish['activate']))),
            ],
        ];
        if ($contentPlan !== null) {
            $answer['content'] = $contentPlan;
        }
        if ($pluginPlan !== null) {
            $answer['plugins'] = $pluginPlan;
        }
        if (!empty($params['dry'])) {
            if ($contentSha !== null || $wish !== null) {
                // Was ein echter Begin ergäbe – ohne Probe und ohne Datei (Spec Content-Push P3 §5.3). Für einen
                // Satz mit Plugin-Zustand bricht die CLI bei ok: false ab, bevor sie etwas überträgt (P4 §8.2).
                $answer['rescue']['db'] = PushContent::rescueDb($target, $content, '', null, '');
            }
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
        // Dasselbe für den Plugin-Zustand: plugins.error des Probelaufs ist die Ablehnung des echten Begin.
        if ($pluginPlan !== null && !$pluginPlan['ok']) {
            return ContentException::fromArray((array) $pluginPlan['error'])->toError();
        }
        // A12: nur ein Fenster mit Öffner, und der darf Plugins schalten. Wie author_unknown erst hier – der
        // Probelauf sagt über den Öffner nichts.
        if ($wish !== null && !PushPlugins::allowed(Store::pushOpener($keyId))) {
            return PushPlugins::refuse(ContentException::PLUGINS_NOT_ALLOWED, PushPlugins::NOT_ALLOWED_TEXT)->toError();
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
        // Der Rescue-Key entsteht nur hier: der Server behält seinen Hash – und, für einen Push mit
        // DB-Anteil (Inhalte oder Plugin-Zustand), den Umschlag, den nur dieser Schlüssel öffnet (P3 R1, R2).
        $rescueKey = PushRescue::key((string) Store::secretFor($keyId), $pushId, $salt);
        $plan      = [
            'push_id'  => $pushId,
            'key_id'   => $keyId,
            'key_hash' => hash('sha256', $rescueKey),
            'units'    => $planned,
        ];
        // Das geprüfte Paket kommt in den Arbeitsordner des Pushs (bei Staging: in die Kopie) – der
        // Commit wendet genau diese Datei an, auch wenn die Ablage inzwischen verfallen ist.
        $taken = true;
        if ($contentSha !== null) {
            $rows            = (int) array_sum((array) $contentPlan['rows']);
            // Projekt-Erweiterungen des Pakets bleiben im Datensatz des Pushs sichtbar (pushes, WP-Admin).
            $extensions      = isset($contentPlan['extensions']) ? ['extensions' => $contentPlan['extensions']] : [];
            $plan['content'] = ['sha256' => $contentSha, 'rows' => $rows] + $extensions;
            $summary[]       = ['path' => PushContent::UNIT, 'exists' => true, 'old_version' => '', 'new_version' => '', 'files' => $rows, 'uploaded' => $rows] + $extensions;
            $taken           = $staged !== null && PushContent::take($staged, $work . '/' . $pushId, $contentSha);
        }
        if ($wish !== null) {
            // Nur die Einheiten – die mitgeschickten Köpfe werden nie gespeichert (§12); im Commit zählt die gebaute Datei.
            $plan['plugins'] = ['activate' => $wish['activate'], 'deactivate' => $wish['deactivate']];
            $summary[]       = ['path' => PushPlugins::UNIT, 'exists' => true, 'old_version' => '', 'new_version' => '', 'files' => 0, 'uploaded' => 0]
                + $plan['plugins'];
        }
        if ($contentSha !== null || $wish !== null) {
            // Der Umschlag für rescue.php (P3 R1, R2) – für jeden Satz mit DB-Anteil. Ohne ihn wird ein Satz
            // nur aus Inhalten trotzdem gepusht (R12): die CLI warnt oder bricht mit --require-rescue-db ab.
            $answer['rescue']['db'] = $taken ? PushContent::rescueDb($target, $content, $work, $pushId, $rescueKey) : ['ok' => false, 'reason' => RescueContent::WRITE_FAILED];
        }
        unset($rescueKey);
        if ($wish !== null && empty($answer['rescue']['db']['ok'])) {
            // Für einen Satz mit Plugin-Zustand ist der Umschlag Pflicht (P4 A9): ein Plugin, das beim Laden
            // wirft – oder dessen Fehlen ein anderes zum Absturz bringt –, lässt sich nur über die Datenbank
            // zurückdrehen, und der Agent antwortet dann nicht mehr. Der eben angelegte Push verschwindet.
            PushSwap::remove($work . '/' . $pushId);
            self::release($pushId);
            return PushPlugins::refuse(
                ContentException::PLUGINS_RESCUE_DB,
                PushPlugins::NO_ENVELOPE_TEXT,
                [],
                ['detail' => (string) ($answer['rescue']['db']['reason'] ?? RescueContent::PROBE_FAILED)]
            )->toError();
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
     * Baut die neuen Verzeichnisse (in Schritten mit Cursor) und tauscht dann alle Einheiten. Trägt
     * der Plan einen Plugin-Zustand (Spec Content-Push P4 §8.1), wird er vor dem Tausch verbindlich
     * geprüft und im DB-Schritt geschrieben – auch ohne Paket.
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

        // Der Plugin-Zustand, verbindlich und vor dem Tausch (Spec Content-Push P4 §8.1 Nr. 2): der Öffner des
        // Fensters darf Plugins schalten (A12), der Umschlag für rescue.php liegt (A9), jede zu aktivierende
        // Einheit trägt im gebauten Verzeichnis genau eine Hauptdatei, und ihr Kopf passt zum Ziel (A10) – der
        // mitgeschickte Kopf des Begin zählt hier nicht mehr. Scheitert etwas, ist nichts getauscht.
        // Gelesen werden nur die ersten Bytes der Dateien: hier läuft nie Code eines Plugins (A5).
        $wish     = null;
        $resolved = null;
        if (is_array($plan['plugins'] ?? null)) {
            try {
                // plan.json liegt im Arbeitsordner: was daraus kommt, gilt nur in der Form, die auch der Begin verlangt.
                $wish = PushPlugins::request(['activate' => $plan['plugins']['activate'] ?? [], 'deactivate' => $plan['plugins']['deactivate'] ?? []]);
                if ($wish === null) {
                    throw PushPlugins::refuse(ContentException::PLUGINS_INVALID, 'Der Plan dieses Pushs nennt keinen gültigen Plugin-Zustand.');
                }
                $opened   = Store::getPush($pushId);
                $resolved = PushPlugins::check($wish, array_column($plan['units'], 'path'), $base . '/new', $target, $content, $opened === null ? null : $opened['opened_by'], RescueSeal::file($work, $pushId));
            } catch (ContentException $e) {
                self::discard($pushId, self::FAILED);
                return $e->toError();
            } catch (\Throwable $e) {
                // Kein vorgesehener Grund – dieselbe Folge, ohne zu sagen, was es war.
                self::discard($pushId, self::FAILED);
                return (new ContentException(ContentException::FAILED, 'Der Plugin-Zustand liess sich nicht prüfen – nichts getauscht.'))->toError();
            }
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
        // Inhalte kommen zuletzt (Uploads → Code → DB, §7.3). Das geprüfte Paket muss noch genau das sein.
        $contentSha = is_array($plan['content'] ?? null) ? (string) ($plan['content']['sha256'] ?? '') : null;
        $package    = $contentSha === null ? null : PushContent::taken($base, $contentSha);
        if ($contentSha !== null && $package === null) {
            self::discard($pushId, self::FAILED);
            return (new ContentException(ContentException::MISSING, 'Das Paket dieses Pushs fehlt oder wurde verändert – nichts getauscht.'))->toError();
        }
        wp_mkdir_p($base . '/old');
        // Vor dem ersten rename: stirbt PHP mitten im Anlegen oder Tausch, kann rescue.php zurücknehmen.
        // Der DB-Anteil – Paket oder Plugin-Zustand – steht hier schon als „pending“, lange vor START TRANSACTION.
        PushRescue::write($work, $pushId, (string) $plan['key_hash'], $pairs, PushRescue::COMMITTED, $uploads, $contentSha, $wish !== null, $wish === null ? [] : array_merge($wish['activate'], $wish['deactivate']));
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
        // Überholt wird, wer dieselbe Einheit getauscht ODER geschaltet hat (S2) – auch von einem Satz ohne Paare.
        PushRescue::supersede($work, $pushId, array_values(array_unique(array_merge(array_column($pairs, 'unit'), $wish === null ? [] : array_merge($wish['activate'], $wish['deactivate'])))));
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
        if ($contentSha !== null) {
            $rows      = (int) ($plan['content']['rows'] ?? 0);
            $used      = ContentLists::used(is_array($plan['content']['extensions'] ?? null) ? $plan['content']['extensions'] : []);
            $summary[] = ['path' => PushContent::UNIT, 'exists' => true, 'old_version' => '', 'new_version' => '', 'files' => $rows, 'uploaded' => $rows]
                + ($used === null ? [] : ['extensions' => $used]);
        }
        if ($wish !== null) {
            // Die Einheit plugins des Protokolls (V12) – immer die letzte: nach dem DB-Schritt kommt dazu, was er geändert hat.
            $summary[] = ['path' => PushPlugins::UNIT, 'exists' => true, 'old_version' => '', 'new_version' => '', 'files' => 0, 'uploaded' => 0,
                'activate' => $wish['activate'], 'deactivate' => $wish['deactivate']];
        }
        // Ab hier gilt der Push als getauscht – auch wenn PHP beim Anwenden der Inhalte stirbt: dann
        // nimmt die Datenbank die Transaktion zurück, und wpsync rollback holt den Code nach.
        Store::updatePush($pushId, ['status' => PushRescue::COMMITTED, 'committed' => time(), 'units' => (string) wp_json_encode($summary)]);
        $answer = ['next' => null, 'stamps' => (object) $stamps];
        if ($package !== null || $wish !== null) {
            // Der DB-Schritt: das Paket, der Plugin-Zustand oder beides – eine Transaktion (P4 A7).
            // Die Naht vor COMMIT (P3 R10): die Sperre des Pushs nehmen, rescue.json neu lesen. Hat
            // rescue.php den Push inzwischen zurückgenommen, wird nichts festgeschrieben. Die Sperre
            // hält bis nach „applied“ – rescue.php sieht den DB-Anteil nie halb.
            $lock = false;
            $wait = self::$gateWait ?? self::GATE_SECONDS;
            $gate = static function () use ($work, $pushId, $wait, &$lock): bool {
                $lock = PushRescue::lock($work, $pushId, $wait);
                if ($lock === null) {
                    return false; // eine Rücknahme läuft: sie hat Vorrang
                }
                // Lässt sich hier gar nicht sperren (false), nimmt rescue.php auch keine Inhalte zurück –
                // dann entscheidet der Datensatz allein, wie vor P3.
                $record = PushRescue::read($work, $pushId);
                return $record !== null && $record['status'] === PushRescue::COMMITTED
                    && is_array($record['content'] ?? null) && ($record['content']['state'] ?? '') === PushRescue::CONTENT_PENDING;
            };
            try {
                $push    = Store::getPush($pushId);
                $applied = PushContent::apply(
                    $package,
                    $target,
                    $content,
                    $base,
                    $push === null ? null : $push['opened_by'],
                    $gate,
                    $resolved === null ? null : ['add' => array_values($resolved['add']), 'drop' => $resolved['drop']]
                );
            } catch (ContentException $e) {
                return self::contentFailed($e, $content, $work, $pushId, $lock);
            } catch (\Throwable $e) {
                // Kein vorgesehener Grund, dieselbe Folge: was die Transaktion angefangen hat, hat sie
                // zurückgenommen (ContentStore::transaction). Was der Fehler war, bleibt hier.
                return self::contentFailed(new ContentException(ContentException::FAILED, 'Die Inhalte liessen sich nicht anwenden – nichts wurde übernommen.'), $content, $work, $pushId, $lock);
            }
            $done = ['state' => PushRescue::CONTENT_APPLIED];
            if ($resolved !== null) {
                // Nur zur Auskunft – für plugins_not_restored, falls rescue.php den DB-Anteil stehen lassen muss (A18).
                $done['plugins'] = $applied['plugins'];
            }
            PushRescue::setContentFields($work, $pushId, $done);
            PushRescue::unlock($lock);
            if ($resolved !== null) {
                // Der Vermerk im Protokoll zuerst – vor den Nacharbeiten, in denen fremder Code läuft: stirbt PHP
                // dort, weiss die Zeile trotzdem, was der Push an der Liste geändert hat (Nach-Review NR-1).
                $summary[count($summary) - 1] += ['activated' => $applied['plugins']['added'], 'deactivated' => $applied['plugins']['removed']];
                Store::updatePush($pushId, ['units' => (string) wp_json_encode($summary)]);
            }
            $answer['content'] = [
                'rows'         => $applied['rows'],
                'after'        => $applied['after'],
                // §7.7: nie ein Fehler des Pushs. Mit Plugin-Zustand liest der Agent zurück, ob die neue Liste gilt (S4).
                'post_actions' => PushContent::postActions($target, $content, $applied['changes']
                    + ($resolved === null ? [] : ['plugins_expect' => ['present' => $applied['plugins']['added'], 'absent' => $applied['plugins']['removed']]])),
                'seconds'      => $applied['seconds'],
            ];
            if ($resolved !== null) {
                $answer['plugins'] = PushPlugins::result($wish, $resolved, $applied['plugins']);
            }
        }
        if ($target === 'staging') {
            Staging::markUsed(); // ein Push zählt als Nutzung der Kopie (Spec 2b 5.9)
        }
        self::touchLock($pushId, time());
        self::touchStub(time());
        return new \WP_REST_Response($answer);
    }

    /**
     * Die Inhalte liessen sich nicht anwenden – die Datenbank hat nichts davon behalten. Der Satz
     * bleibt ganz (§7.3 Nr. 6): Code und Uploads werden zurückgetauscht, der Push ist gescheitert.
     * Ausnahme unrestored: eine einzelne Zeile steht nicht mehr auf ihrem Stand davor – dann bleibt
     * der Arbeitsordner samt Vorher-Abbild liegen.
     *
     * @param resource|null|false $lock die Sperre des Pushs, wenn die Naht des Commits sie schon hält
     */
    private static function contentFailed(ContentException $e, string $content, string $work, string $pushId, $lock = false): \WP_Error
    {
        // Mit der Sperre des Pushs: kam die Naht nicht mehr dazu (oder lief gerade rescue.php), tauschen
        // sonst zwei Läufe dieselben Paare zurück. Wo sich gar nicht sperren lässt (false), wie vor P3.
        if (!is_resource($lock)) {
            $lock = PushRescue::lock($work, $pushId, self::$gateWait ?? self::GATE_SECONDS);
        }
        if ($lock === null) {
            // Die Sperre bleibt belegt: eine Rücknahme dieses Pushs (rescue.php, der Agent) läuft noch. Sie
            // nimmt Code und Uploads zurück und schliesst den DB-Anteil ab – festgeschrieben wurde hier
            // nichts. Ohne Sperre daneben den Datensatz zu schreiben, die Paare zu tauschen und den Ordner
            // zu löschen, wäre genau der Wettlauf, den sie verhindert. Der Push bleibt, wie er ist; schliesst
            // ihn jene Rücknahme nicht ab, tut es wpsync rollback (Exit 42).
            return self::error('wpsync_push_pending', 'Push ' . $pushId . ': die Inhalte wurden nicht übernommen; eine Rücknahme dieses Pushs läuft gerade. Bleibt er danach offen: wpsync rollback ' . $pushId . '.', 409);
        }
        try {
            PushRescue::setContent($work, $pushId, PushRescue::CONTENT_DONE);
            list($status) = PushRescue::rollback($content, $work, $pushId);
            if ($status !== 200) {
                // Der Push bleibt getauscht und unbestätigt: die CLI nennt den Ausweg (Exit 42).
                return self::error('wpsync_push_pending', 'Push ' . $pushId . ': die Inhalte wurden nicht übernommen, und der Code liess sich nicht zurücktauschen – wpsync rollback ' . $pushId . '.', 409);
            }
            if (!empty($e->toArray()['unrestored'])) {
                // Eine Zeile liess sich nicht zurücksetzen: das Vorher-Abbild ist der einzige Beleg für ihren
                // Stand davor. Der Push ist gescheitert, sein Arbeitsordner bleibt liegen – prune() und
                // pruneOrphans() fassen ihn nicht an, solange seine Zeile nicht als aufgeräumt gilt.
                // Der Umschlag für rescue.php hat ausgedient (P3 R14).
                RescueSeal::forget($work, $pushId);
                Store::updatePush($pushId, ['status' => self::FAILED, 'finished' => time()]);
                self::release($pushId);
                return $e->toError();
            }
            self::discard($pushId, self::FAILED);
            return $e->toError();
        } finally {
            PushRescue::unlock($lock);
        }
    }

    /**
     * Nach bestandenem Health-Check. Braucht kein offenes Fenster.
     *
     * Unter der Sperre des Pushs (P3 R10): rescue.php und die Rücknahme über den Agent halten
     * dieselbe. Ohne sie könnte rescue.php den Push zurücknehmen, während er hier bestätigt wird –
     * „bestätigt“ in der Datenbank, zurückgetauscht auf der Platte. Wer zuerst kommt, gilt; der
     * andere sieht dessen Stand.
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
        $lock = false;
        $dirs = null;
        if ($push['status'] === PushRescue::COMMITTED && !$push['pruned']) {
            $dirs = self::dirs($push['target']);
            if (!($dirs instanceof \WP_Error)) {
                // Eine Rücknahme, die gerade läuft, ist gleich fertig: darauf warten. Wo sich nicht sperren
                // lässt (false), bleibt es wie vor P3 – rescue.php fasst die Datenbank dann nicht an.
                $lock = PushRescue::lock($dirs[1], $push['push_id'], self::$gateWait ?? self::GATE_SECONDS);
                if ($lock === null) {
                    return self::error('wpsync_push_busy', 'Für Push ' . $push['push_id'] . ' läuft gerade eine Rücknahme – gleich noch einmal versuchen.', 423);
                }
            }
        }
        try {
            if ($dirs !== null) {
                // Die Zeile im Protokoll noch einmal – unter der Sperre und auch, wenn es keine mehr zu
                // nehmen gab: eine Rücknahme über den Agent, die inzwischen fertig wurde, hat den Ordner des
                // Pushs samt Datensatz und Sperrdatei gelöscht und die Zeile abgeschlossen (discard()
                // schreibt sie, bevor der Ordner verschwindet). Mit der Zeile von vorhin würde sonst
                // „bestätigt“, was auf der Platte zurückgetauscht ist.
                $fresh = Store::getPush($push['push_id']);
                if ($fresh === null) {
                    return self::error('wpsync_push_state', 'Der Stand von Push ' . $push['push_id'] . ' liess sich nicht lesen – noch einmal versuchen.', 409);
                }
                $push = $fresh;
            }
            if (is_array($dirs) && $push['status'] === PushRescue::COMMITTED && !$push['pruned']) {
                // Und der Datensatz: zwischen sync() oben und der Sperre kann rescue.php den Push ganz
                // zurückgenommen haben. Dann gibt es nichts mehr zu bestätigen – übernehmen wie sync().
                $record = PushRescue::read($dirs[1], $push['push_id']);
                if ($record !== null && $record['status'] === PushRescue::ROLLED_BACK && !PushRescue::contentOpen($record)) {
                    self::afterRescue($push, $dirs[0], $dirs[1], $record);
                    self::finishRollback($push['push_id']);
                    return self::error('wpsync_push_state', 'Push ist im Status ' . PushRescue::ROLLED_BACK . '.', 409);
                }
            }
            return self::confirmLocked($push, $keyId);
        } finally {
            PushRescue::unlock($lock);
        }
    }

    /**
     * confirm, unter der Sperre des Pushs.
     *
     * @param array<string, mixed> $push
     * @return \WP_REST_Response|\WP_Error
     */
    private static function confirmLocked(array $push, string $keyId)
    {
        $kept = ['ok' => true, 'status' => PushRescue::ROLLED_BACK, 'warnings' => [PushRescue::CONTENT_KEPT]];
        if (self::contentKept($push)) {
            return new \WP_REST_Response($kept); // schon so abgeschlossen: eine verlorene Antwort lässt sich wiederholen
        }
        // Hat rescue.php Code und Uploads schon zurückgenommen, wird der Push nie „bestätigt“. Offen ist
        // dann nur noch sein DB-Anteil (sync() schliesst alle anderen ab): confirm nimmt die Inhalte an,
        // wie sie stehen, und schliesst den Push als zurückgerollt ab – der Ausweg, wenn sie sich nicht
        // zurücknehmen lassen (changed_since_push). Das Vorher-Abbild geht mit dem Arbeitsordner.
        if ($push['status'] === PushRescue::COMMITTED && !$push['pruned']) { // einen bestätigten Push nimmt rescue.php nie zurück
            $dirs   = self::dirs($push['target']);
            $record = $dirs instanceof \WP_Error ? null : PushRescue::read($dirs[1], $push['push_id']);
            if ($record !== null && $record['status'] === PushRescue::ROLLED_BACK) {
                $units = [];
                foreach ((array) $push['units'] as $unit) {
                    $units[] = self::dbUnit($unit) ? $unit + ['kept' => true] : $unit;
                }
                Store::updatePush($push['push_id'], ['units' => (string) wp_json_encode($units)]);
                self::finishRollback($push['push_id']);
                self::touchStub(time());
                self::scheduleTidy();
                return new \WP_REST_Response($kept);
            }
        }
        if ($push['status'] !== PushRescue::CONFIRMED) {
            if ($push['status'] !== PushRescue::COMMITTED) {
                return self::error('wpsync_push_state', 'Push ist im Status ' . $push['status'] . '.', 409);
            }
            $dirs = self::dirs($push['target']);
            if ($dirs instanceof \WP_Error) {
                return $dirs;
            }
            // Ohne Datensatz gibt es hier nur noch den Push, dessen Ordner verloren ging (von Hand gelöscht):
            // eine Rücknahme hätte seine Zeile abgeschlossen, bevor der Ordner verschwand, und confirm()
            // hat die Zeile eben neu gelesen. Der gepushte Code steht dann unverändert – bestätigen ist
            // richtig und der einzige Ausweg, sonst sperrte der Push jeden weiteren. Gibt es den Datensatz,
            // muss er den Status auch tragen.
            if (PushRescue::read($dirs[1], $push['push_id']) !== null && !PushRescue::setStatus($dirs[1], $push['push_id'], PushRescue::CONFIRMED)) {
                return self::error('wpsync_push_state', 'Push ' . $push['push_id'] . ' liess sich nicht bestätigen – noch einmal versuchen.', 409);
            }
            // Einen bestätigten Push nimmt rescue.php nie zurück: der Umschlag mit den Zugangsdaten hat ausgedient (P3 R14).
            RescueSeal::forget($dirs[1], $push['push_id']);
            Store::updatePush($push['push_id'], ['status' => PushRescue::CONFIRMED, 'finished' => time()]);
            // Das Paket ist auf Live: die Ablage braucht niemand mehr. Nach Staging bleibt sie für den Push nach Live.
            $record = PushRescue::read($dirs[1], $push['push_id']);
            if ($push['target'] === 'live' && is_string($record['content']['sha256'] ?? null)) {
                PushContent::forget($dirs[1], $keyId, $record['content']['sha256']);
            }
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
        // Wer das Fenster geöffnet hat, verantwortet auch, was die Rücknahme an der Liste der Plugins schaltet (A12).
        return self::rollbackPush($push['push_id'], Store::pushOpener($keyId));
    }

    /**
     * Ohne Fensterprüfung – auch für die Admin-Seite, deren Administratoren vertrauenswürdig sind.
     *
     * @param int|null $actor wer die Rücknahme verantwortet: der Öffner des Push-Fensters bzw. der Benutzer der
     *        Admin-Seite. Nimmt die Rücknahme eines BESTÄTIGTEN Pushs Plugins aus der Liste oder bringt sie welche
     *        zurück, braucht er das Recht activate_plugins (Spec Content-Push P4 A12; Security-Review P4 S1) – wie
     *        beim Push selbst. Ein unbestätigter Push bleibt davon ausgenommen: das ist der Notfallweg, den auch
     *        rescue.php geht. null: niemand
     * @return \WP_REST_Response|\WP_Error
     */
    public static function rollbackPush(string $pushId, ?int $actor = null)
    {
        self::sync();
        $push = Store::getPush($pushId);
        if ($push === null) {
            return self::error('wpsync_push_unknown', 'Unbekannter Push.', 404);
        }
        if (self::contentKept($push)) {
            return self::error('wpsync_push_state', 'Code und Uploads dieses Pushs sind zurück, seine Inhalte wurden mit confirm angenommen – ein Vorher-Abbild gibt es nicht mehr.', 409);
        }
        if ($push['status'] !== PushRescue::ROLLED_BACK) {
            if ($push['pruned'] || !in_array($push['status'], [PushRescue::COMMITTED, PushRescue::CONFIRMED], true)) {
                return self::error('wpsync_push_state', 'Für diesen Push gibt es keinen Snapshot (Status ' . $push['status'] . ').', 409);
            }
            if (self::switchesBack($push) && !PushPlugins::allowed($actor)) {
                return PushPlugins::refuse(ContentException::PLUGINS_NOT_ALLOWED, PushPlugins::ROLLBACK_NOT_ALLOWED_TEXT)->toError();
            }
            $dirs = self::dirs($push['target']);
            if ($dirs instanceof \WP_Error) {
                return $dirs;
            }
            // Nie zugleich mit rescue.php oder dem Commit dieses Pushs (P3 R10). Wo sich nicht sperren
            // lässt (false), bleibt es wie vor P3 – rescue.php fasst die Datenbank dann nicht an.
            $lock = PushRescue::lock($dirs[1], $pushId);
            if ($lock === null) {
                return self::error('wpsync_push_busy', 'Für Push ' . $pushId . ' läuft gerade eine Rücknahme oder sein Commit – gleich noch einmal versuchen.', 423);
            }
            try {
                return self::rollbackLocked($push, $dirs[0], $dirs[1]);
            } finally {
                PushRescue::unlock($lock);
            }
        }
        return new \WP_REST_Response(['ok' => true, 'status' => PushRescue::ROLLED_BACK]);
    }

    /**
     * Schaltet die Rücknahme dieses Pushs Plugins, und zwar ausserhalb des Notfallwegs? Ja für einen
     * bestätigten Push mit der Einheit plugins im Protokoll – ausser der Commit hat vermerkt, dass er an
     * der Liste nichts geändert hat. Für einen unbestätigten Push fragt niemand nach dem Recht (S1).
     *
     * @param array<string, mixed> $push
     */
    public static function switchesBack(array $push): bool
    {
        if (($push['status'] ?? '') !== PushRescue::CONFIRMED) {
            return false;
        }
        foreach ((array) ($push['units'] ?? []) as $unit) {
            if (!is_array($unit) || ($unit['path'] ?? '') !== PushPlugins::UNIT) {
                continue;
            }
            // Fail-closed (Nach-Review NR-1): „nichts geändert“ gilt nur, wenn der Commit genau das vermerkt hat
            // – beide Listen da und leer. Fehlt der Vermerk (PHP starb nach dem COMMIT), ist er halb oder keine
            // Liste, kann das Delta trotzdem in der Datenbank stehen.
            $noted = array_key_exists('activated', $unit) && array_key_exists('deactivated', $unit)
                && $unit['activated'] === [] && $unit['deactivated'] === [];
            if (!$noted) {
                return true;
            }
        }
        return false;
    }

    /**
     * Die Rücknahme selbst, unter der Sperre des Pushs.
     *
     * @param array<string, mixed> $push
     * @param string               $content wp-content des Ziels
     * @param string               $work    Arbeitsordner darin
     * @return \WP_REST_Response|\WP_Error
     */
    private static function rollbackLocked(array $push, string $content, string $work)
    {
        $pushId = (string) $push['push_id'];
        $dirs   = [$content, $work];
        // DB → Code → Uploads (§7.6). Hat sich eine Zeile seit dem Push geändert, wird nichts
        // zurückgenommen – auch Code und Uploads nicht: der Satz bleibt ganz.
        $actions  = [];
        $switched = null; // was die Rücknahme an der Liste der aktiven Plugins geändert hat (P4 §4.4)
        $record   = PushRescue::read($dirs[1], $pushId);
        // Unter der Sperre noch einmal: wurde der Push bestätigt, seit der Aufrufer ihn las, gilt für ihn
        // das Push-Fenster (U18), das für einen unbestätigten niemand geprüft hat. Vor der Datenbank –
        // sonst gingen die Inhalte zurück und der Code bliebe.
        $confirmed = $push['status'] === PushRescue::CONFIRMED;
        if ($record !== null && $record['status'] === PushRescue::CONFIRMED && !$confirmed) {
            return self::error('wpsync_push_state', 'Push ' . $pushId . ' wurde inzwischen bestätigt – die Rücknahme noch einmal aufrufen.', 409);
        }
        if ($record !== null && PushRescue::contentOpen($record)) {
            // Was die Rücknahme des Codes ablehnen würde, zuerst: sonst gingen die Inhalte zurück
            // und der Code bliebe stehen.
            $by = $record['status'] === PushRescue::ROLLED_BACK ? null : PushRescue::supersededBy($dirs[1], $record);
            if ($by !== null) {
                return self::error('wpsync_push_rollback', self::superseded($by), 409);
            }
            try {
                // Nur wenn der Commit „applied“ vermerkt hat, steht fest, dass seine Transaktion ankam: sonst
                // zählt für active_plugins der Abdruck statt des Deltas (P4, V1). Kein Hook läuft (A17).
                $back = PushContent::rollback($push['target'], $dirs[0], $dirs[1] . '/' . $pushId, ($record['content']['state'] ?? '') === PushRescue::CONTENT_APPLIED);
            } catch (ContentException $e) {
                return $e->toError();
            }
            // Ab hier steht in rescue.json, dass die Inhalte zurück sind: scheitert danach der Code,
            // überspringt ein zweiter Lauf die Datenbank und holt nur Code und Uploads nach.
            PushRescue::setContent($dirs[1], $pushId, PushRescue::CONTENT_DONE);
            if (is_array($back['plugins'] ?? null)) {
                $switched = $back['plugins'];
                self::notePlugins($pushId, (array) $push['units'], ['back' => $switched]);
            }
            $actions = PushContent::postActions($push['target'], $dirs[0], $back['changes'] === null || $switched === null ? $back['changes']
                : $back['changes'] + ['plugins_expect' => ['present' => $switched['reactivated'], 'absent' => $switched['deactivated']]]);
        }
        list($status, $body) = PushRescue::rollback($dirs[0], $dirs[1], $pushId, null, $confirmed);
        if ($status !== 200) {
            $why = ($body['error'] ?? '') === 'superseded'
                ? self::superseded((string) ($body['by'] ?? ''))
                : 'Rollback fehlgeschlagen: ' . ($body['error'] ?? 'unbekannt');
            if ($record !== null && is_array($record['content'] ?? null)) {
                $why .= ' – Die Inhalte sind zurückgenommen, Code und Uploads noch nicht: die Rücknahme wiederholen.';
            }
            return self::error('wpsync_push_rollback', $why, $status === 500 ? 500 : 409);
        }
        self::finishRollback($pushId);
        self::touchStub(time()); // wie bei confirm (R5)
        self::scheduleTidy();
        $answer = ['ok' => true, 'status' => PushRescue::ROLLED_BACK];
        // Seit dem Push geänderte Uploads bleiben liegen und werden genannt (Spec Content-Push §8.4).
        if (isset($body['warnings'])) {
            $answer += ['warnings' => $body['warnings'], 'kept' => $body['kept'] ?? []];
        }
        if ($actions !== []) {
            $answer['post_actions'] = $actions; // Nacharbeiten der Rücknahme (§7.7)
        }
        if ($switched !== null) {
            $answer['plugins'] = $switched;
        }
        return new \WP_REST_Response($answer);
    }

    /**
     * Ist das ein Eintrag des Protokolls, der für den DB-Anteil eines Pushs steht – seine Inhalte
     * oder sein Plugin-Zustand (V12)?
     *
     * @param mixed $unit
     */
    private static function dbUnit($unit): bool
    {
        return is_array($unit) && in_array($unit['path'] ?? '', [PushContent::UNIT, PushPlugins::UNIT], true);
    }

    /**
     * Vermerkt etwas an der Einheit plugins im Protokoll eines Pushs.
     *
     * @param list<mixed>          $units die Einheiten des Pushs, wie das Protokoll sie nennt
     * @param array<string, mixed> $note
     */
    private static function notePlugins(string $pushId, array $units, array $note): void
    {
        $out = [];
        foreach ($units as $unit) {
            $out[] = is_array($unit) && ($unit['path'] ?? '') === PushPlugins::UNIT ? array_merge($unit, $note) : $unit;
        }
        Store::updatePush($pushId, ['units' => (string) wp_json_encode($out)]);
    }

    /**
     * Wurde der Push mit confirm abgeschlossen, nachdem rescue.php Code und Uploads zurückgenommen
     * hatte – die Inhalte stehen also bewusst noch – oder der Plugin-Zustand?
     *
     * @param array<string, mixed> $push
     */
    private static function contentKept(array $push): bool
    {
        if ($push['status'] !== PushRescue::ROLLED_BACK) {
            return false;
        }
        foreach ((array) $push['units'] as $unit) {
            if (self::dbUnit($unit) && !empty($unit['kept'])) {
                return true;
            }
        }
        return false;
    }

    private static function superseded(string $by): string
    {
        return 'Zuerst den späteren Push ' . $by . ' zurückrollen.';
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

    /**
     * Übernimmt Rollbacks, die rescue.php an WordPress vorbei ausgeführt hat – auf beiden Zielen. Hat
     * rescue.php dabei auch die Inhalte zurückgenommen, laufen hier die Nacharbeiten, die ohne
     * WordPress nicht gingen (Spec Content-Push P3 §8.2). Jeder Push unter seiner Sperre: was sync()
     * in rescue.json schreibt (post = done), schreibt es nie neben einem anderen Lauf.
     */
    public static function sync(): void
    {
        $name = Store::pushDirName();
        $busy = []; // Ziel → true: ein Push dort war gerade gesperrt
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
            $work   = $content . '/' . $name;
            $record = PushRescue::read($work, $push['push_id']);
            // Hat rescue.php nur Code und Uploads zurückgenommen, stehen die Inhalte noch (§7.6): der
            // Push bleibt offen, sein Vorher-Abbild liegen – wpsync rollback holt sie über den Agent nach.
            if ($record === null || $record['status'] !== PushRescue::ROLLED_BACK || PushRescue::contentOpen($record)) {
                continue;
            }
            // Übernommen wird nur unter der Sperre des Pushs: rescue.php und sein Cache-Schritt schreiben
            // denselben Datensatz. Hält sie ein anderer Lauf, bleibt der Push für diesmal, wie er ist.
            // Wo sich nicht sperren lässt (false), nimmt rescue.php keine Inhalte zurück – wie vor P3.
            $lock = PushRescue::lock($work, $push['push_id']);
            if ($lock === null) {
                $busy[$push['target']] = true;
                continue;
            }
            try {
                $record = PushRescue::read($work, $push['push_id']); // unter der Sperre noch einmal
                if ($record !== null && $record['status'] === PushRescue::ROLLED_BACK && !PushRescue::contentOpen($record)) {
                    self::afterRescue($push, $content, $work, $record);
                    self::finishRollback($push['push_id']);
                }
            } finally {
                PushRescue::unlock($lock);
            }
        }
        // Die Marker beider Ziele haben ausgedient – nur wenn die Liste oben wirklich gelesen wurde.
        if (!Store::dbOk()) {
            return;
        }
        foreach (self::TARGETS as $target) {
            $content = self::content($target);
            $marker  = PushRescue::pendingFile($content . '/' . $name);
            if ($content === '' || is_link($marker)) {
                continue;
            }
            if (isset($busy[$target])) {
                // Ein Push dieses Ziels war gesperrt: der Marker bleibt (oder liegt wieder – catchUp() nimmt
                // ihn vorher weg), damit der nächste Seitenaufruf es noch einmal versucht.
                if (is_dir($content . '/' . $name)) {
                    @touch($marker);
                }
            } elseif (is_file($marker)) {
                @unlink($marker);
            }
        }
    }

    /**
     * Was nach einer Rücknahme der Inhalte durch rescue.php nachzuholen ist (P3 §8.2): das
     * Aufräumen dessen, was an eingefügten Objekten stehen blieb (R15), und die Nacharbeiten (§7.7) –
     * beides höchstens einmal, ein Fehlschlag hält den Abschluss nie auf – und der Vermerk im
     * Protokoll der Pushes (via, post_actions, was stehen blieb).
     *
     * @param array<string, mixed> $push
     * @param array<string, mixed> $record rescue.json
     */
    private static function afterRescue(array $push, string $content, string $work, array $record): void
    {
        $stored = $record['content'] ?? null;
        if (!is_array($stored) || ($stored['via'] ?? '') !== PushRescue::VIA_RESCUE) {
            return;
        }
        $pushId = (string) $push['push_id'];
        $note   = ['via' => PushRescue::VIA_RESCUE];
        if (is_array($stored['left'] ?? null) && $stored['left'] !== []) {
            $note['left']       = array_slice(array_values($stored['left']), 0, ContentException::MAX_KEYS);
            $note['left_total'] = (int) ($stored['left_total'] ?? count($stored['left']));
        }
        if (($stored['post'] ?? '') === PushRescue::POST_PENDING) {
            // Erst merken, dann ausführen: legt eine Nacharbeit WordPress lahm, läuft sie kein zweites Mal.
            PushRescue::setContentFields($work, $pushId, ['post' => PushRescue::POST_DONE]);
            // Zuerst, was rescue.php an den eingefügten Objekten stehen liess (R15): Meta und Zuordnungen
            // an IDs, die es nicht mehr gibt. Sie hingen sich an das nächste Objekt mit dieser ID.
            $swept = null;
            try {
                $swept = PushContent::sweep((string) $push['target'], $content, $work . '/' . $pushId) > 0 ? true : null;
            } catch (\Throwable $e) {
                $swept = false;
            }
            $actions = [['step' => 'post_actions', 'ok' => false]];
            $changes = null;
            try {
                // Hier wieder mit dem Schlüssel der Installation – der Umschlag ist längst weg.
                $image   = ContentImage::get($work . '/' . $pushId . '/' . PushContent::UNIT, ContentImage::AFTER);
                $changes = is_array($image) && is_array($image['changes'] ?? null) ? $image['changes'] : null;
            } catch (\Throwable $e) {
                $changes = null;
            }
            try {
                if ($changes !== null) {
                    $actions = PushContent::postActions((string) $push['target'], $content, $changes);
                } elseif ($push['target'] === 'live' && function_exists('wp_cache_flush')) {
                    wp_cache_flush(); // ohne after.json wenigstens kein Object-Cache mit dem gepushten Stand
                }
            } catch (\Throwable $e) {
                $actions = [['step' => 'post_actions', 'ok' => false]];
            }
            if ($swept !== null) {
                $actions[] = ['step' => self::LEFT_CLEANUP, 'ok' => $swept];
            }
            $note['post_actions'] = $actions;
        }
        // Der Vermerk steht an der Einheit content. Hat der Satz kein Paket, trägt ihn die Einheit plugins;
        // was an der Liste der aktiven Plugins geschah, steht in jedem Fall dort (V12). plugins_back kommt aus
        // rescue.json – nicht authentisiert, deshalb nur in fester Form.
        $back = is_array($stored['plugins_back'] ?? null) ? ['back' => PushRescue::pluginLists($stored['plugins_back'], ['deactivated', 'reactivated'])] : [];
        $hasContent = false;
        foreach ((array) $push['units'] as $unit) {
            $hasContent = $hasContent || (is_array($unit) && ($unit['path'] ?? '') === PushContent::UNIT);
        }
        $units = [];
        foreach ((array) $push['units'] as $unit) {
            $path = is_array($unit) ? ($unit['path'] ?? '') : '';
            if ($path === PushContent::UNIT) {
                $units[] = array_merge($unit, $note);
            } elseif ($path === PushPlugins::UNIT) {
                $units[] = array_merge($unit, $hasContent ? ['via' => PushRescue::VIA_RESCUE] : $note, $back);
            } else {
                $units[] = $unit;
            }
        }
        Store::updatePush($pushId, ['units' => (string) wp_json_encode($units)]);
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
        if (self::content() !== '') {
            PushContent::expire(self::content() . '/' . Store::pushDirName(), $now);
        }
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
                    // Code zurück, Inhalte nicht: das Vorher-Abbild ist der einzige Weg zurück.
                    if ($record['status'] === PushRescue::ROLLED_BACK && PushRescue::contentOpen($record)) {
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
        $fields  = ['status' => $status, 'pruned' => 1];
        if ($push !== null && $push['finished'] === null) {
            $fields['finished'] = time();
        }
        // Erst das Protokoll, dann der Ordner: wer den Ordner (und mit ihm Datensatz und Sperrdatei) nicht
        // mehr findet, liest in der Zeile nie mehr den Stand von davor (confirm()). Stirbt PHP dazwischen,
        // bleibt der Ordner einer abgeschlossenen Zeile liegen; pruneOrphans() räumt ihn später weg, soweit
        // sein Datensatz zurückgerollt oder bestätigt sagt.
        Store::updatePush($pushId, $fields);
        if ($content !== '') {
            PushSwap::remove($content . '/' . Store::pushDirName() . '/' . $pushId);
        }
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
