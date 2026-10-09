<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use WpSync\ContentException;
use WpSync\ContentImage;
use WpSync\ContentSql;
use WpSync\ContentTarget;
use WpSync\PushRescue;
use WpSync\RescueContent;
use WpSync\RescueDb;
use WpSync\RescueSeal;

require_once __DIR__ . '/ContentApplyCase.php';
require_once __DIR__ . '/FakeRescueLink.php';

/**
 * RescueContent (Spec Content-Push P3 §4.2, §5.2, §7.2): die Felder des Umschlags, das Ziel
 * daraus, die Prüfung für die Staging-Kopie und Schritt 6 von rescue.php – gegen den Store im
 * Speicher (Naht RescueContent::$resolve). Eine echte Datenbank sieht der Weg im E2E.
 */
final class RescueContentTest extends ContentApplyCase
{
    private const ID      = 'p_20261009_0123456789ab';
    private const OTHER   = 'p_20261009_ba9876543210';
    private const STAGING = 'wpsync-staging-0123456789ab';

    private string $root;
    private string $content;
    private string $work;
    private string $key;
    /** @var list<string> Schlüssel der Installation, mit denen die Abbilder des Pushs geschrieben sind */
    private array $install;
    /** @var int wie oft die Naht nach dem Ziel gefragt wurde – „verbunden“ */
    private int $resolved = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root    = (string) realpath(sys_get_temp_dir()) . '/wpsync-rescuecontent2-' . bin2hex(random_bytes(4));
        $this->content = $this->root . '/wp-content';
        $this->work    = $this->content . '/wpsync-push-0123456789abcdef';
        $this->dir     = $this->work . '/' . self::ID . '/content'; // hier legt apply() die Abbilder ab
        $this->key     = PushRescue::key(str_repeat('ab', 32), self::ID, 'salt');
        $this->install = [hash('sha256', 'schlüssel der installation', true)];
        mkdir($this->dir, 0777, true);
        ContentImage::$keys     = $this->install;
        ContentImage::$encrypt  = false;
        RescueContent::$resolve = function (array $data, string $contentDir): ?ContentTarget {
            $this->resolved++;
            return ContentFixtures::live($this->store);
        };
    }

    protected function tearDown(): void
    {
        RescueContent::$resolve = null;
        ContentImage::$keys     = null;
        ContentImage::$encrypt  = null;
        ContentImage::$fileKeys = null;
        array_map('unlink', array_filter($this->files, 'is_file'));
        exec('chmod -R u+w ' . escapeshellarg($this->root) . ' 2>/dev/null; rm -rf ' . escapeshellarg($this->root));
    }

    /**
     * Was PushContent::rescueData() für Live sammelt.
     *
     * @param array<string, mixed> $over
     * @return array<string, mixed>
     */
    private function collected(array $over = [], string $id = self::ID): array
    {
        $b64 = static function (array $keys): array {
            return array_map('base64_encode', $keys);
        };
        return $over + [
            'target'     => 'live',
            'db'         => [
                'host' => 'db.internal', 'port' => 3306, 'socket' => null, 'user' => 'wp_user', 'password' => 'geh3im!', 'name' => 'wordpress',
                'flags' => 0, 'charset' => 'utf8mb4', 'collate' => 'utf8mb4_unicode_520_ci', 'sql_mode' => 'NO_ENGINE_SUBSTITUTION',
            ],
            'prefix'     => 'wp_',
            'home'       => ContentFixtures::HOME,
            'siteurl'    => ContentFixtures::HOME,
            'staging'    => null,
            'image_keys' => [
                ContentImage::BEFORE => $b64(ContentImage::fileKeys($id, ContentImage::BEFORE)),
                ContentImage::AFTER  => $b64(ContentImage::fileKeys($id, ContentImage::AFTER)),
            ],
        ];
    }

    /** @return array<string, mixed> der Inhalt des Umschlags, wie rescue.php ihn öffnet */
    private function envelope(array $over = [], array $db = []): array
    {
        $data       = ['v' => 1, 'push_id' => self::ID, 'created' => 1791500000] + $this->collected($over);
        $data['db'] = $db + $data['db'];
        return $data;
    }

    /** @return array<string, mixed> */
    private function stagingEnvelope(array $over = [], array $staging = []): array
    {
        return $this->envelope($over + [
            'target'  => 'staging',
            'prefix'  => 'stgabcdef_',
            'staging' => $staging + ['dir' => self::STAGING, 'live_home' => ContentFixtures::HOME, 'live_prefix' => 'wp_'],
        ]);
    }

    private function stagingContent(): string
    {
        return $this->root . '/' . self::STAGING . '/wp-content';
    }

    private function seal(?array $collected = null): void
    {
        $this->assertSame(['ok' => true], RescueContent::prepare($this->work, self::ID, $this->content, $this->key, $collected ?? $this->collected(), true));
    }

    public function testTheListIsTheOneOfTheSpecAndEveryFileExists(): void
    {
        $this->assertSame([
            'Canon', 'SerializedWalker', 'StagingReplace', 'ContentOrigin', 'ContentException', 'ContentStore', 'ContentState', 'ContentLists',
            'ContentReader', 'ContentImage', 'ContentTarget', 'ContentSql', 'ContentRepair', 'ContentPlugins', 'ContentRollback', 'RescueSeal', 'RescueLink',
            'MysqliLink', 'RescueDb',
        ], RescueContent::CLASSES);
        foreach (RescueContent::CLASSES as $name) {
            $this->assertFileExists(__DIR__ . '/../src/' . $name . '.php');
        }
        RescueContent::load();
        RescueContent::load();
        foreach (RescueContent::CLASSES as $name) {
            $this->assertTrue(class_exists('WpSync\\' . $name, false) || interface_exists('WpSync\\' . $name, false), $name);
        }
        foreach (['SecretKey', 'SecretBox', 'ContentCheck', 'ContentApply', 'ContentPackage', 'PushContent', 'Staging', 'StagingGuard', 'Anonymizer'] as $never) {
            $this->assertNotContains($never, RescueContent::CLASSES);
        }
    }

    public function testAValidEnvelopeOfLive(): void
    {
        $checked = RescueContent::check($this->envelope(), self::ID, $this->content);
        $this->assertNotNull($checked);
        $this->assertSame(['target', 'db', 'prefix', 'home', 'siteurl', 'staging', 'image_keys'], array_keys($checked));
        $this->assertSame('wp_', $checked['prefix']);
        $this->assertNull($checked['staging']);
        $this->assertSame(ContentImage::fileKeys(self::ID, ContentImage::BEFORE), $checked['image_keys'][ContentImage::BEFORE], 'die Dateischlüssel roh');
        $this->assertSame(3306, $checked['db']['port']);

        // Formen, die wpdb::parse_db_host() liefert: Socket ohne Port, IPv6 in Klammern, kein Zeichensatz.
        $this->assertNotNull(RescueContent::check($this->envelope([], ['host' => 'localhost', 'port' => null, 'socket' => '/var/run/mysqld/mysqld.sock']), self::ID, $this->content));
        $this->assertNotNull(RescueContent::check($this->envelope([], ['host' => '[::1]', 'port' => 3307]), self::ID, $this->content));
        $this->assertNotNull(RescueContent::check($this->envelope([], ['host' => '', 'charset' => '', 'collate' => '', 'sql_mode' => '', 'password' => '']), self::ID, $this->content));
        $this->assertNotNull(RescueContent::check($this->envelope([], ['flags' => 2048 | 64 | 32]), self::ID, $this->content));
        $this->assertNotNull(RescueContent::check($this->envelope(['prefix' => '']), self::ID, $this->content), 'ein leeres Präfix gibt es');
        $this->assertNotNull(RescueContent::check($this->envelope(['home' => 'http://kunde.de:8080/blog/']), self::ID, $this->content));
    }

    /** @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}> */
    public static function refusedFields(): array
    {
        $key = base64_encode(str_repeat('k', 32));
        return [
            'andere Version'              => [['v' => 2], []],
            'fremder Push'                => [['push_id' => self::OTHER], []],
            'unbekanntes Ziel'            => [['target' => 'beides'], []],
            'Ziel Staging im Ordner Live' => [['target' => 'staging'], []],
            'Port 0'                      => [[], ['port' => 0]],
            'Port zu gross'               => [[], ['port' => 65536]],
            'Port als Text'               => [[], ['port' => '3306']],
            'Socket leer'                 => [[], ['socket' => '']],
            'Socket mit NUL'              => [[], ['socket' => "/tmp/x\0y"]],
            'Host als Liste'              => [[], ['host' => ['a']]],
            'Benutzer fehlt'              => [[], ['user' => null]],
            'Passwort als Zahl'           => [[], ['password' => 5]],
            'Datenbank leer'              => [[], ['name' => '']],
            'fremde Flags'                => [[], ['flags' => 65536]],
            'Flags mit Mehrfachabfragen'  => [[], ['flags' => 2048 | 65536]],
            'Flags negativ'               => [[], ['flags' => -1]],
            'Flags als Text'              => [[], ['flags' => '0']],
            'Zeichensatz mit Zitat'       => [[], ['charset' => "utf8'"]],
            'Kollation mit Leerzeichen'   => [[], ['collate' => 'a b']],
            'sql_mode mit Zitat'          => [[], ['sql_mode' => "A',x='"]],
            'sql_mode klein'              => [[], ['sql_mode' => 'strict_all_tables']],
            'Präfix mit Backtick'         => [['prefix' => 'wp_`'], []],
            'Präfix mit Leerzeichen'      => [['prefix' => 'wp _'], []],
            'Präfix zu lang'              => [['prefix' => str_repeat('a', 47)], []],
            'Präfix einer Kopie auf Live' => [['prefix' => 'stgabcdef_'], []],
            'Präfix als Zahl'             => [['prefix' => 5], []],
            'Live mit Staging-Angaben'    => [['staging' => ['dir' => self::STAGING, 'live_home' => 'https://kunde.de', 'live_prefix' => 'wp_']], []],
            'home ohne Schema'            => [['home' => 'kunde.de'], []],
            'home leer'                   => [['home' => ''], []],
            'siteurl fehlt'               => [['siteurl' => null], []],
            'keine Dateischlüssel'        => [['image_keys' => []], []],
            'Vorher-Schlüssel leer'       => [['image_keys' => ['before.json' => [], 'after.json' => [$key]]], []],
            'Nachher-Schlüssel fehlt'     => [['image_keys' => ['before.json' => [$key]]], []],
            'drei Schlüssel'              => [['image_keys' => ['before.json' => [$key, $key, $key], 'after.json' => [$key]]], []],
            'Schlüssel zu kurz'           => [['image_keys' => ['before.json' => [base64_encode('kurz')], 'after.json' => [$key]]], []],
            'Schlüssel kein base64'       => [['image_keys' => ['before.json' => ['***'], 'after.json' => [$key]]], []],
            'Schlüssel für fremde Datei'  => [['image_keys' => ['before.json' => [$key], 'after.json' => [$key], 'package.jsonl' => [$key]]], []],
            'db fehlt'                    => [['db' => null], []],
        ];
    }

    /**
     * @param array<string, mixed> $over
     * @param array<string, mixed> $db
     */
    #[DataProvider('refusedFields')]
    public function testAnEnvelopeWithABadFieldIsRefused(array $over, array $db): void
    {
        $data = $this->envelope();
        foreach ($over as $field => $value) {
            $data[$field] = $value;
        }
        foreach ($db as $field => $value) {
            $data['db'][$field] = $value;
        }
        $this->assertNull(RescueContent::check($data, self::ID, $this->content));
    }

    public function testAnEnvelopeIsBoundToItsPush(): void
    {
        $this->assertNull(RescueContent::check($this->envelope(), self::OTHER, $this->content));
        $this->assertNull(RescueContent::check($this->envelope(), 'kein-push', $this->content));
    }

    /** AC-164: der Umschlag einer Kopie gilt nur in ihrem Ordner und nur für Tabellen mit ihrem Präfix. */
    public function testAStagingEnvelopeOnlyInItsCopyAndOnlyForItsTables(): void
    {
        $checked = RescueContent::check($this->stagingEnvelope(), self::ID, $this->stagingContent());
        $this->assertNotNull($checked);
        $this->assertSame(['dir' => self::STAGING, 'live_home' => ContentFixtures::HOME, 'live_prefix' => 'wp_'], $checked['staging']);
        $this->assertSame('stgabcdef_', $checked['prefix']);

        $bad = [
            'im wp-content von Live'           => [$this->stagingEnvelope(), $this->content],
            'in einer anderen Kopie'           => [$this->stagingEnvelope(), $this->root . '/wpsync-staging-ffffffffffff/wp-content'],
            'nennt eine andere Kopie'          => [$this->stagingEnvelope([], ['dir' => 'wpsync-staging-ffffffffffff']), $this->stagingContent()],
            'Ordner ist kein Staging-Name'     => [$this->stagingEnvelope([], ['dir' => 'public_html']), $this->stagingContent()],
            'Präfix von Live'                  => [$this->stagingEnvelope(['prefix' => 'wp_']), $this->stagingContent()],
            'Präfix nicht in der Form stg…_'   => [$this->stagingEnvelope(['prefix' => 'stgABCDEF_']), $this->stagingContent()],
            'Präfix mit Anhang'                => [$this->stagingEnvelope(['prefix' => 'stgabcdef_x']), $this->stagingContent()],
            'Live-Präfix leer'                 => [$this->stagingEnvelope([], ['live_prefix' => '']), $this->stagingContent()],
            'Live-Präfix beginnt wie die Kopie' => [$this->stagingEnvelope([], ['live_prefix' => 'stgabcdef_wp_']), $this->stagingContent()],
            'Kopie-Präfix beginnt wie Live'    => [$this->stagingEnvelope([], ['live_prefix' => 'stg']), $this->stagingContent()],
            'ohne Staging-Angaben'             => [$this->envelope(['target' => 'staging', 'prefix' => 'stgabcdef_']), $this->stagingContent()],
            'home von Live ungültig'           => [$this->stagingEnvelope([], ['live_home' => 'kein-url']), $this->stagingContent()],
            'Umschlag von Live in der Kopie'   => [$this->envelope(), $this->stagingContent()],
        ];
        foreach ($bad as $why => $case) {
            $this->assertNull(RescueContent::check($case[0], self::ID, $case[1]), $why);
        }
    }

    public function testTheTargetOfLiveNamesTheSevenTablesAndCommentsIfTheyExist(): void
    {
        $link = new FakeRescueLink();
        $link->answer('/^SHOW TABLES LIKE/', [['Tables_in_wordpress (wp\_comments)' => 'wp_comments']]);
        $target = RescueContent::target((array) RescueContent::check($this->envelope(), self::ID, $this->content), new RescueDb($link));
        $this->assertInstanceOf(ContentTarget::class, $target);
        $this->assertInstanceOf(ContentSql::class, $target->store);
        $this->assertSame(['live', 'wp_', ContentFixtures::HOME], [$target->name, $target->prefix, $target->home]);
        $this->assertSame(["SHOW TABLES LIKE 'wp\\\\_comments'"], $link->queries);
        $this->assertNull($target->slug);
        $this->assertNull($target->objectTypes);
        $this->assertNull($target->userExists);
        // Lesen und Schreiben treffen genau die Tabellen des Präfixes; Kommentare werden nur gezählt.
        $target->store->attached('posts', ['5'], false);
        $target->store->read('options', ['blogname'], false);
        $this->assertNotSame([], preg_grep('/FROM `wp_comments`/', $link->queries));
        $this->assertNotSame([], preg_grep('/FROM `wp_options`/', $link->queries));
        $this->assertSame('<a href="' . \WpSync\ContentOrigin::PLAIN . '/x">', $target->origin->normalize('<a href="https://kunde.de/x">'));

        // Ohne Tabelle comments (Kopie ohne sie): nichts wird dort gelesen.
        $none   = new FakeRescueLink();
        $target = RescueContent::target((array) RescueContent::check($this->envelope(), self::ID, $this->content), new RescueDb($none));
        $target->store->attached('posts', ['5'], false);
        $this->assertSame([], preg_grep('/comments`/', $none->queries));

        // Antwortet die Datenbank nicht einmal darauf, gibt es kein Ziel.
        $dead = new FakeRescueLink();
        $dead->fail('/^SHOW TABLES/', 1045);
        $this->assertNull(RescueContent::target((array) RescueContent::check($this->envelope(), self::ID, $this->content), new RescueDb($dead)));
    }

    /** AC-164: das Ziel einer Kopie schreibt in ihre Tabellen und rechnet mit ihrer Origin. */
    public function testTheTargetOfACopyUsesItsPrefixAndItsOrigin(): void
    {
        $link   = new FakeRescueLink();
        $target = RescueContent::target((array) RescueContent::check($this->stagingEnvelope(), self::ID, $this->stagingContent()), new RescueDb($link));
        $this->assertSame(['staging', 'stgabcdef_', ContentFixtures::HOME . '/' . self::STAGING], [$target->name, $target->prefix, $target->url]);
        $target->store->read('posts', ['219'], false);
        $this->assertSame('SELECT * FROM `stgabcdef_posts` WHERE `ID` IN (219)', $link->queries[1]);
        $this->assertSame([], preg_grep('/`wp_/', $link->queries), 'keine Tabelle von Live');
        // Der Pfad der Kopie fällt beim Normalisieren weg – derselbe Abdruck wie auf Live (W1).
        $this->assertSame('<a href="' . \WpSync\ContentOrigin::PLAIN . '/x">', $target->origin->normalize('<a href="https://kunde.de/' . self::STAGING . '/x">'));
    }

    /** R13, §5.3: der Begin legt den Umschlag nur an, wenn die Probe besteht – nur für den Besitzer lesbar. */
    public function testPrepareSealsAnEnvelopeThatOnlyTheRescueKeyOpens(): void
    {
        $this->seal();
        $file = RescueSeal::file($this->work, self::ID);
        $this->assertSame('0600', substr(sprintf('%o', fileperms($file)), -4));
        $this->assertSame(1, $this->resolved, 'die Probe hat genau einmal verbunden');
        $raw = (string) file_get_contents($file);
        foreach (['geh3im!', 'wp_user', 'wordpress', 'db.internal', 'kunde.de'] as $secret) {
            $this->assertStringNotContainsString($secret, $raw);
        }
        $opened = RescueSeal::open($raw, $this->key, self::ID);
        $this->assertSame('geh3im!', $opened['db']['password']);
        $this->assertSame(1, $opened['v']);
        $this->assertNull(RescueSeal::open($raw, hash('sha256', $this->key), self::ID), 'der Hash auf dem Server öffnet nichts');
        $this->assertNull(RescueSeal::open($raw, $this->key, self::OTHER));
    }

    public function testPrepareNamesWhyThereIsNoEnvelope(): void
    {
        $file = RescueSeal::file($this->work, self::ID);
        // Was die Sammlung schon weiss, bleibt der Grund.
        foreach ([RescueContent::DRIVER, RescueContent::NO_IMAGE_KEY, RescueContent::PROBE_FAILED] as $reason) {
            $this->assertSame(['ok' => false, 'reason' => $reason], RescueContent::prepare($this->work, self::ID, $this->content, $this->key, $reason, true));
        }
        $this->assertSame(['ok' => false, 'reason' => 'probe_failed'], RescueContent::prepare($this->work, self::ID, $this->content, $this->key, "nichts, was ein Grund wäre\n", true));
        // Ein Umschlag, den rescue.php ablehnte, entsteht gar nicht erst.
        $this->assertSame(['ok' => false, 'reason' => 'probe_failed'], RescueContent::prepare($this->work, self::ID, $this->content, $this->key, $this->collected(['prefix' => 'wp_`']), true));
        $this->assertSame(['ok' => false, 'reason' => 'probe_failed'], RescueContent::prepare($this->work, self::ID, $this->content, $this->key, $this->collected(['image_keys' => []]), true));
        $this->assertSame(0, $this->resolved);
        // Die Probe scheitert: keine Verbindung, oder die Naht wirft.
        RescueContent::$resolve = static function (): ?ContentTarget {
            return null;
        };
        $this->assertSame(['ok' => false, 'reason' => 'probe_failed'], RescueContent::prepare($this->work, self::ID, $this->content, $this->key, $this->collected(), true));
        RescueContent::$resolve = static function (): ?ContentTarget {
            throw new \RuntimeException("Access denied for user 'wp_user'");
        };
        $this->assertSame(['ok' => false, 'reason' => 'probe_failed'], RescueContent::prepare($this->work, self::ID, $this->content, $this->key, $this->collected(), true));
        $this->assertFileDoesNotExist($file);
        // Die Datei lässt sich nicht schreiben.
        RescueContent::$resolve = function (): ContentTarget {
            return ContentFixtures::live($this->store);
        };
        $this->assertSame(['ok' => false, 'reason' => 'write_failed'], RescueContent::prepare($this->root . '/gibt-es-nicht', self::ID, $this->content, $this->key, $this->collected(), true));
        $this->assertSame(['ok' => false, 'reason' => 'write_failed'], RescueContent::prepare($this->work, self::ID, $this->content, '', $this->collected(), true), 'ohne Schlüssel kein Umschlag');
        $this->assertFileDoesNotExist($file);
    }

    /** §5.3: der Probelauf sagt, was ein echter Begin ergäbe – ohne Probe und ohne Datei. */
    public function testTheDryRunOnlyTells(): void
    {
        $this->assertSame(['ok' => true], RescueContent::prepare($this->work, self::ID, $this->content, '', $this->collected(), false));
        $this->assertSame(['ok' => false, 'reason' => 'no_image_key'], RescueContent::prepare($this->work, self::ID, $this->content, '', RescueContent::NO_IMAGE_KEY, false));
        $this->assertSame(['ok' => false, 'reason' => 'probe_failed'], RescueContent::prepare($this->work, self::ID, $this->content, '', $this->collected(['home' => 'x']), false));
        $this->assertSame(0, $this->resolved);
        $this->assertFileDoesNotExist(RescueSeal::file($this->work, self::ID));
    }

    /** §7.2: Schritt 6 nimmt die Inhalte zurück – mit den Dateischlüsseln aus dem Umschlag, ohne den Schlüssel der Installation. */
    public function testRunTakesTheContentBackWithTheKeysOfTheEnvelope(): void
    {
        $old = $this->store->data;
        $this->seal();
        $this->apply($this->rows());
        ContentImage::$keys = []; // in rescue.php gibt es weder Salts noch WPSYNC_KEY
        $this->resolved     = 0;

        $back = RescueContent::run($this->content, $this->work, self::ID, $this->key);
        $this->assertSame(['state' => 'rolled_back', 'wrote' => true], $back);
        $this->assertSame(self::sorted($old), self::sorted($this->store->data));
        $this->assertSame(1, $this->resolved);
        $this->assertNull(ContentImage::$fileKeys, 'die Schlüssel bleiben nicht im Prozess liegen');

        // Wiederholung (AC-166): alle Zeilen stehen im Vorher-Zustand.
        $this->assertSame(['state' => 'nothing', 'wrote' => false], RescueContent::run($this->content, $this->work, self::ID, $this->key));
    }

    /** AC-160: kein Umschlag, ein veränderter, einer mit falschem Schlüssel – die Inhalte bleiben, ohne dass verbunden wird. */
    public function testRunWithoutAUsableEnvelopeConnectsNowhere(): void
    {
        $this->apply($this->rows());
        $pushed         = $this->store->data;
        $unavailable    = ['state' => 'kept', 'wrote' => false, 'error' => ['code' => 'rescue_db_unavailable']];
        $this->assertSame($unavailable, RescueContent::run($this->content, $this->work, self::ID, $this->key), 'kein Umschlag');

        $this->seal();
        $this->resolved = 0;
        $this->assertSame($unavailable, RescueContent::run($this->content, $this->work, self::ID, PushRescue::key(str_repeat('ab', 32), self::ID, 'anderes salt')), 'falscher Schlüssel');
        $file = RescueSeal::file($this->work, self::ID);
        $raw  = (string) file_get_contents($file);
        file_put_contents($file, substr($raw, 0, -3) . 'xyz');
        $this->assertSame($unavailable, RescueContent::run($this->content, $this->work, self::ID, $this->key), 'verändert');
        // Ein gültig versiegelter Umschlag, dessen Inhalt nicht zum Ordner passt (Ziel Staging in wp-content von Live).
        file_put_contents($file, (string) RescueSeal::seal(['v' => 1, 'created' => time()] + $this->collected(['target' => 'staging']), $this->key, self::ID));
        $this->assertSame($unavailable, RescueContent::run($this->content, $this->work, self::ID, $this->key), 'passt nicht zum Ordner');
        // N3: gültig versiegelt, passt zum Ordner – aber älter als sieben Tage.
        file_put_contents($file, (string) RescueSeal::seal(['v' => 1, 'created' => time() - RescueSeal::MAX_AGE - 60] + $this->collected(), $this->key, self::ID));
        $this->assertSame($unavailable, RescueContent::run($this->content, $this->work, self::ID, $this->key), 'zu alt');
        unlink($file);
        symlink($this->work . '/woanders', $file);
        $this->assertSame($unavailable, RescueContent::run($this->content, $this->work, self::ID, $this->key), 'ein Symlink ist kein Umschlag');

        $this->assertSame(0, $this->resolved, 'ohne Umschlag keine Verbindung');
        $this->assertSame($pushed, $this->store->data);
    }

    public function testRunWithoutAConnectionKeepsTheContent(): void
    {
        $this->seal();
        $this->apply($this->rows());
        $pushed                 = $this->store->data;
        RescueContent::$resolve = static function (): ?ContentTarget {
            return null;
        };
        $this->assertSame(['state' => 'kept', 'wrote' => false, 'error' => ['code' => 'db_unreachable']], RescueContent::run($this->content, $this->work, self::ID, $this->key));
        RescueContent::$resolve = static function (): ?ContentTarget {
            throw new \InvalidArgumentException('invalid content table posts');
        };
        $this->assertSame(['state' => 'kept', 'wrote' => false, 'error' => ['code' => 'content_failed']], RescueContent::run($this->content, $this->work, self::ID, $this->key));
        $this->assertSame($pushed, $this->store->data);
    }

    /** R7, AC-163: eine seit dem Push geänderte Zeile – nichts wird angefasst, die Antwort nennt die Schlüssel, nie einen Wert. */
    public function testRunNamesChangedRowsAndTouchesNothing(): void
    {
        $this->seal();
        $this->apply($this->rows());
        $this->store->data['posts']['219']['post_title']        = 'GEHEIMER TITEL nach dem Push';
        $this->store->data['options']['blogname']['option_value'] = 'GEHEIMER NAME';
        $pushed           = $this->store->data;
        $this->store->log = [];

        $back = RescueContent::run($this->content, $this->work, self::ID, $this->key);
        $this->assertSame('kept', $back['state']);
        $this->assertSame(['code' => 'changed_since_push', 'keys' => [['table' => 'posts', 'key' => '219'], ['table' => 'options', 'key' => 'blogname']], 'total' => 2], $back['error']);
        $this->assertStringNotContainsString('GEHEIM', (string) json_encode($back));
        $this->assertSame($pushed, $this->store->data);
        $this->assertSame([], preg_grep('/^(write|delete|purge|commit)/', $this->store->log));
    }

    /** R15: Fremdes an eingefügten Objekten bleibt stehen und wird genannt. */
    public function testRunLeavesAndNamesWhatGrew(): void
    {
        $old = $this->store->data;
        $this->seal();
        $this->apply($this->rows());
        $this->store->data['postmeta']["1000001\0farbe"] = ['values' => ['rot']];
        $this->store->comments['1000001']                = 1;

        $back = RescueContent::run($this->content, $this->work, self::ID, $this->key);
        $this->assertSame([
            'state' => 'rolled_back', 'wrote' => true,
            'left'  => [['table' => 'postmeta', 'key' => "1000001\0farbe"], ['table' => 'comments', 'key' => '1000001']], 'left_total' => 2,
        ], $back);
        $this->assertSame(['values' => ['rot']], $this->store->data['postmeta']["1000001\0farbe"]);
        unset($this->store->data['postmeta']["1000001\0farbe"]);
        $this->assertSame(self::sorted($old), self::sorted($this->store->data));
    }

    /** @return array<string, array{0: callable(self): void, 1: array<string, mixed>}> */
    public static function failures(): array
    {
        return [
            'Vorher-Abbild verändert' => [static function (self $t): void {
                file_put_contents($t->dir . '/before.json', (string) file_get_contents($t->dir . '/before.json') . ' ');
            }, ['code' => 'before_image_invalid']],
            'Vorher-Abbild im Klartext untergeschoben' => [static function (self $t): void {
                file_put_contents($t->dir . '/before.json', '{"keys":[{"t":"options","k":"siteurl","state":{"option_value":"aHR0cHM6Ly9ldmlsLmV4YW1wbGU="}}]}');
            }, ['code' => 'before_image_invalid']],
            'Vorher-Abbild ist ein Symlink' => [static function (self $t): void {
                rename($t->dir . '/before.json', $t->dir . '/echt.json');
                symlink($t->dir . '/echt.json', $t->dir . '/before.json');
            }, ['code' => 'before_image_invalid']],
            'nicht InnoDB' => [static function (self $t): void {
                $t->store->engines = ['options' => 'MyISAM'];
            }, ['code' => 'engine_unsupported']],
            'Schreibzugriff scheitert' => [static function (self $t): void {
                $t->store            = new ContentMemory($t->store->data); // zählt von vorn
                $t->store->failWrite = 2;
            }, ['code' => 'content_failed']],
            'Lesen scheitert' => [static function (self $t): void {
                $t->store->failRead = true;
            }, ['code' => 'content_failed']],
        ];
    }

    /**
     * §7.2: jede Ablehnung von ContentRollback lässt die Inhalte stehen und nennt ihren Code.
     *
     * @param callable(self): void $break
     * @param array<string, mixed> $error
     */
    #[DataProvider('failures')]
    public function testRunKeepsTheContentOnEveryRefusal(callable $break, array $error): void
    {
        $this->seal();
        $this->apply($this->rows());
        $pushed = $this->store->data;
        $break($this);
        $this->assertSame(['state' => 'kept', 'wrote' => false, 'error' => $error], RescueContent::run($this->content, $this->work, self::ID, $this->key));
        $this->assertSame(self::sorted($pushed), self::sorted($this->store->data));
        $this->assertNull(ContentImage::$fileKeys);
    }

    /** §7.6: eine Zeile, die sich nach einem Verbindungsverlust nicht zurücksetzen liess, steht mit unrestored da. */
    public function testRunNamesAnUnrestoredRow(): void
    {
        $this->seal();
        $this->apply($this->rows());
        $this->store              = new ContentMemory($this->store->data); // zählt von vorn
        $this->store->loseAtWrite = 1;
        $this->store->failWrite   = 2;
        $back = RescueContent::run($this->content, $this->work, self::ID, $this->key);
        $this->assertSame('kept', $back['state']);
        $this->assertSame('content_failed', $back['error']['code']);
        $this->assertTrue($back['error']['unrestored']);
        $this->assertCount(1, $back['error']['keys']);
    }

    /** Ein Vorher-Abbild, das nur mit dem Schlüssel der Installation geschützt ist und dessen Dateischlüssel der Umschlag nicht trägt, öffnet rescue.php nicht. */
    public function testRunNeverFallsBackToTheInstallationKey(): void
    {
        $this->seal($this->collected([], self::OTHER) + []); // Dateischlüssel eines anderen Pushs
        $this->apply($this->rows());
        $pushed = $this->store->data;
        $this->assertSame(['state' => 'kept', 'wrote' => false, 'error' => ['code' => 'before_image_invalid']], RescueContent::run($this->content, $this->work, self::ID, $this->key));
        $this->assertSame($pushed, $this->store->data);
    }

    public function testAResponseNeverCarriesMoreThanTwoHundredKeys(): void
    {
        $this->seal();
        // Die Abbilder eines Pushs, der 250 Optionen neu angelegt hat – inzwischen trägt jede einen anderen Wert.
        $before = [];
        $after  = [];
        for ($i = 0; $i < 250; $i++) {
            $name     = 'option_' . $i;
            $before[] = ['t' => 'options', 'k' => $name, 'state' => null];
            $after[]  = ['t' => 'options', 'k' => $name, 'h' => ContentFixtures::hash('options', $name, ContentFixtures::option($name, 'gepusht'))];
            $this->store->data['options'][$name] = ContentFixtures::option($name, 'seither geändert');
        }
        ContentImage::put($this->dir, ContentImage::BEFORE, ['keys' => $before]);
        ContentImage::put($this->dir, ContentImage::AFTER, ['keys' => $after, 'changes' => []]);
        $back = RescueContent::run($this->content, $this->work, self::ID, $this->key);
        $this->assertSame('changed_since_push', $back['error']['code']);
        $this->assertCount(ContentException::MAX_KEYS, $back['error']['keys']);
        $this->assertSame(250, $back['error']['total']);
    }
}
