<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use WpSync\PushContent;
use WpSync\PushPlugins;
use WpSync\RescueContent;
use WpSync\Staging;
use WpSync\Store;

require_once __DIR__ . '/PushPluginsFlowCase.php';

/**
 * /push/begin mit Plugin-Zustand (Spec Content-Push P4 §4.2, §8.2; AC-179–AC-181, AC-192, AC-199,
 * AC-200, AC-202, AC-206, AC-208): der Plan im Probelauf – auch ohne Fenster –, die Ablehnungen des
 * echten Begin, Öffner und Pflicht-Umschlag.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PushPluginsBeginTest extends PushPluginsFlowCase
{
    private const UNITS = ['plugins/kunde' => ['kunde.php' => self::KUNDE, 'inc/a.php' => "<?php\n"]];

    /** AC-199, AC-208: der Probelauf – ohne offenes Fenster – nennt je Plugin Stand, Datei, Name, Version und die Warnungen. */
    public function testADryRunPlansThePluginStateWithoutAWindow(): void
    {
        Store::$until  = 0;
        Store::$opener = null;
        $begin = $this->beginSet(self::UNITS, ['dry' => true] + $this->wish(['plugins/kunde'], ['plugins/old'], self::UNITS));
        $this->assertInstanceOf(\WP_REST_Response::class, $begin, $begin instanceof \WP_Error ? $begin->code . ' ' . $begin->message : '');
        $this->assertFalse($begin->data['window_open']);
        $this->assertSame([
            'ok'          => true,
            'error'       => null,
            'activate'    => [[
                'unit' => 'plugins/kunde', 'state' => 'new', 'file' => 'kunde/kunde.php', 'name' => 'Kunde Widgets', 'version' => '1.2.0',
                'requirements' => ['checked' => 'head', 'ok' => true, 'failed' => []],
            ]],
            'deactivate'  => [['unit' => 'plugins/old', 'state' => 'active', 'files' => ['old/old.php'], 'name' => 'Altes Plugin', 'version' => '3.2.1', 'required_by' => [], 'hooks' => true]],
            'warnings'    => [PushPlugins::REVIEW],
            'health_urls' => [ContentFixtures::HOME . '/wp-admin/admin-ajax.php'],
        ], $begin->data['plugins']);
        // §8.2: rescue.db sagt schon im Probelauf, was ein echter Begin ergäbe – ohne Probe und ohne Datei.
        $this->assertSame(['ok' => true], $begin->data['rescue']['db']);
        $this->assertSame(0, $this->connected);
        $this->assertSame([], Store::$pushes);
        $this->assertSame([], $this->pushDirs($this->live));
        $this->assertSame([], $this->liveDb->log);
        $this->assertStringNotContainsString('akismet', (string) json_encode($begin->data), 'AC-197');
    }

    public function testWithoutThePluginFieldsNothingChanges(): void
    {
        $begin = $this->beginSet(self::UNITS, ['dry' => true]);
        $this->assertArrayNotHasKey('plugins', $begin->data);
        $this->assertArrayNotHasKey('db', $begin->data['rescue']);
        // Leere Listen sind kein Plugin-Zustand.
        $begin = $this->beginSet(self::UNITS, ['dry' => true, 'activate' => [], 'deactivate' => []]);
        $this->assertArrayNotHasKey('plugins', $begin->data);
    }

    /** AC-206, AC-200: Formfehler sind 400 – im Probelauf wie im echten Begin, bevor irgendetwas geprüft wird. */
    public function testFormErrorsAreRefusedAtOnce(): void
    {
        foreach ([['activate' => ['themes/x']], ['deactivate' => ['plugins/wpsync-agent']], ['activate' => ['plugins/kunde'], 'deactivate' => ['plugins/kunde']],
            ['activate' => ['plugins/kunde'], 'plugin_heads' => ['plugins/kunde' => ['kunde.php' => base64_encode(str_repeat('x', 8193))]]]] as $extra) {
            foreach ([true, false] as $dry) {
                $error = $this->assertRefused('wpsync_plugins_invalid', 400, $this->beginSet(self::UNITS, $extra + ['dry' => $dry]));
                $this->assertSame([], $error->data['plugins']);
            }
        }
        $this->assertSame([], Store::$pushes);
    }

    /** AC-199, AC-179: was der Probelauf als plugins.error nennt, lehnt der echte Begin ab – mit denselben Einzelheiten. */
    public function testTheRealBeginRefusesWhatTheDryRunNames(): void
    {
        $units = ['plugins/kunde' => ['kunde.php' => "<?php\n/* Plugin Name: Kunde\n * Requires PHP: 9.0 */\n"]];
        $extra = $this->wish(['plugins/kunde'], [], $units);
        $dry   = $this->beginSet($units, $extra + ['dry' => true]);
        $this->assertFalse($dry->data['plugins']['ok']);
        $failed = [['unit' => 'plugins/kunde', 'why' => 'requires_php', 'needs' => '9.0', 'has' => '8.1.0']];
        $this->assertSame(['plugins_requirements', $failed], [$dry->data['plugins']['error']['code'], $dry->data['plugins']['error']['plugins']]);

        $error = $this->assertRefused('wpsync_plugins_requirements', 409, $this->beginSet($units, $extra));
        $this->assertSame($failed, $error->data['plugins']);

        // Aktivieren ohne die Einheit im Satz (A3).
        $error = $this->assertRefused('wpsync_plugins_invalid', 400, $this->beginSet([], ['activate' => ['plugins/kunde']]));
        $this->assertSame([['unit' => 'plugins/kunde', 'why' => 'unit_missing']], $error->data['plugins']);

        $this->assertSame([], Store::$pushes);
        $this->assertNull(Store::getState('push_lock'));
        $this->assertSame([], $this->pushDirs($this->live));
    }

    /** AC-180: nur ein Fenster mit Öffner, und der darf Plugins schalten – geprüft im echten Begin, nicht im Probelauf. */
    public function testTheRealBeginNeedsAnOpenerWhoMaySwitchPlugins(): void
    {
        $extra = $this->wish([], ['plugins/old']);
        foreach ([null, 8] as $opener) {
            Store::$opener = $opener;
            $this->assertTrue($this->beginSet([], $extra + ['dry' => true])->data['plugins']['ok']);
            $error = $this->assertRefused('wpsync_plugins_not_allowed', 403, $this->beginSet([], $extra));
            $this->assertStringContainsString('activate_plugins', $error->message);
        }
        $this->assertSame([], Store::$pushes);
        // Das Fenster kommt zuerst: ohne Fenster erfährt der Aufrufer nichts über den Öffner.
        Store::$until = 0;
        $this->assertRefused('wpsync_push_window', 403, $this->beginSet([], $extra));
    }

    /** AC-180: Multisite ist schon im Probelauf plugins_unsupported. */
    public function testMultisiteIsRefused(): void
    {
        PushPlugins::$site['multisite'] = true;
        $extra = $this->wish([], ['plugins/old']);
        $this->assertSame('plugins_unsupported', $this->beginSet([], $extra + ['dry' => true])->data['plugins']['error']['code']);
        $this->assertRefused('wpsync_plugins_unsupported', 409, $this->beginSet([], $extra));
    }

    /** AC-181, A9: ohne Umschlag lehnt der Agent den echten Begin ab und verwirft den eben angelegten Push. */
    public function testTheEnvelopeIsMandatory(): void
    {
        PushContent::$rescueData = static function (): string {
            return RescueContent::NO_IMAGE_KEY;
        };
        $extra = $this->wish(['plugins/kunde'], [], self::UNITS);
        $dry   = $this->beginSet(self::UNITS, $extra + ['dry' => true]);
        $this->assertTrue($dry->data['plugins']['ok']);
        $this->assertSame(['ok' => false, 'reason' => 'no_image_key'], $dry->data['rescue']['db'], 'die CLI bricht schon hier ab');

        $error = $this->assertRefused('wpsync_plugins_rescue_db', 409, $this->beginSet(self::UNITS, $extra));
        $this->assertSame('no_image_key', $error->data['detail']);
        $this->assertSame([], Store::$pushes, 'kein Push im Protokoll');
        $this->assertNull(Store::getState('push_lock'), 'die Sperre ist frei');
        $this->assertSame([], $this->pushDirs($this->live), 'der Arbeitsordner des Pushs ist weg');
        $this->assertSame(serialize(self::ACTIVE), $this->liveDb->data['options']['active_plugins']['option_value']);
    }

    /** §4.2, V12: der echte Begin legt den Push mit seinem Auftrag an – die mitgeschickten Köpfe werden nie gespeichert. */
    public function testTheRealBeginCreatesThePushWithItsWish(): void
    {
        $begin = $this->beginSet(self::UNITS, $this->wish(['plugins/kunde'], ['plugins/old'], self::UNITS));
        $this->assertInstanceOf(\WP_REST_Response::class, $begin, $begin instanceof \WP_Error ? $begin->code . ' ' . $begin->message : '');
        $id   = (string) $begin->data['push_id'];
        $base = $this->work($this->live) . '/' . $id;
        $this->assertTrue($begin->data['plugins']['ok']);
        $this->assertSame(['ok' => true], $begin->data['rescue']['db']);
        $this->assertSame('0600', substr(sprintf('%o', fileperms($this->sealed($this->live, $id))), -4), 'der Umschlag liegt, nur für den Besitzer lesbar');
        $raw  = (string) file_get_contents($base . '/plan.json');
        $plan = json_decode($raw, true);
        $this->assertSame(['activate' => ['plugins/kunde'], 'deactivate' => ['plugins/old']], $plan['plugins']);
        $this->assertStringNotContainsString('Plugin Name', $raw, '§12: plugin_heads wird nie gespeichert');
        $this->assertStringNotContainsString(base64_encode(substr(self::KUNDE, 0, 30)), $raw);
        $this->assertSame(
            ['path' => 'plugins', 'exists' => true, 'old_version' => '', 'new_version' => '', 'files' => 0, 'uploaded' => 0, 'activate' => ['plugins/kunde'], 'deactivate' => ['plugins/old']],
            $this->pluginsUnit($id)
        );
        $this->assertSame(['plugins/kunde', 'plugins'], array_column(Store::getPush($id)['units'], 'path'));
        $this->assertSame([], $this->liveDb->log, 'der Begin schreibt nichts in die Datenbank');
        $this->assertFileDoesNotExist($this->live . '/plugins/kunde/geladen', 'AC-178');
    }

    /** AC-202, §8.1: ein Satz nur aus --deactivate hat keine Einheit und kein Paket. */
    public function testASetOfOnlyADeactivationHasNoUnit(): void
    {
        $begin = $this->beginSet([], $this->wish([], ['plugins/old']));
        $this->assertInstanceOf(\WP_REST_Response::class, $begin, $begin instanceof \WP_Error ? $begin->code . ' ' . $begin->message : '');
        $id = (string) $begin->data['push_id'];
        $this->assertSame([], $begin->data['units']);
        $this->assertSame(['plugins'], array_column(Store::getPush($id)['units'], 'path'));
        $this->assertFileExists($this->sealed($this->live, $id));
    }

    /** AC-192, A14: derselbe Aufruf für die Kopie – was sie abschaltet, wird übersprungen; ihre Adresse im Health-Check. */
    public function testBeginForTheStagingCopy(): void
    {
        $units = ['plugins/wp-rocket' => ['wp-rocket.php' => "<?php\n/* Plugin Name: WP Rocket\n * Version: 3.0 */\n"]] + self::UNITS;
        $begin = $this->beginSet($units, ['target' => 'staging'] + $this->wish(['plugins/wp-rocket', 'plugins/kunde'], ['plugins/old'], $units));
        $this->assertInstanceOf(\WP_REST_Response::class, $begin, $begin instanceof \WP_Error ? $begin->code . ' ' . $begin->message : '');
        $this->assertSame(['skipped', 'new'], array_column($begin->data['plugins']['activate'], 'state'));
        $this->assertSame(PushPlugins::DISABLED_ON_STAGING, $begin->data['plugins']['activate'][0]['why']);
        $this->assertSame('active', $begin->data['plugins']['deactivate'][0]['state'], 'die Liste der Kopie, nicht die von Live');
        $this->assertSame([ContentFixtures::HOME . '/' . Staging::DIR . '/wp-admin/admin-ajax.php'], $begin->data['plugins']['health_urls']);
        $id = (string) $begin->data['push_id'];
        $this->assertFileExists($this->sealed($this->staging, $id), 'der Umschlag liegt im Arbeitsordner der Kopie');
        $this->assertSame([], $this->pushDirs($this->live));
    }

    /** Security-Review P4 S3: liegt der Agent unter einem anderen Ordnernamen, schützt der Begin auch diesen – als Schalter und als Einheit. */
    public function testTheRealFolderOfTheAgentIsProtected(): void
    {
        mkdir($this->live . '/plugins/wpsync-agent-main', 0777, true);
        \WpSync\Push::register($this->live . '/plugins/wpsync-agent-main');
        foreach ([true, false] as $dry) {
            $this->assertRefused('wpsync_plugins_invalid', 400, $this->beginSet([], ['dry' => $dry, 'deactivate' => ['plugins/wpsync-agent-main']]));
            $error = $this->beginSet(['plugins/wpsync-agent-main' => ['wpsync-agent.php' => "<?php\n"]], ['dry' => $dry]);
            $this->assertInstanceOf(\WP_Error::class, $error, 'auch der Code-Kanal ersetzt den laufenden Agent nicht');
            $this->assertSame(400, $error->data['status']);
        }
        $this->assertSame([], Store::$pushes);
    }

    /**
     * Security-Review P4 H2: ein Plugin, das rescue.php sperren kann, zählt für rescue.hardening schon, wenn
     * der Satz es erst aktiviert – die CLI nennt es dann als wahrscheinliche Ursache, wenn der Rückweg zu ist.
     */
    public function testAHardeningPluginThatThePushActivatesIsNamed(): void
    {
        $units = ['plugins/better-wp-security' => ['better-wp-security.php' => "<?php\n/* Plugin Name: Solid Security\n * Version: 9.0 */\n"]];
        $begin = $this->beginSet($units, ['dry' => true] + $this->wish(['plugins/better-wp-security'], [], $units));
        $this->assertSame(['better-wp-security'], $begin->data['rescue']['hardening']);
        $this->assertSame([], $this->beginSet($units, ['dry' => true])->data['rescue']['hardening'], 'ohne --activate liegt es nur da');
        $this->assertSame([], $this->beginSet([], ['dry' => true, 'deactivate' => ['plugins/better-wp-security']])->data['rescue']['hardening']);
    }
}
