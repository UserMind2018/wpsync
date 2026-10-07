<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use WpSync\Push;
use WpSync\Staging;
use WpSync\StagingAccess;
use WpSync\StagingConfig;
use WpSync\StagingGuard;
use WpSync\Store;

/**
 * Der Staging-Job (Spec 2b 5.2, 5.9; V1, V7, V9, V12–V14): Staging läuft hier echt auf einem
 * temporären Webroot, mit den echten Staging-Klassen darunter. Nur WordPress, Store und Push sind
 * Attrappen (StagingHarness.php), die Datenbank ist FakeWpdb – deshalb ein Prozess pro Test. Was
 * MySQL und Apache aus alldem machen, prüft scripts/e2e-staging.sh.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class StagingJobTest extends TestCase
{
    /** @var FakeWpdb */
    private $db;
    /** @var list<string> Staging-Tabellen, die es „in der Datenbank“ gerade gibt */
    private $tables = [];
    /** @var (callable(string): void)|null */
    private $watch = null;

    protected function tearDown(): void
    {
        exec('chmod -R u+rwx ' . escapeshellarg(ABSPATH) . ' 2>/dev/null; rm -rf ' . escapeshellarg(rtrim(ABSPATH, '/')));
    }

    /**
     * @param array<string, mixed> $env     home, site, multisite, uploads
     * @param string|null          $content WP_CONTENT_DIR, wenn es nicht im WordPress-Ordner liegt
     */
    private function boot(array $env = [], string $prefix = 'wp_', ?string $content = null): void
    {
        require_once __DIR__ . '/StagingHarness.php';
        require_once __DIR__ . '/FakeWpdb.php';

        exec('rm -rf ' . escapeshellarg(rtrim(ABSPATH, '/')));
        foreach (['wp-admin', 'wp-includes', 'wp-content/plugins/x', 'wp-content/plugins/wpsync-agent', 'wp-content/themes/t', 'wp-content/uploads'] as $dir) {
            mkdir(ABSPATH . $dir, 0777, true);
        }
        foreach ([
            'index.php', 'wp-load.php', 'wp-settings.php', 'wp-config.php', 'wp-admin/index.php', 'wp-admin/setup-config.php',
            'wp-includes/version.php', 'wp-content/index.php', 'wp-content/plugins/x/x.php',
            'wp-content/plugins/wpsync-agent/wpsync-agent.php', 'wp-content/themes/t/style.css', 'wp-content/uploads/a.jpg',
        ] as $file) {
            file_put_contents(ABSPATH . $file, 'live:' . $file);
        }
        define('WP_CONTENT_DIR', $content ?? ABSPATH . 'wp-content');
        $GLOBALS['wpsync_env'] = $env + $GLOBALS['wpsync_env'];

        $this->db              = new FakeWpdb();
        $this->db->base_prefix = $prefix;
        $this->db->observer    = function (string $sql): void {
            if (preg_match('/^CREATE TABLE `([^`]+)`/', $sql, $m) === 1) {
                $this->tables[] = $m[1];
            }
            if (preg_match('/^DROP TABLE IF EXISTS `([^`]+)`/', $sql, $m) === 1) {
                $this->tables = array_values(array_diff($this->tables, [$m[1]]));
            }
            if ($this->watch !== null) {
                ($this->watch)($sql);
            }
        };
        $this->db->answer('/GET_LOCK/', '1');
        $this->db->answer('/^SHOW TABLES LIKE \'stg/', function (): array {
            return $this->tables;
        });
        $this->db->answer('/^SHOW KEYS/', ['ID']);
        $this->db->answer('/^SHOW COLUMNS/', [['Field' => 'ID', 'Type' => 'bigint(20)'], ['Field' => 'txt', 'Type' => 'longtext']]);
        $GLOBALS['wpdb'] = $this->db;
        Staging::register(dirname(__DIR__));
    }

    /** @return array<string, mixed> */
    private function record(): array
    {
        $record = Staging::record();
        $this->assertNotNull($record);
        return $record;
    }

    private function root(): string
    {
        return (string) realpath(ABSPATH) . '/' . $this->record()['dir'];
    }

    private function htaccess(): string
    {
        return (string) @file_get_contents($this->root() . '/.htaccess');
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function begin(string $op, array $params = []): array
    {
        $result = Staging::begin(['op' => $op] + $params);
        $this->assertInstanceOf(\WP_REST_Response::class, $result, $result instanceof \WP_Error ? $result->code . ': ' . $result->message : '');
        return $result->data;
    }

    /**
     * Schritte bis zum Ende des Jobs.
     *
     * @param array<string, mixed> $first
     * @return array<string, mixed> letzter Fortschritt
     */
    private function finish(array $first = []): array
    {
        $params = $first;
        for ($n = 0; $n < 50; $n++) {
            $result = Staging::step($params);
            $this->assertInstanceOf(\WP_REST_Response::class, $result, $result instanceof \WP_Error ? $result->code . ': ' . $result->message : '');
            if ($result->data['phase'] === '') {
                return $result->data;
            }
            $params = [];
        }
        $this->fail('job does not end');
    }

    /** @return array<string, mixed> */
    private function create(array $params = []): array
    {
        $this->begin('create', $params);
        $progress = $this->finish(['probe' => 'ok']);
        $this->assertSame(Staging::READY, $progress['status'], $progress['error']);
        return $progress;
    }

    /** @param mixed $result */
    private function assertError(string $code, int $status, $result): void
    {
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame([$code, $status], [$result->code, $result->status], $result->message);
    }

    private function assertNothingCreated(): void
    {
        $this->assertNull(Staging::record());
        $this->assertSame([], glob(ABSPATH . 'wpsync-staging-*'));
        $this->assertSame([], $this->db->writes());
    }

    /** V1: dry prüft und rechnet, legt aber nichts an */
    public function testDryCreateWritesNothing(): void
    {
        $this->boot();
        $this->db->answer('/information_schema/', '4096');
        $answer = $this->begin('create', ['dry' => true]);
        $this->assertSame(4096, $answer['need']['db_bytes']);
        $this->assertGreaterThan(0, $answer['need']['code_bytes']);
        $this->assertNull($answer['probe']);
        $this->assertNothingCreated();
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string, 2: bool}> */
    public static function unsupported(): array
    {
        return [
            'multisite'           => [['multisite' => true], 'wp_', false],
            'home is not siteurl' => [['site' => 'https://example.test/wp'], 'wp_', false],
            'uploads url'         => [['uploads' => 'https://example.test/wp content/uploads'], 'wp_', false],
            'home url'            => [['home' => 'https://example.test/a b', 'site' => 'https://example.test/a b'], 'wp_', false],
            'no prefix'           => [[], '', false],
            'prefix s'            => [[], 's', false],
            'prefix stg'          => [[], 'stg', false],
            'prefix ST'           => [[], 'ST', false],
            'content elsewhere'   => [[], 'wp_', true],
        ];
    }

    /**
     * Spec 3, V14: 422, bevor irgendetwas geschrieben ist – auch im Probelauf.
     *
     * @param array<string, mixed> $env
     */
    #[DataProvider('unsupported')]
    public function testUnsupportedSetupsAreRejectedBeforeAnyWrite(array $env, string $prefix, bool $moved): void
    {
        $elsewhere = sys_get_temp_dir() . '/wpsync-elsewhere-' . bin2hex(random_bytes(4));
        if ($moved) {
            mkdir($elsewhere . '/wp-content', 0777, true);
        }
        try {
            $this->boot($env, $prefix, $moved ? $elsewhere . '/wp-content' : null);
            foreach ([true, false] as $dry) {
                $this->assertError('wpsync_staging_unsupported', 422, Staging::begin(['op' => 'create', 'dry' => $dry]));
                $this->assertNothingCreated();
            }
        } finally {
            exec('rm -rf ' . escapeshellarg($elsewhere));
        }
    }

    /** Ein wp-content, das nur ein Symlink ist, liegt nicht im WordPress-Ordner. */
    public function testSymlinkedContentDirIsUnsupported(): void
    {
        $elsewhere = sys_get_temp_dir() . '/wpsync-elsewhere-' . bin2hex(random_bytes(4));
        $this->boot();
        try {
            rename(ABSPATH . 'wp-content', $elsewhere);
            symlink($elsewhere, ABSPATH . 'wp-content');
            $this->assertError('wpsync_staging_unsupported', 422, Staging::begin(['op' => 'create']));
            $this->assertNothingCreated();
        } finally {
            exec('rm -rf ' . escapeshellarg($elsewhere));
        }
    }

    /** Tabellen, die sich nicht abbilden lassen: 422 mit Namen, bevor etwas angelegt ist – ausser das Profil kopiert sie ohnehin nicht. */
    public function testUnmappableTablesAreNamedUpFront(): void
    {
        $this->boot();
        $long          = 'wp_' . str_repeat('a', 58); // 61 Zeichen, mit stg…_ statt wp_ 68
        $odd           = 'wp_my-table';
        Store::$tables = ['wp_options', $long, $odd];
        foreach ([true, false] as $dry) {
            $result = Staging::begin(['op' => 'create', 'dry' => $dry]);
            $this->assertError('wpsync_staging_unsupported', 422, $result);
            $this->assertStringContainsString($long, $result->message);
            $this->assertStringContainsString($odd, $result->message);
            $this->assertStringNotContainsString('wp_options', $result->message);
            $this->assertNothingCreated();
        }

        Store::$tables = ['wp_options', $long];
        $this->create(['scope' => ['tables' => [$long => 'skip']]]);
        $this->assertCount(1, $this->tables);
        $this->assertMatchesRegularExpression('/^stg[a-f0-9]{6}_options\z/', $this->tables[0]);
    }

    /** V7, T2: bis zum letzten Schritt ist alles gesperrt; anonymisiert wird vor den URLs, geöffnet zuletzt */
    public function testCreateStaysLockedUntilTheLastStep(): void
    {
        $this->boot();
        $this->db->answer('/^SELECT \* FROM `stg[a-f0-9]{6}_users`/', [[
            'ID' => '7', 'user_login' => 'max', 'user_pass' => 'hash', 'user_nicename' => 'max', 'user_email' => 'max@example.org',
            'user_url' => '', 'user_activation_key' => '', 'display_name' => 'Max Muster',
        ]]);
        $this->db->answer('/^SELECT `ID` AS k, `txt` AS v FROM `stg[a-f0-9]{6}_options`/', [['k' => '1', 'v' => 'https://example.test/shop']]);

        $answer = $this->begin('create');
        $record = $this->record();
        $this->assertMatchesRegularExpression(StagingGuard::DIR_RE, $record['dir']);
        $this->assertMatchesRegularExpression(StagingGuard::PREFIX_RE, $record['prefix']);
        $this->assertSame(Staging::CREATING, $record['status']);
        $this->assertSame('https://example.test/' . $record['dir'], $answer['url']);
        $this->assertSame($answer['url'] . '/' . StagingConfig::PROBE_REWRITE, $answer['probe']['rewrite_url']);
        $this->assertStringContainsString(StagingConfig::PROBE_REWRITE, $this->htaccess());
        // Solange nur die Probe liegt, gibt es in der Kopie nichts ausser ihr.
        $this->assertSame(['.htaccess', StagingConfig::PROBE_DENY, StagingConfig::PROBE_TARGET, StagingConfig::PROBE_FILES], array_values(array_diff((array) scandir($this->root()), ['.', '..'])));
        $this->assertError('wpsync_staging_busy', 423, Staging::pushContent());
        $this->assertError('wpsync_staging_busy', 423, Staging::login());
        $this->assertError('wpsync_staging_busy', 423, Staging::begin(['op' => 'delete']));

        $seen        = [];
        $root        = $this->root();
        $this->watch = function (string $sql) use (&$seen, $root): void {
            if (preg_match('/^\s*(SELECT|SHOW)\b/i', $sql) !== 1) {
                $seen[] = [
                    'sql'    => $sql,
                    'locked' => file_get_contents($root . '/.htaccess') === StagingConfig::locked(),
                    'riegel' => is_file($root . '/wp-content/mu-plugins/00-wpsync-staging.php') && is_file($root . '/wp-config.php'),
                    'status' => $this->record()['status'],
                ];
            }
        };
        $progress = $this->finish(['probe' => 'ok']);
        $this->assertSame(Staging::READY, $progress['status'], $progress['error']);

        $this->assertNotSame([], $seen);
        $first = [];
        foreach ($seen as $i => $write) {
            $this->assertTrue($write['locked'], 'open during: ' . $write['sql']);
            $this->assertSame(Staging::CREATING, $write['status']);
            foreach (['anonymize' => '/_users` SET .*example\.invalid/', 'urls' => '/SET `txt` = \'https:\/\/example\.test\/wpsync-staging-/', 'settings' => '/blog_public/', 'copy' => '/^INSERT INTO/'] as $what => $re) {
                if (!isset($first[$what]) && preg_match($re, $write['sql']) === 1) {
                    $first[$what] = $i;
                }
            }
        }
        $last = [];
        foreach ($seen as $i => $write) {
            if (preg_match('/^(CREATE TABLE|INSERT INTO)/', $write['sql']) === 1) {
                $last['copy'] = $i;
            }
        }
        $this->assertLessThan($first['anonymize'], $last['copy'], 'anonymized before every table was copied');
        $this->assertLessThan($first['urls'], $first['anonymize']);
        $this->assertLessThan($first['settings'], $first['urls']);
        // Die Riegel in der Datenbank kommen, wenn Riegel und wp-config.php der Kopie schon liegen.
        $this->assertTrue($seen[$first['settings']]['riegel']);
        $this->assertFalse($seen[$first['urls']]['riegel']);

        // Erst jetzt ist die Kopie offen – nur mit Cookie, und alles Nötige liegt.
        $path = '/' . $record['dir'];
        $this->assertSame(StagingConfig::htaccess($path, 'https://example.test/wp-content/uploads'), $this->htaccess());
        $state = (new StagingAccess($root . '/' . StagingAccess::FILE))->read();
        $this->assertSame(['https://example.test', $path, 'https://example.test/wp-content/uploads', false], [$state['live_url'], $state['staging_path'], $state['uploads_url'], $state['locked']]);
        $this->assertSame(file_get_contents(dirname(__DIR__) . '/staging/00-wpsync-staging.php'), file_get_contents($root . '/wp-content/mu-plugins/00-wpsync-staging.php'));
        $this->assertFileExists($root . '/wp-content/mu-plugins/wpsync-staging/StagingAccess.php');
        $this->assertFileExists($root . '/wp-content/mu-plugins/wpsync-staging/StagingHosts.php');
        $config = (string) file_get_contents($root . '/wp-config.php');
        $this->assertStringContainsString("\$table_prefix = '" . $record['prefix'] . "';", $config);
        $this->assertStringContainsString("define('WPSYNC_STAGING', true);", $config);
        $this->assertStringNotContainsString('live:wp-config.php', $config);
        $this->assertSame(fileperms(ABSPATH . 'wp-config.php') & 0777, fileperms($root . '/wp-config.php') & 0777);
        $this->assertSame([], glob($root . '/{,.}*.tmp', GLOB_BRACE));
        $this->assertSame('live:wp-content/plugins/x/x.php', file_get_contents($root . '/wp-content/plugins/x/x.php'));
        foreach (['wp-admin/setup-config.php', 'wp-content/plugins/wpsync-agent', 'wp-content/uploads', StagingConfig::PROBE_TARGET] as $never) {
            $this->assertFileDoesNotExist($root . '/' . $never);
        }

        $this->assertSame(['url' => $answer['url'], 'prefix' => $record['prefix'], 'anonymized' => true], array_intersect_key($progress['result'], ['url' => 1, 'prefix' => 1, 'anonymized' => 1]));
        $this->assertSame([$record['prefix'] . 'options' => 1], (array) $progress['result']['replaced']);
        $this->assertNull(Store::getState(Staging::PLAN));

        // Was Push von der Kopie braucht (Task 10).
        $this->assertSame($root . '/wp-content', Staging::contentDir());
        $this->assertSame($root . '/wp-content', Staging::pushContent());
        $this->assertTrue(Staging::inside($root . '/wp-content/plugins/x'));
        $this->assertFalse(Staging::inside((string) realpath(ABSPATH) . '/wp-content/plugins/x'));
        $this->assertSame([$answer['url'] . '/', $answer['url'] . '/wp-login.php', $answer['url'] . '/shop/'], Staging::healthUrls());
        $this->assertSame([$record['prefix']], Staging::hiddenPrefixes());
        $this->assertError('wpsync_staging_exists', 409, Staging::begin(['op' => 'create']));
    }

    /** Cursor und Phase stehen nach jedem Schritt in der Datenbank, bevor der nächste beginnt. */
    public function testEveryStepIsSavedBeforeTheNext(): void
    {
        $this->boot();
        Store::$tables = ['wp_users'];
        $this->db->answer('/^INSERT INTO/', 2000, 4000, 0);
        $this->db->answer('/^SELECT MAX/', '2000', "6000\xff");
        $page = [];
        for ($id = 1; $id <= 100; $id++) {
            $page[] = ['ID' => (string) $id, 'user_email' => 'u' . $id . '@example.org'];
        }
        $this->db->answer('/^SELECT \* FROM `stg[a-f0-9]{6}_users`/', $page, [['ID' => '101', 'user_email' => 'u101@example.org']]);

        $this->begin('create');
        $seen        = [];
        $this->watch = function (string $sql) use (&$seen): void {
            if (preg_match('/^(INSERT INTO|SELECT \*)/', $sql) === 1) {
                $job    = $this->record()['job'];
                $seen[] = [$sql, $job['phase'], $job['i'], $job['cursor']];
            }
        };
        $this->finish(['probe' => 'ok']);

        $this->assertCount(5, $seen);
        $this->assertSame(['tables', 0, null], array_slice($seen[0], 1));
        $this->assertSame('b:' . base64_encode('2000'), $seen[1][3]['after']);
        $this->assertStringContainsString("> '2000'", $seen[1][0]);
        // Bytes aus der Datenbank überstehen den Zustand unverändert – sonst spränge der Cursor.
        $this->assertSame('b:' . base64_encode("6000\xff"), $seen[2][3]['after']);
        $this->assertStringContainsString("> '6000\xff'", $seen[2][0]);
        $this->assertSame(['anonymize', 0, null], array_slice($seen[3], 1));
        $this->assertSame(['anonymize', 0, 'b:' . base64_encode('100')], array_slice($seen[4], 1));
        $this->assertStringContainsString("> '100'", $seen[4][0]);
    }

    /** Lässt sich der Zustand nicht speichern, endet der Aufruf – der nächste Schritt liefe sonst doppelt. */
    public function testAStepThatCannotBeSavedStopsTheRequest(): void
    {
        $this->boot();
        Store::$tables = ['wp_users'];
        $this->db->answer('/^INSERT INTO/', 2000, 4000, 0);
        $this->db->answer('/^SELECT MAX/', '2000', '6000');
        $this->begin('create');
        $inserts     = 0;
        $this->watch = static function (string $sql) use (&$inserts): void {
            if (strpos($sql, 'INSERT INTO') === 0 && ++$inserts === 2) {
                Store::$failNext = true;
            }
        };
        $this->assertError('wpsync_staging_failed', 500, Staging::step(['probe' => 'ok']));
        $this->assertSame(2, $inserts);
        $job = $this->record()['job'];
        $this->assertSame(['tables', 'b:' . base64_encode('2000')], [$job['phase'], $job['cursor']['after']]);
        $this->assertSame(StagingConfig::locked(), $this->htaccess());
    }

    /** @return array<string, array{0: string}> */
    public static function phases(): array
    {
        return ['files' => ['files'], 'tables' => ['tables'], 'anonymize' => ['anonymize'], 'fixup' => ['fixup'], 'urls' => ['urls'], 'settings' => ['settings']];
    }

    /** V12, V13, AC-85: ein abgebrochenes create hinterlässt „failed“ ohne Ordner und Tabellen */
    #[DataProvider('phases')]
    public function testAbortedCreateLeavesNothingBehind(string $phase): void
    {
        define('WPSYNC_TEST_FAIL_PHASE', $phase);
        $this->boot();
        $this->begin('create');
        $root     = $this->root();
        $progress = $this->finish(['probe' => 'ok']);

        $this->assertSame(Staging::FAILED, $progress['status']);
        $this->assertSame('failed', $progress['error_code']);
        $this->assertStringStartsWith($phase . ': Testabbruch', $progress['error']);
        $this->assertFileDoesNotExist($root);
        $this->assertSame([], $this->tables);
        $this->assertNull($this->record()['job']);
        $this->assertNull(Store::getState(Staging::PLAN));
        $this->assertSame('', Staging::contentDir());
        $this->assertError('wpsync_staging_state', 409, Staging::pushContent());
        $this->assertError('wpsync_staging_state', 409, Staging::login());
        // Ein neues create ist erlaubt – mit neuem Ordner und Präfix.
        $this->begin('create');
        $this->assertNotSame($root, $this->root());
    }

    /** S5: ohne wirksame .htaccess gibt es keine Kopie */
    public function testFailedProbeRemovesTheFolder(): void
    {
        $this->boot();
        $this->begin('create');
        $root     = $this->root();
        $progress = $this->finish(['probe' => 'fail']);
        $this->assertSame([Staging::FAILED, 'staging_unsupported'], [$progress['status'], $progress['error_code']]);
        $this->assertStringContainsString('location ^~ /' . basename($root) . '/', $progress['error']);
        $this->assertFileDoesNotExist($root);
        $this->assertSame([], $this->db->writes());
    }

    /** V7, V12: ein abgebrochenes refresh löscht die Tabellen, die Kopie bleibt gesperrt; create darf die Reste nicht verwaisen lassen */
    public function testAbortedRefreshKeepsTheCopyLocked(): void
    {
        $this->boot();
        $this->create();
        $root = $this->root();
        $this->db->fail('/^CREATE TABLE `stg[a-f0-9]{6}_posts`/', 'boom');
        $cookie = (string) (new StagingAccess($root . '/' . StagingAccess::FILE))->redeemToken((new StagingAccess($root . '/' . StagingAccess::FILE))->issueToken(time()), time());
        $this->begin('refresh');
        $this->assertSame(StagingConfig::locked(), $this->htaccess());
        // Mit dem Job enden die Zugänge: der Riegel lehnt auch ab, wo die .htaccess nicht wirkt.
        $this->assertFalse((new StagingAccess($root . '/' . StagingAccess::FILE))->cookieValid($cookie, time()));
        // Selbst wenn ein früherer Versuch die Kopie schon wieder geöffnet hätte: das Aufräumen sperrt zuerst.
        file_put_contents($root . '/.htaccess', StagingConfig::htaccess('/' . basename($root), 'https://example.test/wp-content/uploads'));
        $progress = $this->finish();

        $this->assertSame([Staging::FAILED, 'failed'], [$progress['status'], $progress['error_code']]);
        $this->assertStringStartsWith('tables: SQL-Fehler: boom', $progress['error']);
        $this->assertSame([], $this->tables);
        $this->assertSame(StagingConfig::locked(), $this->htaccess());
        $this->assertTrue((new StagingAccess($root . '/' . StagingAccess::FILE))->read()['locked']);
        $this->assertFileExists($root . '/wp-content/plugins/x/x.php');
        $this->assertError('wpsync_staging_state', 409, Staging::pushContent());
        $this->assertError('wpsync_staging_state', 409, Staging::login());
        $result = Staging::begin(['op' => 'create']);
        $this->assertError('wpsync_staging_exists', 409, $result);
        $this->assertStringContainsString('staging delete', $result->message);
        $this->assertSame($root, $this->root());
        $this->assertSame([], Push::$dropped);
    }

    /** Spec 5.2: refresh ersetzt die Datenbank, der Code bleibt; mit code auch der Code, und Staging-Pushes verfallen */
    public function testRefreshReplacesTablesAndOnRequestTheCode(): void
    {
        $this->boot();
        $this->create();
        $root = $this->root();
        file_put_contents($root . '/wp-content/plugins/x/pushed.php', 'pushed');
        mkdir($root . '/wp-content/' . Store::pushDirName() . '/p_1/old', 0777, true);
        $before = $this->tables;

        $this->begin('refresh');
        $this->assertSame(Staging::REFRESHING, $this->record()['status']);
        $this->assertSame(Staging::READY, $this->finish()['status']);
        $this->assertSame($before, $this->tables);
        $this->assertFileExists($root . '/wp-content/plugins/x/pushed.php');
        $this->assertSame([], Push::$dropped);

        $this->begin('refresh', ['code' => true, 'scope' => ['plain_pii' => true]]);
        $this->assertSame(['staging'], Push::$dropped);
        $progress = $this->finish();
        $this->assertSame(Staging::READY, $progress['status'], $progress['error']);
        $this->assertFalse($progress['result']['anonymized']);
        $this->assertFileDoesNotExist($root . '/wp-content/plugins/x/pushed.php');
        $this->assertFileDoesNotExist($root . '/wp-content/' . Store::pushDirName());
        $this->assertFileExists($root . '/wp-content/plugins/x/x.php');
        $this->assertFileExists($root . '/wp-content/mu-plugins/00-wpsync-staging.php');
        $this->assertStringNotContainsString(StagingConfig::locked(), $this->htaccess());
    }

    /**
     * code_copied_at nennt, wann der Code der Kopie zuletzt von Live kam: create setzt es, ein reiner
     * Datenbank-Refresh lässt es stehen, ein Refresh mit Code ändert es – auch der erzwungene nach
     * code_ok = false, und auch in derselben Sekunde.
     */
    public function testCodeCopiedAtFollowsOnlyTheCode(): void
    {
        $this->boot();
        $this->create();
        $created = $this->record()['code_copied_at'];
        $this->assertGreaterThan(0, $created);
        $this->assertSame($this->record()['copied_at'], $created);
        $this->assertSame($created, Staging::status()->data['code_copied_at']);

        $record = $this->record();
        $record['copied_at'] = $created - 100; // damit ein Refresh in derselben Sekunde sichtbar wird
        Store::setState(Staging::STATE, $record);
        $this->begin('refresh');
        $this->assertSame(Staging::READY, $this->finish()['status']);
        $this->assertSame($created, $this->record()['code_copied_at']);
        $this->assertGreaterThan($created - 100, $this->record()['copied_at']);
        $this->assertSame($created, Staging::status()->data['code_copied_at']);

        $this->begin('refresh', ['code' => true]);
        $this->assertSame($created, $this->record()['code_copied_at'], 'erst die fertige Kopie zählt');
        $this->assertSame(Staging::READY, $this->finish()['status']);
        $refreshed = $this->record()['code_copied_at'];
        $this->assertGreaterThan($created, $refreshed);

        $record = $this->record();
        $record['code_ok'] = false;
        $record['status']  = Staging::FAILED;
        Store::setState(Staging::STATE, $record);
        $this->begin('refresh');
        $this->assertSame('refresh-code', $this->record()['job']['op']);
        $this->assertSame(Staging::READY, $this->finish()['status']);
        $this->assertGreaterThan($refreshed, $this->record()['code_copied_at']);
        $this->assertSame($this->record()['code_copied_at'], Staging::status()->data['code_copied_at']);
    }

    /** Ein Datensatz von vor dem Feld meldet 0 – die CLI bindet dann wie bisher an copied_at. */
    public function testStatusOfAnOlderRecordNamesNoCodeCopy(): void
    {
        $this->boot();
        $this->create();
        $record = $this->record();
        unset($record['code_copied_at']);
        Store::setState(Staging::STATE, $record);
        $this->assertSame(0, Staging::status()->data['code_copied_at']);
        $this->assertGreaterThan(0, Staging::status()->data['copied_at']);
    }

    /** Ein refresh, das mitten im Code abbrach, lässt keinen Stand, auf den eine reine Datenbank-Kopie passte. */
    public function testRefreshAfterAnAbortInTheCodeCopiesTheCodeAgain(): void
    {
        $this->boot();
        $this->create();
        $root   = $this->root();
        $record = $this->record();
        $this->assertTrue($record['code_ok']);
        $record['code_ok'] = false;
        $record['status']  = Staging::FAILED;
        Store::setState(Staging::STATE, $record);
        unlink($root . '/wp-content/plugins/x/x.php');

        $this->begin('refresh');
        $this->assertSame('refresh-code', $this->record()['job']['op']);
        $this->assertSame(Staging::READY, $this->finish()['status']);
        $this->assertFileExists($root . '/wp-content/plugins/x/x.php');
        $this->assertTrue($this->record()['code_ok']);
    }

    /** V9: ein unbestätigter oder laufender Push nach Staging hält refresh und delete auf */
    public function testPushesToStagingBlockRefreshAndDelete(): void
    {
        $this->boot();
        $this->create();
        Push::$pending = ['push_id' => 'p_1', 'device' => 'd', 'created' => 1];
        foreach (['refresh', 'delete'] as $op) {
            $this->assertError('wpsync_staging_pending', 409, Staging::begin(['op' => $op]));
        }
        Push::$pending = null;
        Push::$running = true;
        foreach (['refresh', 'delete'] as $op) {
            $this->assertError('wpsync_staging_busy', 423, Staging::begin(['op' => $op]));
        }
        $this->assertSame(Staging::READY, $this->record()['status']);
        $this->assertSame([], Push::$dropped);
    }

    /** Spec 5.8, 5.9: delete nimmt Ordner, Tabellen, Push-Arbeitsordner und offene Staging-Pushes mit */
    public function testDeleteRemovesEverything(): void
    {
        $this->boot();
        $this->assertError('wpsync_staging_missing', 409, Staging::begin(['op' => 'delete']));
        $this->create();
        $root = $this->root();
        mkdir($root . '/wp-content/' . Store::pushDirName() . '/p_1/old', 0777, true);

        $dry = $this->begin('delete', ['dry' => true]);
        $this->assertSame(Staging::READY, $this->record()['status']);
        $this->assertNull($dry['need']);

        $this->begin('delete');
        $this->assertSame(['staging'], Push::$dropped);
        $this->assertSame(StagingConfig::locked(), $this->htaccess());
        $this->assertSame(Staging::DELETING, $this->record()['status']);
        $progress = $this->finish();
        $this->assertSame('deleted', $progress['status']);
        $this->assertNull(Staging::record());
        $this->assertFileDoesNotExist($root);
        $this->assertSame([], $this->tables);
        $this->assertSame(['exists' => false], Staging::status()->data);
        $this->assertFileExists(ABSPATH . 'wp-content/plugins/x/x.php');
    }

    /** Leitplanke 2, V12: liegt statt des Ordners ein Symlink da, geht nur der Symlink – und delete endet trotzdem */
    public function testDeleteNeverFollowsASymlinkedRoot(): void
    {
        $this->boot();
        $this->create();
        $root = $this->root();
        exec('rm -rf ' . escapeshellarg($root) . ' && ln -s ' . escapeshellarg((string) realpath(ABSPATH) . '/wp-content') . ' ' . escapeshellarg($root));

        $this->assertSame('', Staging::contentDir());
        $this->assertFalse(Staging::inside($root . '/plugins/x'));
        $this->begin('delete');
        $this->assertSame('deleted', $this->finish()['status']);
        $this->assertFalse(is_link($root));
        $this->assertSame('live:wp-content/plugins/x/x.php', file_get_contents(ABSPATH . 'wp-content/plugins/x/x.php'));
        $this->assertFileDoesNotExist(ABSPATH . 'wp-content/.htaccess');
    }

    /** V12: scheitert delete, bleibt „failed“ mit Grund, und delete lässt sich wiederholen */
    public function testFailedDeleteCanBeRepeated(): void
    {
        $this->boot();
        $this->create();
        $root = $this->root();
        $this->db->fail('/^DROP TABLE/', 'no');
        $this->begin('delete');
        $progress = $this->finish();
        $this->assertSame(Staging::FAILED, $progress['status']);
        $this->assertStringStartsWith('drop: SQL-Fehler: no', $progress['error']);
        $this->assertFileExists($root);
        $this->assertSame(StagingConfig::locked(), $this->htaccess());

        $this->db = new FakeWpdb();
        $this->db->answer('/GET_LOCK/', '1');
        $this->db->answer('/^SHOW TABLES LIKE/', []);
        $GLOBALS['wpdb'] = $this->db;
        $this->begin('delete');
        $this->assertSame('deleted', $this->finish()['status']);
        $this->assertFileDoesNotExist($root);
    }

    /** Spec 5.9: Deaktivieren des Agents nimmt Kopie, Tabellen und Staging-Pushes mit */
    public function testUninstallRemovesCopyTablesAndPushes(): void
    {
        $this->boot();
        Staging::uninstall(); // ohne Kopie: nichts
        $this->assertSame([], Push::$dropped);
        $this->create();
        $root = $this->root();
        mkdir($root . '/wp-content/' . Store::pushDirName() . '/p_1/old', 0777, true);

        Staging::uninstall();
        $this->assertSame(['staging'], Push::$dropped);
        $this->assertNull(Staging::record());
        $this->assertFileDoesNotExist($root);
        $this->assertSame([], $this->tables);
        $this->assertFileExists(ABSPATH . 'wp-content/plugins/x/x.php');
    }

    /** Nur ein Präfix, das StagingGuard annähme, erreicht die Filter von Live. */
    public function testHiddenPrefixesNeedAValidStagingPrefix(): void
    {
        $this->boot();
        $this->assertSame([], Staging::hiddenPrefixes());
        foreach (['', 'wp_', 'wp_posts', 'stgzzzzzz_', 'stg12345_', 'STGABC123_', '%', 42, null] as $prefix) {
            Store::$state[Staging::STATE] = ['status' => Staging::READY, 'dir' => 'wpsync-staging-0123456789ab', 'prefix' => $prefix];
            $this->assertSame([], Staging::hiddenPrefixes(), var_export($prefix, true));
        }
        Store::$state[Staging::STATE] = ['status' => Staging::READY, 'dir' => '../x', 'prefix' => 'stgabc123_'];
        $this->assertSame(['stgabc123_'], Staging::hiddenPrefixes());
        $this->db->base_prefix = 'stgabc123_x_';
        $this->assertSame([], Staging::hiddenPrefixes());
    }

    /** T1, S4, Spec 5.9: Einmal-Link nur für eine bereite Kopie; nach 14 Tagen gesperrt, open entsperrt */
    public function testLoginExpiryAndUnlock(): void
    {
        $this->boot();
        $this->assertError('wpsync_staging_missing', 409, Staging::login());
        $this->create();
        $root   = $this->root();
        $access = new StagingAccess($root . '/' . StagingAccess::FILE);

        $login = Staging::login();
        $this->assertInstanceOf(\WP_REST_Response::class, $login);
        $this->assertSame(1, preg_match('#^https://example\.test/wpsync-staging-[a-f0-9]{12}/\?wpsync_login=([a-f0-9]{64})\z#', $login->data['url'], $m));
        $this->assertNotNull($access->redeemToken($m[1], time()));
        $this->assertNull($access->redeemToken($m[1], time()));

        Staging::maintain();
        $this->assertSame(Staging::READY, $this->record()['status']);

        $state              = json_decode((string) file_get_contents($root . '/' . StagingAccess::FILE), true);
        $state['last_used'] = time() - StagingAccess::EXPIRE_AFTER - 60;
        file_put_contents($root . '/' . StagingAccess::FILE, json_encode($state));
        Staging::maintain();
        $this->assertSame(Staging::LOCKED, $this->record()['status']);
        $this->assertSame(StagingConfig::locked(), $this->htaccess());
        $this->assertTrue($access->read()['locked']);
        $this->assertSame([], $access->read()['cookies']);
        $this->assertError('wpsync_staging_locked', 409, Staging::pushContent());
        $this->assertSame(Staging::LOCKED, Staging::summary()['status']);
        $this->assertSame($root . '/wp-content', Staging::contentDir()); // bestehende Pushes bleiben auffindbar

        $login = Staging::login();
        $this->assertInstanceOf(\WP_REST_Response::class, $login);
        $this->assertSame(Staging::READY, $this->record()['status']);
        $this->assertFalse($access->read()['locked']);
        $this->assertStringContainsString('RewriteCond %{HTTP_COOKIE}', $this->htaccess());
        $this->assertSame($root . '/wp-content', Staging::pushContent());

        // Ohne Riegel prüft niemand das Cookie: kein Link.
        unlink($root . '/wp-content/mu-plugins/00-wpsync-staging.php');
        $this->assertError('wpsync_staging_state', 409, Staging::login());
    }

    /** Ein Job, dessen CLI verschwunden ist, hält nichts für immer fest: delete geht nach 10 Minuten. */
    public function testAStaleJobCanBeDeleted(): void
    {
        $this->boot();
        $this->begin('create');
        $root                     = $this->root();
        $record                   = $this->record();
        $record['job']['touched'] = time() - Staging::JOB_TTL - 1;
        Store::setState(Staging::STATE, $record);

        $this->assertError('wpsync_staging_exists', 409, Staging::begin(['op' => 'create']));
        $this->assertError('wpsync_staging_state', 409, Staging::begin(['op' => 'refresh']));
        $this->assertError('wpsync_staging_state', 409, Staging::pushContent());
        $this->begin('delete');
        $this->assertSame('deleted', $this->finish()['status']);
        $this->assertFileDoesNotExist($root);
    }

    /** Ein Datensatz, der nicht von hier stammt, bringt den Job zum Abbruch – nie zu einem Schritt ausserhalb. */
    public function testATamperedRecordCannotLeaveTheCopy(): void
    {
        $this->boot();
        $this->create();
        $record           = $this->record();
        $record['prefix'] = 'wp_';
        Store::setState(Staging::STATE, $record);
        $this->assertSame('', Staging::contentDir());
        $this->assertFalse(Staging::inside($this->rootOf($record) . '/wp-content'));
        $before = $this->db->writes();
        $this->begin('delete');
        $progress = $this->finish();
        $this->assertSame(Staging::FAILED, $progress['status']);
        $this->assertSame('guard', $progress['error_code']);
        $this->assertSame($before, $this->db->writes());
        $this->assertFileExists($this->rootOf($record));
    }

    /** @param array<string, mixed> $record */
    private function rootOf(array $record): string
    {
        return (string) realpath(ABSPATH) . '/' . $record['dir'];
    }
}
