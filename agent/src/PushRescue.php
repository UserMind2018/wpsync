<?php
namespace WpSync;

defined('ABSPATH') || defined('WPSYNC_RESCUE') || exit;

/**
 * Rollback eines Pushs ohne WordPress (Spec Stufe 2, 5.5; Content-Push P3 §4). Pro Push liegt im
 * Arbeitsordner eine rescue.json mit den Tausch-Paaren und dem Hash eines Schlüssels, den nur
 * die CLI aus dem Pairing-Secret ableiten kann. Die Datei ist die Wahrheit über den Tausch;
 * die Tabelle wpsync_pushes übernimmt ihren Status.
 *
 * Reihenfolge der Rücknahme: DB → Code → Uploads. Die Datenbank fasst diese Klasse nicht selbst
 * an: hat der Push einen DB-Anteil und verlangt der Aufrufer ihn (content=1), lädt sie nach der
 * Schlüsselprüfung RescueContent – vorher wird nichts geladen, kein Umschlag gelesen, keine
 * Verbindung geöffnet. Neben rescue.json liegen je Push
 *   rescue.sealed   der versiegelte Umschlag mit den Verbindungsdaten (RescueSeal)
 *   rescue.lock     Sperrdatei: rescue.php, die Rücknahme über den Agent und der Commit schliessen sich aus (R10)
 *   rescue.tries    Fehlversuche mit falschem Schlüssel – nie in rescue.json: wer den Schlüssel nicht
 *                   hat, schreibt nicht in den Datensatz, an dem die Rücknahme hängt
 * und im Arbeitsordner rescue.pending, sobald rescue.php einen Push zurückgenommen hat: der Agent
 * holt dann die Nacharbeiten nach, wenn WordPress wieder lädt (§8).
 */
final class PushRescue
{
    public const COMMITTED   = 'committed';
    public const CONFIRMED   = 'confirmed';
    public const ROLLED_BACK = 'rolled_back';

    public const MAX_ATTEMPTS = 5;
    public const LOCK_SECONDS = 600;
    public const ID           = '/^p_[0-9]{8}_[a-f0-9]{12}\z/';
    /** Ordner einer Staging-Kopie im Webroot – wie StagingGuard::DIR_RE, das rescue.php nicht lädt. */
    public const STAGING_DIR  = '/^wpsync-staging-[a-f0-9]{12}\z/';
    /** Name eines Arbeitsordners in wp-content, wie Store::pushDirName() ihn vergibt (und jeder ältere mit diesem Anfang). */
    public const WORK_DIR     = '/^wpsync-push-/';
    /** Warnung der Rücknahme: eine hinzugefügte Datei wurde seit dem Push geändert und bleibt (§8.4). */
    public const UPLOAD_CHANGED = 'upload_changed_since_push';
    /**
     * Warnung der Rücknahme ohne WordPress (Spec Content-Push §7.6, C7): Code und Uploads sind
     * zurück, die Inhalte nicht – sie nimmt nur der Agent zurück (wpsync rollback <id>).
     */
    public const CONTENT_NOT_ROLLED_BACK = 'content_not_rolled_back';

    /**
     * Warnung von confirm: rescue.php hatte Code und Uploads zurückgenommen, die Inhalte des Pushs
     * bleiben bewusst stehen – der Push ist als zurückgerollt abgeschlossen.
     */
    public const CONTENT_KEPT = 'content_kept';

    /** Stand des DB-Anteils in rescue.json: steht vor START TRANSACTION, angewandt, zurückgenommen. */
    public const CONTENT_PENDING = 'pending';
    public const CONTENT_APPLIED = 'applied';
    public const CONTENT_DONE    = 'rolled_back';

    /**
     * Warnung der Rücknahme ohne WordPress (Spec Content-Push P3 R15): an einem vom Push eingefügten
     * Objekt hing etwas, das nicht vom Push stammt – es blieb stehen (content.left).
     */
    public const CONTENT_LEFT = 'content_left_extra';

    /**
     * Warnung der Rücknahme ohne WordPress (Spec Content-Push P4 A18): der DB-Anteil des Pushs blieb
     * stehen – und mit ihm sein Plugin-Zustand. Die Liste active_plugins trägt noch den Stand des
     * Pushs, während Code und Uploads zurück sind. Steht neben content_not_rolled_back.
     */
    public const PLUGINS_NOT_RESTORED = 'plugins_not_restored';

    /**
     * Eintrag von active_plugins: <slug>/<pfad>.php – der Slug wie eine Einheit, der Pfad ohne Steuerzeichen
     * und Backslash. Die EINE Regel des Agents (pluginEntry()): ContentPlugins::valid() ist dieselbe, damit
     * alles, was geschaltet wird, auch genannt werden kann (Security-Review P4 S6).
     */
    private const PLUGIN_ENTRY = '#^[A-Za-z0-9][A-Za-z0-9._-]*/[^\x00-\x1f\x7f\\\\]{1,200}\.php\z#';

    /** content.via in rescue.json: rescue.php hat den DB-Anteil abgeschlossen. */
    public const VIA_RESCUE = 'rescue';
    /** content.post: Nacharbeiten stehen aus bzw. sind nachgeholt (P3 §8). */
    public const POST_PENDING = 'pending';
    public const POST_DONE    = 'done';
    /** content.cache: persistenter Object-Cache (P3 §7.5). */
    public const CACHE_NONE    = 'none';
    public const CACHE_STALE   = 'stale';
    public const CACHE_FLUSHED = 'flushed';

    /** Dateien neben rescue.json im Ordner des Pushs. */
    public const LOCK_FILE  = 'rescue.lock';
    public const TRIES_FILE = 'rescue.tries';
    /** Marker im Arbeitsordner: rescue.php hat einen Push zurückgenommen (P3 §8.1). */
    public const PENDING_FILE = 'rescue.pending';

    /** So lange wartet der Cache-Schritt auf die Sperre des Pushs, bevor er ohne Marke antwortet (flushed()). */
    public const FLUSH_WAIT = 2.0;

    /**
     * So lange wartet, wer den Datensatz eines anderen Pushs ändert (supersede(), das Lösen in
     * rollback()), auf dessen Sperre. Ist sie dann noch belegt, bleibt jener Datensatz unberührt.
     */
    public const LINK_WAIT = 5.0;

