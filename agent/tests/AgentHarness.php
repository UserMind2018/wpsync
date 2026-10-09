<?php
/**
 * Umgebung für AgentBootstrapTest, RestRoutesTest, StoreDataTablesTest und AdminStagingTest:
 * WordPress-Funktionen als Attrappen, alle Klassen des Agents laufen echt. Darf nur in einem
 * eigenen Prozess geladen werden (RunTestsInSeparateProcesses) – nie zusammen mit
 * StagingHarness.php oder PushHarness.php.
 */
namespace {
    if (!defined('ARRAY_A')) {
        define('ARRAY_A', 'ARRAY_A');
    }
    if (!defined('ARRAY_N')) {
        define('ARRAY_N', 'ARRAY_N');
    }
    if (!defined('HOUR_IN_SECONDS')) {
        define('HOUR_IN_SECONDS', 3600);
    }

    /** @var array{routes: array<string, array<string, mixed>>, actions: list<string>, filters: list<string>, activation: int, deactivation: list<callable>} */
    $GLOBALS['wpsync_calls'] = ['routes' => [], 'actions' => [], 'filters' => [], 'activation' => 0, 'deactivation' => []];
    /** @var array<string, mixed> */
    $GLOBALS['wpsync_options'] = [];

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

    class WP_REST_Request
    {
        /** @var string */
        private $route;
        /** @var array<string, string> */
        private $headers;
        /** @var string */
        private $body;

        /** @param array<string, string> $headers */
        public function __construct(string $route, array $headers = [], string $body = '')
        {
            $this->route   = $route;
            $this->headers = $headers;
            $this->body    = $body;
        }

        public function get_header(string $name): ?string
        {
            return $this->headers[$name] ?? null;
        }

        public function get_method(): string
        {
            return 'POST';
        }

        public function get_route(): string
        {
            return $this->route;
        }

        public function get_body(): string
        {
            return $this->body;
        }

        /** @return mixed */
        public function get_json_params()
        {
            return json_decode($this->body, true);
        }
    }

    /** @param array<string, mixed> $args */
    function register_rest_route(string $namespace, string $route, array $args): void
    {
        $GLOBALS['wpsync_calls']['routes'][$namespace . $route] = $args;
    }

    /** @param mixed ...$args */
    function add_action(string $hook, ...$args): void
    {
        $GLOBALS['wpsync_calls']['actions'][] = $hook;
    }

    /** @param mixed ...$args */
    function add_filter(string $hook, ...$args): void
    {
        $GLOBALS['wpsync_calls']['filters'][] = $hook;
    }

    /** @param mixed $callback */
    function register_activation_hook(string $file, $callback): void
    {
        $GLOBALS['wpsync_calls']['activation']++;
    }

    function register_deactivation_hook(string $file, callable $callback): void
    {
        $GLOBALS['wpsync_calls']['deactivation'][] = $callback;
    }

    function is_ssl(): bool
    {
        return true;
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    function get_option(string $name, $default = false)
    {
        return $GLOBALS['wpsync_options'][$name] ?? $default;
    }

    /**
     * @param mixed $value
     * @param mixed $autoload
     */
    function update_option(string $name, $value, $autoload = null): bool
    {
        $GLOBALS['wpsync_options'][$name] = $value;
        return true;
    }

    function delete_option(string $name): bool
    {
        unset($GLOBALS['wpsync_options'][$name]);
        return true;
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
        return 'https://example.test' . $path;
    }

    /** @param mixed $text */
    function esc_html($text): string
    {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }

    /** @param mixed $text */
    function esc_attr($text): string
    {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }

    function wp_date(string $format, int $timestamp): string
    {
        return gmdate($format, $timestamp);
    }
}
