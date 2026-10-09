<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use WpSync\Admin;
use WpSync\Push;
use WpSync\PushRescue;
use WpSync\Store;

require_once __DIR__ . '/PushPluginsFlowCase.php';

/**
 * Die Rücknahme eines Satzes mit Plugin-Zustand im echten Ablauf (Spec Content-Push P4 §8.3–§8.5;
 * AC-182, AC-184, AC-185, AC-190, AC-192, AC-193, AC-204): über den Agent, über rescue.php samt
 * Wiederanlauf, in der Kopie – und was im Protokoll und auf der Admin-Seite steht.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PushPluginsRollbackTest extends PushPluginsFlowCase
{
    private const UNITS = ['plugins/kunde' => ['kunde.php' => self::KUNDE], 'plugins/x' => ['main.php' => 'new']];

    /** Ein getauschter, unbestätigter Satz: plugins/kunde neu und aktiviert, plugins/x geändert, „old“ abgeschaltet. */
    private function pushed(string $target = 'live', ?string $sha = null): string
    {
        list($id, $commit) = $this->pushSet(self::UNITS, ['target' => $target] + $this->wish(['plugins/kunde'], ['plugins/old'], self::UNITS), $sha);
        $this->assertInstanceOf(\WP_REST_Response::class, $commit, $commit instanceof \WP_Error ? $commit->code . ' ' . $commit->message : '');
        $GLOBALS['wpsync_post_actions'] = []; // was der Commit an Nacharbeiten lief, zählt hier nicht
        return $id;
    }

    /** @param mixed $result */
    private function ok($result): \WP_REST_Response
    {
        $this->assertInstanceOf(\WP_REST_Response::class, $result, $result instanceof \WP_Error ? $result->code . ' ' . $result->message : '');
        return $result;
    }

    /** AC-184, A17: DB → Code → Uploads; die Liste ist zurück, kein Hook läuft, die Antwort nennt, was geändert wurde. */
    public function testRollbackThroughTheAgent(): void
    {
        $old  = $this->liveDb->data;
        $id   = $this->pushed();
        $seen = null;
        $this->liveDb->beforeLock = function () use (&$seen): void {
            $seen = [is_dir($this->live . '/plugins/kunde'), file_get_contents($this->live . '/plugins/x/main.php')];
        };
        $this->liveDb->log = [];

        $data = $this->ok(Push::rollback(['push_id' => $id], self::KEY))->data;
        $this->assertSame([
            'ok'           => true,
            'status'       => 'rolled_back',
            'post_actions' => [['step' => 'object_cache', 'ok' => true], ['step' => 'plugins_cache', 'ok' => true], ['step' => 'rewrite_rules', 'ok' => true]],
            'plugins'      => ['deactivated' => ['kunde/kunde.php'], 'reactivated' => ['old/old.php']],
        ], $data);
        $this->assertSame([true, 'new'], $seen, 'als die Liste zurückging, lag der neue Code noch (DB → Code)');
        $this->assertSame($old, $this->liveDb->data);
        $this->assertSame(['begin', 'write options:active_plugins', 'commit'], $this->liveDb->log);
        $this->assertDirectoryDoesNotExist($this->live . '/plugins/kunde');
        $this->assertSame('old', file_get_contents($this->live . '/plugins/x/main.php'));
        $this->assertSame([], preg_grep('/^do_action/', $GLOBALS['wpsync_post_actions']), 'A17: kein Deaktivierungs-, kein Aktivierungs-Hook');
        $push = Store::getPush($id);
        $this->assertSame(['rolled_back', true], [$push['status'], $push['pruned']]);
        $unit = $this->pluginsUnit($id);
        $this->assertSame(['deactivated' => ['kunde/kunde.php'], 'reactivated' => ['old/old.php']], $unit['back']);
        $this->assertSame('Plugin-Zustand – aktiviert: kunde/kunde.php; deaktiviert: old/old.php – Plugin-Zustand zurückgenommen', Admin::unitLine($unit));
        $this->assertStringNotContainsString('akismet', (string) json_encode([$data, $push]), 'AC-197');
    }

    /** AC-185: auch ein bestätigter Push geht zurück, wenn seither im WP-Admin ein anderes Plugin geschaltet wurde – das bleibt. */
    public function testAConfirmedPushGoesBackNextToForeignChanges(): void
    {
        $id = $this->pushed();
        $this->ok(Push::confirm(['push_id' => $id], self::KEY));
        $this->assertFileDoesNotExist($this->sealed($this->live, $id), 'R14: mit confirm hat der Umschlag ausgedient');
        $this->adminActivates($this->liveDb, 'fremd/fremd.php');

        $data = $this->ok(Push::rollback(['push_id' => $id], self::KEY))->data;
        $this->assertSame(['deactivated' => ['kunde/kunde.php'], 'reactivated' => ['old/old.php']], $data['plugins']);
        $this->assertSame(['akismet/akismet.php', 'fremd/fremd.php', 'old/old.php'], $this->active($this->liveDb));
        $this->assertSame('rolled_back', Store::getPush($id)['status']);
    }

    /** AC-182: war ein Plugin schon aktiv, lässt es die Rücknahme dieses Pushs aktiv. */
    public function testAPluginThatWasAlreadyActiveStaysActive(): void
    {
        $units = ['plugins/akismet' => ['akismet.php' => "<?php\n/* Plugin Name: Akismet\n * Version: 5.3 */\n"]];
        list($id, $commit) = $this->pushSet($units, $this->wish(['plugins/akismet'], [], $units));
        $this->ok($commit);
        $data = $this->ok(Push::rollback(['push_id' => $id], self::KEY))->data;
        $this->assertSame(['deactivated' => [], 'reactivated' => []], $data['plugins']);
        $this->assertSame(self::ACTIVE, $this->active($this->liveDb));
    }

    /** §8.3 Nr. 2: hat sich eine Paketzeile geändert, bleibt der Satz ganz – Liste, Code und Uploads. */
    public function testAChangedRowKeepsTheWholeSet(): void
    {
        $id = $this->pushed('live', $this->stage($this->rows()));
        $this->liveDb->data['posts']['219']['post_title'] = 'nach dem Push geändert';
        $error = $this->assertRefused('wpsync_content_changed_since_push', 409, Push::rollback(['push_id' => $id], self::KEY));
        $this->assertSame([['table' => 'posts', 'key' => '219']], $error->data['keys']);
        $this->assertSame(['akismet/akismet.php', 'kunde/kunde.php'], $this->active($this->liveDb));
        $this->assertSame('new', file_get_contents($this->live . '/plugins/x/main.php'));
        $this->assertSame('committed', Store::getPush($id)['status']);
    }

    /** AC-186, AC-193: rescue.php nimmt alles ohne WordPress zurück; beim nächsten Laden holt der Agent die Nacharbeiten nach und vermerkt es. */
    public function testRescueTakesTheSetBackAndTheAgentCatchesUp(): void
    {
        $old  = $this->liveDb->data;
        $id   = $this->pushed();
        $body = $this->rescued($id);
        $this->assertSame([
            'ok' => true, 'status' => 'rolled_back',
            'content' => ['state' => 'rolled_back', 'cache' => 'none'],
            'plugins' => ['deactivated' => ['kunde/kunde.php'], 'reactivated' => ['old/old.php']],
        ], $body);
        $this->assertSame($old, $this->liveDb->data);
        $this->assertDirectoryDoesNotExist($this->live . '/plugins/kunde');
        $this->assertSame([], $GLOBALS['wpsync_post_actions'], 'rescue.php kennt kein WordPress');
        $this->assertSame('committed', Store::getPush($id)['status'], 'die Datenbank von WordPress weiss noch nichts davon');

        Push::catchUp(); // init, Priorität 1

        $push = Store::getPush($id);
        $this->assertSame(['rolled_back', true], [$push['status'], $push['pruned']]);
        $unit = $this->pluginsUnit($id);
        $this->assertSame('rescue', $unit['via']);
        $this->assertSame(['object_cache', 'plugins_cache', 'rewrite_rules'], array_column($unit['post_actions'], 'step'), 'dieselben Schritte wie nach einer Rücknahme über den Agent');
        $this->assertSame(['deactivated' => ['kunde/kunde.php'], 'reactivated' => ['old/old.php']], $unit['back']);
        $this->assertContains('wp_cache_delete ["active_plugins","options"]', $GLOBALS['wpsync_post_actions']);
        $this->assertSame('Plugin-Zustand – aktiviert: kunde/kunde.php; deaktiviert: old/old.php – Plugin-Zustand zurückgenommen (über rescue.php)', Admin::unitLine($unit));
        $this->assertSame([], $this->pushDirs($this->live));
        $this->assertNull(Push::pending());
    }

    /** Mit Paket und Plugin-Zustand trägt die Einheit content die Nacharbeiten, die Einheit plugins nur, was an der Liste geschah. */
    public function testWithAPackageTheNotesAreSplitBetweenTheTwoUnits(): void
    {
        $id = $this->pushed('live', $this->stage($this->rows()));
        $this->rescued($id);
        Push::catchUp();
        $units = [];
        foreach (Store::getPush($id)['units'] as $unit) {
            $units[$unit['path']] = $unit;
        }
        $this->assertSame('rescue', $units['content']['via']);
        $this->assertArrayHasKey('post_actions', $units['content']);
        $this->assertSame('rescue', $units['plugins']['via']);
        $this->assertArrayNotHasKey('post_actions', $units['plugins']);
        $this->assertSame(['deactivated' => ['kunde/kunde.php'], 'reactivated' => ['old/old.php']], $units['plugins']['back']);
    }

    /** AC-190: liess rescue.php den DB-Anteil stehen, schliesst die Rücknahme über den Agent danach ab. */
    public function testAfterAKeptStateTheAgentFinishes(): void
    {
        $old = $this->liveDb->data;
        $id  = $this->pushed();
        $pushed = $this->liveDb->data['options']['active_plugins']['option_value'];
        $this->liveDb->data['options']['active_plugins']['option_value'] = 'kaputt';
        $body = $this->rescued($id);
        $this->assertSame('kept', $body['content']['state']);
        $this->assertSame(['content_not_rolled_back', 'plugins_not_restored'], $body['warnings']);
        $this->assertSame(['added' => ['kunde/kunde.php'], 'removed' => ['old/old.php']], $body['plugins_not_restored']);
        $this->assertDirectoryDoesNotExist($this->live . '/plugins/kunde', 'Code und Uploads gehen trotzdem zurück (R7)');
        Push::catchUp();
        $this->assertSame('committed', Store::getPush($id)['status'], 'der Push bleibt offen, solange sein DB-Anteil steht');

        $this->liveDb->data['options']['active_plugins']['option_value'] = $pushed; // die Liste ist wieder lesbar
        $data = $this->ok(Push::rollback(['push_id' => $id], self::KEY))->data;
        $this->assertSame(['deactivated' => ['kunde/kunde.php'], 'reactivated' => ['old/old.php']], $data['plugins']);
        $this->assertSame($old, $this->liveDb->data);
        $this->assertSame('rolled_back', Store::getPush($id)['status']);
    }

    /** D24 für einen Satz ohne Paket (V12): confirm nach rescue.php nimmt den Plugin-Zustand an, wie er steht – an der Einheit plugins vermerkt. */
    public function testConfirmAfterRescueKeepsThePluginState(): void
    {
        list($id, $commit) = $this->pushSet([], $this->wish([], ['plugins/old']));
        $this->ok($commit);
        $this->liveDb->data['options']['active_plugins']['option_value'] = 'kaputt';
        $this->assertSame('kept', $this->rescued($id)['content']['state']);

        $kept = ['ok' => true, 'status' => 'rolled_back', 'warnings' => [PushRescue::CONTENT_KEPT]];
        $this->assertSame($kept, $this->ok(Push::confirm(['push_id' => $id], self::KEY))->data);
        $unit = $this->pluginsUnit($id);
        $this->assertTrue($unit['kept']);
        $this->assertSame('Plugin-Zustand – deaktiviert: old/old.php – steht noch (mit confirm angenommen)', Admin::unitLine($unit));
        $this->assertSame($kept, $this->ok(Push::confirm(['push_id' => $id], self::KEY))->data, 'eine verlorene Antwort lässt sich wiederholen');
        $this->assertRefused('wpsync_push_state', 409, Push::rollback(['push_id' => $id], self::KEY));
        $this->assertNull(Push::pending());
    }

    /** AC-192: in der Kopie – über den Agent und über rescue.php; Live bleibt unberührt. */
    public function testRollbackInTheStagingCopy(): void
    {
        $live  = $this->liveDb->data;
        $stage = $this->stagingDb->data;
        $id    = $this->pushed('staging');
        $this->assertSame(['akismet/akismet.php', 'kunde/kunde.php'], $this->active($this->stagingDb));
        $data = $this->ok(Push::rollback(['push_id' => $id], self::KEY))->data;
        $this->assertSame(['deactivated' => ['kunde/kunde.php'], 'reactivated' => ['old/old.php']], $data['plugins']);
        $this->assertSame([['step' => 'rewrite_rules', 'ok' => true]], $data['post_actions']);
        $this->assertSame($stage, $this->stagingDb->data);
        $this->assertDirectoryDoesNotExist($this->staging . '/plugins/kunde');

        $id   = $this->pushed('staging');
        $body = $this->rescued($id);
        $this->assertSame(['deactivated' => ['kunde/kunde.php'], 'reactivated' => ['old/old.php']], $body['plugins']);
        $this->assertSame($stage, $this->stagingDb->data);
        $this->assertSame($live, $this->liveDb->data, 'kein Byte von Live');
        $this->assertSame([], $this->liveDb->log);
    }

    /** §8.5: was die Admin-Seite je Stand der Einheit plugins zeigt. */
    public function testTheAdminLineOfThePluginUnit(): void
    {
        $unit = ['path' => 'plugins', 'exists' => true, 'old_version' => '', 'new_version' => '', 'files' => 0, 'uploaded' => 0, 'activate' => ['plugins/kunde'], 'deactivate' => ['plugins/old']];
        $this->assertSame('Plugin-Zustand – vorgesehen: aktivieren plugins/kunde; deaktivieren plugins/old', Admin::unitLine($unit));
        $this->assertSame('Plugin-Zustand – unverändert', Admin::unitLine($unit + ['activated' => [], 'deactivated' => []]));
        $this->assertSame('Plugin-Zustand – aktiviert: kunde/kunde.php', Admin::unitLine($unit + ['activated' => ['kunde/kunde.php', 7], 'deactivated' => 'kaputt']));
        $this->assertSame('Plugin-Zustand – vorgesehen: nichts', Admin::unitLine(['path' => 'plugins']));
        $this->assertStringContainsString('esc_html(self::unitLine($unit))', (string) file_get_contents(__DIR__ . '/../src/Admin.php'), 'die Zeile wird escaped ausgegeben');
    }

    /**
     * Ergänzt beim Umsetzen (nicht im Plan), V1 über den Agent: hat der Commit „applied“ nie vermerkt, zählt
     * für die Liste der Abdruck – eine fremde Änderung sperrt die Rücknahme, der Satz bleibt ganz.
     */
    public function testAnUnacknowledgedCommitIsJudgedByTheFingerprintThroughTheAgent(): void
    {
        $old    = $this->liveDb->data;
        $id     = $this->pushed();
        $pushed = $this->liveDb->data['options']['active_plugins']['option_value'];
        PushRescue::setContentFields($this->work($this->live), $id, ['state' => PushRescue::CONTENT_PENDING]); // PHP starb vor „applied“
        $this->adminActivates($this->liveDb, 'fremd/fremd.php');

        $error = $this->assertRefused('wpsync_content_changed_since_push', 409, Push::rollback(['push_id' => $id], self::KEY));
        $this->assertSame([['table' => 'options', 'key' => 'active_plugins']], $error->data['keys']);
        $this->assertSame(['akismet/akismet.php', 'fremd/fremd.php', 'kunde/kunde.php'], $this->active($this->liveDb));
        $this->assertSame('new', file_get_contents($this->live . '/plugins/x/main.php'), 'auch der Code bleibt');
        $this->assertSame('committed', Store::getPush($id)['status']);

        $this->liveDb->data['options']['active_plugins']['option_value'] = $pushed; // bytegleich der Stand des Pushs
        $data = $this->ok(Push::rollback(['push_id' => $id], self::KEY))->data;
        $this->assertSame(['deactivated' => ['kunde/kunde.php'], 'reactivated' => ['old/old.php']], $data['plugins']);
        $this->assertSame($old, $this->liveDb->data);
    }

    /** Ergänzt: eine wiederholte Rücknahme über den Agent fasst die Liste nicht noch einmal an. */
    public function testASecondRollbackThroughTheAgentNeverSwitchesAgain(): void
    {
        $id = $this->pushed();
        $this->ok(Push::rollback(['push_id' => $id], self::KEY));
        $this->adminActivates($this->liveDb, 'kunde/kunde.php'); // ein Administrator schaltet es selbst wieder ein
        $stands            = $this->liveDb->data;
        $this->liveDb->log = [];
        $this->assertSame(['ok' => true, 'status' => 'rolled_back'], $this->ok(Push::rollback(['push_id' => $id], self::KEY))->data);
        $this->assertSame($stands, $this->liveDb->data);
        $this->assertSame([], $this->liveDb->log);
    }
}
