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
}
