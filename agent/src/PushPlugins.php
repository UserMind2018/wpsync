<?php
namespace WpSync;

defined('ABSPATH') || exit;

/**
 * Plugin-Zustand eines Pushs, soweit er WordPress und das Dateisystem braucht (Spec Content-Push
 * P4 §4.2, §7.1, §7.2, §8.1): den Auftrag aus dem Request lesen, die Hauptdatei eines Plugins
 * auflösen, seinen Kopf lesen und gegen das Ziel prüfen, den Öffner des Push-Fensters prüfen. Die
 * Liste selbst – lesen, ändern, packen – liegt in ContentPlugins, weil rescue.php sie ohne
 * WordPress braucht.
 *
 * Aus dem Request kommen nur Namen von Einheiten und – als Behauptung für den Probelauf – die
 * ersten Bytes ihrer PHP-Dateien (plugin_heads). Die Köpfe werden gelesen, nie gespeichert, nie
 * ausgeführt; verbindlich ist im Commit die gebaute Datei (A10). Hier läuft nie Code eines
 * Plugins: kein include, kein Hook (A5).
 */
final class PushPlugins
{
    /** Name der Einheit im Protokoll der Pushes (V12) – keine Einheit eines Requests. */
    public const UNIT = 'plugins';
    /** Höchstens so viele Einheiten je Richtung, Köpfe je Einheit und Bytes je Kopf (§4.2). */
    public const MAX_UNITS  = 20;
    public const MAX_HEADS  = 5;
    public const HEAD_BYTES = 8192;

    /** Warnungen im Plan (§4.5). */
    public const REVIEW    = 'deactivation_review';
    public const UNCHECKED = 'requirements_unchecked';
    /** Gründe, aus denen eine Einheit auf der Kopie nicht aktiviert wird (A14, V9). */
    public const DISABLED_ON_STAGING = 'disabled_on_staging';
    public const REQUIRES_SKIPPED    = 'requires_skipped';

    private const UNIT_RE = '#^plugins/[A-Za-z0-9][A-Za-z0-9._-]*\z#';
    private const FILE_RE = '/^[A-Za-z0-9][A-Za-z0-9._-]*\.php\z/';
    /** Slug einer Abhängigkeit, wie WP_Plugin_Dependencies::sanitize_dependency_slugs() ihn verlangt. */
    private const DEP_RE = '/^[a-z0-9]+(-[a-z0-9]+)*$/mu';
    /** Felder des Kopfs, wie get_plugin_data() sie nennt. */
    private const FIELDS = ['name' => 'Plugin Name', 'version' => 'Version', 'requires_wp' => 'Requires at least', 'requires_php' => 'Requires PHP'];
    /** So viel liest der Plan von der Hauptdatei eines abzuschaltenden Plugins, um register_deactivation_hook zu finden. */
    private const HOOK_BYTES = 524288;

    public const NOT_ALLOWED_TEXT = 'Plugins schaltet ein Push nur, wenn ein Benutzer mit dem Recht activate_plugins das Push-Fenster im WP-Admin geöffnet hat – nicht per WP-CLI.';
    public const NO_ENVELOPE_TEXT = 'Ein Push, der Plugins schaltet, braucht die Notfall-Rücknahme ohne WordPress: ohne Umschlag (rescue.sealed) wird nichts getauscht.';
    public const ROLLBACK_NOT_ALLOWED_TEXT = 'Die Rücknahme dieses bestätigten Pushs schaltet Plugins: das geht nur, wenn ein Benutzer mit dem Recht activate_plugins das Push-Fenster im WP-Admin geöffnet hat oder ihn dort selbst zurückrollt – nicht per WP-CLI.';

    /** @var array{php: string, wp: string, multisite: bool}|null für Tests: das Ziel anstelle von site() */
    public static $site = null;
    /** @var (callable(int): bool)|null für Tests: darf dieser Benutzer Plugins schalten? */
    public static $can = null;

