<?php
declare(strict_types=1);

namespace WpSync\Tests;

use WpSync\ContentPlugins;
use WpSync\Push;
use WpSync\PushPlugins;

require_once __DIR__ . '/PushRescueFlowCase.php';

/**
 * Aufbau der Tests, die den Plugin-Zustand im echten Push-Ablauf prüfen: wie PushRescueFlowCase
 * (temporärer Webroot, Stores im Speicher, die Installation hat einen Schlüssel, rescue.php über
 * die Naht), dazu eine Liste aktiver Plugins auf Live und in der Kopie, ein Plugin „old“ auf der
 * Platte und die Nähte von PushPlugins (PHP 8.1, WordPress 6.5.2, Öffner 7 darf Plugins schalten).
 */
abstract class PushPluginsFlowCase extends PushRescueFlowCase
{
    protected const ACTIVE = ['akismet/akismet.php', 'old/old.php'];
    /** Das neue Plugin: schreibt beim Laden einen Marker – im Commit darf er nie entstehen (AC-178). */
    protected const KUNDE = "<?php\n/**\n * Plugin Name: Kunde Widgets\n * Version: 1.2.0\n */\nfile_put_contents(__DIR__ . '/geladen', '1');\nregister_activation_hook(__FILE__, 'kunde_on');\n";
    protected const OLD   = "<?php\n/* Plugin Name: Altes Plugin\n * Version: 3.2.1 */\nregister_deactivation_hook(__FILE__, 'old_off');\n";

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([$this->live, $this->staging] as $content) {
            mkdir($content . '/plugins/old', 0777, true);
            file_put_contents($content . '/plugins/old/old.php', self::OLD);
        }
        PushPlugins::$site = ['php' => '8.1.0', 'wp' => '6.5.2', 'multisite' => false];
        PushPlugins::$can  = static function (int $id): bool {
            return $id === 7;
        };
    }

    /** Wie der Elternaufbau, dazu die Liste der aktiven Plugins – auf Live und in der Kopie dieselbe. */
    protected function site(string $path): array
    {
        $data = parent::site($path);
        $data['options']['active_plugins'] = ContentFixtures::option('active_plugins', serialize(self::ACTIVE), '33');
        return $data;
    }

    /** @return list<string> */
    protected function active(ContentMemory $db): array
    {
        return (array) ContentPlugins::parse($db->data['options']['active_plugins']['option_value'] ?? null);
    }

    /**
     * Felder activate, deactivate und plugin_heads eines Begin – die Köpfe so, wie die CLI sie liest:
     * die ersten 8192 Bytes jeder *.php direkt im Ordner, die „Plugin Name:“ nennt.
     *
     * @param list<string>                          $activate
     * @param list<string>                          $deactivate
     * @param array<string, array<string, string>> $units Einheit → Datei → Inhalt (für die Köpfe)
     * @return array<string, mixed>
     */
    protected function wish(array $activate, array $deactivate = [], array $units = []): array
    {
        $heads = [];
        foreach ($activate as $unit) {
            if (!isset($units[$unit])) {
                continue;
            }
            $heads[$unit] = [];
            foreach ($units[$unit] as $rel => $bytes) {
                if (strpos($rel, '/') === false && substr($rel, -4) === '.php' && stripos(substr($bytes, 0, 8192), 'plugin name:') !== false) {
                    $heads[$unit][$rel] = base64_encode(substr($bytes, 0, 8192));
                }
            }
        }
        return ['activate' => $activate, 'deactivate' => $deactivate] + ($heads === [] ? [] : ['plugin_heads' => $heads]);
    }

    /**
     * /push/begin für einen Satz aus beliebigen Einheiten.
     *
     * @param array<string, array<string, string>> $units Einheit → Datei → Inhalt
     * @param array<string, mixed>                 $extra target, dry, activate, deactivate, plugin_heads …
     * @return \WP_REST_Response|\WP_Error
     */
    protected function beginSet(array $units, array $extra = [], ?string $sha = null)
    {
        $list = [];
        foreach ($units as $path => $files) {
            $list[] = ['path' => $path, 'files' => array_map([$this, 'entry'], $files), 'base' => []];
        }
        $params = $extra + ['force' => true, 'units' => $list];
        if ($sha !== null) {
            $params['content'] = ['sha256' => $sha];
        }
        return Push::begin($params, self::KEY);
    }

    /**
     * Begin, Upload jeder Einheit, Commit; liefert ID und Antwort des Commits.
     *
     * @param array<string, array<string, string>> $units
     * @param array<string, mixed>                 $extra
     * @return array{0: string, 1: \WP_REST_Response|\WP_Error}
     */
    protected function pushSet(array $units, array $extra = [], ?string $sha = null): array
    {
        $id = $this->uploadedSet($units, $extra, $sha);
        return [$id, Push::commit(['push_id' => $id], self::KEY)];
    }

    /**
     * Begin und Upload; der Commit steht noch aus.
     *
     * @param array<string, array<string, string>> $units
     * @param array<string, mixed>                 $extra
     */
    protected function uploadedSet(array $units, array $extra = [], ?string $sha = null): string
    {
        $begin = $this->beginSet($units, $extra, $sha);
        $this->assertInstanceOf(\WP_REST_Response::class, $begin, $begin instanceof \WP_Error ? $begin->code . ' ' . $begin->message : '');
        $id         = (string) $begin->data['push_id'];
        $this->salt = (string) $begin->data['rescue']['salt'];
        $n          = 0;
        foreach ($units as $files) {
            $chunks = [];
            foreach ($begin->data['units'][$n]['need'] as $rel) {
                $chunks[] = ['path' => $rel, 'data' => base64_encode($files[$rel]), 'offset' => 0];
            }
            if ($chunks !== []) {
                $up = Push::upload(['push_id' => $id, 'unit' => $n, 'files' => $chunks], self::KEY);
                $this->assertInstanceOf(\WP_REST_Response::class, $up, $up instanceof \WP_Error ? $up->code . ' ' . $up->message : '');
            }
            $n++;
        }
        return $id;
    }

    /** Die Einheit plugins im Protokoll eines Pushs; null, wenn er keine hat. */
    protected function pluginsUnit(string $id): ?array
    {
        foreach ((array) (\WpSync\Store::getPush($id)['units'] ?? []) as $unit) {
            if (is_array($unit) && ($unit['path'] ?? '') === PushPlugins::UNIT) {
                return $unit;
            }
        }
        return null;
    }

    /** @return list<string> Ordner von Pushes im Arbeitsordner eines Ziels */
    protected function pushDirs(string $content): array
    {
        $out = [];
        foreach ((array) @scandir($this->work($content)) as $name) {
            if (is_string($name) && preg_match(\WpSync\PushRescue::ID, $name) === 1) {
                $out[] = $name;
            }
        }
        return $out;
    }
}
