<?php
/**
 * Umgebung für PushTargetTest: WordPress-Funktionen, Store und Staging als Attrappen. Die Datei
 * definiert WpSync\Store und WpSync\Staging selbst und darf deshalb nur in einem eigenen Prozess
 * geladen werden (RunTestsInSeparateProcesses) – nie von einem anderen Test.
 */
namespace {
    const WPSYNC_VERSION = 'test';

    class WP_Error
    {
        /** @var string */
        public $code;
        /** @var string */
        public $message;
        /** @var int */
        public $status;
        /** @var array<string, mixed> die Fehlerdaten, wie WordPress sie als data ausliefert */
        public $data;

        /** @param array<string, mixed> $data */
        public function __construct(string $code, string $message = '', array $data = [])
        {
            $this->code    = $code;
            $this->message = $message;
            $this->status  = (int) ($data['status'] ?? 0);
            $this->data    = $data;
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

    final class WpsyncHarnessDb
    {
        /** @param mixed ...$args */
        public function prepare(string $query, ...$args): string
        {
            return $query;
        }

        public function get_var(string $query): int
        {
            return 1;
        }

        public function query(string $query): int
        {
            return 1;
        }
    }

    function wp_normalize_path(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    function wp_mkdir_p(string $dir): bool
    {
        return is_dir($dir) || mkdir($dir, 0777, true);
    }

    /** @param mixed $value */
    function wp_json_encode($value): string
    {
        return (string) json_encode($value);
    }

    function home_url(string $path = ''): string
    {
        return 'https://example.test' . $path;
    }

    function wp_login_url(): string
    {
        return 'https://example.test/wp-login.php';
    }

    function plugins_url(string $file, string $plugin): string
    {
        return 'https://example.test/wp-content/plugins/wpsync-agent/' . $file;
    }

    function content_url(): string
    {
        return $GLOBALS['wpsync_test_content_url'] ?? 'https://example.test/wp-content';
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    function get_option(string $name, $default = false)
    {
        return $name === 'active_plugins' ? ($GLOBALS['wpsync_test_active_plugins'] ?? []) : $default;
    }

    function is_multisite(): bool
    {
        return false;
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    function get_site_option(string $name, $default = false)
    {
        return $default;
    }

    /** @param array<int, mixed> $args */
    function wp_schedule_single_event(int $timestamp, string $hook, array $args = []): bool
    {
        $GLOBALS['wpsync_test_single_events'][] = [$timestamp, $hook];
        return true;
    }

    /**
     * Wie WordPress für einen Request ohne unfiltered_html; $GLOBALS['wpsync_test_extra_mimes'] spielt
     * ein Theme, das per upload_mimes weitere Typen erlaubt.
     *
     * @return array<string, string>
     */
    function get_allowed_mime_types(): array
    {
        return ['jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'pdf' => 'application/pdf', 'txt' => 'text/plain']
            + ($GLOBALS['wpsync_test_extra_mimes'] ?? []);
    }

    /**
     * Nur die Regel von WordPress für mittlere Endungen: ein Teil zwischen erstem und letztem Punkt,
     * der wie eine Endung aussieht und kein erlaubter Typ ist, bekommt ein „_“ (bild.cgi.png → bild.cgi_.png).
     */
    function sanitize_file_name(string $filename): string
    {
        $parts = explode('.', $filename);
        if (count($parts) <= 2) {
            return $filename;
        }
        $out  = (string) array_shift($parts);
        $last = (string) array_pop($parts);
        foreach ($parts as $part) {
            $out .= '.' . $part;
            if (preg_match('/^[a-zA-Z]{2,5}\d?$/', $part) !== 1) {
                continue;
            }
            $allowed = false;
            foreach (array_keys(get_allowed_mime_types()) as $exts) {
                if (preg_match('!^(' . $exts . ')$!i', $part) === 1) {
                    $allowed = true;
                    break;
                }
            }
            if (!$allowed) {
                $out .= '_';
            }
        }
        return $out . '.' . $last;
    }

    /**
     * @param array<string, string>|null $mimes
     * @return array{ext: string|false, type: string|false}
     */
    function wp_check_filetype(string $filename, ?array $mimes = null): array
    {
        foreach ($mimes ?? get_allowed_mime_types() as $exts => $type) {
            if (preg_match('!\.(' . $exts . ')$!i', $filename, $m) === 1) {
                return ['ext' => $m[1], 'type' => $type];
            }
        }
        return ['ext' => false, 'type' => false];
    }

    /**
     * Wie WordPress im Kern: ein Bild muss sich als Bild lesen lassen.
     *
     * @param array<string, string>|null $mimes
     * @return array{ext: string|false, type: string|false, proper_filename: string|false}
     */
    function wp_check_filetype_and_ext(string $file, string $filename, ?array $mimes = null): array
    {
        $check = wp_check_filetype($filename, $mimes);
        if (is_string($check['type']) && strpos($check['type'], 'image/') === 0 && @getimagesize($file) === false) {
            return ['ext' => false, 'type' => false, 'proper_filename' => false];
        }
        return $check + ['proper_filename' => false];
    }

    /**
     * @param mixed $time
     * @return array{basedir: string}
     */
    function wp_upload_dir($time = null, bool $create = true): array
    {
        return ['basedir' => $GLOBALS['wpsync_test_upload_basedir'] ?? WP_CONTENT_DIR . '/uploads'];
    }

    /** @param mixed ...$args */
    function add_action(...$args): void
    {
    }

    /** @return string|false Adresse eines Beitrags unter $GLOBALS['wpsync_test_permalink_base'] */
    function get_permalink(int $id)
    {
        return ($GLOBALS['wpsync_test_permalink_base'] ?? 'https://example.test') . '/?p=' . $id;
    }
}

namespace WpSync {
    final class Store
    {
        /** @var array<string, array<string, mixed>> */
        public static $state = [];
        /** @var array<string, array<string, mixed>> */
        public static $pushes = [];
        /** @var int */
        public static $until = 0;
        /** @var int|null wer das Push-Fenster geöffnet hat */
        public static $opener = null;
        /** @var bool Datenbank antwortet nicht: Lesezugriffe liefern null/[], dbOk() false */
        public static $dbError = false;

        public static function install(): void
        {
        }

        /** @return array<string, mixed>|null */
        public static function getState(string $name): ?array
        {
            return self::$dbError ? null : (self::$state[$name] ?? null);
        }

        /** @param array<string, mixed>|null $value */
        public static function setState(string $name, ?array $value): void
        {
            if ($value === null) {
                unset(self::$state[$name]);
                return;
            }
            self::$state[$name] = $value;
        }

        public static function pushDirName(): string
        {
            return 'wpsync-push-0123456789abcdef';
        }

        public static function lockName(string $purpose): string
        {
            return 'wpsync_test_' . $purpose;
        }

        public static function pushUntil(string $keyId): int
        {
            return self::$until;
        }

        public static function pushOpener(string $keyId): ?int
        {
            return self::$opener;
        }

        public static function secretFor(string $keyId): ?string
        {
            return str_repeat('ab', 32);
        }

        public static function deviceFor(string $keyId): string
        {
            return 'test-device';
        }

        /** @param array<string, mixed> $row */
        public static function addPush(array $row): bool
        {
            self::$pushes[(string) $row['push_id']] = $row + ['pruned' => 0, 'committed' => null, 'finished' => null];
            return true;
        }

        /** @param array<string, mixed> $fields */
        public static function updatePush(string $pushId, array $fields): void
        {
            if (isset(self::$pushes[$pushId])) {
                self::$pushes[$pushId] = array_merge(self::$pushes[$pushId], $fields);
            }
        }

        /** @return array<string, mixed>|null */
        public static function getPush(string $pushId): ?array
        {
            return !self::$dbError && isset(self::$pushes[$pushId]) ? self::row(self::$pushes[$pushId]) : null;
        }

        /** @return list<array<string, mixed>> neueste zuerst */
        public static function pushes(int $limit): array
        {
            if (self::$dbError) {
                return [];
            }
            $rows = array_values(self::$pushes);
            usort($rows, static function (array $a, array $b): int {
                return [$b['created'], $b['push_id']] <=> [$a['created'], $a['push_id']];
            });
            return array_map([self::class, 'row'], array_slice($rows, 0, $limit));
        }

        public static function dbOk(): bool
        {
            return !self::$dbError;
        }

        /**
         * @param array<string, mixed> $row
         * @return array<string, mixed>
         */
        private static function row(array $row): array
        {
            $units = json_decode((string) $row['units'], true);
            return [
                'push_id'   => (string) $row['push_id'],
                'key_id'    => (string) $row['key_id'],
                'device'    => (string) $row['device'],
                'target'    => (string) $row['target'],
                'status'    => (string) $row['status'],
                'forced'    => (bool) $row['forced'],
                'pruned'    => (bool) $row['pruned'],
                'units'     => is_array($units) ? $units : [],
                'created'   => (int) $row['created'],
                'committed' => $row['committed'] === null ? null : (int) $row['committed'],
                'finished'  => $row['finished'] === null ? null : (int) $row['finished'],
                'opened_by' => isset($row['opened_by']) ? (int) $row['opened_by'] : null,
            ];
        }
    }

    /** Was Push von Staging braucht – Pfade prüft der echte StagingGuard. */
    final class Staging
    {
        public const DIR = 'wpsync-staging-0123456789ab';

        /** @var string Webroot */
        public static $root = '';
        /** @var string|null liefert contentDir() statt des echten Ordners */
        public static $override = null;
        /** @var \WP_Error|null liefert pushContent() (Job läuft, Kopie gesperrt …) */
        public static $error = null;
        /** @var int */
        public static $used = 0;

        public static function contentDir(): string
        {
            if (self::$override !== null) {
                return self::$override;
            }
            $dir = self::$root . '/' . self::DIR . '/wp-content';
            return is_dir($dir) ? $dir : '';
        }

        /** @return string|\WP_Error */
        public static function pushContent()
        {
            if (self::$error !== null) {
                return self::$error;
            }
            $dir = self::contentDir();
            return $dir !== '' ? $dir : new \WP_Error('wpsync_staging_missing', '', ['status' => 409]);
        }

        public static function inside(string $path): bool
        {
            try {
                (new StagingGuard('wp_', 'stgabcdef_', self::$root, self::DIR))->path($path);
                return true;
            } catch (StagingException $e) {
                return false;
            }
        }

        /** @return list<string> */
        public static function healthUrls(): array
        {
            return ['https://example.test/' . self::DIR . '/'];
        }

        public static function markUsed(): void
        {
            self::$used++;
        }
    }
}