    /**
     * Der Auftrag eines Begin (§4.2): activate, deactivate und – für den Probelauf – plugin_heads.
     *
     * @param array<string, mixed> $params
     * @return array{activate: list<string>, deactivate: list<string>, heads: array<string, array<string, string>>}|null
     *         null: der Satz hat keinen Plugin-Zustand. heads: Einheit → Datei → die ersten Bytes, dekodiert
     * @throws ContentException plugins_invalid
     */
    public static function request(array $params): ?array
    {
        if (!array_key_exists('activate', $params) && !array_key_exists('deactivate', $params)) {
            return null;
        }
        $form  = 'activate und deactivate nennen je höchstens ' . self::MAX_UNITS . ' Einheiten plugins/<slug> – keine doppelt, keine in beiden, nie plugins/wpsync-agent.';
        $lists = [];
        $seen  = [];
        foreach (['activate', 'deactivate'] as $side) {
            $raw = $params[$side] ?? [];
            if (!is_array($raw) || count($raw) > self::MAX_UNITS) {
                throw self::refuse(ContentException::PLUGINS_INVALID, $form);
            }
            $lists[$side] = [];
            foreach ($raw as $unit) {
                if (!is_string($unit) || preg_match(self::UNIT_RE, $unit) !== 1 || !PushUnits::valid($unit) || isset($seen[strtolower($unit)])) {
                    throw self::refuse(ContentException::PLUGINS_INVALID, $form);
                }
                $seen[strtolower($unit)] = true;
                $lists[$side][]          = $unit;
            }
        }
        if ($lists['activate'] === [] && $lists['deactivate'] === []) {
            return null;
        }
        return $lists + ['heads' => self::heads($params['plugin_heads'] ?? null, $lists['activate'])];
    }

    /**
     * plugin_heads eines Requests: je Einheit aus activate höchstens MAX_HEADS Dateien direkt im
     * Ordner, der Kopf base64 und höchstens HEAD_BYTES gross.
     *
     * @param mixed        $raw
     * @param list<string> $activate
     * @return array<string, array<string, string>>
     * @throws ContentException plugins_invalid
     */
    private static function heads($raw, array $activate): array
    {
        if ($raw === null) {
            return [];
        }
        $form = 'plugin_heads nennt je Einheit aus activate höchstens ' . self::MAX_HEADS . ' Dateien direkt im Ordner – base64, je höchstens ' . self::HEAD_BYTES . ' Bytes.';
        if (!is_array($raw)) {
            throw self::refuse(ContentException::PLUGINS_INVALID, $form);
        }
        $out = [];
        foreach ($raw as $unit => $files) {
            if (!is_string($unit) || !in_array($unit, $activate, true) || !is_array($files) || count($files) > self::MAX_HEADS) {
                throw self::refuse(ContentException::PLUGINS_INVALID, $form);
            }
            $out[$unit] = [];
            foreach ($files as $file => $encoded) {
                $bytes = is_string($encoded) ? base64_decode($encoded, true) : false;
                if (!is_string($file) || preg_match(self::FILE_RE, $file) !== 1 || !is_string($bytes) || strlen($bytes) > self::HEAD_BYTES) {
                    throw self::refuse(ContentException::PLUGINS_INVALID, $form);
                }
                $out[$unit][$file] = $bytes;
            }
        }
        return $out;
    }

    /**
     * Der Kopf eines Plugins aus den ersten Bytes seiner Datei – die Regel von get_file_data() und
     * _cleanup_header_comment() des Core, auf den Bytes statt auf einem Pfad (§7.2): derselbe Code
     * liest im Probelauf den mitgeschickten Kopf und im Commit die gebaute Datei.
     *
     * @return array{name: string, version: string, requires_wp: string, requires_php: string, requires_plugins: list<string>}
     */
    public static function header(string $head): array
    {
        $head = str_replace("\r", "\n", (string) substr($head, 0, self::HEAD_BYTES));
        $read = static function (string $field) use ($head): string {
            if (preg_match('/^(?:[ \t]*<\?(?:php)?)?[ \t\/*#@]*' . preg_quote($field, '/') . ':(.*)$/mi', $head, $m) === 1 && $m[1]) {
                return trim((string) preg_replace('/\s*(?:\*\/|\?>).*/', '', $m[1]));
            }
            return '';
        };
        $out = [];
        foreach (self::FIELDS as $key => $field) {
            $out[$key] = $read($field);
        }
        $slugs = [];
        foreach (explode(',', $read('Requires Plugins')) as $slug) {
            $slug = trim($slug);
            if (preg_match(self::DEP_RE, $slug) === 1) {
                $slugs[$slug] = true;
            }
        }
        $out['requires_plugins'] = array_map('strval', array_keys($slugs));
        sort($out['requires_plugins']);
        return $out;
    }

