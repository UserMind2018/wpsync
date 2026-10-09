<?php
namespace WpSync;

defined('ABSPATH') || defined('WPSYNC_RESCUE') || exit;

/**
 * Rollback eines Pushs ohne WordPress und ohne Datenbank (Spec Stufe 2, 5.5). Pro Push liegt im
 * Arbeitsordner eine rescue.json mit den Tausch-Paaren und dem Hash eines Schlüssels, den nur
 * die CLI aus dem Pairing-Secret ableiten kann. Die Datei ist die Wahrheit über den Tausch;
 * die Tabelle wpsync_pushes übernimmt ihren Status.
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
     * @param string|null $contentSha sha256 des Inhalts-Pakets, wenn der Push einen DB-Anteil hat (§7.3): der
     *        Datensatz nennt ihn als „pending“, bevor die Transaktion beginnt
     */
    public static function write(string $workDir, string $pushId, string $keyHash, array $pairs, string $status, array $uploads = [], ?string $contentSha = null): void
    {
        self::save($workDir, [
            'push_id'       => $pushId,
            'key_hash'      => $keyHash,
            'pairs'         => $pairs,
            'uploads'       => $uploads + ['added' => [], 'dirs' => []],
            'content'       => $contentSha === null ? null : ['state' => self::CONTENT_PENDING, 'sha256' => $contentSha],
            'status'        => $status,
            'superseded_by' => null,
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
     * Ältere, noch aktive Pushes derselben Einheiten lassen sich erst wieder zurückrollen, wenn
     * dieser hier zurückgerollt ist (U6).
     *
     * @param list<string> $units
     */
    public static function supersede(string $workDir, string $pushId, array $units): void
    {
        foreach (self::others($workDir, $pushId) as $record) {
            $active = in_array($record['status'], [self::COMMITTED, self::CONFIRMED], true) && $record['superseded_by'] === null;
            $shared = array_intersect($units, array_column($record['pairs'], 'unit')) !== [];
            if ($active && $shared) {
                $record['superseded_by'] = $pushId;
                self::save($workDir, $record);
            }
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
     * Einstieg für rescue.php. Sucht den Push in wp-content von Live und der Staging-Kopie (V8);
     * jeder Datensatz wird nur gegen das wp-content geprüft, in dem er liegt.
     *
     * @param list<string>         $contentDirs
     * @param array<string, mixed> $post
     * @return array{0: int, 1: array<string, mixed>} HTTP-Status und JSON-Antwort
     */
    public static function handle(array $contentDirs, array $post, int $now): array
    {
        $action = $post['action'] ?? '';
        if ($action === 'ping') {
            return [200, ['ok' => true]];
        }
        $pushId = $post['push_id'] ?? '';
        $key    = $post['key'] ?? '';
        if ($action !== 'rollback' || !is_string($pushId) || !is_string($key) || preg_match(self::ID, $pushId) !== 1) {
            return [400, ['ok' => false, 'error' => 'bad request']];
        }
        foreach ($contentDirs as $contentDir) {
            // glob() liefert bei einem Fehler false – (array) false wäre [false] und damit der Pfad ''.
            foreach (glob($contentDir . '/wpsync-push-*', GLOB_ONLYDIR) ?: [] as $workDir) {
                if (is_link((string) $workDir)) {
                    continue; // der Agent legt den Arbeitsordner als echten Ordner an
                }
                $record = self::read((string) $workDir, $pushId);
                if ($record === null) {
                    continue;
                }
                if ((int) $record['locked_until'] > $now) {
                    return [429, ['ok' => false, 'error' => 'locked']];
                }
                if (!hash_equals((string) $record['key_hash'], hash('sha256', $key))) {
                    $record['attempts'] = (int) $record['attempts'] + 1;
                    if ($record['attempts'] >= self::MAX_ATTEMPTS) {
                        $record['attempts']     = 0;
                        $record['locked_until'] = $now + self::LOCK_SECONDS;
                    }
                    self::save((string) $workDir, $record);
                    return [403, ['ok' => false, 'error' => 'wrong key']];
                }
                // Notfallweg nur für den unbestätigten Push (U18): einen bestätigten rollt nur der Agent
                // zurück – per CLI bei offenem Push-Fenster oder im WP-Admin. rescue.php kennt kein Fenster.
                if ($record['status'] === self::CONFIRMED) {
                    return [409, ['ok' => false, 'error' => 'confirmed']];
                }
                return self::rollback((string) $contentDir, (string) $workDir, $pushId);
            }
        }
        return [404, ['ok' => false, 'error' => 'unknown push']];
    }

    /**
     * Tauscht alle Paare eines Pushs zurück. Ohne Schlüsselprüfung – die leistet handle() bzw.
     * die signierte REST-Route.
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    public static function rollback(string $contentDir, string $workDir, string $pushId): array
    {
        $record = self::read($workDir, $pushId);
        if ($record === null) {
            return [404, ['ok' => false, 'error' => 'unknown push']];
        }
        if ($record['status'] === self::ROLLED_BACK) {
            return [200, ['ok' => true, 'status' => self::ROLLED_BACK] + (self::contentOpen($record) ? ['warnings' => [self::CONTENT_NOT_ROLLED_BACK]] : [])];
        }
        if ($record['superseded_by'] !== null) {
            return [409, ['ok' => false, 'error' => 'superseded', 'by' => $record['superseded_by']]];
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
            return [500, ['ok' => false, 'error' => 'restore failed', 'units' => $failed]];
        }
        // Uploads nach dem Code (Spec Content-Push §1, AC-144): nur, was der Push hinzugefügt hat.
        $kept             = self::removeUploads($contentDir, is_array($record['uploads'] ?? null) ? $record['uploads'] : []);
        $record['status'] = self::ROLLED_BACK;
        self::save($workDir, $record);
        foreach (self::others($workDir, $pushId) as $other) {
            if ($other['superseded_by'] === $pushId) {
                $other['superseded_by'] = null;
                self::save($workDir, $other);
            }
        }
        $body = ['ok' => true, 'status' => self::ROLLED_BACK];
        if ($kept !== []) {
            $body['warnings'] = [self::UPLOAD_CHANGED];
            $body['kept']     = $kept;
        }
        // Hier gibt es weder WordPress noch die Datenbank: die Inhalte stehen noch (§7.6, bis P3).
        if (self::contentOpen($record)) {
            $body['warnings'][] = self::CONTENT_NOT_ROLLED_BACK;
        }
        return [200, $body];
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