    /** @var float|null für Tests: Wartezeit anstelle von LINK_WAIT */
    public static $linkWait = null;
    /** @var (callable(string): void)|null für Tests: läuft mit der Push-ID, unmittelbar bevor lock() eine Sperre nimmt */
    public static $onLock = null;

    /** Antwort von handle() auf action=cache: kein HTTP-Status – rescue.php leert jetzt den Cache und ruft flushed(). */
    public const FLUSH = 0;

    public static function newId(int $now): string
    {
        return 'p_' . gmdate('Ymd', $now) . '_' . bin2hex(random_bytes(6));
    }

    /** Der Server speichert nur sha256() davon – rescue.php braucht kein Secret. */
    public static function key(string $secret, string $pushId, string $salt): string
    {
        return hash_hmac('sha256', 'rescue:' . $pushId . ':' . $salt, $secret);
    }

    public static function file(string $workDir, string $pushId): string
    {
        return $workDir . '/' . $pushId . '/rescue.json';
    }

    /**
     * @param list<array{unit: string, target: string, snapshot: string|null, discard: string}> $pairs
     * @param array{added?: list<array{path: string, sha256: string}>, dirs?: list<string>}  $uploads Einheit
     *        uploads (Spec Content-Push §8.4): Dateien relativ zu uploads/, angelegte Ordner relativ zu wp-content
     * @param string|null $contentSha sha256 des Inhalts-Pakets, wenn der Push eins hat (§7.3)
     * @param bool        $plugins    der Push hat einen Plugin-Zustand (Spec Content-Push P4 §8.5)
     *
     * Hat der Push ein Paket oder einen Plugin-Zustand, hat er einen DB-Anteil: der Datensatz nennt ihn als
     * „pending“, bevor die Transaktion beginnt. sha256 ist null ohne Paket. content.plugins ({added, removed})
     * steht nur zur Auskunft da – für plugins_not_restored –; geschrieben wird nie daraus, die Rücknahme
     * liest allein das authentisierte Vorher-Abbild. Der Commit trägt die Einträge nach dem COMMIT ein.
     *
     * @param list<string> $switched Einheiten plugins/<slug>, die der Push schaltet (activate und deactivate): sie
     *        zählen für „überholt“ wie die getauschten (unitsOf(), Security-Review P4 S2). Das Feld fehlt ohne
     *        Plugin-Zustand – der Datensatz ist dann Byte für Byte wie vor P4
     */
    public static function write(string $workDir, string $pushId, string $keyHash, array $pairs, string $status, array $uploads = [], ?string $contentSha = null, bool $plugins = false, array $switched = []): void
    {
        $content = null;
        if ($contentSha !== null || $plugins) {
            $content = ['state' => self::CONTENT_PENDING, 'sha256' => $contentSha];
            if ($plugins) {
                $content['plugins'] = ['added' => [], 'removed' => []];
            }
        }
        self::save($workDir, ($switched === [] ? [] : ['switched' => array_values($switched)]) + [
            'push_id'       => $pushId,
            'key_hash'      => $keyHash,
            'pairs'         => $pairs,
            'uploads'       => $uploads + ['added' => [], 'dirs' => []],
            'content'       => $content,
            'status'        => $status,
            'superseded_by' => null,
            // Wann dieser Datensatz entstand: daran erkennt supersededBy() den späteren Push (NR-2).
            'committed_at'  => microtime(true),
            // Seit 0.8.0 ohne Bedeutung (Fehlversuche stehen in rescue.tries); die Felder bleiben für einen Agent 0.7.x.
            'attempts'      => 0,
            'locked_until'  => 0,
        ]);
    }

    /** @return array<string, mixed>|null */
    public static function read(string $workDir, string $pushId): ?array
    {
        if (preg_match(self::ID, $pushId) !== 1) {
            return null;
        }
        $raw    = @file_get_contents(self::file($workDir, $pushId));
        $record = $raw === false ? null : json_decode($raw, true);
        return is_array($record) && ($record['push_id'] ?? null) === $pushId && is_array($record['pairs'] ?? null) ? $record : null;
    }

    public static function setStatus(string $workDir, string $pushId, string $status): bool
    {
        $record = self::read($workDir, $pushId);
        if ($record === null) {
            return false;
        }
        $record['status'] = $status;
        self::save($workDir, $record);
        return true;
    }

    /** Stand des DB-Anteils nach COMMIT bzw. nach seiner Rücknahme; false, wenn der Push keinen hat. */
    public static function setContent(string $workDir, string $pushId, string $state): bool
    {
        $record = self::read($workDir, $pushId);
        if ($record === null || !is_array($record['content'] ?? null)) {
            return false;
        }
        $record['content']['state'] = $state;
        self::save($workDir, $record);
        return true;
    }

    /**
     * Weitere Felder des DB-Anteils (via, post, cache, error – P3 §7.4); null entfernt ein Feld.
     * false, wenn der Push keinen DB-Anteil hat.
     *
     * @param array<string, mixed> $fields
     */
    public static function setContentFields(string $workDir, string $pushId, array $fields): bool
    {
        $record = self::read($workDir, $pushId);
        if ($record === null || !is_array($record['content'] ?? null)) {
            return false;
        }
        foreach ($fields as $name => $value) {
            if ($value === null) {
                unset($record['content'][$name]);
            } else {
                $record['content'][$name] = $value;
            }
        }
        self::save($workDir, $record);
        return true;
    }

    /**
     * Nimmt die Sperre eines Pushs (R10): rescue.php und die Rücknahme über den Agent halten sie für
     * ihren ganzen Lauf, der Commit um „rescue.json erneut lesen → COMMIT → content.state = applied“.
     * Freigabe mit unlock() oder dem Ende des Prozesses.
     *
     * @param float $wait Sekunden, die auf eine belegte Sperre gewartet wird; 0: gar nicht
     * @return resource|null|false die Sperre; null: belegt; false: hier lässt sich nicht sperren (kein
     *         flock auf diesem Dateisystem, Ordner fehlt oder nicht beschreibbar) – dann nimmt rescue.php
     *         keine Inhalte zurück, und der Begin legt gar nicht erst einen Umschlag an
     */
    public static function lock(string $workDir, string $pushId, float $wait = 0.0)
    {
        if (preg_match(self::ID, $pushId) !== 1) {
            return false;
        }
        if (self::$onLock !== null) {
            (self::$onLock)($pushId);
        }
        $dir  = $workDir . '/' . $pushId;
        $file = $dir . '/' . self::LOCK_FILE;
        if (!is_dir($dir) || is_link($dir) || is_link($file)) {
            return false;
        }
        $handle = @fopen($file, 'c');
        if ($handle === false) {
            return false;
        }
        $until = microtime(true) + $wait;
        while (true) {
            $would = 0;
            if (@flock($handle, LOCK_EX | LOCK_NB, $would)) {
                return $handle;
            }
            if (!$would) {
                @fclose($handle);
                return false;
            }
            if (microtime(true) >= $until) {
                @fclose($handle);
                return null;
            }
            usleep(100000);
        }
    }