    /**
     * Die Hauptdatei eines Plugins (§7.1): genau eine Datei direkt im Ordner, deren Kopf einen
     * Plugin-Namen trägt (wie get_plugins() des Core). Der Client nennt nie eine Datei, die zählt.
     *
     * @param array<string, string> $heads Datei → ihre ersten Bytes
     * @return array{file: string, header: array<string, mixed>}|array{why: string} why: no_plugin_file, ambiguous, file_name
     */
    public static function mainFile(array $heads): array
    {
        $found = [];
        foreach ($heads as $file => $bytes) {
            $header = self::header((string) $bytes);
            // Wie get_plugins(): empty( $plugin_data['Name'] ) – auch ein Name „0“ ist keiner.
            if ($header['name'] !== '' && $header['name'] !== '0') {
                $found[(string) $file] = $header;
            }
        }
        if ($found === []) {
            return ['why' => 'no_plugin_file'];
        }
        if (count($found) > 1) {
            return ['why' => 'ambiguous'];
        }
        $file = (string) array_keys($found)[0];
        if (preg_match(self::FILE_RE, $file) !== 1) {
            return ['why' => 'file_name'];
        }
        return ['file' => $file, 'header' => $found[$file]];
    }

    /**
     * Die Köpfe eines Verzeichnisses auf der Platte – im Commit das gebaute new/<n>/: jede *.php
     * direkt darin (keine Unterordner, keine versteckte, kein Symlink), ihre ersten HEAD_BYTES.
     *
     * @return array<string, string> Datei → Kopf
     */
    public static function built(string $dir): array
    {
        $out = [];
        foreach (PushSwap::entries($dir, '/^[^.].*\.php\z/') as $file) {
            if (is_link($file) || !is_file($file)) {
                continue;
            }
            $head = @file_get_contents($file, false, null, 0, self::HEAD_BYTES);
            if (is_string($head)) {
                $out[basename($file)] = $head;
            }
        }
        return $out;
    }

    /** Wie is_php_version_compatible() des Core, mit der Version des Ziels als Parameter. */
    public static function phpOk(string $required, string $php): bool
    {
        $required = trim($required);
        return $required === '' || $required === '0' || version_compare($php, $required, '>=');
    }

    /** Wie is_wp_version_compatible() des Core: Zusätze der installierten Version (-RC1, -beta…) fallen weg, ein „.0“ am Ende einer dreiteiligen Angabe ebenso. */
    public static function wpOk(string $required, string $wp): bool
    {
        $version  = explode('-', $wp)[0];
        $required = trim($required);
        if (substr_count($required, '.') > 1 && substr($required, -2) === '.0') {
            $required = (string) substr($required, 0, -2);
        }
        return $required === '' || $required === '0' || version_compare($version, $required, '>=');
    }

    /**
     * Was ein Kopf verlangt und das Ziel nicht hat (§7.2).
     *
     * @param array<string, mixed>                               $header aus header()
     * @param list<string>                                       $slugs  Ordner, die nach diesem Satz in der Liste des Ziels stehen
     * @param array{php: string, wp: string, multisite: bool} $site
     * @return list<array{why: string, needs: string, has: string}>
     */
    public static function requirements(array $header, array $slugs, array $site): array
    {
        $failed = [];
        if (!self::phpOk((string) $header['requires_php'], $site['php'])) {
            $failed[] = ['why' => 'requires_php', 'needs' => self::printable((string) $header['requires_php']), 'has' => $site['php']];
        }
        if (!self::wpOk((string) $header['requires_wp'], $site['wp'])) {
            $failed[] = ['why' => 'requires_wp', 'needs' => self::printable((string) $header['requires_wp']), 'has' => $site['wp']];
        }
        $missing = array_values(array_diff((array) $header['requires_plugins'], $slugs));
        if ($missing !== []) {
            $failed[] = ['why' => 'requires_plugins', 'needs' => implode(', ', $missing), 'has' => ''];
        }
        return $failed;
    }

