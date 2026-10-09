<?php
namespace WpSync;

defined('ABSPATH') || exit;

/**
 * Einheit „uploads“ eines Pushs (Spec Content-Push §8): neue Dateien unter wp-content/uploads/
 * des Ziels – nur hinzufügen, nie ersetzen, nie löschen. Pfade sind relativ zu uploads/ und mit
 * „/“ getrennt. Kein Verzeichnistausch: jede Datei kommt einzeln per rename aus dem
 * Arbeitsordner. Die Rücknahme – auch ohne WordPress – übernimmt PushRescue::removeUploads().
 */
final class PushUploads
{
    public const UNIT = 'uploads';
    /** Höchstens so viele Dateien je Push – Manifest und Hash-Prüfung bleiben ein Request. */
    public const MAX_FILES = 5000;
    /** Code einer RuntimeException: am Pfad liegt inzwischen etwas (→ wpsync_upload_exists). */
    public const EXISTS = 409;
    /**
     * Ausführbares und aktive Typen (SVG, HTML, XML, JavaScript – Stored XSS), egal was WordPress
     * per upload_mimes erlaubt – auch als mittlere Endung (bild.php.jpg, bild.html.jpg).
     */
    private const BLOCKED = '/\.(php[0-9]?|phtml|phar|pht|phps|svgz?|x?html?|shtml|xml|m?js)(\.|\z)/i';

    /** Pfad relativ zu uploads/: kein Ausbruch, keine VCS-, Staging- oder Push-Arbeitsordner. */
    public static function validFile(string $rel): bool
    {
        if (!PushUnits::validFile(self::UNIT, $rel)) {
            return false;
        }
        foreach (explode('/', strtolower($rel)) as $segment) {
            if (strpos($segment, Excludes::STAGING_DIR_PREFIX) === 0 || strpos($segment, 'wpsync-push-') === 0) {
                return false;
            }
        }
        return true;
    }

    /**
     * Fester Teil der Typ-Sperre (§8.3), ohne WordPress: ausführbare Endungen, versteckte Dateien
     * (.htaccess, .user.ini) und was auch der Pull nie liefert (Logs, Dumps, Zugangsdaten).
     */
    public static function blockedName(string $rel): bool
    {
        $name = basename($rel);
        return $name === '' || $name[0] === '.' || preg_match(self::BLOCKED, $name) === 1
            || Excludes::path(self::UNIT . '/' . $rel, 0) !== null;
    }

    /**
     * Erlaubt WordPress auf dem Ziel diese Endung? Der Begin kennt nur den Namen. Ein Name, den
     * sanitize_file_name() ändern würde, zählt als nicht erlaubt – vor allem versteckte mittlere
     * Endungen (bild.cgi.png, bild.shtml.jpg), die Apache mit AddHandler ausführt; WordPress' eigener
     * Upload hängt dort ein „_“ an.
     */
    public static function allowedName(string $rel): bool
    {
        $name = basename($rel);
        if (sanitize_file_name($name) !== $name) {
            return false;
        }
        $check = wp_check_filetype($name, get_allowed_mime_types());
        return !empty($check['ext']) && !empty($check['type']);
    }

    /** Nach dem Upload: passt der Inhalt zur Endung? Ein falsch benanntes Bild zählt als falsch. */
    public static function allowedContent(string $file, string $rel): bool
    {
        $check = wp_check_filetype_and_ext($file, basename($rel), get_allowed_mime_types());
        return !empty($check['ext']) && !empty($check['type']) && empty($check['proper_filename']);
    }

    /**
     * uploads/ liegt direkt in wp-content und ist kein Symlink; auf Live ist es genau der Ordner,
     * den WordPress benutzt (kein UPLOADS, kein abweichendes upload_path, kein Multisite). Fehlt der
     * Ordner (frische Staging-Kopie), legt place() ihn an.
     *
     * @return true|\WP_Error
     */
    public static function layout(string $target, string $content)
    {
        $dir = $content . '/' . self::UNIT;
        if (is_link($dir) || (file_exists($dir) && !is_dir($dir))) {
            return new \WP_Error('wpsync_upload_layout', 'wp-content/uploads ist ein symbolischer Link oder kein Ordner – Uploads lassen sich hier nicht pushen.', ['status' => 409]);
        }
        if ($target !== 'live') {
            return true;
        }
        $base = (string) (wp_upload_dir(null, false)['basedir'] ?? '');
        $base = rtrim(wp_normalize_path(is_dir($base) ? (string) realpath($base) : $base), '/');
        if (is_multisite() || $base !== $dir) {
            return new \WP_Error('wpsync_upload_layout', 'WordPress legt Uploads hier nicht unter wp-content/uploads ab (UPLOADS, upload_path oder Multisite) – Uploads lassen sich nicht pushen.', ['status' => 409]);
        }
        return true;
    }

