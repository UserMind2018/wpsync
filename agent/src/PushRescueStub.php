<?php
namespace WpSync;

defined('ABSPATH') || exit;

/**
 * Zweiter Weg zu rescue.php über den Webroot (Spec Stufe 2, 12; B1). Sicherheits-Plugins wie
 * iThemes/Solid Security sperren HTTP-Aufrufe von PHP-Dateien unter wp-content/plugins/, nicht
 * aber ein require im Dateisystem. Der Stub enthält nur dieses require mit relativem Pfad.
 * Reine Dateisystem-Arbeit; wann ein Stub entsteht und verschwindet, entscheidet Push.
 */
final class PushRescueStub
{
    public const NAME = '/^wpsync-rescue-[a-f0-9]{32}\.php\z/';
    private const TMP = '/^\.wpsync-rescue-[a-f0-9]{32}\.php\.tmp\z/';
    /** Ordnername des Agents, wie er im require stehen darf. */
    private const PLUGIN = '/^[A-Za-z0-9._-]+\z/';
    /** Aktive Plugins, die PHP unter wp-content sperren können (R7) – Ordnernamen. */
    public const HARDENING = ['better-wp-security', 'ithemes-security-pro', 'sucuri-scanner'];

    /**
     * Legt einen neuen Stub an und liefert seinen Dateinamen. null, wenn der Agent nicht unter
     * <webroot>/wp-content/plugins/ liegt oder der Webroot nicht beschreibbar ist (R6).
     */
    public static function create(string $webroot, string $pluginDir): ?string
    {
        $webroot = rtrim(str_replace('\\', '/', $webroot), '/');
        $plugin  = basename(str_replace('\\', '/', $pluginDir));
        $rel     = '/wp-content/plugins/' . $plugin . '/rescue.php';
        $real    = realpath($pluginDir);
        if ($webroot === '' || preg_match(self::PLUGIN, $plugin) !== 1 || $real === false
            || realpath($webroot . '/wp-content/plugins/' . $plugin) !== $real
            || !is_file($webroot . $rel) || !is_writable($webroot)) {
            return null;
        }
        $name = 'wpsync-rescue-' . bin2hex(random_bytes(16)) . '.php';
        $tmp  = $webroot . '/.' . $name . '.tmp';
        $code = "<?php\n// wpsync: Rückweg für einen laufenden Push (rescue.php), wird danach gelöscht.\n"
            . 'require __DIR__ . ' . var_export($rel, true) . ";\n";
        if (@file_put_contents($tmp, $code) !== strlen($code) || !@chmod($tmp, 0644) || !@rename($tmp, $webroot . '/' . $name)) {
            @unlink($tmp);
            return null;
        }
        return $name;
    }

    public static function exists(string $webroot, string $name): bool
    {
        $file = rtrim(str_replace('\\', '/', $webroot), '/') . '/' . $name;
        return preg_match(self::NAME, $name) === 1 && is_file($file) && !is_link($file);
    }

    /** Löscht Stubs und Reste im Webroot ausser $keep. Nur das Namensmuster, keine Symlinks (R9). */
    public static function remove(string $webroot, ?string $keep = null): void
    {
        $webroot = rtrim(str_replace('\\', '/', $webroot), '/');
        foreach ((array) @scandir($webroot) as $name) {
            if (!is_string($name) || $name === $keep || (preg_match(self::NAME, $name) !== 1 && preg_match(self::TMP, $name) !== 1)) {
                continue;
            }
            $file = $webroot . '/' . $name;
            if (is_file($file) && !is_link($file)) {
                @unlink($file);
            }
        }
    }

    /**
     * @param array<int|string, mixed> $active Einträge aus active_plugins, z. B. „better-wp-security/better-wp-security.php“
     * @return list<string>
     */
    public static function hardening(array $active): array
    {
        $dirs = [];
        foreach ($active as $file) {
            if (is_string($file)) {
                $dirs[explode('/', $file, 2)[0]] = true;
            }
        }
        return array_values(array_filter(self::HARDENING, static function (string $slug) use ($dirs): bool {
            return isset($dirs[$slug]);
        }));
    }
}