    /**
     * PHP- und WordPress-Version des Ziels und ob es eine Multisite ist. Für die Kopie gilt dasselbe:
     * sie läuft mit dem Core und dem PHP von Live.
     *
     * @return array{php: string, wp: string, multisite: bool}
     */
    public static function site(): array
    {
        if (self::$site !== null) {
            return self::$site;
        }
        return [
            'php'       => PHP_VERSION,
            'wp'        => function_exists('get_bloginfo') ? (string) get_bloginfo('version') : '',
            'multisite' => function_exists('is_multisite') && is_multisite(),
        ];
    }

    /**
     * A12: wer das Push-Fenster geöffnet hat, muss existieren und Plugins schalten dürfen
     * (activate_plugins – WordPress kennt für das Deaktivieren kein eigenes Recht).
     */
    public static function allowed(?int $opener): bool
    {
        if ($opener === null || $opener < 1) {
            return false;
        }
        if (self::$can !== null) {
            return (bool) (self::$can)($opener);
        }
        return function_exists('get_userdata') && function_exists('user_can') && get_userdata($opener) !== false && user_can($opener, 'activate_plugins');
    }

    /**
     * Eine Ablehnung des Plugin-Zustands in der Form des Inhaltskanals (V5).
     *
     * @param list<array<string, string>> $plugins [{unit, why, needs?, has?}]
     * @param array<string, mixed>        $extra   weitere Felder der Antwort (detail)
     */
    public static function refuse(string $reason, string $message, array $plugins = [], array $extra = []): ContentException
    {
        return new ContentException($reason, $message, [], ['plugins' => array_values($plugins)] + $extra);
    }

    /** Ordner einer Einheit plugins/<slug>. */
    public static function slug(string $unit): string
    {
        return (string) substr($unit, 8);
    }

    /**
     * Text aus einem Plugin-Kopf für eine Antwort: ohne Steuerzeichen (auch C1 und Bidi), höchstens
     * 200 Zeichen; Umlaute bleiben. Die CLI säubert ihn noch einmal für das Terminal.
     */
    public static function printable(string $value): string
    {
        if (preg_match('//u', $value) !== 1) {
            $value = (string) preg_replace('/[^\x20-\x7e]/', '?', $value);
        }
        $value = (string) preg_replace('/[\x{00}-\x{1f}\x{7f}-\x{9f}\x{202a}-\x{202e}\x{2066}-\x{2069}]/u', '?', $value);
        return preg_match('/^.{0,200}/us', $value, $m) === 1 ? (string) $m[0] : '';
    }

    /**
     * Der Teil plugins der Antwort des Begin (§4.2) – im Probelauf wie im echten Begin, auch ohne
     * offenes Fenster: die Liste der aktiven Plugins nennt Rest::env() einem gekoppelten Gerät
     * ohnehin. Eine Ablehnung steht als error in der Antwort, ohne dass der Request scheitert.
     *
     * @param array{activate: list<string>, deactivate: list<string>, heads: array<string, array<string, string>>} $wish
     * @param list<string> $unitPaths Einheiten des Satzes, in der Reihenfolge des Requests
     * @param string       $name      live oder staging
     * @param string       $content   wp-content des Ziels
     * @return array{ok: bool, error: array<string, mixed>|null, activate: list<array<string, mixed>>, deactivate: list<array<string, mixed>>, warnings: list<string>, health_urls: list<string>}
     */
    public static function plan(array $wish, array $unitPaths, string $name, string $content): array
    {
        $out = ['ok' => false, 'error' => null, 'activate' => [], 'deactivate' => [], 'warnings' => [], 'health_urls' => []];
        try {
            $site = self::site();
            if ($site['multisite']) {
                throw self::refuse(ContentException::PLUGINS_UNSUPPORTED, 'Auf einer Multisite schaltet wpsync keine Plugins.');
            }
            $target = PushContent::target($name, $content);
            ContentState::innodb($target->store); // ohne InnoDB keine Transaktion – auch nicht für diese eine Zeile
            $seen = self::analyse($wish, $unitPaths, static function (string $unit) use ($wish): ?array {
                return $wish['heads'][$unit] ?? null;
            }, $name, $content, self::listOf($target), $site);
            $out['activate']   = $seen['activate'];
            $out['deactivate'] = $seen['deactivate'];
            $out['warnings']   = $seen['warnings'];
            // A13: eine Seite im Admin-Kontext für den Health-Check – in der Kopie unter ihrer Adresse (V11).
            $out['health_urls'] = [rtrim($name === 'staging' ? $target->url : $target->siteurl, '/') . '/wp-admin/admin-ajax.php'];
            if ($seen['refused'] !== []) {
                throw self::refusedBy($seen['refused']);
            }
            $out['ok'] = true;
        } catch (ContentException $e) {
            $out['error'] = $e->toArray();
        } catch (\Throwable $e) {
            // Kein vorgesehener Grund: auch der steht in der Antwort – ohne zu sagen, was es war.
            $out['error'] = (new ContentException(ContentException::FAILED, 'Der Plugin-Zustand liess sich nicht prüfen.'))->toArray();
        }
        return $out;
    }

