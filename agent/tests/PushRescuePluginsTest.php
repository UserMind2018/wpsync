<?php
declare(strict_types=1);

namespace WpSync\Tests;

use WpSync\ContentImage;
use WpSync\ContentTarget;
use WpSync\PushRescue;
use WpSync\RescueContent;
use WpSync\RescueSeal;

require_once __DIR__ . '/ContentRollbackPluginsCase.php';

/**
 * rescue.php mit Plugin-Zustand (Spec Content-Push P4 §4.4, §8.4, §8.5, A18; AC-186, AC-187, AC-189,
 * AC-190, AC-197, AC-204, AC-205): die Liste geht ohne WordPress zurück – vor Code und Uploads –,
 * und was stehen bleibt, sagt die Antwort. Die Tabellen des Ziels sind ein Store im Speicher
 * (Naht RescueContent::$resolve).
 */
final class PushRescuePluginsTest extends ContentRollbackPluginsCase
{
    private const ID = 'p_20261009_0123456789ab';

    private string $root;
    private string $content;
    private string $work;
    private string $key;
    /** @var int wie oft die Naht nach dem Ziel gefragt wurde */
    private int $connected = 0;
    /** @var list<array<string, mixed>> jede Antwort dieses Tests */
    private array $answers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->root    = (string) realpath(sys_get_temp_dir()) . '/wpsync-rescueplg-' . bin2hex(random_bytes(4));
        $this->content = $this->root . '/wp-content';
        $this->work    = $this->content . '/wpsync-push-0123456789abcdef';
        $this->key     = PushRescue::key(str_repeat('ab', 32), self::ID, 'salt');
        ContentImage::$keys    = [hash('sha256', 'schlüssel der installation', true)];
        ContentImage::$encrypt = false;
        RescueContent::$resolve = function (array $data, string $contentDir): ?ContentTarget {
            $this->connected++;
            return ContentFixtures::live($this->store);
        };
    }

    protected function tearDown(): void
    {
        // AC-197: keine Antwort nennt die Liste des Ziels oder einen Eintrag, den der Push nicht anfasst.
        foreach ($this->answers as $answer) {
            $this->assertStringNotContainsString('akismet', (string) json_encode($answer));
        }
        RescueContent::$resolve = null;
        ContentImage::$fileKeys = null;
        exec('chmod -R u+w ' . escapeshellarg($this->root) . ' 2>/dev/null; rm -rf ' . escapeshellarg($this->root));
        parent::tearDown();
    }

    /** @return array<string, mixed> was PushContent::rescueData() sammelt */
    private function collected(): array
    {
        return [
            'target'     => 'live',
            'db'         => ['host' => 'db.internal', 'port' => 3306, 'socket' => null, 'user' => 'wp_user', 'password' => 'geh3im!', 'name' => 'wordpress_db', 'flags' => 0, 'charset' => 'utf8mb4', 'collate' => '', 'sql_mode' => ''],
            'prefix'     => 'wp_',
            'home'       => ContentFixtures::HOME,
            'siteurl'    => ContentFixtures::HOME,
            'staging'    => null,
            'image_keys' => [
                ContentImage::BEFORE => array_map('base64_encode', ContentImage::fileKeys(self::ID, ContentImage::BEFORE)),
                ContentImage::AFTER  => array_map('base64_encode', ContentImage::fileKeys(self::ID, ContentImage::AFTER)),
            ],
        ];
    }

    /**
     * Ein getauschter, unbestätigter Push mit Plugin-Zustand: die neue Einheit plugins/kunde liegt an
     * ihrem Platz (kein Snapshot – es gab sie nicht), rescue.json nennt den DB-Anteil, der Umschlag ist
     * versiegelt, die Transaktion ist durch.
     *
     * @param list<string>                    $add
     * @param list<string>                    $drop
     * @param list<array<string, mixed>>|null $rows         Zeilen eines Pakets; null: der Satz hat keins
     * @param bool                            $acknowledged der Agent hat content.state = applied noch geschrieben
     */
    private function pushed(array $add, array $drop, ?array $rows = null, bool $acknowledged = true): void
    {
        $target = $this->content . '/plugins/kunde';
        mkdir($target, 0777, true);
        file_put_contents($target . '/kunde.php', "<?php\n/* Plugin Name: Kunde */\n");
        mkdir($this->work . '/' . self::ID, 0777, true);
        PushRescue::write(
            $this->work,
            self::ID,
            hash('sha256', $this->key),
            [['unit' => 'plugins/kunde', 'target' => $target, 'snapshot' => null, 'discard' => $this->work . '/' . self::ID . '/discard/0']],
            PushRescue::COMMITTED,
            [],
            $rows === null ? null : str_repeat('ab', 32),
            true
        );
        $this->dir = $this->work . '/' . self::ID . '/content';
        $this->assertSame(['ok' => true], RescueContent::prepare($this->work, self::ID, $this->content, $this->key, $this->collected(), true));
        $result = $this->push($rows, $add, $drop);
        if ($acknowledged) {
            PushRescue::setContentFields($this->work, self::ID, ['state' => PushRescue::CONTENT_APPLIED, 'plugins' => $result['plugins']]);
        }
        $this->store        = new ContentMemory($this->store->data); // Zähler und Protokoll von vorn
        $this->connected    = 0;
        ContentImage::$keys = []; // rescue.php kennt den Schlüssel der Installation nicht
    }

    /**
     * @param array<string, mixed> $over
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function rescue(array $over = []): array
    {
        $answer = PushRescue::handle([$this->content], $over + ['action' => 'rollback', 'push_id' => self::ID, 'key' => $this->key, 'content' => '1'], time());
        if ($answer[0] === 200) {
            $this->assertSame(self::ID, $answer[1]['push_id'] ?? null);
            unset($answer[1]['push_id']);
        }
        $this->answers[] = $answer[1];
        return $answer;
    }

    /** @return array<string, mixed> */
    private function record(): array
    {
        return (array) PushRescue::read($this->work, self::ID);
    }

    /** AC-186 (ohne Webserver): die Liste geht ohne WordPress zurück, dann der Code; rescue.json merkt sich beides. */
    public function testRescueTakesAnActivationBack(): void
    {
        $this->pushed(['kunde/kunde.php'], []);
        $this->assertSame(['state' => 'applied', 'sha256' => null, 'plugins' => ['added' => ['kunde/kunde.php'], 'removed' => []]], $this->record()['content']);
        $seen                    = null;
        $this->store->beforeLock = function () use (&$seen): void {
            $seen = is_dir($this->content . '/plugins/kunde');
        };

        $this->assertSame(
            [200, ['ok' => true, 'status' => 'rolled_back', 'content' => ['state' => 'rolled_back', 'cache' => 'none'], 'plugins' => ['deactivated' => ['kunde/kunde.php'], 'reactivated' => []]]],
            $this->rescue()
        );
        $this->assertTrue($seen, 'als die Liste zurückging, lag der Code noch (DB → Code)');
        $this->assertSame(self::ACTIVE, $this->active());
        $this->assertDirectoryDoesNotExist($this->content . '/plugins/kunde', 'die neue Einheit ist wieder weg');
        $this->assertSame(1, $this->connected);
        $record = $this->record();
        $this->assertSame('rolled_back', $record['status']);
        $this->assertSame([
            'state' => 'rolled_back', 'sha256' => null, 'plugins' => ['added' => ['kunde/kunde.php'], 'removed' => []],
            'via' => 'rescue', 'post' => 'pending', 'cache' => 'none', 'plugins_back' => ['deactivated' => ['kunde/kunde.php'], 'reactivated' => []],
        ], $record['content']);
        $this->assertFileDoesNotExist(RescueSeal::file($this->work, self::ID), 'R14: der Umschlag hat ausgedient');
        $this->assertFileExists(PushRescue::pendingFile($this->work), 'der Agent holt die Nacharbeiten nach');
    }

    /** AC-204, AC-205 (ohne Webserver): ein abgeschaltetes Plugin steht nach rescue.php wieder in der Liste. */
    public function testRescueTakesADeactivationBack(): void
    {
        $this->pushed([], ['old']);
        $this->assertSame(['akismet/akismet.php'], $this->active());
        list($status, $body) = $this->rescue();
        $this->assertSame(200, $status);
        $this->assertSame(['deactivated' => [], 'reactivated' => ['old/old.php']], $body['plugins']);
        $this->assertSame(self::ACTIVE, $this->active());
    }

    /** AC-166: jeder weitere Aufruf antwortet gleich – ohne Umschlag, ohne Verbindung. */
    public function testARepeatedCallAnswersTheSame(): void
    {
        $this->pushed(['kunde/kunde.php'], ['old']);
        $first = $this->rescue();
        $this->assertSame(['deactivated' => ['kunde/kunde.php'], 'reactivated' => ['old/old.php']], $first[1]['plugins']);
        $this->assertSame($first, $this->rescue());
        $this->assertSame(1, $this->connected);
    }

    /** AC-187: mit einem Object-Cache-Drop-in hält der Cache die Liste des Pushs – die Antwort sagt stale. */
    public function testAnObjectCacheDropInMakesTheCacheStale(): void
    {
        $this->pushed(['kunde/kunde.php'], []);
        file_put_contents($this->content . '/object-cache.php', '<?php');
        $this->assertSame('stale', $this->rescue()[1]['content']['cache']);
        // Hat der Push an der Liste nichts geändert, wurde nichts geschrieben: kein Cache-Schritt.
        exec('rm -rf ' . escapeshellarg($this->work . '/' . self::ID) . ' ' . escapeshellarg($this->work . '/' . PushRescue::PENDING_FILE));
        ContentImage::$keys = [hash('sha256', 'schlüssel der installation', true)];
        $this->pushed(['akismet/akismet.php'], []);
        $body = $this->rescue()[1];
        $this->assertSame(['state' => 'nothing'], $body['content']);
        $this->assertSame(['deactivated' => [], 'reactivated' => []], $body['plugins']);
    }

    /** AC-190, A18: bleibt der DB-Anteil stehen, gehen Code und Uploads trotzdem zurück – mit beiden Warnungen und den Einträgen. */
    public function testAChangedRowKeepsThePluginStateAndSaysSo(): void
    {
        $this->pushed(['kunde/kunde.php'], ['old'], $this->rows());
        $this->store->data['posts']['219']['post_title'] = 'nach dem Push geändert';
        $stands = $this->store->data;

        $this->assertSame([200, [
            'ok' => true, 'status' => 'rolled_back',
            'content'              => ['state' => 'kept', 'error' => ['code' => 'changed_since_push', 'keys' => [['table' => 'posts', 'key' => '219']], 'total' => 1]],
            'plugins_not_restored' => ['added' => ['kunde/kunde.php'], 'removed' => ['old/old.php']],
            'warnings'             => ['content_not_rolled_back', 'plugins_not_restored'],
        ]], $this->rescue());
        $this->assertSame($stands, $this->store->data, 'in der Datenbank ist nichts angefasst');
        $this->assertDirectoryDoesNotExist($this->content . '/plugins/kunde', 'der Code ist zurück: den Eintrag ohne Datei überspringt WordPress');
        $this->assertTrue(PushRescue::contentOpen($this->record()));
        $this->assertFileExists(RescueSeal::file($this->work, self::ID), 'der Umschlag bleibt für den nächsten Versuch');
    }

    /** AC-190: ist die Liste nicht lesbar oder fehlt der Umschlag, bleibt der DB-Anteil stehen – gemeldet, nicht verschwiegen. */
    public function testAnUnreadableListOrNoEnvelopeKeepsTheState(): void
    {
        $this->pushed(['kunde/kunde.php'], []);
        $this->store->data['options']['active_plugins']['option_value'] = 'kaputt';
        $body = $this->rescue()[1];
        $this->assertSame(['state' => 'kept', 'error' => ['code' => 'changed_since_push', 'keys' => [['table' => 'options', 'key' => 'active_plugins']], 'total' => 1]], $body['content']);
        $this->assertSame(['content_not_rolled_back', 'plugins_not_restored'], $body['warnings']);
        $this->assertArrayNotHasKey('plugins', $body);

        // Die Wiederholung ohne Umschlag: Code und Uploads sind schon zurück, der DB-Anteil bleibt offen.
        RescueSeal::forget($this->work, self::ID);
        $body = $this->rescue()[1];
        $this->assertSame(['state' => 'kept', 'error' => ['code' => 'rescue_db_unavailable']], $body['content']);
        $this->assertSame(['added' => ['kunde/kunde.php'], 'removed' => []], $body['plugins_not_restored']);
    }

    /** AC-174 für P4: ohne content=1 (ältere CLI) nimmt rescue.php nur Code und Uploads zurück – und sagt, was stehen bleibt. */
    public function testWithoutTheWishOfTheCallerThePluginStateStays(): void
    {
        $this->pushed(['kunde/kunde.php'], []);
        $this->assertSame([200, [
            'ok' => true, 'status' => 'rolled_back',
            'plugins_not_restored' => ['added' => ['kunde/kunde.php'], 'removed' => []],
            'warnings'             => ['content_not_rolled_back', 'plugins_not_restored'],
        ]], $this->rescue(['content' => '0']));
        $this->assertSame(0, $this->connected);
        $this->assertSame(['akismet/akismet.php', 'kunde/kunde.php', 'old/old.php'], $this->active());
    }

    /** V1: starb der Agent, bevor er „applied“ vermerkte, zählt für die Liste der Abdruck des Pushs. */
    public function testAnUnacknowledgedCommitIsJudgedByTheFingerprint(): void
    {
        $this->pushed(['kunde/kunde.php'], [], null, false);
        $this->assertSame('pending', $this->record()['content']['state']);
        $this->adminActivates('fremd/fremd.php');
        $body = $this->rescue()[1];
        $this->assertSame('changed_since_push', $body['content']['error']['code']);
        $this->assertSame([['table' => 'options', 'key' => 'active_plugins']], $body['content']['error']['keys']);

        // Steht die Liste wieder bytegleich wie nach dem Push, holt derselbe Aufruf den DB-Anteil nach.
        $this->store->data['options']['active_plugins']['option_value'] = serialize(['akismet/akismet.php', 'kunde/kunde.php', 'old/old.php']);
        $body = $this->rescue()[1];
        $this->assertSame('rolled_back', $body['content']['state']);
        $this->assertSame(['deactivated' => ['kunde/kunde.php'], 'reactivated' => []], $body['plugins']);
        $this->assertSame(self::ACTIVE, $this->active());
    }

    /** AC-189, §12: kein Parameter des Requests wählt einen Eintrag – es zählt allein das authentisierte Abbild. */
    public function testNoRequestParameterChoosesAnEntry(): void
    {
        $this->pushed(['kunde/kunde.php'], []);
        $body = $this->rescue([
            'plugins' => 'evil/evil.php', 'added' => 'old/old.php', 'removed' => 'evil/evil.php', 'activate' => 'plugins/evil', 'deactivate' => 'plugins/old',
        ])[1];
        $this->assertSame(['deactivated' => ['kunde/kunde.php'], 'reactivated' => []], $body['plugins']);
        $this->assertSame(self::ACTIVE, $this->active());
    }

    /** §14 Nr. 12: rescue.json ist nicht authentisiert – was daraus in eine Antwort geht, hat feste Form und Grenze, und geschrieben wird nie daraus. */
    public function testWhatRescueJsonClaimsOnlyReachesTheAnswer(): void
    {
        $this->pushed(['kunde/kunde.php'], []);
        $many = [];
        for ($i = 0; $i < 150; $i++) {
            $many[] = 'evil/p' . $i . '.php';
        }
        PushRescue::setContentFields($this->work, self::ID, ['plugins' => ['added' => ['../x.php', 7, "a/b\x01.php", 'ok/ok.php'], 'removed' => $many]]);
        $body = $this->rescue(['content' => '0'])[1];
        $this->assertSame(['ok/ok.php'], $body['plugins_not_restored']['added']);
        $this->assertCount(100, $body['plugins_not_restored']['removed']);
        // Mit content=1 entscheidet das Abbild – die gefälschten Einträge erreichen die Datenbank nie.
        $this->rescue();
        $this->assertSame(self::ACTIVE, $this->active());
    }

    /** Ein Push ohne Plugin-Zustand antwortet und speichert wie vor P4. */
    public function testWithoutAPluginStateTheAnswerIsAsBefore(): void
    {
        PushRescue::write($this->work, self::ID, hash('sha256', $this->key), [], PushRescue::COMMITTED, [], str_repeat('ab', 32));
        $this->assertSame(['state' => 'pending', 'sha256' => str_repeat('ab', 32)], $this->record()['content']);
        PushRescue::write($this->work, self::ID, hash('sha256', $this->key), [], PushRescue::COMMITTED);
        $this->assertNull($this->record()['content']);
        $this->assertSame([200, ['ok' => true, 'status' => 'rolled_back']], $this->rescue());
    }

    /**
     * Ergänzt beim Umsetzen (nicht im Plan): nach der Rücknahme schaltet ein Administrator das Plugin
     * selbst wieder ein. Ein weiterer Aufruf von rescue.php nimmt es ihm nicht – der DB-Anteil ist
     * abgeschlossen, die Liste wird nicht mehr gelesen.
     */
    public function testARepeatedCallNeverSwitchesTheListAgain(): void
    {
        $this->pushed(['kunde/kunde.php'], ['old']);
        $first = $this->rescue();
        $this->assertSame(self::ACTIVE, $this->active());
        $this->adminActivates('kunde/kunde.php');
        $this->adminDeactivates('old/old.php');
        $stands           = $this->store->data;
        $this->store->log = [];
        $this->assertSame($first, $this->rescue());
        $this->assertSame($stands, $this->store->data);
        $this->assertSame([], $this->store->log);
        $this->assertSame(1, $this->connected);
    }

    /** Ergänzt: hat jemand die Liste schon von Hand zurückgestellt, ist nichts zu tun – der Code geht trotzdem zurück. */
    public function testAListAlreadySwitchedBackByHandIsNothingToDo(): void
    {
        $this->pushed(['kunde/kunde.php'], ['old']);
        $this->adminDeactivates('kunde/kunde.php');
        $this->adminActivates('old/old.php');
        $stands = $this->store->data;
        list($status, $body) = $this->rescue();
        $this->assertSame(200, $status);
        $this->assertSame(['state' => 'nothing'], $body['content']);
        $this->assertSame(['deactivated' => [], 'reactivated' => []], $body['plugins']);
        $this->assertArrayNotHasKey('warnings', $body);
        $this->assertSame($stands, $this->store->data);
        $this->assertDirectoryDoesNotExist($this->content . '/plugins/kunde');
        $this->assertFalse(PushRescue::contentOpen($this->record()));
    }

    /**
     * Ergänzt: ein doppelter Eintrag des Pushs in der Liste und ein fremder daneben – rescue.php streicht
     * jedes Vorkommen des eigenen und lässt den fremden stehen.
     */
    public function testRescueRemovesEveryOccurrenceOfItsEntry(): void
    {
        $this->pushed(['kunde/kunde.php'], []);
        $this->store->data['options']['active_plugins']['option_value'] = serialize(
            [0 => 'kunde/kunde.php', 3 => 'fremd/fremd.php', 5 => 'kunde/kunde.php', 6 => 'old/old.php']
        );
        $body = $this->rescue()[1];
        $this->assertSame(['deactivated' => ['kunde/kunde.php'], 'reactivated' => []], $body['plugins']);
        $this->assertSame(['fremd/fremd.php', 'old/old.php'], $this->active());
    }

    /**
     * Security-Review P4 S2 über rescue.php: der unbestätigte Push hat X nur geschaltet (kein Paar), ein
     * späterer hat X getauscht – rescue.php lehnt die Rücknahme ab, bevor es Sperre, Umschlag oder Datenbank anfasst.
     */
    public function testRescueRefusesAPushWhoseSwitchedUnitALaterPushSwapped(): void
    {
        $this->pushed([], ['old']);
        $record             = $this->record();
        $record['switched'] = ['plugins/old'];
        file_put_contents(PushRescue::file($this->work, self::ID), (string) json_encode($record));
        $later = 'p_20261009_ba9876543210';
        mkdir($this->work . '/' . $later, 0777, true);
        PushRescue::write($this->work, $later, hash('sha256', 'x'), [['unit' => 'plugins/old', 'target' => $this->content . '/plugins/old', 'snapshot' => null, 'discard' => $this->work . '/' . $later . '/discard/0']], PushRescue::COMMITTED);
        PushRescue::supersede($this->work, $later, ['plugins/old']);
        $this->assertSame($later, $this->record()['superseded_by']);

        $stands = $this->store->data;
        $this->assertSame([409, ['ok' => false, 'error' => 'superseded', 'by' => $later]], $this->rescue());
        $this->assertSame($stands, $this->store->data);
        $this->assertSame(0, $this->connected);

        // Ein späterer Push, der die Einheit nur schaltet, überholt ebenso; einer mit anderen Einheiten nicht.
        PushRescue::setStatus($this->work, $later, PushRescue::ROLLED_BACK);
        $third = 'p_20261009_cccccccccccc';
        mkdir($this->work . '/' . $third, 0777, true);
        PushRescue::write($this->work, $third, hash('sha256', 'y'), [], PushRescue::COMMITTED, [], null, true, ['plugins/anderes']);
        PushRescue::supersede($this->work, $third, PushRescue::unitsOf((array) PushRescue::read($this->work, $third)));
        $this->assertSame($later, $this->record()['superseded_by'], 'unverändert – und der zurückgerollte spätere sperrt nicht mehr');
        $this->assertSame(200, $this->rescue()[0]);
    }
}
