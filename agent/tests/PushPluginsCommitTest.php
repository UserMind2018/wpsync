<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use WpSync\Push;
use WpSync\PushPlugins;
use WpSync\Store;

require_once __DIR__ . '/PushPluginsFlowCase.php';

/**
 * /push/commit mit Plugin-Zustand (Spec Content-Push P4 §4.3, §7.4, §8.1, §8.5; AC-176–AC-183,
 * AC-191, AC-192, AC-200, AC-202, AC-209): Prüfung vor dem Tausch, die Liste in der Transaktion
 * des DB-Schritts – mit und ohne Paket –, und nie Code des Plugins im Request des Commits.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PushPluginsCommitTest extends PushPluginsFlowCase
{
    private const UNITS = ['plugins/kunde' => ['kunde.php' => self::KUNDE, 'inc/a.php' => "<?php\n"]];

    /** @param mixed $result */
    private function ok($result): \WP_REST_Response
    {
        $this->assertInstanceOf(\WP_REST_Response::class, $result, $result instanceof \WP_Error ? $result->code . ' ' . $result->message : '');
        return $result;
    }

    /** Der Push ist gescheitert, nichts ist getauscht, die Sperre frei, der Arbeitsordner weg. */
    private function assertNothingSwapped(string $id): void
    {
        $push = Store::getPush($id);
        $this->assertSame(['failed', true], [$push['status'], $push['pruned']]);
        $this->assertDirectoryDoesNotExist($this->live . '/plugins/kunde');
        $this->assertSame('old', file_get_contents($this->live . '/plugins/x/main.php'));
        $this->assertSame([], $this->pushDirs($this->live));
        $this->assertNull(Store::getState('push_lock'));
        $this->assertNull(Push::pending());
        $this->assertNotContains('commit', $this->liveDb->log, 'in der Datenbank ist nichts festgeschrieben');
    }

    /** AC-176, AC-178: eine neue Einheit wird getauscht und in derselben Transaktion in die Liste eingetragen – ohne dass ihr Code läuft. */
    public function testCommitActivatesANewPlugin(): void
    {
        list($id, $commit) = $this->pushSet(self::UNITS, $this->wish(['plugins/kunde'], [], self::UNITS));
        $data = $this->ok($commit)->data;
        $this->assertSame([
            'activated'   => [['unit' => 'plugins/kunde', 'file' => 'kunde/kunde.php']],
            'deactivated' => [],
            'unchanged'   => [],
            'skipped'     => [],
        ], $data['plugins']);
        // §4.3: content steht in der Antwort, sobald der DB-Schritt lief – ohne Paket mit rows 0 und ohne after.
        $this->assertSame([0, []], [$data['content']['rows'], $data['content']['after']]);
        $this->assertSame([
            ['step' => 'object_cache', 'ok' => true],
            ['step' => 'plugins_cache', 'ok' => true],
            ['step' => 'rewrite_rules', 'ok' => true],
        ], $data['content']['post_actions']);
        $this->assertContains('wp_cache_delete ["active_plugins","options"]', $GLOBALS['wpsync_post_actions']);
        $this->assertContains('wp_cache_delete ["plugins","plugins"]', $GLOBALS['wpsync_post_actions']);

        $this->assertSame(['akismet/akismet.php', 'kunde/kunde.php', 'old/old.php'], $this->active($this->liveDb));
        $this->assertSame(self::KUNDE, file_get_contents($this->live . '/plugins/kunde/kunde.php'));
        $this->assertFileDoesNotExist($this->live . '/plugins/kunde/geladen', 'AC-178: im Commit ist das Plugin nie geladen worden');
        $this->assertSame(['begin', 'write options:active_plugins', 'commit'], $this->liveDb->log);

        $record = $this->rescue($this->live, $id);
        $this->assertSame('committed', $record['status']);
        $this->assertSame(['state' => 'applied', 'sha256' => null, 'plugins' => ['added' => ['kunde/kunde.php'], 'removed' => []]], $record['content']);
        $this->assertSame('committed', Store::getPush($id)['status']);
        $this->assertSame(
            ['path' => 'plugins', 'exists' => true, 'old_version' => '', 'new_version' => '', 'files' => 0, 'uploaded' => 0,
                'activate' => ['plugins/kunde'], 'deactivate' => [], 'activated' => ['kunde/kunde.php'], 'deactivated' => []],
            $this->pluginsUnit($id)
        );
        $this->assertStringNotContainsString('akismet', (string) json_encode([$data, $record, Store::getPush($id)]), 'AC-197');
    }

    /** AC-176, AC-209: Uploads → Code → DB; Paket und Plugin-Zustand in einer Transaktion. */
    public function testASetOfUploadsCodePackageAndPlugins(): void
    {
        $sha   = $this->stage($this->rows());
        $units = self::UNITS + ['uploads' => ['2026/10/neu.png' => (string) base64_decode(self::PNG)]];
        list($id, $commit) = $this->pushSet($units, $this->wish(['plugins/kunde'], ['plugins/old'], self::UNITS), $sha);
        $data = $this->ok($commit)->data;
        $this->assertSame(5, $data['content']['rows']);
        $this->assertSame([['unit' => 'plugins/kunde', 'file' => 'kunde/kunde.php']], $data['plugins']['activated']);
        $this->assertSame([['unit' => 'plugins/old', 'files' => ['old/old.php']]], $data['plugins']['deactivated']);
        $this->assertNotContains('active_plugins', array_column($data['content']['after'], 'k'), '§4.3: content.after nennt die Liste nie');
        $this->assertCount(1, array_keys($this->liveDb->log, 'begin', true));
        $this->assertCount(1, array_keys($this->liveDb->log, 'write options:active_plugins', true), 'ein Schreibzugriff auf die Liste');
        $this->assertSame('Kunde GmbH', $this->liveDb->data['options']['blogname']['option_value']);
        $this->assertSame(['akismet/akismet.php', 'kunde/kunde.php'], $this->active($this->liveDb));
        $this->assertFileExists($this->live . '/uploads/2026/10/neu.png');
        $this->assertFileExists($this->live . '/plugins/old/old.php', 'A20: gelöscht wird nichts – der Ordner des abgeschalteten Plugins bleibt');
        $record = $this->rescue($this->live, $id);
        $this->assertSame([$sha, ['added' => ['kunde/kunde.php'], 'removed' => ['old/old.php']]], [$record['content']['sha256'], $record['content']['plugins']]);
        $this->assertSame(['plugins/kunde', 'uploads', 'content', 'plugins'], array_column(Store::getPush($id)['units'], 'path'));
    }

    /** AC-202: Deaktivieren ohne Einheit, ohne Paket, ohne Uploads – der Ordner auf dem Ziel bleibt unverändert. */
    public function testCommitOfOnlyADeactivation(): void
    {
        list($id, $commit) = $this->pushSet([], $this->wish([], ['plugins/old', 'plugins/nie']));
        $data = $this->ok($commit)->data;
        $this->assertSame([
            'activated'   => [],
            'deactivated' => [['unit' => 'plugins/old', 'files' => ['old/old.php']]],
            'unchanged'   => ['plugins/nie'],
            'skipped'     => [],
        ], $data['plugins']);
        $this->assertSame(['akismet/akismet.php'], $this->active($this->liveDb));
        $this->assertSame(self::OLD, file_get_contents($this->live . '/plugins/old/old.php'));
        $this->assertSame('{}', json_encode($data['stamps']));
        $this->assertSame('committed', Store::getPush($id)['status']);
        $this->assertSame([], $this->rescue($this->live, $id)['pairs']);
    }

    /** AC-182, AC-203, V14: schon im gewünschten Zustand – kein Fehler, nichts geschrieben, aber der Schritt lief. */
    public function testASetThatChangesNothing(): void
    {
        $units = ['plugins/akismet' => ['akismet.php' => "<?php\n/* Plugin Name: Akismet\n * Version: 5.3 */\n"]];
        $value = $this->liveDb->data['options']['active_plugins']['option_value'];
        list($id, $commit) = $this->pushSet($units, $this->wish(['plugins/akismet'], ['plugins/nie'], $units));
        $data = $this->ok($commit)->data;
        $this->assertSame(['activated' => [], 'deactivated' => [], 'unchanged' => ['plugins/akismet', 'plugins/nie'], 'skipped' => []], $data['plugins']);
        $this->assertSame($value, $this->liveDb->data['options']['active_plugins']['option_value']);
        $this->assertSame(['begin', 'commit'], $this->liveDb->log);
        $this->assertSame([['step' => 'object_cache', 'ok' => true]], $data['content']['post_actions']);
        $this->assertSame(['added' => [], 'removed' => []], $this->rescue($this->live, $id)['content']['plugins']);
    }

    /** AC-200, AC-179: im Commit zählt die gebaute Datei – scheitert die Prüfung, ist nichts getauscht. */
    public function testTheBuiltFileDecidesBeforeTheSwap(): void
    {
        // Der mitgeschickte Kopf nennt geringere Anforderungen als die Datei.
        $units = ['plugins/kunde' => ['kunde.php' => "<?php\n/* Plugin Name: Kunde\n * Requires PHP: 9.0 */\n"], 'plugins/x' => ['main.php' => 'new']];
        $extra = ['activate' => ['plugins/kunde'], 'plugin_heads' => ['plugins/kunde' => ['kunde.php' => base64_encode("<?php\n/* Plugin Name: Kunde\n * Requires PHP: 7.4 */\n")]]];
        list($id, $commit) = $this->pushSet($units, $extra);
        $error = $this->assertRefused('wpsync_plugins_requirements', 409, $commit);
        $this->assertSame([['unit' => 'plugins/kunde', 'why' => 'requires_php', 'needs' => '9.0', 'has' => '8.1.0']], $error->data['plugins']);
        $this->assertNothingSwapped($id);

        // Zwei Dateien mit Plugin-Kopf, ohne mitgeschickten Kopf: der Probelauf konnte es nicht sehen.
        $two = ['plugins/kunde' => ['a.php' => self::KUNDE, 'b.php' => self::KUNDE]];
        $begin = $this->beginSet($two, ['dry' => true, 'activate' => ['plugins/kunde']]);
        $this->assertSame([true, [PushPlugins::UNCHECKED]], [$begin->data['plugins']['ok'], $begin->data['plugins']['warnings']]);
        list($id, $commit) = $this->pushSet($two, ['activate' => ['plugins/kunde']]);
        $error = $this->assertRefused('wpsync_plugins_invalid', 400, $commit);
        $this->assertSame([['unit' => 'plugins/kunde', 'why' => 'ambiguous']], $error->data['plugins']);
        $this->assertNothingSwapped($id);
    }

    /** AC-180, AC-181: Öffner und Umschlag werden im Commit noch einmal geprüft – vor dem Tausch. */
    public function testOpenerAndEnvelopeAreCheckedAgainBeforeTheSwap(): void
    {
        $units = self::UNITS + ['plugins/x' => ['main.php' => 'new']];
        $id    = $this->uploadedSet($units, $this->wish(['plugins/kunde'], [], self::UNITS));
        PushPlugins::$can = static function (): bool {
            return false; // dem Öffner wurde das Recht inzwischen genommen
        };
        $this->assertRefused('wpsync_plugins_not_allowed', 403, Push::commit(['push_id' => $id], self::KEY));
        $this->assertNothingSwapped($id);

        PushPlugins::$can = static function (int $user): bool {
            return $user === 7;
        };
        $id = $this->uploadedSet($units, $this->wish(['plugins/kunde'], [], self::UNITS));
        unlink($this->sealed($this->live, $id));
        $error = $this->assertRefused('wpsync_plugins_rescue_db', 409, Push::commit(['push_id' => $id], self::KEY));
        $this->assertSame('write_failed', $error->data['detail']);
        $this->assertNothingSwapped($id);
    }

    /** AC-183: ist active_plugins in der Transaktion nicht lesbar, bleibt nichts – Code und Uploads sind zurückgetauscht, der Push ist gescheitert. */
    public function testAnUnreadableListFailsTheSetAndSwapsBack(): void
    {
        $units = self::UNITS + ['plugins/x' => ['main.php' => 'new']];
        $id    = $this->uploadedSet($units, $this->wish(['plugins/kunde'], [], self::UNITS));
        // Zwischen der Prüfung vor dem Tausch und der Transaktion: unmittelbar vor dem Lesen unter Sperre.
        $this->liveDb->beforeLock = static function (ContentMemory $db): void {
            $db->data['options']['active_plugins']['option_value'] = 'kaputt';
        };
        $error = $this->assertRefused('wpsync_plugins_failed', 409, Push::commit(['push_id' => $id], self::KEY));
        $this->assertStringContainsString('active_plugins', $error->message);
        // Nicht überschrieben: die Transaktion hat die Liste nie geschrieben. (Der Store im Speicher stellt beim
        // ROLLBACK seinen Stand vom Beginn der Transaktion wieder her – die fremde Änderung aus beforeLock geht
        // dabei mit verloren; eine echte Datenbank liesse sie stehen. Geprüft wird deshalb am Protokoll.)
        $this->assertNotContains('write options:active_plugins', $this->liveDb->log, 'nicht überschrieben');
        $this->assertNotContains('kunde/kunde.php', $this->active($this->liveDb));
        $this->assertNothingSwapped($id);
    }

    /** AC-183, AC-209: scheitert eine Zeile des Pakets, ist auch an der Liste nichts geschehen. */
    public function testAFailingRowOfThePackageLeavesTheListAlone(): void
    {
        $sha   = $this->stage($this->rows());
        $units = self::UNITS + ['plugins/x' => ['main.php' => 'new']];
        $id    = $this->uploadedSet($units, $this->wish(['plugins/kunde'], ['plugins/old'], self::UNITS), $sha);
        $this->liveDb->failWrite = 2;
        $this->assertRefused('wpsync_content_content_failed', 500, Push::commit(['push_id' => $id], self::KEY));
        $this->assertSame(self::ACTIVE, $this->active($this->liveDb));
        $this->assertSame('Kunde', $this->liveDb->data['options']['blogname']['option_value']);
        $this->assertNothingSwapped($id);
    }

    /** AC-191 (R10): kommt rescue.php mitten in die Transaktion des Commits, entsteht nie „Push abgeschlossen, Liste geändert“. */
    public function testRescueDuringTheCommitNeverLeavesTheListChanged(): void
    {
        $units  = self::UNITS + ['plugins/x' => ['main.php' => 'new']];
        $id     = $this->uploadedSet($units, $this->wish(['plugins/kunde'], ['plugins/old'], self::UNITS));
        $rescue = null;
        $this->liveDb->beforeLock = function () use ($id, &$rescue): void {
            $rescue = $this->rescueDb($id); // der Tausch ist durch, der DB-Anteil „pending“, noch kein Vorher-Abbild
        };
        $commit = Push::commit(['push_id' => $id], self::KEY);
        $this->assertSame([200, ['ok' => true, 'status' => 'rolled_back', 'content' => ['state' => 'nothing']]], $rescue);
        $error = $this->assertRefused('wpsync_content_content_failed', 500, $commit);
        $this->assertStringContainsString('zurückgenommen', $error->message);
        $this->assertSame(self::ACTIVE, $this->active($this->liveDb), 'der Commit hat an seiner Naht ROLLBACK gemacht');
        $this->assertSame('rollback', $this->liveDb->log[count($this->liveDb->log) - 1]);
        $this->assertNothingSwapped($id);
    }

    /** AC-192: in die Kopie – ihre Liste, ihre Dateien; Live bleibt Byte für Byte, wie es war. */
    public function testCommitIntoTheStagingCopy(): void
    {
        $live  = $this->liveDb->data;
        $units = ['plugins/wp-rocket' => ['wp-rocket.php' => "<?php\n/* Plugin Name: WP Rocket\n * Version: 3.0 */\n"]] + self::UNITS;
        list($id, $commit) = $this->pushSet($units, ['target' => 'staging'] + $this->wish(['plugins/wp-rocket', 'plugins/kunde'], ['plugins/old'], $units));
        $data = $this->ok($commit)->data;
        $this->assertSame([
            'activated'   => [['unit' => 'plugins/kunde', 'file' => 'kunde/kunde.php']],
            'deactivated' => [['unit' => 'plugins/old', 'files' => ['old/old.php']]],
            'unchanged'   => [],
            'skipped'     => [['unit' => 'plugins/wp-rocket', 'why' => PushPlugins::DISABLED_ON_STAGING]],
        ], $data['plugins']);
        $this->assertSame(['akismet/akismet.php', 'kunde/kunde.php'], $this->active($this->stagingDb));
        $this->assertSame($live, $this->liveDb->data, 'active_plugins von Live ist bytegleich wie zuvor');
        $this->assertSame([], $this->liveDb->log);
        $this->assertFileExists($this->staging . '/plugins/kunde/kunde.php');
        $this->assertFileExists($this->staging . '/plugins/wp-rocket/wp-rocket.php', 'die Einheit wird getauscht, nur nicht eingeschaltet');
        $this->assertDirectoryDoesNotExist($this->live . '/plugins/kunde');
        $this->assertSame([['step' => 'rewrite_rules', 'ok' => true]], $data['content']['post_actions'], 'in der Kopie nur, was sich per SQL sagen lässt');
        $this->assertSame('applied', $this->rescue($this->staging, $id)['content']['state']);
        $this->assertContains('staging', $this->resolved);
    }

    /** Ein Plan, an dem jemand im Arbeitsordner gedreht hat, gilt nur in der Form, die auch der Begin verlangt. */
    public function testATamperedPlanIsRefusedBeforeTheSwap(): void
    {
        $units = self::UNITS + ['plugins/x' => ['main.php' => 'new']];
        $id    = $this->uploadedSet($units, $this->wish(['plugins/kunde'], [], self::UNITS));
        $file  = $this->work($this->live) . '/' . $id . '/plan.json';
        $plan  = json_decode((string) file_get_contents($file), true);
        $plan['plugins'] = ['activate' => ['plugins/kunde'], 'deactivate' => ['plugins/wpsync-agent']];
        file_put_contents($file, (string) json_encode($plan));
        $this->assertRefused('wpsync_plugins_invalid', 400, Push::commit(['push_id' => $id], self::KEY));
        $this->assertNothingSwapped($id);
    }

    /** Ohne Plugin-Zustand ist der Commit wie vor P4: kein Feld plugins, rescue.json ohne content. */
    public function testWithoutAPluginStateTheCommitIsAsBefore(): void
    {
        list($id, $commit) = $this->pushSet(['plugins/x' => ['main.php' => 'new']]);
        $data = $this->ok($commit)->data;
        $this->assertSame(['next', 'stamps'], array_keys($data));
        $this->assertNull($this->rescue($this->live, $id)['content']);
        $this->assertNull($this->pluginsUnit($id));
        $this->assertSame([], $this->liveDb->log);
    }
}