    /**
     * Die verbindliche Prüfung im Commit, vor dem Tausch (§8.1 Nr. 2): Öffner, Umschlag, Hauptdatei
     * und Kopf – am gebauten Verzeichnis, nicht am mitgeschickten Kopf (A10).
     *
     * @param array{activate: list<string>, deactivate: list<string>} $wish
     * @param list<string> $unitPaths Einheiten des Plans; ihr Index ist der Ordner unter $newDir
     * @param string       $newDir    <arbeitsordner>/<push>/new
     * @param string       $sealed    Datei des Umschlags (rescue.sealed)
     * @return array{add: array<string, string>, skipped: list<array{unit: string, why: string}>, drop: list<string>}
     *         add: Einheit → Eintrag <slug>/<hauptdatei>.php; drop: Ordner der abzuschaltenden Plugins
     * @throws ContentException plugins_not_allowed, plugins_rescue_db, plugins_unsupported, plugins_invalid,
     *         plugins_requirements, plugins_failed, engine_unsupported – dann wird nichts getauscht
     */
    public static function check(array $wish, array $unitPaths, string $newDir, string $name, string $content, ?int $opener, string $sealed): array
    {
        if (!self::allowed($opener)) {
            throw self::refuse(ContentException::PLUGINS_NOT_ALLOWED, self::NOT_ALLOWED_TEXT);
        }
        clearstatcache(true, $sealed);
        if (is_link($sealed) || !is_file($sealed)) {
            throw self::refuse(ContentException::PLUGINS_RESCUE_DB, self::NO_ENVELOPE_TEXT, [], ['detail' => RescueContent::WRITE_FAILED]);
        }
        $site = self::site();
        if ($site['multisite']) {
            throw self::refuse(ContentException::PLUGINS_UNSUPPORTED, 'Auf einer Multisite schaltet wpsync keine Plugins.');
        }
        $target = PushContent::target($name, $content);
        $seen   = self::analyse($wish, $unitPaths, static function (string $unit) use ($unitPaths, $newDir): ?array {
            $n = array_search($unit, $unitPaths, true);
            return $n === false ? null : self::built($newDir . '/' . $n);
        }, $name, $content, self::listOf($target), $site);
        if ($seen['refused'] !== []) {
            throw self::refusedBy($seen['refused']);
        }
        return ['add' => $seen['add'], 'skipped' => $seen['skipped'], 'drop' => $seen['drop']];
    }