    /** @param resource|null|false $lock aus lock() */
    public static function unlock($lock): void
    {
        if (is_resource($lock)) {
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
    }

    /**
     * Fehlversuche mit falschem Schlüssel und das Ende der Sperre dafür (R9).
     *
     * @return array{attempts: int, locked_until: int}
     */
    public static function tries(string $workDir, string $pushId): array
    {
        $file = $workDir . '/' . $pushId . '/' . self::TRIES_FILE;
        $raw  = preg_match(self::ID, $pushId) === 1 && is_file($file) && !is_link($file) ? @file_get_contents($file) : false;
        $data = is_string($raw) ? json_decode($raw, true) : null;
        return ['attempts' => max(0, (int) ($data['attempts'] ?? 0)), 'locked_until' => max(0, (int) ($data['locked_until'] ?? 0))];
    }

    /** Liegt im Arbeitsordner der Marker einer Rücknahme durch rescue.php? */
    public static function pendingFile(string $workDir): string
    {
        return $workDir . '/' . self::PENDING_FILE;
    }

    /**
     * Hat der Push einen DB-Anteil, der noch nicht zurückgenommen ist? Dann braucht ihn der Agent
     * noch: sein Vorher-Abbild liegt im Arbeitsordner.
     *
     * @param array<string, mixed> $record
     */
    public static function contentOpen(array $record): bool
    {
        $content = $record['content'] ?? null;
        return is_array($content) && in_array($content['state'] ?? '', [self::CONTENT_PENDING, self::CONTENT_APPLIED], true);
    }

    /**
     * Die Einheiten, die ein Push angefasst hat: die getauschten (pairs) und die, deren Plugin er ein- oder
     * ausgeschaltet hat (switched, P4). Ein Satz nur aus --deactivate hat keine Paare – ohne das zweite
     * überholte er nie und würde nie überholt, und eine Rücknahme aktivierte Code, den ein späterer Push
     * gebracht hat (Security-Review P4 S2). rescue.json ist nicht authentisiert: nur Namen in ihrer Form.
     *
     * @param array<string, mixed> $record
     * @return list<string>
     */
    public static function unitsOf(array $record): array
    {
        $units = [];
        foreach ((array) ($record['pairs'] ?? []) as $pair) {
            if (is_array($pair) && is_string($pair['unit'] ?? null)) {
                $units[$pair['unit']] = true;
            }
        }
        foreach (is_array($record['switched'] ?? null) ? $record['switched'] : [] as $unit) {
            if (is_string($unit) && preg_match('#^plugins/[A-Za-z0-9][A-Za-z0-9._-]*\z#', $unit) === 1) {
                $units[$unit] = true;
            }
        }
        return array_map('strval', array_keys($units));
    }

    /**
     * Ältere, noch aktive Pushes derselben Einheiten lassen sich erst wieder zurückrollen, wenn
     * dieser hier zurückgerollt ist (U6). Jeder ihrer Datensätze wird nur unter seiner eigenen
     * Sperre geändert (amend()) – nie neben seiner Rücknahme oder seiner Bestätigung.
     *
     * @param list<string> $units
     */
    public static function supersede(string $workDir, string $pushId, array $units): void
    {
        foreach (self::others($workDir, $pushId) as $seen) {
            self::amend($workDir, (string) $seen['push_id'], static function (array $record) use ($pushId, $units): ?array {
                $active = in_array($record['status'], [self::COMMITTED, self::CONFIRMED], true) && $record['superseded_by'] === null;
                $shared = array_intersect($units, self::unitsOf($record)) !== [];
                if (!$active || !$shared) {
                    return null;
                }
                $record['superseded_by'] = $pushId;
                return $record;
            });
        }
    }

    /**
     * Der spätere Push, der diesen überholt hat (U6) – solange er noch getauscht ist: der späteste unter
     * allen, die noch stehen und eine Einheit mit diesem teilen (getauscht oder geschaltet). Ein Vermerk,
     * dessen Push inzwischen zurückgerollt ist oder dessen Datensatz fehlt (zurückgerollt und
     * aufgeräumt), gilt nicht mehr: er kann stehen geblieben sein, weil beim Lösen die Sperre dieses
     * Pushs belegt war (amend()), und sperrte ihn sonst auf Dauer.
     *
     * @param array<string, mixed> $record Datensatz des älteren Pushs
     */
    public static function supersededBy(string $workDir, array $record): ?string
    {
        // Gerechnet, nicht nur nachgeschlagen (Nach-Review NR-2): der Vermerk superseded_by hält einen einzigen
        // Nachfolger. Sperren muss jeder spätere Push, der noch steht und eine Einheit mit diesem teilt – nur
        // gelesen, ohne Sperre: ein Push, der eben erst entsteht, vermerkt sich über supersede() selbst.
        $mine  = self::unitsOf($record);
        $at    = self::committedAt($record);
        $found = null;
        $when  = 0.0;
        foreach (self::others($workDir, (string) ($record['push_id'] ?? '')) as $other) {
            $later = self::committedAt($other);
            if ($later === null || ($at !== null && ($later < $at || ($later === $at && strcmp((string) $other['push_id'], (string) $record['push_id']) <= 0)))) {
                continue; // ohne Zeit (Datensatz von vor 0.9.0) oder früher: dafür gilt nur der Vermerk unten
            }
            if (in_array($other['status'], [self::COMMITTED, self::CONFIRMED], true)) {
                $theirs = self::unitsOf($other);
            } elseif ($other['status'] === self::ROLLED_BACK && self::contentOpen($other)) {
                // rescue.php hat Code und Uploads zurückgenommen, der DB-Anteil steht noch: die Liste trägt
                // weiter den Stand dieses Pushs (NR-4) – er sperrt für die Einheiten, die er geschaltet hat.
                $theirs = self::unitsOf(['switched' => $other['switched'] ?? []]);
            } else {
                continue;
            }
            if (array_intersect($mine, $theirs) !== [] && ($found === null || $later > $when)) {
                $found = (string) $other['push_id'];
                $when  = $later;
            }
        }
        if ($found !== null) {
            return $found;
        }
        $by = $record['superseded_by'] ?? null;
        if (!is_string($by) || preg_match(self::ID, $by) !== 1) {
            return null;
        }
        $later = self::read($workDir, $by);
        return $later !== null && $later['status'] !== self::ROLLED_BACK ? $by : null;
    }

    /**
     * Wann der Commit eines Pushs seinen Datensatz angelegt hat (Unix-Zeit mit Bruchteil); null für einen
     * Datensatz von vor dieser Version. Auf einer Site läuft immer nur ein Push (Sperre push_lock) – die
     * Zeiten zweier Datensätze eines Arbeitsordners sind deshalb geordnet.
     *
     * @param array<string, mixed> $record
     */
    private static function committedAt(array $record): ?float
    {
        $at = $record['committed_at'] ?? null;
        return (is_int($at) || is_float($at)) && $at > 0 ? (float) $at : null;
    }

    /**
     * Ändert den Datensatz eines anderen Pushs: unter dessen Sperre, an dem Stand, der dort dann
     * steht – nicht an einer Kopie von vorher. Sonst überschriebe der eine Lauf, was ein anderer
     * (Rücknahme, confirm) eben geschrieben hat, oder legte den Datensatz eines schon aufgeräumten
     * Pushs neu an. Bleibt die Sperre belegt, geschieht nichts: der Lauf, der sie hält, schreibt
     * seinen Stand selbst – ein Vermerk superseded_by, der deshalb stehen bleibt, gilt nicht mehr,
     * sobald sein Push zurückgerollt ist (supersededBy()). Wo sich nicht sperren lässt (false), wie vor P3.
     *
     * @param callable(array<string, mixed>): (array<string, mixed>|null) $change der geänderte Datensatz; null: nichts zu tun
     */
    private static function amend(string $workDir, string $pushId, callable $change): void
    {
        $lock = self::lock($workDir, $pushId, self::$linkWait ?? self::LINK_WAIT);
        if ($lock === null) {
            return;
        }
        try {
            $record  = self::read($workDir, $pushId);
            $changed = $record === null ? null : $change($record);
            if ($changed !== null) {
                self::save($workDir, $changed);
            }
        } finally {
            self::unlock($lock);
        }
    }

    /**
     * wp-content von Live und – daneben im Webroot – das jeder Staging-Kopie (Spec 2b 5.8, V8).
     * Ohne glob: nur echte Ordner mit genau dem Namen, den der Agent vergibt, keine Symlinks.
     *
     * @return list<string>
     */
    public static function contentDirs(string $liveContent): array
    {
        clearstatcache(true);
        $live = rtrim(str_replace('\\', '/', $liveContent), '/');
        $dirs = [$live];
        $base = dirname($live);
        foreach ((array) @scandir($base) as $name) {
            if (!is_string($name) || preg_match(self::STAGING_DIR, $name) !== 1) {
                continue;
            }
            $content = $base . '/' . $name . '/wp-content';
            if (!is_link($base . '/' . $name) && !is_link($content) && is_dir($content)) {
                $dirs[] = $content;
            }
        }
        return $dirs;
    }

    /**
     * Die Arbeitsordner in einem wp-content (wpsync-push-<zufall>): nur echte Ordner – der Agent legt
     * sie so an –, nie ein Symlink. Ohne glob(): ein Pfad mit [ ] * ? wäre dort selbst ein Muster.
     *
     * @return list<string>
     */
    public static function workDirs(string $contentDir): array
    {
        $out = [];
        foreach (PushSwap::entries(rtrim(str_replace('\\', '/', $contentDir), '/'), self::WORK_DIR) as $dir) {
            if (!is_link($dir) && is_dir($dir)) {
                $out[] = $dir;
            }
        }
        return $out;
    }

    /**
     * Einstieg für rescue.php. Sucht den Push in wp-content von Live und der Staging-Kopie (V8);
     * jeder Datensatz wird nur gegen das wp-content geprüft, in dem er liegt.
     *
     * Aktionen: ping; rollback (mit content=1 samt Inhalten, R11); cache (R8). Bis zur bestandenen
     * Schlüsselprüfung wird nichts geladen, kein Umschlag gelesen und keine Verbindung geöffnet.
     * Ein richtiger Schlüssel gilt auch während der Sperre für falsche (R9).
     *
     * @param list<string>         $contentDirs
     * @param array<string, mixed> $post
     * @return array{0: int, 1: array<string, mixed>} HTTP-Status und JSON-Antwort – oder [FLUSH, [work, push_id]]:
     *         der Cache darf geleert werden, rescue.php tut es und antwortet mit flushed()
     */
    public static function handle(array $contentDirs, array $post, int $now): array
    {
        $action = $post['action'] ?? '';
        if ($action === 'ping') {
            return [200, ['ok' => true]];
        }
        $pushId = $post['push_id'] ?? '';
        $key    = $post['key'] ?? '';
        if (!in_array($action, ['rollback', 'cache'], true) || !is_string($pushId) || !is_string($key) || preg_match(self::ID, $pushId) !== 1) {
            return [400, ['ok' => false, 'error' => 'bad request']];
        }
        foreach ($contentDirs as $contentDir) {
            foreach (self::workDirs((string) $contentDir) as $workDir) {
                $record = self::read($workDir, $pushId);
                if ($record === null) {
                    continue;
                }
                if (!hash_equals((string) $record['key_hash'], hash('sha256', $key))) {
                    return self::wrongKey($workDir, $pushId, $now);
                }
                if ($action === 'cache') {
                    return self::cacheStep((string) $contentDir, $workDir, $pushId, $record);
                }
                // Notfallweg nur für den unbestätigten Push (U18): einen bestätigten rollt nur der Agent
                // zurück – per CLI bei offenem Push-Fenster oder im WP-Admin. rescue.php kennt kein Fenster.
                if ($record['status'] === self::CONFIRMED) {
                    return [409, ['ok' => false, 'error' => 'confirmed']];
                }
                // Was die Rücknahme des Codes ablehnte, vor Sperre und Datenbank: sonst gingen die Inhalte
                // zurück und der Code bliebe stehen.
                $by = $record['status'] === self::ROLLED_BACK ? null : self::supersededBy($workDir, $record);
                if ($by !== null) {
                    return [409, ['ok' => false, 'error' => 'superseded', 'by' => $by]];
                }
                $lock = self::lock($workDir, $pushId);
                if ($lock === null) {
                    return [423, ['ok' => false, 'error' => 'busy']];
                }
                try {
                    // Ohne Sperre (false) keine Datenbank: dann wie bisher nur Code und Uploads. Ob der Push
                    // inzwischen bestätigt ist, prüft rollback() selbst – an dem Datensatz, den es jetzt,
                    // unter der Sperre, liest (confirm nimmt dieselbe Sperre).
                    $content = ($post['content'] ?? '') === '1' ? ['key' => $key, 'locked' => $lock !== false] : null;
                    $answer  = self::rollback((string) $contentDir, $workDir, $pushId, $content);
                } finally {
                    self::unlock($lock);
                }
                if ($answer[0] === 200) {
                    // Welchen Push die Antwort meint: die CLI vergleicht es mit dem, den sie zurücknehmen wollte.
                    $answer[1]['push_id'] = $pushId;
                }
                if ($answer[0] === 200 && !is_link(self::pendingFile($workDir))) {
                    @touch(self::pendingFile($workDir)); // der Agent holt nach, was WordPress braucht (§8.1)
                }
                return $answer;
            }
        }
        return [404, ['ok' => false, 'error' => 'unknown push']];
    }

    /**
     * Nach action=cache: rescue.php hat WordPress mit SHORTINIT geladen und wp_cache_flush() gerufen.
     * Die Marke „flushed“ kommt nur unter der Sperre des Pushs in rescue.json – jedes Schreiben dort
     * liest und ersetzt den ganzen Datensatz, und der Agent schreibt beim Wiederanlauf denselben
     * (Push::sync()). Ist die Sperre nicht zu haben, ist der Cache trotzdem geleert: die Antwort
     * bleibt, die Marke fehlt, und ein weiterer Aufruf leerte ihn höchstens noch einmal.
     *
     * @param float $wait so lange wartet der Schritt auf die Sperre
     * @return array{0: int, 1: array<string, mixed>}
     */
    public static function flushed(string $workDir, string $pushId, bool $ok, float $wait = self::FLUSH_WAIT): array
    {
        if (!$ok) {
            return [500, ['ok' => false, 'error' => 'cache failed']];
        }
        $lock = self::lock($workDir, $pushId, $wait);
        if (is_resource($lock)) {
            try {
                $record = self::read($workDir, $pushId);
                if ($record !== null && is_array($record['content'] ?? null) && ($record['content']['cache'] ?? '') === self::CACHE_STALE) {
                    self::setContentFields($workDir, $pushId, ['cache' => self::CACHE_FLUSHED]);
                }
            } finally {
                self::unlock($lock);
            }
        }
        return [200, ['ok' => true, 'cache' => self::CACHE_FLUSHED]];
    }

    /**
     * Tauscht alle Paare eines Pushs zurück. Ohne Schlüsselprüfung und ohne die Sperre selbst zu
     * nehmen – beides leistet handle() bzw. die signierte REST-Route (Push::rollbackPush()).
     *
     * Einen bestätigten Push lehnt es selbst ab (409 confirmed, U18) – geprüft an dem Datensatz, den
     * es hier, unter der Sperre des Aufrufers, liest: confirm setzt den Status unter derselben Sperre,
     * ein confirm zwischen einer früheren Prüfung und diesem Aufruf wird also nie überschrieben.
     * Nur der Agent, der das Push-Fenster geprüft hat (oder die Admin-Seite), sagt $confirmed.
     *
     * @param array{key: string, locked: bool}|null $content nur von handle(): der Aufrufer will auch die
     *        Inhalte zurück (content=1) – mit dem Rescue-Key, der den Umschlag öffnet; locked: die Sperre
     *        des Pushs ist gehalten. null: wie der Agent – die Inhalte nimmt er vorher selbst zurück
     * @param bool $confirmed der Aufrufer darf auch einen bestätigten Push zurücknehmen
     * @return array{0: int, 1: array<string, mixed>}
     */
    public static function rollback(string $contentDir, string $workDir, string $pushId, ?array $content = null, bool $confirmed = false): array
    {
        $record = self::read($workDir, $pushId);
        if ($record === null) {
            return [404, ['ok' => false, 'error' => 'unknown push']];
        }
        if ($record['status'] === self::CONFIRMED && !$confirmed) {
            return [409, ['ok' => false, 'error' => 'confirmed']];
        }
        $want = $content !== null;
        // Ist alles zurück – oder nur der DB-Anteil offen, den dieser Aufruf nicht verlangt –, bleibt es dabei.
        if ($record['status'] === self::ROLLED_BACK && !($want && self::contentOpen($record))) {
            return [200, self::answer($record, $want, null, [])];
        }
        $by = $record['status'] === self::ROLLED_BACK ? null : self::supersededBy($workDir, $record);
        if ($by !== null) {
            return [409, ['ok' => false, 'error' => 'superseded', 'by' => $by]];
        }
        // Der Arbeitsordner und jeder Pfad des Datensatzes müssen in genau diesem wp-content liegen:
        // ein Datensatz der Staging-Kopie tauscht nichts auf Live und umgekehrt (Spec 2b 5.8).
        if (!self::confined($contentDir, $workDir . '/' . $pushId)) {
            return [409, ['ok' => false, 'error' => 'path outside wp-content']];
        }
        foreach ($record['pairs'] as $pair) {
            foreach ([$pair['target'], $pair['snapshot'], $pair['discard']] as $path) {
                if ($path !== null && !self::confined($contentDir, (string) $path)) {
                    return [409, ['ok' => false, 'error' => 'path outside wp-content']];
                }
            }
        }
        // DB zuerst (P3 §4.1 Nr. 6). Ihr Ausgang steht in rescue.json, bevor der Code zurückgeht:
        // scheitert danach ein Paar, überspringt die Wiederholung die Datenbank.
        $result = null;
        if ($want && self::contentOpen($record)) {
            $result = self::takeContentBack($contentDir, $workDir, $pushId, $record, $content);
            $record = self::read($workDir, $pushId);
            if ($record === null) {
                return [404, ['ok' => false, 'error' => 'unknown push']];
            }
        }
        if ($record['status'] === self::ROLLED_BACK) {
            return [200, self::answer($record, $want, $result, [])]; // Code und Uploads waren schon zurück
        }
        $failed = [];
        foreach (array_reverse($record['pairs']) as $pair) {
            if ($pair['snapshot'] !== null && !is_dir((string) $pair['snapshot']) && is_dir((string) $pair['target'])) {
                continue; // der Tausch brach vor diesem Paar ab – das Ziel ist noch der alte Stand
            }
            if (!is_dir(dirname((string) $pair['discard']))) {
                @mkdir(dirname((string) $pair['discard']), 0755, true);
            }
            if (!PushSwap::restore((string) $pair['target'], $pair['snapshot'], (string) $pair['discard'])) {
                $failed[] = (string) $pair['unit'];
            }
        }
        PushSwap::resetCaches();
        if ($failed !== []) {
            $body = ['ok' => false, 'error' => 'restore failed', 'units' => $failed];
            if ($want && is_array($record['content'] ?? null)) {
                // Damit die CLI sagen kann, ob die Inhalte schon zurück sind (§7.6).
                $body['content'] = ['state' => self::contentOpen($record) ? 'kept' : self::CONTENT_DONE];
            }
            return [500, $body];
        }
        // Uploads nach dem Code (Spec Content-Push §1, AC-144): nur, was der Push hinzugefügt hat.
        $kept             = self::removeUploads($contentDir, is_array($record['uploads'] ?? null) ? $record['uploads'] : []);
        $record['status'] = self::ROLLED_BACK;
        self::save($workDir, $record);
        foreach (self::others($workDir, $pushId) as $seen) {
            if ($seen['superseded_by'] !== $pushId) {
                continue;
            }
            self::amend($workDir, (string) $seen['push_id'], static function (array $other) use ($pushId): ?array {
                if ($other['superseded_by'] !== $pushId) {
                    return null;
                }
                $other['superseded_by'] = null;
                return $other;
            });
        }
        return [200, self::answer($record, $want, $result, $kept)];
    }

    /**
     * Schritt 6 (P3 §7.2): die Inhalte, über RescueContent – das hier und erst hier geladen wird. Der
     * Ausgang steht danach in rescue.json. Steht der DB-Anteil auf „pending“ und gibt es kein
     * Vorher-Abbild, wurde nie geschrieben (§7.1): dann ist nichts zu tun, ohne Umschlag und ohne
     * Verbindung – ein Commit, der noch läuft, lehnt an seiner Naht ab. Auch das nur unter der
     * Sperre des Pushs: ohne sie bleibt der DB-Anteil offen (rescue_db_unavailable).
     *
     * @param array<string, mixed>              $record
     * @param array{key: string, locked: bool} $content
     * @return array<string, mixed> state: rolled_back, nothing oder kept (mit error)
     */
    private static function takeContentBack(string $contentDir, string $workDir, string $pushId, array $record, array $content): array
    {
        $before = $workDir . '/' . $pushId . '/content/before.json';
        clearstatcache(true, $before);
        if (empty($content['locked'])) {
            // Ohne Sperre könnte ein laufender Commit die Inhalte nach der Rücknahme festschreiben (R10) –
            // auch den, der sein Vorher-Abbild eben erst schreibt: keine Abkürzung ohne Sperre.
            $result = ['state' => 'kept', 'wrote' => false, 'error' => ['code' => 'rescue_db_unavailable']];
        } elseif (($record['content']['state'] ?? '') === self::CONTENT_PENDING && !file_exists($before) && !is_link($before)) {
            $result = ['state' => 'nothing', 'wrote' => false];
        } elseif (!class_exists(__NAMESPACE__ . '\\RescueContent', false) && !is_file(__DIR__ . '/RescueContent.php')) {
            // Ein halb aktualisierter Agent: lieber Code und Uploads zurück als ein Fatal ohne Antwort.
            $result = ['state' => 'kept', 'wrote' => false, 'error' => ['code' => 'rescue_db_unavailable']];
        } else {
            require_once __DIR__ . '/RescueContent.php';
            RescueContent::load();
            // Nur wenn der Agent „applied“ noch vermerkt hat, steht fest, dass der COMMIT des Pushs ankam (P4, V1).
            $result = RescueContent::run($contentDir, $workDir, $pushId, (string) $content['key'], ($record['content']['state'] ?? '') === self::CONTENT_APPLIED);
        }
        if ($result['state'] === 'kept') {
            self::setContentFields($workDir, $pushId, ['error' => (string) ($result['error']['code'] ?? 'content_failed')]);
            return $result;
        }
        $live   = preg_match(self::STAGING_DIR, basename(dirname(rtrim(str_replace('\\', '/', $contentDir), '/')))) !== 1;
        $fields = ['state' => self::CONTENT_DONE, 'via' => self::VIA_RESCUE, 'error' => null];
        if (!empty($result['wrote'])) {
            $fields['post'] = self::POST_PENDING;
            // Ein persistenter Object-Cache hält den gepushten Stand (alloptions) – nur Live hat ein Drop-in (§7.5).
            $fields['cache'] = $live && is_file($contentDir . '/object-cache.php') ? self::CACHE_STALE : self::CACHE_NONE;
        }
        if (!empty($result['left'])) {
            $fields['left']       = $result['left'];
            $fields['left_total'] = (int) ($result['left_total'] ?? count($result['left']));
        }
        if (is_array($result['plugins'] ?? null)) {
            // Was an der Liste geändert wurde: daraus antwortet eine Wiederholung gleich (AC-166).
            $fields['plugins_back'] = $result['plugins'];
        }
        self::setContentFields($workDir, $pushId, $fields);
        // Der Umschlag hat ausgedient (R14) – erst jetzt: stirbt PHP davor, findet die Wiederholung ihn noch.
        $sealed = $workDir . '/' . $pushId . '/rescue.sealed';
        if (is_link($sealed) || is_file($sealed)) {
            @unlink($sealed);
        }
        return $result;
    }

    /**
     * Die Antwort einer gelungenen Rücknahme (P3 §7.6, P4 §4.4). content nur, wenn der Push einen
     * DB-Anteil hat und der Aufrufer ihn verlangt hat; sonst wie bisher die Warnung, solange er offen
     * ist. Hatte der Push einen Plugin-Zustand: plugins – was an der Liste geändert wurde –, oder,
     * solange der DB-Anteil steht, plugins_not_restored mit den Einträgen des Pushs (A18).
     *
     * @param array<string, mixed>      $record
     * @param array<string, mixed>|null $result Ausgang von Schritt 6 in diesem Aufruf; null: lief nicht
     * @param list<string>              $kept   Uploads, die liegen bleiben
     * @return array<string, mixed>
     */
    private static function answer(array $record, bool $want, ?array $result, array $kept): array
    {
        $body     = ['ok' => true, 'status' => self::ROLLED_BACK];
        $warnings = $kept === [] ? [] : [self::UPLOAD_CHANGED];
        $stored   = $record['content'] ?? null;
        if (is_array($stored) && self::contentOpen($record)) {
            $warnings[] = self::CONTENT_NOT_ROLLED_BACK;
            if ($want) {
                $error           = is_array($result['error'] ?? null) ? $result['error'] : ['code' => is_string($stored['error'] ?? null) ? $stored['error'] : 'content_failed'];
                $body['content'] = ['state' => 'kept', 'error' => $error];
            }
            if (is_array($stored['plugins'] ?? null)) {
                $warnings[]                   = self::PLUGINS_NOT_RESTORED;
                $body['plugins_not_restored'] = self::pluginLists($stored['plugins'], ['added', 'removed']);
                if (($stored['state'] ?? '') === self::CONTENT_PENDING) {
                    // Der Commit hat „applied“ nie vermerkt: was er an der Liste geändert hat, steht hier nicht.
                    // Leere Listen heissen dann „unbekannt“, nicht „nichts“ (Security-Review P4 S6).
                    $body['plugins_not_restored']['unknown'] = true;
                }
            }
        } elseif (is_array($stored) && $want) {
            $content = ['state' => ($result['state'] ?? '') === 'nothing' ? 'nothing' : self::CONTENT_DONE];
            if (is_string($stored['cache'] ?? null)) {
                $content['cache'] = $stored['cache'];
            }
            if (is_array($stored['left'] ?? null) && $stored['left'] !== []) {
                $content['left']       = $stored['left'];
                $content['left_total'] = (int) ($stored['left_total'] ?? count($stored['left']));
                $warnings[]            = self::CONTENT_LEFT;
            }
            $body['content'] = $content;
            if (is_array($stored['plugins_back'] ?? null)) {
                $body['plugins'] = self::pluginLists($stored['plugins_back'], ['deactivated', 'reactivated']);
            }
        }
        if ($warnings !== []) {
            $body['warnings'] = $warnings;
        }
        if ($kept !== []) {
            $body['kept'] = $kept;
        }
        return $body;
    }

    /**
     * Listen von Plugin-Einträgen aus rescue.json für eine Antwort oder das Protokoll. Der Datensatz
     * ist nicht authentisiert (wer im Arbeitsordner schreiben kann, kann ihn ändern): hinaus geht nur,
     * was die Form eines Eintrags hat, je höchstens 100 – und geschrieben wird nie daraus. Weicht die Zahl
     * der Einträge einer Liste von dem ab, was hinausgeht, steht sie als <liste>_total daneben.
     *
     * @param array<string, mixed> $raw
     * @param list<string>         $sides Namen der Listen
     * @return array<string, list<string>|int>
     */
    public static function pluginLists(array $raw, array $sides): array
    {
        $out = [];
        foreach ($sides as $side) {
            $list       = is_array($raw[$side] ?? null) ? $raw[$side] : [];
            $out[$side] = [];
            foreach ($list as $entry) {
                if (count($out[$side]) < 100 && self::pluginEntry($entry)) {
                    $out[$side][] = $entry;
                }
            }
        }
        // Was nicht hinausging (keine Form, über 100), zählt: eine Liste ist nie still unvollständig.
        foreach ($sides as $side) {
            $total = count(is_array($raw[$side] ?? null) ? $raw[$side] : []);
            if ($total !== count($out[$side])) {
                $out[$side . '_total'] = min($total, 1 << 20);
            }
        }
        return $out;
    }

    /**
     * Hat ein Eintrag die Form, die der Agent schaltet und nennt? <slug>/<pfad>.php, höchstens 255 Bytes,
     * gültiges UTF-8, kein leeres Segment, kein „.“ und kein „..“. Ohne eine weitere Klasse – diese hier
     * ist geladen, bevor rescue.php einen Schlüssel geprüft hat.
     *
     * @param mixed $entry
     */
    public static function pluginEntry($entry): bool
    {
        if (!is_string($entry) || strlen($entry) > 255 || preg_match('//u', $entry) !== 1 || preg_match(self::PLUGIN_ENTRY, $entry) !== 1) {
            return false;
        }
        foreach (explode('/', $entry) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }
        return true;
    }

    /**
     * Falscher Schlüssel (R9): zählt bis zur Sperre – in rescue.tries, nie in rescue.json. In der
     * Sperre 429, ohne zu zählen.
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private static function wrongKey(string $workDir, string $pushId, int $now): array
    {
        $tries = self::tries($workDir, $pushId);
        if ($tries['locked_until'] > $now) {
            return [429, ['ok' => false, 'error' => 'locked']];
        }
        $tries['attempts']++;
        if ($tries['attempts'] >= self::MAX_ATTEMPTS) {
            $tries = ['attempts' => 0, 'locked_until' => $now + self::LOCK_SECONDS];
        }
        $file = $workDir . '/' . $pushId . '/' . self::TRIES_FILE;
        $tmp  = $file . '.tmp';
        if (!is_link($file) && !is_link($tmp) && @file_put_contents($tmp, (string) json_encode($tries)) !== false) {
            @rename($tmp, $file);
        }
        return [403, ['ok' => false, 'error' => 'wrong key']];
    }

    /**
     * action=cache (R8): nur mit richtigem Schlüssel (geprüft), nur für Live, nur wenn der Push ganz
     * zurück ist – der Code also wieder der alte – und rescue.php Zeilen zurückgeschrieben hat,
     * während ein Object-Cache-Drop-in lag.
     *
     * @param array<string, mixed> $record
     * @return array{0: int, 1: array<string, mixed>}
     */
    private static function cacheStep(string $contentDir, string $workDir, string $pushId, array $record): array
    {
        $content = $record['content'] ?? null;
        $live    = preg_match(self::STAGING_DIR, basename(dirname(rtrim(str_replace('\\', '/', $contentDir), '/')))) !== 1;
        if (!$live || $record['status'] !== self::ROLLED_BACK || !is_array($content) || ($content['cache'] ?? '') !== self::CACHE_STALE) {
            return [409, ['ok' => false, 'error' => 'nothing to flush']];
        }
        return [self::FLUSH, ['work' => $workDir, 'push_id' => $pushId]];
    }

    /**
     * Nimmt die Uploads eines Pushs zurück (Spec Content-Push §8.4) – auch für rescue.php, ohne
     * WordPress. Löscht nur Dateien, deren sha256 noch dem hinzugefügten entspricht, und vom Push
     * angelegte Ordner, wenn sie leer sind. Pfade ausserhalb von uploads/ oder mit einem Symlink
     * auf dem Weg fasst es nie an.
     *
     * @param array<string, mixed> $uploads ['added' => [['path' => …, 'sha256' => …]], 'dirs' => […]]
     * @return list<string> Dateien relativ zu uploads/, die seither geändert sind (oder sich nicht
     *                      löschen liessen) und liegen bleiben
     */
    public static function removeUploads(string $contentDir, array $uploads): array
    {
        clearstatcache(true);
        $root = rtrim(str_replace('\\', '/', (string) realpath($contentDir)), '/');
        if ($root === '') {
            return [];
        }
        $kept = [];
        foreach ((array) ($uploads['added'] ?? []) as $file) {
            $rel  = is_array($file) && is_string($file['path'] ?? null) ? $file['path'] : '';
            $sha  = is_array($file) && is_string($file['sha256'] ?? null) ? $file['sha256'] : '';
            $full = $root . '/uploads/' . $rel;
            if (!self::uploadPath($rel) || !self::plainWay($root, 'uploads/' . $rel) || !self::confined($root, $full)) {
                continue;
            }
            if (!file_exists($full)) {
                continue; // nie angelegt – der Commit brach vorher ab
            }
            $same = is_file($full) && $sha !== '' && hash_equals($sha, (string) hash_file('sha256', $full));
            if (!$same || !@unlink($full)) {
                $kept[] = $rel;
            }
        }
        foreach (array_reverse((array) ($uploads['dirs'] ?? [])) as $dir) {
            if (!is_string($dir) || ($dir !== 'uploads' && (strpos($dir, 'uploads/') !== 0 || !self::uploadPath(substr($dir, 8))))) {
                continue;
            }
            $full = $root . '/' . $dir;
            if (self::plainWay($root, $dir) && self::confined($root, $full) && is_dir($full)) {
                @rmdir($full); // nur, wenn leer
            }
        }
        clearstatcache(true);
        return $kept;
    }

    /** Pfad relativ zu uploads/, wie ihn der Agent in rescue.json schreibt. */
    private static function uploadPath(string $rel): bool
    {
        if ($rel === '' || strlen($rel) > 1024 || strpbrk($rel, "\\\0") !== false) {
            return false;
        }
        foreach (explode('/', $rel) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }
        return true;
    }

    /** Kein Symlink auf dem Weg von wp-content zu $rel, $rel selbst eingeschlossen. */
    private static function plainWay(string $root, string $rel): bool
    {
        $path = $root;
        foreach (explode('/', $rel) as $segment) {
            $path .= '/' . $segment;
            if (is_link($path)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Liegt $path in diesem wp-content – auch aufgelöst? In einer Staging-Kopie darf auf dem Weg
     * kein Symlink liegen (StagingFiles kopiert keine). Live darf welche haben (2a), nur keinen,
     * der in eine Staging-Kopie führt.
     */
    public static function confined(string $contentDir, string $path): bool
    {
        // Auch der realpath-Cache: ein PHP-FPM-Prozess merkt sich aufgelöste Pfade über Requests hinweg
        // und sähe einen eben erst gesetzten Symlink sonst nicht.
        clearstatcache(true);
        $root = rtrim(str_replace('\\', '/', (string) realpath($contentDir)), '/');
        $path = str_replace('\\', '/', $path);
        if (!self::inside($root, $path)) {
            return false;
        }
        $dir = dirname($path);
        while (strlen($dir) > strlen($root) && !file_exists($dir)) {
            $dir = dirname($dir);
        }
        $real = realpath($dir);
        if ($real === false) {
            return false;
        }
        $real = rtrim(str_replace('\\', '/', $real), '/');
        if (preg_match(self::STAGING_DIR, basename(dirname($root))) === 1) {
            return $real === $dir;
        }
        return strpos($real . '/', dirname($root) . '/wpsync-staging-') !== 0;
    }

    private static function inside(string $root, string $path): bool
    {
        $path = str_replace('\\', '/', $path);
        return $root !== '' && strpos($path, $root . '/') === 0 && !in_array('..', explode('/', $path), true);
    }

    /** @return list<array<string, mixed>> alle lesbaren Datensätze ausser $pushId */
    private static function others(string $workDir, string $pushId): array
    {
        $out = [];
        foreach ((array) @scandir($workDir) as $name) {
            if (is_string($name) && $name !== $pushId) {
                $record = self::read($workDir, $name);
                if ($record !== null) {
                    $out[] = $record;
                }
            }
        }
        return $out;
    }

    /** @param array<string, mixed> $record */
    private static function save(string $workDir, array $record): void
    {
        $file = self::file($workDir, (string) $record['push_id']);
        if (!is_dir(dirname($file))) {
            @mkdir(dirname($file), 0755, true);
        }
        $tmp = $file . '.tmp';
        if (@file_put_contents($tmp, (string) json_encode($record)) === false || !@rename($tmp, $file)) {
            throw new \RuntimeException('cannot write the rescue record');
        }
    }
}
