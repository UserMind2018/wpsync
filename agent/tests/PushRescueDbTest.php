<?php
declare(strict_types=1);

namespace WpSync\Tests;

use WpSync\ContentImage;
use WpSync\ContentTarget;
use WpSync\PushRescue;
use WpSync\RescueContent;
use WpSync\RescueSeal;

require_once __DIR__ . '/ContentApplyCase.php';

/**
 * rescue.php mit DB-Anteil (Spec Content-Push P3 §4.1, §7; AC-159…AC-167, AC-169, AC-173):
 * Reihenfolge DB → Code → Uploads, was vor der Datenbank ablehnt, die Sperre, die Wiederholung
 * und der Rückfall auf Code und Uploads. Die Tabellen des Ziels sind ein Store im Speicher
 * (Naht RescueContent::$resolve); „verbunden“ heisst hier: die Naht wurde gefragt.
 */
final class PushRescueDbTest extends ContentApplyCase
{
    private const ID      = 'p_20261009_0123456789ab';
    private const OTHER   = 'p_20261010_ba9876543210';
    private const SHA     = 'ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12';
    private const STAGING = 'wpsync-staging-0123456789ab';
    /** Was nie in einer Antwort stehen darf (AC-173). */
    private const SECRETS = ['geh3im!', 'wp_user', 'db.internal', 'wordpress_db', 'GEHEIM'];