    /**
     * Der Teil plugins der Antwort des Commits (§4.3): was die Transaktion wirklich geändert hat.
     *
     * @param array{activate: list<string>, deactivate: list<string>}                                             $wish
     * @param array{add: array<string, string>, skipped: list<array{unit: string, why: string}>, drop: list<string>} $resolved aus check()
     * @param array{added: list<string>, removed: list<string>}                                                    $delta    aus ContentApply::run()
     * @return array{activated: list<array{unit: string, file: string}>, deactivated: list<array{unit: string, files: list<string>}>, unchanged: list<string>, skipped: list<array{unit: string, why: string}>}
     */
    public static function result(array $wish, array $resolved, array $delta): array
    {
        $out = ['activated' => [], 'deactivated' => [], 'unchanged' => [], 'skipped' => array_values($resolved['skipped'])];
        foreach ($wish['activate'] as $unit) {
            if (!isset($resolved['add'][$unit])) {
                continue; // auf diesem Ziel übersprungen
            }
            if (in_array($resolved['add'][$unit], $delta['added'], true)) {
                $out['activated'][] = ['unit' => $unit, 'file' => $resolved['add'][$unit]];
            } else {
                $out['unchanged'][] = $unit; // schon aktiv (A11)
            }
        }
        foreach ($wish['deactivate'] as $unit) {
            $files = self::matching($delta['removed'], self::slug($unit));
            if ($files === []) {
                $out['unchanged'][] = $unit; // schon inaktiv oder gar nicht da (A11)
            } else {
                $out['deactivated'][] = ['unit' => $unit, 'files' => $files];
            }
        }
        return $out;
    }

    /**
     * Die Liste des Ziels, ohne Sperre – für Plan und Prüfung. Verbindlich liest sie die
     * Transaktion noch einmal unter Sperre (ContentApply).
     *
     * @return list<string>
     * @throws ContentException plugins_failed, content_failed
     */
    private static function listOf(ContentTarget $target): array
    {
        $row  = $target->store->read('options', [ContentPlugins::OPTION], false)[ContentPlugins::OPTION] ?? null;
        $list = $row === null ? null : ContentPlugins::parse($row['option_value'] ?? null);
        if ($list === null) {
            throw ContentPlugins::failed();
        }
        return $list;
    }

    /**
     * Einträge einer Liste, die im Ordner <slug>/ liegen.
     *
     * @param list<string> $list
     * @return list<string>
     */
    private static function matching(array $list, string $slug): array
    {
        $prefix = $slug . '/';
        $out    = [];
        foreach ($list as $entry) {
            if (strncmp((string) $entry, $prefix, strlen($prefix)) === 0) {
                $out[] = (string) $entry;
            }
        }
        return $out;
    }

    /**
     * Die ersten Bytes einer Plugin-Datei auf dem Ziel. Der Eintrag kommt aus der Datenbank: nur in
     * der Form eines Eintrags, nur eine echte Datei unter wp-content/plugins des Ziels, nie über
     * einen Symlink.
     */
    private static function fileOn(string $content, string $entry, int $bytes): ?string
    {
        if (!ContentPlugins::valid($entry)) {
            return null;
        }
        $file = $content . '/plugins/' . $entry;
        if (is_link($file) || !is_file($file) || !PushRescue::confined($content, $file)) {
            return null;
        }
        $text = @file_get_contents($file, false, null, 0, $bytes);
        return is_string($text) ? $text : null;
    }

    /**
     * Was die Plugins einer Liste laut Kopf voraussetzen (Requires Plugins).
     *
     * @param list<string> $entries
     * @return array<string, list<string>> Ordner des Plugins → verlangte Ordner
     */
    private static function dependencies(string $content, array $entries): array
    {
        $out = [];
        foreach ($entries as $entry) {
            $head = self::fileOn($content, (string) $entry, self::HEAD_BYTES);
            if ($head === null) {
                continue;
            }
            $required = self::header($head)['requires_plugins'];
            if ($required !== []) {
                $out[ContentPlugins::slug((string) $entry)] = $required;
            }
        }
        return $out;
    }

