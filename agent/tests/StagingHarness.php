<?php
/**
 * Umgebung für StagingJobTest: WordPress-Funktionen, Store und Push als Attrappen; Staging,
 * StagingDb, StagingFiles, StagingGuard, StagingConfig und StagingAccess laufen echt. Die Datei
 * definiert WpSync\Store und WpSync\Push selbst und darf deshalb nur in einem eigenen Prozess
 * geladen werden (RunTestsInSeparateProcesses) – nie von einem anderen Test.
 */
namespace {
    const WPSYNC_VERSION   = 'test';
    const HOUR_IN_SECONDS  = 3600;
    const DB_NAME          = 'db';
    const DB_USER          = 'user';
    const DB_PASSWORD      = 'db-password-of-live';
    const DB_HOST          = 'localhost';
    const DB_CHARSET       = 'utf8mb4';
    const DB_COLLATE       = '';

    if (!defined('ARRAY_A')) {
        define('ARRAY_A', 'ARRAY_A');
    }
    if (!defined('ARRAY_N')) {
        define('ARRAY_N', 'ARRAY_N');
    }

    /** @var array{home: string, site: string, multisite: bool, uploads: string} */
    $GLOBALS['wpsync_env'] = [
        'home'      => 'https://example.test',
        'site'      => 'https://example.test',
        'multisite' => false,
        'uploads'   => 'https://example.test/wp-content/uploads',
    ];

    class WP_Error
    {
        /** @var string */
        public $code;
        /** @var string */
        public $message;
        /** @var int */
        public $status;

        /** @param array<string, mixed> $data */
        public function __construct(string $code, string $message = '', array $data = [])
        {
            $this->code    = $code;
            $this->message = $message;
            $this->status  = (int) ($data['status'] ?? 0);
        }
    }

    class WP_REST_Response
    {
        /** @var mixed */
        public $data;

        /** @param mixed $data */
        public function __construct($data = null)
        {
            $this->data = $data;
        }
    }

    function wp_normalize_path(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    function untrailingslashit(string $value): string
    {
        return rtrim($value, '/\\');
    }

    function home_url(string $path = ''): string
    {
        return $GLOBALS['wpsync_env']['home'] . $path;
    }

    function site_url(string $path = ''): string
    {
        return $GLOBALS['wpsync_env']['site'] . $path;
    }

    function is_multisite(): bool
    {
        return $GLOBALS['wpsync_env']['multisite'];
    }

    /**
     * @param mixed $time
     * @return array{baseurl: string}
     */
    function wp_upload_dir($time = null, bool $create = true): array
    {
        return ['baseurl' => $GLOBALS['wpsync_env']['uploads']];
    }

    /** @param mixed ...$args */
    function add_action(...$args): void
    {
    }
}

namespace WpSync {
    final class Store
    {
        /** @var array<string, array<string, mixed>> */
        public static $state = [];
        /** @var list<string> */
        public static $tables = ['wp_options', 'wp_posts', 'wp_usermeta', 'wp_users'];
        /** @var list<array<string, mixed>> */
        public static $pushes = [];
        /** @var bool der nächste Schreibversuch scheitert (Datenbank weg) */
        public static $failNext = false;
        /** @var int */
        public static $writes = 0;

        public static function install(): void
        {
        }

        /** @return array<string, mixed>|null */
        public static function getState(string $name): ?array
        {
            return self::$state[$name] ?? null;
        }

        /** @param array<string, mixed>|null $value */
        public static function setState(string $name, ?array $value): bool
        {
            if (self::$failNext) {
                self::$failNext = false;
                return false;
            }
            self::$writes++;
            if ($value === null) {
                unset(self::$state[$name]);
                return true;
            }
            // wie die echte Tabelle: nur, was als JSON hin und zurück kommt
            $json = json_encode($value);
            if (!is_string($json)) {
                return false;
            }
            self::$state[$name] = json_decode($json, true);
            return true;
        }

        public static function lockName(string $purpose): string
        {
            return 'wpsync_test_' . $purpose;
        }

        public static function pushDirName(): string
        {
            return 'wpsync-push-0123456789abcdef';
        }

        public static function anonKey(): string
        {
            return str_repeat('0a', 32);
        }

        /** @return list<string> */
        public static function dataTables(): array
        {
            return self::$tables;
        }

        /** @return list<array<string, mixed>> */
        public static function pushes(int $limit): array
        {
            return array_slice(self::$pushes, 0, $limit);
        }
    }

    /** Was Staging von Push braucht. */
    final class Push
    {
        /** @var array<string, mixed>|null */
        public static $pending = null;
        /** @var bool */
        public static $running = false;
        /** @var list<string> */
        public static $dropped = [];

        /** @return array<string, mixed>|null */
        public static function pending(?string $target = null): ?array
        {
            return self::$pending;
        }

        public static function running(string $target, int $now): bool
        {
            return self::$running;
        }

        public static function dropTarget(string $target): void
        {
            self::$dropped[] = $target;
        }

        /** @return list<string> */
        public static function healthUrls(): array
        {
            return ['https://example.test/', 'https://example.test/wp-login.php', 'https://example.test/shop/'];
        }

        public static function sync(): void
        {
        }
    }
}