    /**
     * Stand je Datei auf dem Ziel (§8.2): fehlt → need, gleicher Inhalt → same, sonst conflicts.
     * Ein Symlink oder Ordner am Pfad oder auf dem Weg dorthin zählt als anderer Inhalt.
     *
     * @param array<string, array{size: int, sha256: string, mtime: int}> $files
     * @return array{need: list<string>, same: list<string>, conflicts: list<string>}
     */
    public static function plan(string $content, array $files): array
    {
        $out = ['need' => [], 'same' => [], 'conflicts' => []];
        foreach ($files as $rel => $want) {
            $rel  = (string) $rel;
            $full = $content . '/' . self::UNIT . '/' . $rel;
            if (!self::realWay($content, $rel)) {
                $out['conflicts'][] = $rel;
                continue;
            }
            if (!file_exists($full) && !is_link($full)) {
                $out['need'][] = $rel;
                continue;
            }
            $same = is_file($full) && !is_link($full) && (int) filesize($full) === (int) $want['size']
                && hash_equals((string) $want['sha256'], (string) hash_file('sha256', $full));
            $out[$same ? 'same' : 'conflicts'][] = $rel;
        }
        return $out;
    }

    /**
     * Was der Commit anlegt – steht vor dem ersten rename in rescue.json (§8.4): Dateien relativ zu
     * uploads/ mit sha256, fehlende Ordner relativ zu wp-content, von oben nach unten.
     *
     * @param array<string, array{size: int, sha256: string, mtime: int}> $files
     * @param list<string>                                                $need
     * @return array{added: list<array{path: string, sha256: string}>, dirs: list<string>}
     * @throws \RuntimeException mit Code EXISTS, wenn am Pfad inzwischen etwas liegt
     */
    public static function prepare(string $content, array $files, array $need): array
    {
        $added = [];
        $dirs  = [];
        foreach ($need as $rel) {
            $rel = (string) $rel;
            if (!self::validFile($rel) || !isset($files[$rel])) {
                throw new \RuntimeException('upload path not usable: ' . $rel);
            }
            $full = $content . '/' . self::UNIT . '/' . $rel;
            if (!self::realWay($content, $rel) || file_exists($full) || is_link($full)) {
                throw new \RuntimeException('exists: ' . $rel, self::EXISTS);
            }
            $added[] = ['path' => $rel, 'sha256' => (string) $files[$rel]['sha256']];
            $step    = self::UNIT;
            $steps   = [$step];
            foreach (array_slice(explode('/', $rel), 0, -1) as $segment) {
                $step   .= '/' . $segment;
                $steps[] = $step;
            }
            foreach ($steps as $candidate) {
                if (!is_dir($content . '/' . $candidate) && !in_array($candidate, $dirs, true)) {
                    $dirs[] = $candidate;
                }
            }
        }
        return ['added' => $added, 'dirs' => $dirs];
    }

    /**
     * Legt fehlende Ordner an (Rechte wie der Elternordner, höchstens 0755) und benennt jede Datei aus
     * $stage an ihren Platz – nur, wenn sie dann noch fehlt. Dateien bekommen die Rechte ihres Ordners
     * ohne x, höchstens 0644.
     * $placed nennt danach, was liegt.
     *
     * @param array{added: list<array{path: string, sha256: string}>, dirs: list<string>} $uploads
     * @param list<array{path: string, sha256: string}>                                    $placed
     * @throws \RuntimeException mit Code EXISTS, wenn am Pfad inzwischen etwas liegt
     */
    public static function place(string $content, string $stage, array $uploads, array &$placed): void
    {
        foreach ($uploads['dirs'] as $dir) {
            $full = $content . '/' . $dir;
            if (is_dir($full)) {
                continue;
            }
            if (!@mkdir($full, 0755) && !is_dir($full)) {
                throw new \RuntimeException('cannot create ' . $dir);
            }
            @chmod($full, fileperms(dirname($full)) & 0755); // nie weiter als 0755, auch unter einem 0777-uploads/
        }
        foreach ($uploads['added'] as $file) {
            $to = $content . '/' . self::UNIT . '/' . $file['path'];
            clearstatcache(true, $to);
            if (file_exists($to) || is_link($to)) {
                throw new \RuntimeException('exists: ' . $file['path'], self::EXISTS);
            }
            if (!@rename($stage . '/' . $file['path'], $to)) {
                throw new \RuntimeException('cannot place ' . $file['path']);
            }
            @chmod($to, fileperms(dirname($to)) & 0644); // nie für Gruppe/alle schreibbar
            $placed[] = $file;
        }
        clearstatcache(true);
    }

    /**
     * Stempel der hinzugefügten Dateien für die Baseline der CLI – ein Folge-Pull überträgt sie
     * nicht noch einmal. Dateien, die nur gleich waren (same), gehören nicht dazu.
     *
     * @param array{added: list<array{path: string, sha256: string}>, dirs: list<string>} $uploads
     * @return array<string, array{size: int, mtime: int}>
     */
    public static function stamps(string $content, array $uploads): array
    {
        clearstatcache(true);
        $out = [];
        foreach ($uploads['added'] as $file) {
            $full = $content . '/' . self::UNIT . '/' . $file['path'];
            if (is_file($full) && !is_link($full)) {
                $out[$file['path']] = ['size' => (int) filesize($full), 'mtime' => (int) filemtime($full)];
            }
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /** Kein Symlink und keine Datei auf dem Weg von uploads/ zum Ordner der Datei. */
    private static function realWay(string $content, string $rel): bool
    {
        $dir = $content . '/' . self::UNIT;
        foreach (array_slice(explode('/', $rel), 0, -1) as $segment) {
            $dir .= '/' . $segment;
            if (is_link($dir) || (file_exists($dir) && !is_dir($dir))) {
                return false;
            }
        }
        return true;
    }
}