    /**
     * Der Auftrag gegen das Ziel gelesen – dieselbe Rechnung für den Plan (mitgeschickte Köpfe) und
     * für die Prüfung im Commit (gebautes Verzeichnis).
     *
     * @param array{activate: list<string>, deactivate: list<string>} $wish
     * @param list<string>                                          $unitPaths
     * @param callable(string): (array<string, string>|null)        $headsOf Köpfe einer Einheit des Satzes: Datei → erste
     *                                                                       Bytes; null: es kam keiner (nur im Probelauf)
     * @param list<string>                                          $list    active_plugins des Ziels
     * @param array{php: string, wp: string, multisite: bool}       $site
     * @return array{activate: list<array<string, mixed>>, deactivate: list<array<string, mixed>>, warnings: list<string>, refused: list<array<string, string>>, add: array<string, string>, skipped: list<array{unit: string, why: string}>, drop: list<string>}
     * @throws ContentException plugins_failed
     */
    private static function analyse(array $wish, array $unitPaths, callable $headsOf, string $name, string $content, array $list, array $site): array
    {
        $refused = [];
        $drop    = array_map([self::class, 'slug'], $wish['deactivate']);
        $going   = ContentPlugins::change($list, [], $drop);
        if ($going === null) {
            throw ContentPlugins::failed(); // ein Eintrag der Liste, den kein Abbild nennen dürfte (V4)
        }
        $staying = ContentPlugins::apply($list, [], $going['removed']);

        // 1. Aktivieren: Stand auf dem Ziel, Hauptdatei und Kopf je Einheit.
        $rows    = [];
        $headers = []; // Einheit → Kopf ihrer Hauptdatei
        $add     = []; // Einheit → Eintrag
        $counts  = []; // Ordner, die nach diesem Satz in der Liste des Ziels stehen
        foreach ($staying as $entry) {
            if (strpos((string) $entry, '/') !== false) {
                $counts[ContentPlugins::slug((string) $entry)] = true;
            }
        }
        foreach ($wish['activate'] as $unit) {
            $slug  = self::slug($unit);
            $there = is_dir($content . '/plugins/' . $slug);
            $row   = [
                'unit' => $unit, 'state' => self::matching($list, $slug) !== [] ? 'active' : ($there ? 'inactive' : 'new'),
                'file' => null, 'name' => null, 'version' => null, 'requirements' => ['checked' => 'at_commit', 'ok' => true, 'failed' => []],
            ];
            if (!in_array($unit, $unitPaths, true)) {
                // A3: aktiviert wird nur Code, den derselbe Push geprüft hat.
                $refused[]   = ['unit' => $unit, 'why' => 'unit_missing'];
                $rows[$unit] = $row;
                continue;
            }
            if ($name === 'staging' && in_array(strtolower($slug), StagingDb::DISABLED_PLUGINS, true)) {
                $rows[$unit] = ['unit' => $unit, 'state' => 'skipped', 'why' => self::DISABLED_ON_STAGING] + $row;
                continue;
            }
            $counts[$slug] = true;
            $files         = $headsOf($unit);
            if ($files === null) {
                $rows[$unit] = $row; // kein Kopf mitgeschickt: die Prüfung läuft erst im Commit
                continue;
            }
            $main = self::mainFile($files);
            if (isset($main['why'])) {
                $refused[]   = ['unit' => $unit, 'why' => (string) $main['why']];
                $rows[$unit] = $row;
                continue;
            }
            $entry          = $slug . '/' . $main['file'];
            $add[$unit]     = $entry;
            $headers[$unit] = $main['header'];
            $row['state']   = in_array($entry, $list, true) ? 'active' : ($there ? 'inactive' : 'new');
            $row['file']    = $entry;
            $row['name']    = self::printable((string) $main['header']['name']);
            $row['version'] = self::printable((string) $main['header']['version']);
            $row['requirements']['checked'] = 'head';
            $rows[$unit]    = $row;
        }

        // 2. Auf der Kopie: was ein dort übersprungenes Plugin voraussetzt, bleibt ebenfalls aus (V9).
        $skippedSlugs = [];
        foreach ($rows as $row) {
            if ($row['state'] === 'skipped') {
                $skippedSlugs[] = self::slug((string) $row['unit']);
            }
        }
        do {
            $again = false;
            foreach (array_keys($add) as $unit) {
                if (array_intersect($headers[$unit]['requires_plugins'], $skippedSlugs) === []) {
                    continue;
                }
                $rows[$unit] = ['unit' => $unit, 'state' => 'skipped', 'why' => self::REQUIRES_SKIPPED] + $rows[$unit];
                $rows[$unit]['requirements'] = ['checked' => 'at_commit', 'ok' => true, 'failed' => []];
                $skippedSlugs[] = self::slug((string) $unit);
                unset($add[$unit], $headers[$unit], $counts[self::slug((string) $unit)]);
                $again = true;
            }
        } while ($again);

        // 3. Voraussetzungen des Kopfs gegen das Ziel (§7.2).
        $present = array_map('strval', array_keys($counts));
        foreach (array_keys($add) as $unit) {
            $failed = self::requirements($headers[$unit], $present, $site);
            $rows[$unit]['requirements']['failed'] = $failed;
            $rows[$unit]['requirements']['ok']     = $failed === [];
            foreach ($failed as $f) {
                $refused[] = ['unit' => (string) $unit] + $f;
            }
        }

        // 4. Deaktivieren: keine Auflösung – es gehen alle Einträge des Ziels mit <slug>/ (A20).
        $needs      = self::dependencies($content, $staying);
        $deactivate = [];
        foreach ($wish['deactivate'] as $unit) {
            $slug  = self::slug($unit);
            $files = self::matching($going['removed'], $slug);
            $row   = [
                'unit' => $unit, 'state' => $files !== [] ? 'active' : (is_dir($content . '/plugins/' . $slug) ? 'inactive' : 'absent'),
                'files' => $files, 'name' => null, 'version' => null, 'required_by' => [], 'hooks' => false,
            ];
            if ($files !== []) {
                $body = self::fileOn($content, $files[0], self::HOOK_BYTES);
                if ($body !== null) {
                    $header         = self::header($body);
                    $row['name']    = $header['name'] === '' ? null : self::printable($header['name']);
                    $row['version'] = $header['version'] === '' ? null : self::printable($header['version']);
                    $row['hooks']   = strpos($body, 'register_deactivation_hook') !== false; // Heuristik, für den Hinweis der CLI (A17)
                }
                foreach ($needs as $other => $required) {
                    if (in_array($slug, $required, true)) {
                        $row['required_by'][] = 'plugins/' . $other;
                    }
                }
                if ($row['required_by'] !== []) {
                    $refused[] = ['unit' => $unit, 'why' => 'required_by', 'needs' => implode(', ', $row['required_by']), 'has' => ''];
                }
            }
            $deactivate[] = $row;
        }

        $warnings = [];
        $skipped  = [];
        foreach ($rows as $row) {
            if ($row['state'] === 'skipped') {
                $skipped[] = ['unit' => (string) $row['unit'], 'why' => (string) $row['why']];
            } elseif ($row['requirements']['checked'] === 'at_commit' && in_array($row['unit'], $unitPaths, true)) {
                $warnings[self::UNCHECKED] = true;
            }
        }
        foreach ($deactivate as $row) {
            if ($row['state'] === 'active') {
                $warnings[self::REVIEW] = true; // A22: bei jedem Deaktivieren, unübersehbar
            }
        }
        $order = [];
        foreach ([self::REVIEW, self::UNCHECKED] as $word) {
            if (isset($warnings[$word])) {
                $order[] = $word;
            }
        }
        return [
            'activate' => array_values($rows), 'deactivate' => $deactivate, 'warnings' => $order, 'refused' => $refused,
            'add' => $add, 'skipped' => $skipped, 'drop' => $drop,
        ];
    }

