<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use WpSync\ContentImage;
use WpSync\PushContent;
use WpSync\RescueContent;
use WpSync\Staging;

/**
 * Was der Begin für den Umschlag aus dem laufenden WordPress sammelt (Spec Content-Push P3 §5.2,
 * §5.3): die Verbindung in der Form von wpdb::db_connect(), die Sitzung, Präfix und Adressen des
 * Ziels – und die Gründe, aus denen es keinen Umschlag gibt. Die Konstanten DB_* gibt es je
 * Prozess einmal, deshalb ein Prozess pro Test.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PushContentRescueDataTest extends TestCase
{
    private const ID = 'p_20261009_0123456789ab';

    protected function setUp(): void
    {
        require_once __DIR__ . '/PushHarness.php';
        $GLOBALS['wpsync_test_options'] = ['home' => 'https://kunde.de/', 'siteurl' => 'https://kunde.de/wp'];
        ContentImage::$keys = [hash('sha256', 'a', true), hash('sha256', 'b', true)];
    }

    protected function tearDown(): void
    {
        ContentImage::$keys = null;
    }

    /**
     * Ein $wpdb, wie der Kanal es braucht: dbh ist ein mysqli (hier unverbunden), parse_db_host()
     * rechnet wie WordPress.
     *
     * @param array<string, mixed> $over
     */
    private function wpdb(array $over = []): object
    {
        $wpdb = new class {
            /** @var mixed */
            public $dbh;
            /** @var string */
            public $prefix = 'wp_';
            /** @var string */
            public $base_prefix = 'wp_';
            /** @var string */
            public $charset = 'utf8mb4';
            /** @var string */
            public $collate = 'utf8mb4_unicode_520_ci';
            /** @var string */
            public $last_error = '';
            /** @var string|null */
            public $mode = 'NO_ENGINE_SUBSTITUTION';
            /** @var list<string> */
            public $queries = [];

            /** Wie wpdb::parse_db_host() (WordPress 4.9+). */
            public function parse_db_host(string $host)
            {
                $socket     = null;
                $is_ipv6    = false;
                $socket_pos = strpos($host, ':/');
                if (false !== $socket_pos) {
                    $socket = substr($host, $socket_pos + 1);
                    $host   = substr($host, 0, $socket_pos);
                }
                if (substr_count($host, ':') > 1) {
                    $pattern = '#^(?:\[)?(?P<host>[0-9a-fA-F:]+)(?:\]:(?P<port>[\d]+))?#';
                    $is_ipv6 = true;
                } else {
                    $pattern = '#^(?P<host>[^:/]*)(?::(?P<port>[\d]+))?#';
                }
                if (1 !== preg_match($pattern, $host, $matches)) {
                    return false;
                }
                return [!empty($matches['host']) ? $matches['host'] : '', !empty($matches['port']) ? abs((int) $matches['port']) : null, $socket, $is_ipv6];
            }

            public function get_var(string $sql): ?string
            {
                $this->queries[] = $sql;
                if ($this->mode === null) {
                    $this->last_error = "Access denied for user 'wp_user'";
                    return null;
                }
                return $this->mode;
            }
        };
        $wpdb->dbh = mysqli_init();
        foreach ($over as $name => $value) {
            $wpdb->{$name} = $value;
        }
        return $GLOBALS['wpdb'] = $wpdb;
    }

    private function define(string $host = 'db.internal:3307', int $flags = 0): void
    {
        define('DB_HOST', $host);
        define('DB_USER', 'wp_user');
        define('DB_PASSWORD', "geh'eim\"\\");
        define('DB_NAME', 'wordpress_db');
        if ($flags !== 0) {
            define('MYSQL_CLIENT_FLAGS', $flags);
        }
    }

    public function testCollectsTheConnectionOfWpdbAndTheSession(): void
    {
        $this->define('db.internal:3307', 2048 | 65536 | 2); // SSL, dazu Mehrfachabfragen und FOUND_ROWS
        $wpdb = $this->wpdb();
        $data = PushContent::rescueData('live', self::ID);
        $this->assertIsArray($data);
        $this->assertSame([
            'host' => 'db.internal', 'port' => 3307, 'socket' => null, 'user' => 'wp_user', 'password' => "geh'eim\"\\", 'name' => 'wordpress_db',
            'flags' => 2048, 'charset' => 'utf8mb4', 'collate' => 'utf8mb4_unicode_520_ci', 'sql_mode' => 'NO_ENGINE_SUBSTITUTION',
        ], $data['db']);
        $this->assertSame(['live', 'wp_', 'https://kunde.de', 'https://kunde.de/wp', null], [$data['target'], $data['prefix'], $data['home'], $data['siteurl'], $data['staging']]);
        $this->assertSame(['SELECT @@SESSION.sql_mode'], $wpdb->queries, 'gelesen, nicht nachgerechnet');
        $this->assertSame(array_map('base64_encode', ContentImage::fileKeys(self::ID, 'before.json')), $data['image_keys']['before.json']);
        $this->assertCount(2, $data['image_keys']['after.json']);
        $this->assertNotContains(base64_encode(ContentImage::$keys[0]), $data['image_keys']['before.json'], 'nie der Schlüssel der Installation');

        // Was der Begin sammelt, besteht die Prüfung von rescue.php.
        $checked = RescueContent::check(['v' => 1, 'push_id' => self::ID, 'created' => 1] + $data, self::ID, '/var/www/html/wp-content');
        $this->assertNotNull($checked);
        $this->assertSame("geh'eim\"\\", $checked['db']['password']);
    }

    /** @return array<string, array{0: string, 1: array{0: string, 1: int|null, 2: string|null}}> */
    public static function hosts(): array
    {
        $v6 = extension_loaded('mysqlnd') ? '[::1]' : '::1';
        return [
            'nur der Name'     => ['localhost', ['localhost', null, null]],
            'Socket'           => ['localhost:/var/run/mysqld/mysqld.sock', ['localhost', null, '/var/run/mysqld/mysqld.sock']],
            'nur ein Socket'   => [':/tmp/mysql.sock', ['', null, '/tmp/mysql.sock']],
            'Port und Socket'  => ['db:3306:/tmp/mysql.sock', ['db', 3306, '/tmp/mysql.sock']],
            'IPv6 mit Port'    => ['[::1]:3308', [$v6, 3308, null]],
            'IPv6 ohne Port'   => ['::1', [$v6, null, null]],
        ];
    }

    /** @param array{0: string, 1: int|null, 2: string|null} $want */
    #[\PHPUnit\Framework\Attributes\DataProvider('hosts')]
    public function testTheHostHasTheFormWpdbGivesToMysqli(string $host, array $want): void
    {
        $this->define($host);
        $this->wpdb();
        $data = PushContent::rescueData('live', self::ID);
        $this->assertSame($want, [$data['db']['host'], $data['db']['port'], $data['db']['socket']]);
        $this->assertNotNull(RescueContent::check(['v' => 1, 'push_id' => self::ID] + $data, self::ID, '/var/www/html/wp-content'));
    }

    public function testAnEmptySessionModeAndNoCharsetAreCollectedAsTheyAre(): void
    {
        $this->define();
        $this->wpdb(['mode' => '', 'charset' => '', 'collate' => '', 'prefix' => '']);
        $data = PushContent::rescueData('live', self::ID);
        $this->assertSame(['', '', '', ''], [$data['db']['sql_mode'], $data['db']['charset'], $data['db']['collate'], $data['prefix']]);
        $this->assertSame(0, $data['db']['flags']);
    }

    public function testWithoutMysqliThereIsNoEnvelope(): void
    {
        $this->define();
        $this->wpdb(['dbh' => new \stdClass()]);
        $this->assertSame('driver', PushContent::rescueData('live', self::ID));
        $this->wpdb(['dbh' => null]);
        $this->assertSame('driver', PushContent::rescueData('live', self::ID));
        $GLOBALS['wpdb'] = new \WpsyncHarnessDb(); // kein parse_db_host, kein dbh
        $this->assertSame('driver', PushContent::rescueData('live', self::ID));
        $GLOBALS['wpdb'] = null;
        $this->assertSame('driver', PushContent::rescueData('live', self::ID));
    }

    public function testWithoutTheConstantsOfTheInstallationThereIsNoEnvelope(): void
    {
        $this->wpdb();
        $this->assertSame('driver', PushContent::rescueData('live', self::ID));
    }

    /** §5.3: ohne Schlüssel der Installation lägen die Abbilder als Klartext da. */
    public function testWithoutAKeyOfTheInstallationThereIsNoEnvelope(): void
    {
        $this->define();
        $wpdb               = $this->wpdb();
        ContentImage::$keys = [];
        $this->assertSame('no_image_key', PushContent::rescueData('live', self::ID));
        $this->assertSame([], $wpdb->queries);
    }

    public function testAFailedReadOfTheSessionIsAFailedProbeWithoutItsText(): void
    {
        $this->define();
        $this->wpdb(['mode' => null]);
        $this->assertSame('probe_failed', PushContent::rescueData('live', self::ID));
    }

    public function testForTheCopyItNamesItsPrefixAndFolder(): void
    {
        $this->define();
        $this->wpdb();
        $this->assertSame('probe_failed', PushContent::rescueData('staging', self::ID), 'ohne benutzbare Kopie');
        Staging::$copy = ['prefix' => 'stgabcdef_', 'dir' => Staging::DIR, 'live_home' => 'https://kunde.de', 'live_prefix' => 'wp_'];
        $data          = PushContent::rescueData('staging', self::ID);
        $this->assertSame(['staging', 'stgabcdef_', ['dir' => Staging::DIR, 'live_home' => 'https://kunde.de', 'live_prefix' => 'wp_']], [$data['target'], $data['prefix'], $data['staging']]);
        $this->assertSame('https://kunde.de', $data['home'], 'home bleibt die von Live – wie PushContent::target()');
        $this->assertNotNull(RescueContent::check(['v' => 1, 'push_id' => self::ID] + $data, self::ID, '/var/www/html/' . Staging::DIR . '/wp-content'));
        $this->assertNull(RescueContent::check(['v' => 1, 'push_id' => self::ID] + $data, self::ID, '/var/www/html/wp-content'), 'und gilt nur in der Kopie');
    }

    /** Die Naht für Tests ersetzt die ganze Sammlung. */
    public function testTheSeamReplacesTheCollection(): void
    {
        PushContent::$rescueData = static function (string $name, string $id): string {
            return $name . ':' . $id;
        };
        $this->assertSame('live:' . self::ID, PushContent::rescueData('live', self::ID));
    }
}