    private string $root;
    private string $content;
    private string $work;
    private string $key;
    /** @var int wie oft die Naht nach dem Ziel gefragt wurde */
    private int $connected = 0;
    /** @var array<string, array<string, array<string, mixed>>> Stand vor dem Push */
    private array $old = [];
    /** @var list<array<string, mixed>> jede Antwort dieses Tests */
    private array $answers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->root    = (string) realpath(sys_get_temp_dir()) . '/wpsync-rescuedb-' . bin2hex(random_bytes(4));
        $this->content = $this->root . '/wp-content';
        $this->key     = PushRescue::key(str_repeat('ab', 32), self::ID, 'salt');
        ContentImage::$keys    = [hash('sha256', 'schlüssel der installation', true)];
        ContentImage::$encrypt = false;
        $this->work = $this->push($this->content);
        $this->old  = $this->store->data;
        RescueContent::$resolve = function (array $data, string $contentDir): ?ContentTarget {
            $this->connected++;
            return ContentFixtures::live($this->store);
        };
    }

    protected function tearDown(): void
    {
        foreach ($this->answers as $answer) {
            $json = (string) json_encode($answer);
            foreach (self::SECRETS as $secret) {
                $this->assertStringNotContainsString($secret, $json, 'AC-173');
            }
        }
        RescueContent::$resolve = null;
        ContentImage::$keys     = null;
        ContentImage::$encrypt  = null;
        ContentImage::$fileKeys = null;
        array_map('unlink', array_filter($this->files, 'is_file'));
        exec('chmod -R u+w ' . escapeshellarg($this->root) . ' 2>/dev/null; rm -rf ' . escapeshellarg($this->root));
    }

    /**
     * Ein getauschter Push in diesem wp-content: ein Plugin (neu ↔ alt), ein hinzugefügter Upload,
     * rescue.json mit DB-Anteil. Liefert den Arbeitsordner.
     */
    private function push(string $content, string $id = self::ID): string
    {
        $work     = $content . '/wpsync-push-0123456789abcdef';
        $target   = $content . '/plugins/x';
        $snapshot = $work . '/' . $id . '/old/0';
        foreach ([$target, $snapshot, $content . '/uploads/2026/10'] as $dir) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($target . '/main.php', 'new');
        file_put_contents($snapshot . '/main.php', 'old');
        file_put_contents($content . '/uploads/2026/10/neu.png', 'neu');
        PushRescue::write(
            $work,
            $id,
            hash('sha256', $this->key),
            [['unit' => 'plugins/x', 'target' => $target, 'snapshot' => $snapshot, 'discard' => $work . '/' . $id . '/discard/0']],
            PushRescue::COMMITTED,
            ['added' => [['path' => '2026/10/neu.png', 'sha256' => hash('sha256', 'neu')]], 'dirs' => []],
            self::SHA
        );
        return $work;
    }

    /** Derselbe Push, aber nach Staging: sein Datensatz liegt nur im wp-content der Kopie. */
    private function pushIntoTheCopy(string $copy): string
    {
        exec('rm -rf ' . escapeshellarg($this->work . '/' . self::ID));
        return $this->push($copy);
    }

    /**
     * @param array<string, mixed> $over
     * @return array<string, mixed> was PushContent::rescueData() sammelt
     */
    private function collected(array $over = []): array
    {
        return $over + [
            'target'     => 'live',
            'db'         => [
                'host' => 'db.internal', 'port' => 3306, 'socket' => null, 'user' => 'wp_user', 'password' => 'geh3im!', 'name' => 'wordpress_db',
                'flags' => 0, 'charset' => 'utf8mb4', 'collate' => '', 'sql_mode' => '',
            ],
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

    /** Der Push hat seine Inhalte angewandt: Abbilder im Arbeitsordner, Umschlag versiegelt, rescue.json „applied“. */
    private function applied(?array $collected = null, ?string $content = null): void
    {
        $content   = $content ?? $this->content;
        $work      = $content . '/wpsync-push-0123456789abcdef';
        $this->dir = $work . '/' . self::ID . '/content';
        $this->assertSame(['ok' => true], RescueContent::prepare($work, self::ID, $content, $this->key, $collected ?? $this->collected(), true));
        $this->apply($this->rows());
        PushRescue::setContent($work, self::ID, PushRescue::CONTENT_APPLIED);
        $this->store      = new ContentMemory($this->store->data); // Zähler und Protokoll von vorn
        $this->connected  = 0;
        ContentImage::$keys = []; // rescue.php kennt den Schlüssel der Installation nicht
    }

    /**
     * @param array<string, mixed> $over
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function rescue(array $over = [], ?array $dirs = null): array
    {
        $answer          = PushRescue::handle($dirs ?? [$this->content], $over + ['action' => 'rollback', 'push_id' => self::ID, 'key' => $this->key, 'content' => '1'], 1000);
        $this->answers[] = $answer[1];
        return $answer;
    }

    /** @return array<string, mixed> */
    private function record(?string $work = null): array
    {
        return (array) PushRescue::read($work ?? $this->work, self::ID);
    }

    private function code(?string $content = null): string
    {
        return (string) file_get_contents(($content ?? $this->content) . '/plugins/x/main.php');
    }

    /** §4.1, AC-159: DB → Code → Uploads; rescue.json trägt den Ausgang, der Umschlag ist weg, der Marker liegt. */
    public function testTakesContentThenCodeThenUploadsBack(): void
    {
        $this->applied();
        $seen                    = null;
        $this->store->beforeLock = function () use (&$seen): void {
            $seen = [$this->code(), file_exists($this->content . '/uploads/2026/10/neu.png')];
        };
        list($status, $body) = $this->rescue();
        $this->assertSame(200, $status);
        $this->assertSame(['ok' => true, 'status' => 'rolled_back', 'content' => ['state' => 'rolled_back', 'cache' => 'none']], $body);
        $this->assertSame(['new', true], $seen, 'als die Inhalte zurückgingen, lagen Code und Uploads noch');
        $this->assertSame(self::sorted($this->old), self::sorted($this->store->data));
        $this->assertSame('old', $this->code());
        $this->assertFileDoesNotExist($this->content . '/uploads/2026/10/neu.png');
        $this->assertSame(1, $this->connected);

        $record = $this->record();
        $this->assertSame('rolled_back', $record['status']);
        $this->assertSame(['state' => 'rolled_back', 'sha256' => self::SHA, 'via' => 'rescue', 'post' => 'pending', 'cache' => 'none'], $record['content']);
        $this->assertFalse(PushRescue::contentOpen($record));
        $this->assertFileDoesNotExist(RescueSeal::file($this->work, self::ID), 'R14: der Umschlag hat ausgedient');
        $this->assertFileExists(PushRescue::pendingFile($this->work), '§8.1: der Agent holt die Nacharbeiten nach');
        $this->assertSame('', file_get_contents(PushRescue::pendingFile($this->work)));
    }

    /** AC-166: jeder weitere Aufruf antwortet gleich – ohne Umschlag, ohne Verbindung. */
    public function testARepeatedCallAnswersTheSameWithoutTheDatabase(): void
    {
        $this->applied();
        file_put_contents($this->content . '/object-cache.php', '<?php');
        $first = $this->rescue();
        $this->assertSame(['state' => 'rolled_back', 'cache' => 'stale'], $first[1]['content']);
        $this->connected  = 0;
        $this->store->log = [];
        $this->assertSame($first, $this->rescue());
        $this->assertSame($first, $this->rescue());
        $this->assertSame(0, $this->connected);
        $this->assertSame([], $this->store->log);
        // Ohne content=1 (eine CLI 0.7.x) sagt die Wiederholung nichts über die Inhalte – sie sind ja zurück.
        $this->assertSame([200, ['ok' => true, 'status' => 'rolled_back']], $this->rescue(['content' => '']));
    }

    /**
     * AC-166: stirbt PHP zwischen COMMIT und dem Schreiben von rescue.json, findet die Wiederholung
     * alle Zeilen im Vorher-Zustand (nothing) und schliesst ab.
     */
    public function testACrashAfterTheCommitIsFinishedByTheNextCall(): void
    {
        $this->applied();
        // Der erste Lauf kam bis nach dem COMMIT der Rücknahme – und nicht weiter.
        $this->assertSame('rolled_back', RescueContent::run($this->content, $this->work, self::ID, $this->key)['state']);
        $this->assertSame('applied', $this->record()['content']['state']);
        $this->assertSame('new', $this->code());
        $this->store->log = [];

        list($status, $body) = $this->rescue();
        $this->assertSame(200, $status);
        $this->assertSame(['ok' => true, 'status' => 'rolled_back', 'content' => ['state' => 'nothing']], $body);
        $this->assertSame([], preg_grep('/^(write|delete|purge)/', $this->store->log));
        $this->assertSame(self::sorted($this->old), self::sorted($this->store->data));
        $this->assertSame('old', $this->code());
        $this->assertSame(['state' => 'rolled_back', 'sha256' => self::SHA, 'via' => 'rescue'], $this->record()['content'], 'ohne zurückgeschriebene Zeile keine Nacharbeiten, kein Cache');
        $this->assertFileDoesNotExist(RescueSeal::file($this->work, self::ID));
    }

    /** AC-159: scheitert der Code nach gelungener DB-Rücknahme, überspringt die Wiederholung die Datenbank. */
    public function testAFailedCodeRollbackAfterTheDatabaseIsRepeatedWithoutIt(): void
    {
        $this->applied();
        chmod($this->content . '/plugins', 0555);
        list($status, $body) = $this->rescue();
        $this->assertSame(500, $status);
        $this->assertSame(['ok' => false, 'error' => 'restore failed', 'units' => ['plugins/x'], 'content' => ['state' => 'rolled_back']], $body);
        $this->assertSame(self::sorted($this->old), self::sorted($this->store->data), 'die Inhalte sind zurück');
        $this->assertSame('new', $this->code());
        $this->assertSame(['committed', 'rolled_back'], [$this->record()['status'], $this->record()['content']['state']]);
        $this->assertFileDoesNotExist(PushRescue::pendingFile($this->work), 'noch nicht zurückgenommen');

        chmod($this->content . '/plugins', 0755);
        $this->connected  = 0;
        $this->store->log = [];
        list($status, $body) = $this->rescue();
        $this->assertSame(200, $status);
        $this->assertSame(['ok' => true, 'status' => 'rolled_back', 'content' => ['state' => 'rolled_back', 'cache' => 'none']], $body);
        $this->assertSame(0, $this->connected, 'die Datenbank wird nicht noch einmal angefasst');
        $this->assertSame([], $this->store->log);
        $this->assertSame('old', $this->code());
        $this->assertFileDoesNotExist($this->content . '/uploads/2026/10/neu.png');
    }

    /** R11, AC-174: ohne content=1 (CLI 0.7.x) nimmt rescue.php nur Code und Uploads zurück – wie P2. */
    public function testWithoutTheWishOfTheCallerTheContentStays(): void
    {
        $this->applied();
        $pushed = $this->store->data;
        foreach ([['content' => ''], ['content' => '0'], ['content' => 'true'], ['content' => 1], ['content' => ['1']]] as $i => $over) {
            if ($i > 0) {
                PushRescue::setStatus($this->work, self::ID, PushRescue::COMMITTED);
            }
            $this->assertSame([200, ['ok' => true, 'status' => 'rolled_back', 'warnings' => ['content_not_rolled_back']]], $this->rescue($over), json_encode($over));
        }
        $this->assertSame($pushed, $this->store->data);
        $this->assertSame(0, $this->connected);
        $this->assertSame('old', $this->code());
        $this->assertSame(['state' => 'applied', 'sha256' => self::SHA], $this->record()['content']);
        $this->assertFileExists(RescueSeal::file($this->work, self::ID), 'der Umschlag liegt ungenutzt und verfällt (R14)');

        // Eine CLI 0.8.0 holt die Inhalte danach über rescue.php nach (§4.1, letzter Absatz): nur Schritt 6.
        $this->assertSame([200, ['ok' => true, 'status' => 'rolled_back', 'content' => ['state' => 'rolled_back', 'cache' => 'none']]], $this->rescue());
        $this->assertSame(self::sorted($this->old), self::sorted($this->store->data));
    }

    /**
     * R7, AC-163: eine seit dem Push geänderte Zeile. In der Datenbank ändert sich nichts, Code und
     * Uploads gehen trotzdem zurück, die Antwort nennt die Schlüssel; der Push bleibt offen.
     */
    public function testChangedSincePushKeepsTheContentAndStillTakesCodeAndUploadsBack(): void
    {
        $this->applied();
        $this->store->data['posts']['219']['post_title'] = 'GEHEIM – nach dem Push geändert';
        $pushed = $this->store->data;

        list($status, $body) = $this->rescue();
        $this->assertSame(200, $status);
        $this->assertSame([
            'ok' => true, 'status' => 'rolled_back',
            'content'  => ['state' => 'kept', 'error' => ['code' => 'changed_since_push', 'keys' => [['table' => 'posts', 'key' => '219']], 'total' => 1]],
            'warnings' => ['content_not_rolled_back'],
        ], $body);
        $this->assertSame($pushed, $this->store->data);
        $this->assertSame([], preg_grep('/^(write|delete|purge|commit)/', $this->store->log));
        $this->assertSame('old', $this->code());
        $this->assertFileDoesNotExist($this->content . '/uploads/2026/10/neu.png');
        $record = $this->record();
        $this->assertSame(['rolled_back', 'applied', 'changed_since_push'], [$record['status'], $record['content']['state'], $record['content']['error']]);
        $this->assertTrue(PushRescue::contentOpen($record), 'der Push bleibt offen – wie nach P2');
        $this->assertFileExists(RescueSeal::file($this->work, self::ID), 'der Umschlag bleibt für den nächsten Versuch');

        // Die Wiederholung sagt dasselbe; es gibt kein force.
        $again = $this->rescue(['force' => '1']);
        $this->assertSame('changed_since_push', $again[1]['content']['error']['code']);
        $this->assertSame($pushed, $this->store->data);

        // Steht die Zeile wieder auf dem gepushten Stand, holt derselbe Aufruf die Inhalte nach.
        $this->store->data['posts']['219']['post_title'] = 'Neu';
        $this->assertSame([200, ['ok' => true, 'status' => 'rolled_back', 'content' => ['state' => 'rolled_back', 'cache' => 'none']]], $this->rescue());
        $this->assertSame(self::sorted($this->old), self::sorted($this->store->data));
        $this->assertArrayNotHasKey('error', $this->record()['content']);
    }

    /** AC-160: ohne Umschlag, mit verändertem Umschlag oder ohne Verbindung gehen Code und Uploads zurück, die Inhalte bleiben. */
    public function testWithoutEnvelopeOrConnectionOnlyCodeAndUploadsGoBack(): void
    {
        $this->applied();
        $pushed = $this->store->data;
        $sealed = (string) file_get_contents(RescueSeal::file($this->work, self::ID));
        $cases  = [
            'rescue_db_unavailable' => static function (self $t) use ($sealed): void {
                file_put_contents(RescueSeal::file($t->work, self::ID), substr($sealed, 0, -4) . 'abcd'); // verändert
            },
            'db_unreachable' => static function (self $t) use ($sealed): void {
                file_put_contents(RescueSeal::file($t->work, self::ID), $sealed);
                RescueContent::$resolve = static function (): ?ContentTarget {
                    return null;
                };
            },
        ];
        foreach ($cases as $code => $arrange) {
            PushRescue::setStatus($this->work, self::ID, PushRescue::COMMITTED);
            $arrange($this);
            list($status, $body) = $this->rescue();
            $this->assertSame(200, $status);
            $this->assertSame(['state' => 'kept', 'error' => ['code' => $code]], $body['content']);
            $this->assertSame(['content_not_rolled_back'], $body['warnings']);
            $this->assertSame($code, $this->record()['content']['error']);
        }
        unlink(RescueSeal::file($this->work, self::ID));
        PushRescue::setStatus($this->work, self::ID, PushRescue::COMMITTED);
        $this->assertSame(['state' => 'kept', 'error' => ['code' => 'rescue_db_unavailable']], $this->rescue()[1]['content'], 'kein Umschlag (no_crypto, driver, no_image_key …)');
        $this->assertSame($pushed, $this->store->data);
        $this->assertSame('old', $this->code());
        $this->assertTrue(PushRescue::contentOpen($this->record()));
    }

    /**
     * Security-Review P3, N3: ein Umschlag, der älter ist als sieben Tage, wird nie angewandt – auch
     * wenn seine Datei frisch aussieht (mtime) und der Cron ihn nie gelöscht hat. Code und Uploads
     * gehen zurück wie ohne Umschlag.
     */
    public function testAnEnvelopeOlderThanSevenDaysIsNeverApplied(): void
    {
        $this->applied();
        $pushed = $this->store->data;
        $file   = RescueSeal::file($this->work, self::ID);
        file_put_contents($file, (string) RescueSeal::seal(['v' => 1, 'created' => time() - RescueSeal::MAX_AGE - 60] + $this->collected(), $this->key, self::ID));
        touch($file); // die Uhr der Datei sagt „eben erst“
        $this->connected = 0;
        list($status, $body) = $this->rescue();
        $this->assertSame(200, $status);
        $this->assertSame(['state' => 'kept', 'error' => ['code' => 'rescue_db_unavailable']], $body['content']);
        $this->assertSame(['content_not_rolled_back'], $body['warnings']);
        $this->assertSame(0, $this->connected, 'keine Verbindung');
        $this->assertSame($pushed, $this->store->data);
        $this->assertSame('old', $this->code());
        $this->assertTrue(PushRescue::contentOpen($this->record()));
    }

    /** AC-161: mit falschem Schlüssel wird kein Umschlag gelesen und nicht verbunden; nichts ändert sich. */
    public function testAWrongKeyReachesNeitherEnvelopeNorDatabase(): void
    {
        $this->applied();
        $pushed = $this->store->data;
        $record = file_get_contents(PushRescue::file($this->work, self::ID));
        $sealed = RescueSeal::file($this->work, self::ID);
        touch($sealed, 1000);
        clearstatcache();
        $atime = fileatime($sealed);
        $wrong = [str_repeat('0', 64), '', hash('sha256', $this->key), $this->key . 'x'];
        foreach ($wrong as $key) {
            $this->assertSame([403, ['ok' => false, 'error' => 'wrong key']], $this->rescue(['key' => $key]));
        }
        $this->assertSame(0, $this->connected);
        clearstatcache();
        $this->assertSame($atime, fileatime($sealed), 'der Umschlag wurde nicht einmal gelesen');
        $this->assertSame($pushed, $this->store->data);
        $this->assertSame($record, file_get_contents(PushRescue::file($this->work, self::ID)));
        $this->assertSame('new', $this->code());
        $this->assertFileDoesNotExist(PushRescue::pendingFile($this->work));
        // AC-162: auch in der Sperre für falsche Schlüssel nimmt der richtige die Inhalte zurück.
        $this->rescue(['key' => 'noch einer']);
        $this->assertSame(429, $this->rescue(['key' => 'gesperrt'])[0]);
        $this->assertSame(200, $this->rescue()[0]);
        $this->assertSame(self::sorted($this->old), self::sorted($this->store->data));
    }

    /** AC-165, §4.1 Nr. 3: bestätigt oder überholt ⇒ abgelehnt, bevor die Datenbank berührt wird. */
    public function testAConfirmedOrSupersededPushIsRefusedBeforeTheDatabase(): void
    {
        $this->applied();
        $pushed = $this->store->data;
        PushRescue::setStatus($this->work, self::ID, PushRescue::CONFIRMED);
        $this->assertSame([409, ['ok' => false, 'error' => 'confirmed']], $this->rescue());

        PushRescue::setStatus($this->work, self::ID, PushRescue::COMMITTED);
        PushRescue::write($this->work, self::OTHER, 'x', [['unit' => 'plugins/x', 'target' => $this->content . '/plugins/x', 'snapshot' => null, 'discard' => $this->work . '/' . self::OTHER . '/discard/0']], PushRescue::COMMITTED);
        PushRescue::supersede($this->work, self::OTHER, ['plugins/x']);
        $this->assertSame([409, ['ok' => false, 'error' => 'superseded', 'by' => self::OTHER]], $this->rescue());

        $this->assertSame(0, $this->connected);
        $this->assertSame($pushed, $this->store->data);
        $this->assertSame([], $this->store->log);
        $this->assertSame('new', $this->code());
        $this->assertSame('applied', $this->record()['content']['state']);
        $this->assertFileExists(RescueSeal::file($this->work, self::ID));
    }

    /** §4.1 Nr. 5: die Pfadprüfung der Paare steht vor der Datenbank. */
    public function testPathsOutsideWpContentAreRefusedBeforeTheDatabase(): void
    {
        $this->applied();
        $record                       = $this->record();
        $record['pairs'][0]['target'] = $this->root . '/ausserhalb';
        file_put_contents(PushRescue::file($this->work, self::ID), json_encode($record));
        $pushed = $this->store->data;
        $this->assertSame([409, ['ok' => false, 'error' => 'path outside wp-content']], $this->rescue());
        $this->assertSame(0, $this->connected);
        $this->assertSame($pushed, $this->store->data);
    }

    /** R10, AC-167: läuft eine Rücknahme (oder der Commit an seiner Naht), antwortet rescue.php mit 423 und tut nichts. */
    public function testBusyWhileAnotherRunHoldsTheLock(): void
    {
        $this->applied();
        $pushed = $this->store->data;
        $lock   = PushRescue::lock($this->work, self::ID);
        $this->assertIsResource($lock);
        $this->assertNull(PushRescue::lock($this->work, self::ID), 'eine zweite Sperre gibt es nicht');
        $started = microtime(true);
        $this->assertNull(PushRescue::lock($this->work, self::ID, 0.25));
        $this->assertGreaterThan(0.2, microtime(true) - $started, 'wartet höchstens so lange');

        $this->assertSame([423, ['ok' => false, 'error' => 'busy']], $this->rescue());
        $this->assertSame([423, ['ok' => false, 'error' => 'busy']], $this->rescue(['content' => '']));
        $this->assertSame(0, $this->connected);
        $this->assertSame($pushed, $this->store->data);
        $this->assertSame('new', $this->code());
        $this->assertSame('committed', $this->record()['status']);
        $this->assertFileDoesNotExist(PushRescue::pendingFile($this->work));

        PushRescue::unlock($lock);
        $this->assertSame(200, $this->rescue()[0]);
        $this->assertSame('old', $this->code());
        // Nach dem Lauf ist die Sperre wieder frei.
        $again = PushRescue::lock($this->work, self::ID);
        $this->assertIsResource($again);
        PushRescue::unlock($again);
        PushRescue::unlock(null);
        PushRescue::unlock(false);
    }

    /** Wo sich nicht sperren lässt, nimmt rescue.php keine Inhalte zurück – Code und Uploads wie bisher. */
    public function testWithoutAWorkingLockTheDatabaseStaysUntouched(): void
    {
        $this->assertFalse(PushRescue::lock($this->work, 'kein-push'));
        $this->assertFalse(PushRescue::lock($this->work, self::OTHER), 'ohne Ordner des Pushs');
        $this->applied();
        $pushed = $this->store->data;
        $file   = $this->work . '/' . self::ID . '/' . PushRescue::LOCK_FILE;
        symlink($this->root . '/irgendwo', $file); // ein Symlink an der Stelle der Sperrdatei wird nie geöffnet
        $this->assertFalse(PushRescue::lock($this->work, self::ID));

        list($status, $body) = $this->rescue();
        $this->assertSame(200, $status);
        $this->assertSame(['state' => 'kept', 'error' => ['code' => 'rescue_db_unavailable']], $body['content']);
        $this->assertSame(0, $this->connected);
        $this->assertSame($pushed, $this->store->data);
        $this->assertSame('old', $this->code());
        $this->assertFileDoesNotExist($this->root . '/irgendwo');
    }

    /**
     * §7.1: kommt rescue.php, bevor der Commit sein Vorher-Abbild geschrieben hat („pending“, keine
     * before.json), wurde nie geschrieben – ohne Umschlag und ohne Verbindung. Der Commit lehnt
     * danach an seiner Naht ab (ContentApplyGateTest, PushContentFlowTest).
     */
    public function testPendingWithoutABeforeImageIsDoneWithoutTheDatabase(): void
    {
        // kein applied(): der Push steht noch vor START TRANSACTION
        $this->assertSame('pending', $this->record()['content']['state']);
        list($status, $body) = $this->rescue();
        $this->assertSame(200, $status);
        $this->assertSame(['ok' => true, 'status' => 'rolled_back', 'content' => ['state' => 'nothing']], $body);
        $this->assertSame(0, $this->connected);
        $this->assertSame(['state' => 'rolled_back', 'sha256' => self::SHA, 'via' => 'rescue'], $this->record()['content']);
        $this->assertSame('old', $this->code());
    }

    /**
     * Security-Review P3, N4: die Abkürzung „pending ohne Vorher-Abbild ⇒ nichts zu tun“ gilt nur
     * unter der Sperre des Pushs. Ohne sie (kein flock) könnte der Commit sein Vorher-Abbild genau
     * jetzt schreiben und danach festschreiben – der DB-Anteil gälte als erledigt, die Inhalte
     * stünden. Dann bleibt er offen, wie überall sonst ohne Sperre.
     */
    public function testPendingWithoutABeforeImageStaysOpenWithoutTheLock(): void
    {
        $this->assertSame('pending', $this->record()['content']['state']);
        symlink($this->root . '/irgendwo', $this->work . '/' . self::ID . '/' . PushRescue::LOCK_FILE); // hier lässt sich nicht sperren
        $this->assertFalse(PushRescue::lock($this->work, self::ID));

        list($status, $body) = $this->rescue();
        $this->assertSame(200, $status);
        $this->assertSame(['state' => 'kept', 'error' => ['code' => 'rescue_db_unavailable']], $body['content']);
        $this->assertSame(['content_not_rolled_back'], $body['warnings']);
        $record = $this->record();
        $this->assertSame(['state' => 'pending', 'sha256' => self::SHA, 'error' => 'rescue_db_unavailable'], $record['content']);
        $this->assertTrue(PushRescue::contentOpen($record), 'der DB-Anteil ist nicht abgeschlossen');
        $this->assertSame(0, $this->connected);
        $this->assertSame('old', $this->code(), 'Code und Uploads gehen trotzdem zurück (R7)');
    }

    /** R15: Fremdes an eingefügten Objekten bleibt stehen; Antwort und Datensatz nennen es. */
    public function testWhatGrewOnInsertedObjectsIsLeftAndNamed(): void
    {
        $this->applied();
        $this->store->data['postmeta']["1000001\0farbe"] = ['values' => ['rot']];
        $left = [['table' => 'postmeta', 'key' => "1000001\0farbe"]];
        $want = ['ok' => true, 'status' => 'rolled_back', 'content' => ['state' => 'rolled_back', 'cache' => 'none', 'left' => $left, 'left_total' => 1], 'warnings' => ['content_left_extra']];
        $this->assertSame([200, $want], $this->rescue());
        $this->assertSame(['values' => ['rot']], $this->store->data['postmeta']["1000001\0farbe"]);
        $this->assertSame([$left, 1], [$this->record()['content']['left'], $this->record()['content']['left_total']]);
        $this->assertSame([200, $want], $this->rescue(), 'die Wiederholung nennt es weiter');
    }

    /** §7.6: Warnungen der Uploads und der Inhalte stehen nebeneinander. */
    public function testWarningsOfUploadsAndContentComeTogether(): void
    {
        $this->applied();
        file_put_contents($this->content . '/uploads/2026/10/neu.png', 'seither geändert');
        $this->store->data['posts']['219']['post_title'] = 'geändert';
        list($status, $body) = $this->rescue();
        $this->assertSame(200, $status);
        $this->assertSame(['ok', 'status', 'content', 'warnings', 'kept'], array_keys($body));
        $this->assertSame(['upload_changed_since_push', 'content_not_rolled_back'], $body['warnings']);
        $this->assertSame(['2026/10/neu.png'], $body['kept']);
        $this->assertSame('kept', $body['content']['state']);
    }

    /** AC-164: ein Push nach Staging – die Inhalte gehen in den Tabellen der Kopie zurück, Live bleibt unberührt. */
    public function testAStagingPushGoesBackInTheTablesOfTheCopy(): void
    {
        $copy    = $this->root . '/' . self::STAGING . '/wp-content';
        $work    = $this->pushIntoTheCopy($copy);
        $live    = new ContentMemory($this->old);
        $seen    = null;
        $this->applied($this->collected([
            'target'  => 'staging',
            'prefix'  => 'stgabcdef_',
            'staging' => ['dir' => self::STAGING, 'live_home' => ContentFixtures::HOME, 'live_prefix' => 'wp_'],
        ]), $copy);
        RescueContent::$resolve = function (array $data, string $contentDir) use (&$seen): ?ContentTarget {
            $this->connected++;
            $seen = [$data['target'], $data['prefix'], $data['staging']['dir'], $contentDir];
            return ContentFixtures::live($this->store); // die Tabellen der Kopie
        };
        file_put_contents($copy . '/object-cache.php', '<?php'); // die Kopie hat nie ein Drop-in – und zählte auch nicht

        list($status, $body) = $this->rescue([], PushRescue::contentDirs($this->content));
        $this->assertSame(200, $status);
        $this->assertSame(['state' => 'rolled_back', 'cache' => 'none'], $body['content']);
        $this->assertSame(['staging', 'stgabcdef_', self::STAGING, $copy], $seen);
        $this->assertSame(self::sorted($this->old), self::sorted($this->store->data));
        $this->assertSame([], $live->log, 'keine Zeile von Live');
        $this->assertSame('old', $this->code($copy));
        $this->assertSame('new', $this->code(), 'der Code von Live bleibt');
        $this->assertFileExists(PushRescue::pendingFile($work));
        $this->assertFileDoesNotExist(PushRescue::pendingFile($this->work));
    }

    /** AC-164: ein Umschlag, dessen Präfix oder Ordner nicht zur Kopie passt, wird abgelehnt – nichts wird verbunden. */
    public function testAnEnvelopeThatDoesNotFitTheCopyIsRefused(): void
    {
        $copy = $this->root . '/' . self::STAGING . '/wp-content';
        $work = $this->pushIntoTheCopy($copy);
        $this->dir = $work . '/' . self::ID . '/content';
        $this->apply($this->rows());
        PushRescue::setContent($work, self::ID, PushRescue::CONTENT_APPLIED);
        $pushed = $this->store->data;
        $bad    = [
            'ein Umschlag von Live'  => $this->collected(),
            'Präfix von Live'        => $this->collected(['target' => 'staging', 'prefix' => 'wp_', 'staging' => ['dir' => self::STAGING, 'live_home' => ContentFixtures::HOME, 'live_prefix' => 'wp_']]),
            'eine andere Kopie'      => $this->collected(['target' => 'staging', 'prefix' => 'stgabcdef_', 'staging' => ['dir' => 'wpsync-staging-ffffffffffff', 'live_home' => ContentFixtures::HOME, 'live_prefix' => 'wp_']]),
        ];
        ContentImage::$keys = [];
        foreach ($bad as $why => $collected) {
            PushRescue::setStatus($work, self::ID, PushRescue::COMMITTED);
            // So versiegelt, wie es nur jemand mit dem Schlüssel könnte – und trotzdem abgelehnt.
            RescueSeal::put($work, self::ID, (string) RescueSeal::seal(['v' => 1, 'created' => time()] + $collected, $this->key, self::ID));
            list($status, $body) = $this->rescue([], PushRescue::contentDirs($this->content));
            $this->assertSame(200, $status, $why);
            $this->assertSame(['state' => 'kept', 'error' => ['code' => 'rescue_db_unavailable']], $body['content'], $why);
        }
        $this->assertSame(0, $this->connected);
        $this->assertSame($pushed, $this->store->data);
    }

    /** R8, AC-169: action=cache – nur mit richtigem Schlüssel, nur für Live, nur wenn der Push ganz zurück und der Cache „stale“ ist. */
    public function testTheCacheStepIsOnlyOfferedForAStaleCacheOfARolledBackLivePush(): void
    {
        $cache = function (array $over = []): array {
            return $this->rescue($over + ['action' => 'cache', 'content' => '']);
        };
        $nothing = [409, ['ok' => false, 'error' => 'nothing to flush']];
        $this->applied();
        file_put_contents($this->content . '/object-cache.php', '<?php');
        $this->assertSame($nothing, $cache(), 'der Push ist noch nicht zurück');
        $this->assertSame([403, ['ok' => false, 'error' => 'wrong key']], $cache(['key' => 'falsch']));

        // Die Inhalte sind zurück, der Code noch nicht: geladen würde sonst der gepushte Code.
        chmod($this->content . '/plugins', 0555);
        $this->assertSame(500, $this->rescue()[0]);
        chmod($this->content . '/plugins', 0755);
        $this->assertSame('stale', $this->record()['content']['cache']);
        $this->assertSame($nothing, $cache(), 'Code noch nicht zurück');

        $this->assertSame('stale', $this->rescue()[1]['content']['cache']);
        $this->assertSame([403, ['ok' => false, 'error' => 'wrong key']], $cache(['key' => 'falsch']));
        $this->assertSame([PushRescue::FLUSH, ['work' => $this->work, 'push_id' => self::ID]], $cache());
        // Scheitert das Leeren, bleibt der Cache „stale“ – die Rücknahme gilt.
        $this->assertSame([500, ['ok' => false, 'error' => 'cache failed']], PushRescue::flushed($this->work, self::ID, false));
        $this->assertSame('stale', $this->record()['content']['cache']);
        $this->assertSame([200, ['ok' => true, 'cache' => 'flushed']], PushRescue::flushed($this->work, self::ID, true));
        $this->assertSame('flushed', $this->record()['content']['cache']);
        $this->assertSame($nothing, $cache(), 'einmal geleert, nichts mehr zu tun');
        $this->assertSame(['state' => 'rolled_back', 'cache' => 'flushed'], $this->rescue()[1]['content']);
        $this->assertSame([400, ['ok' => false, 'error' => 'bad request']], $this->rescue(['action' => 'flush']));
        // Ist der Arbeitsordner inzwischen weg, entsteht er durch flushed() nicht neu.
        exec('rm -rf ' . escapeshellarg($this->work . '/' . self::ID));
        $this->assertSame(200, PushRescue::flushed($this->work, self::ID, true)[0]);
        $this->assertDirectoryDoesNotExist($this->work . '/' . self::ID);
    }

    /**
     * Security-Review P3, N5: die Marke „flushed“ schreibt der Cache-Schritt nur unter der Sperre des
     * Pushs. Hält sie ein anderer Lauf (eine Rücknahme, der Agent beim Wiederanlauf), ist der Cache
     * zwar geleert, rescue.json bleibt aber unberührt – zwei Läufe überschreiben sich nie.
     */
    public function testTheCacheMarkIsOnlyWrittenUnderTheLock(): void
    {
        $this->applied();
        file_put_contents($this->content . '/object-cache.php', '<?php');
        $this->assertSame('stale', $this->rescue()[1]['content']['cache']);
        $before = file_get_contents(PushRescue::file($this->work, self::ID));

        $held = PushRescue::lock($this->work, self::ID);
        $this->assertIsResource($held);
        $this->assertSame([200, ['ok' => true, 'cache' => 'flushed']], PushRescue::flushed($this->work, self::ID, true, 0.0), 'geleert ist er');
        $this->assertSame($before, file_get_contents(PushRescue::file($this->work, self::ID)), 'ohne Sperre kein Schreiben');
        PushRescue::unlock($held);

        $this->assertSame([200, ['ok' => true, 'cache' => 'flushed']], PushRescue::flushed($this->work, self::ID, true, 0.0));
        $this->assertSame('flushed', $this->record()['content']['cache']);
        // Nach dem Lauf ist die Sperre wieder frei.
        $again = PushRescue::lock($this->work, self::ID);
        $this->assertIsResource($again);
        PushRescue::unlock($again);
    }

    public function testNoCacheStepWithoutContentOrForACopy(): void
    {
        // Ein Push ohne DB-Anteil.
        PushRescue::write($this->work, self::ID, hash('sha256', $this->key), [], PushRescue::ROLLED_BACK);
        $this->assertSame(409, $this->rescue(['action' => 'cache'])[0]);
        // Ein Datensatz in einer Kopie, selbst mit „stale“ (von Hand hineingeschrieben).
        $copy = $this->root . '/' . self::STAGING . '/wp-content';
        $work = $this->push($copy);
        PushRescue::setStatus($work, self::ID, PushRescue::ROLLED_BACK);
        PushRescue::setContentFields($work, self::ID, ['state' => 'rolled_back', 'cache' => 'stale']);
        $this->assertSame([409, ['ok' => false, 'error' => 'nothing to flush']], $this->rescue(['action' => 'cache'], [$copy]));
    }

    public function testSetContentFieldsMergesAndRemoves(): void
    {
        $this->assertTrue(PushRescue::setContentFields($this->work, self::ID, ['via' => 'rescue', 'post' => 'pending', 'error' => 'x']));
        $this->assertTrue(PushRescue::setContentFields($this->work, self::ID, ['post' => 'done', 'error' => null]));
        $this->assertSame(['state' => 'pending', 'sha256' => self::SHA, 'via' => 'rescue', 'post' => 'done'], $this->record()['content']);
        PushRescue::write($this->work, self::OTHER, 'x', [], PushRescue::COMMITTED);
        $this->assertFalse(PushRescue::setContentFields($this->work, self::OTHER, ['via' => 'rescue']), 'ein Push ohne DB-Anteil bekommt keinen');
        $this->assertFalse(PushRescue::setContentFields($this->work, 'p_20261009_ffffffffffff', ['via' => 'rescue']));
    }
}