    /**
     * Die Ablehnung zu dem, was analyse() nicht gelten lässt: was sich nicht auflösen lässt, ist
     * plugins_invalid; sonst sind es unerfüllte Voraussetzungen.
     *
     * @param list<array<string, string>> $refused [{unit, why, needs?, has?}]
     */
    private static function refusedBy(array $refused): ContentException
    {
        $invalid = [];
        foreach ($refused as $entry) {
            if (in_array($entry['why'], ['unit_missing', 'no_plugin_file', 'ambiguous', 'file_name'], true)) {
                $invalid[] = $entry;
            }
        }
        if ($invalid !== []) {
            return self::refuse(
                ContentException::PLUGINS_INVALID,
                'Nicht aktivierbar: ' . implode(', ', array_unique(array_column($invalid, 'unit'))) . ' – die Einheit muss im selben Satz liegen und genau eine PHP-Datei mit Plugin-Kopf direkt im Ordner tragen.',
                $invalid
            );
        }
        return self::refuse(
            ContentException::PLUGINS_REQUIREMENTS,
            'Voraussetzungen nicht erfüllt: ' . implode(', ', array_unique(array_column($refused, 'unit'))) . ' – PHP- oder WordPress-Version, ein fehlendes Plugin oder ein aktives Plugin, das dieses voraussetzt.',
            $refused
        );
    }
}
