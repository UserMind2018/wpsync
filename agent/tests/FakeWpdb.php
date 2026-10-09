<?php
declare(strict_types=1);

namespace WpSync\Tests;

/**
 * Ersatz für $wpdb in Unit-Tests: merkt sich jede Abfrage und antwortet nach Muster. Führt kein
 * SQL aus – was die Datenbank daraus macht, prüft scripts/e2e-staging.sh.
 */
final class FakeWpdb
{
    /** @var string */
    public $last_error = '';
    /** @var string */
    public $base_prefix = 'wp_';
    /** @var string */
    public $prefix = 'wp_';
    /** @var string */
    public $charset = 'utf8mb4';
    /** @var string */
    public $posts = 'wp_posts';
    /** @var string */
    public $postmeta = 'wp_postmeta';
    /** @var string */
    public $term_relationships = 'wp_term_relationships';
    /** @var string */
    public $comments = 'wp_comments';
    /** @var string */
    public $options = 'wp_options';
    /** @var list<string> */
    public $queries = [];
    /** @var (callable(string): void)|null sieht jede Abfrage, bevor sie beantwortet wird */
    public $observer = null;
    /** @var list<mixed> jeder Wert, der durch prepare() ging */
    public $prepared = [];
    /** @var bool wie $wpdb->show_errors: ein Fehler wird samt Abfrage in die Antwort ausgegeben */
    public $show_errors = false;
    /** @var list<string> Abfragen, deren Fehler $wpdb ausgegeben hätte */
    public $shown = [];
    /** @var list<array{pattern: string, results: list<mixed>, error: string|null}> */
    private $answers = [];

    public function show_errors(bool $show = true): bool
    {
        $before            = $this->show_errors;
        $this->show_errors = $show;
        return $before;
    }

    public function hide_errors(): bool
    {
        return $this->show_errors(false);
    }

    /** Antworten in dieser Reihenfolge; die letzte gilt für alle weiteren Treffer. Eine Closure wird mit dem SQL gefragt. */
    public function answer(string $pattern, ...$results): void
    {
        $this->answers[] = ['pattern' => $pattern, 'results' => $results, 'error' => null];
    }

    public function fail(string $pattern, string $error): void
    {
        $this->answers[] = ['pattern' => $pattern, 'results' => [], 'error' => $error];
    }

    /** @return list<string> alles ausser SELECT und SHOW */
    public function writes(): array
    {
        return array_values(array_filter($this->queries, static function (string $sql): bool {
            return preg_match('/^\s*(SELECT|SHOW)\b/i', $sql) !== 1;
        }));
    }

    public function prepare(string $query, ...$args): string
    {
        return (string) preg_replace_callback('/%[sd]/', function (array $m) use (&$args): string {
            $value            = array_shift($args);
            $this->prepared[] = $value;
            return $m[0] === '%d' ? (string) (int) $value : "'" . addslashes((string) $value) . "'";
        }, $query);
    }

    public function esc_like(string $text): string
    {
        return addcslashes($text, '_%\\');
    }

    public function get_charset_collate(): string
    {
        return '';
    }

    /** @param mixed $value */
    public function _real_escape($value): string
    {
        return addslashes((string) $value);
    }

    /** @return int|bool */
    public function query(string $sql)
    {
        list($ok, $result) = $this->run($sql);
        if (!$ok) {
            return false;
        }
        if (is_int($result)) {
            return $result;
        }
        if (preg_match('/^\s*UPDATE\b/i', $sql) === 1) {
            return 1;
        }
        return preg_match('/^\s*INSERT\b/i', $sql) === 1 ? 0 : true;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $where
     * @return int|bool
     */
    public function update(string $table, array $data, array $where)
    {
        return $this->query('UPDATE `' . $table . '` SET ' . json_encode($data) . ' WHERE ' . json_encode($where));
    }

    /**
     * @param mixed $output
     * @return list<array<string, string|null>>|null
     */
    public function get_results(string $sql, $output = null)
    {
        list($ok, $result) = $this->run($sql);
        return $ok ? (is_array($result) ? $result : []) : null;
    }

    /** @return string|null */
    public function get_var(string $sql)
    {
        list($ok, $result) = $this->run($sql);
        return $ok && is_scalar($result) ? (string) $result : null;
    }

    /** @return list<string> */
    public function get_col(string $sql, int $x = 0): array
    {
        list($ok, $result) = $this->run($sql);
        return $ok && is_array($result) ? $result : [];
    }

    /** @return array{0: bool, 1: mixed} */
    private function run(string $sql): array
    {
        $this->last_error = '';
        $this->queries[]  = $sql;
        if ($this->observer !== null) {
            ($this->observer)($sql);
        }
        foreach ($this->answers as $i => $answer) {
            if (preg_match($answer['pattern'], $sql) !== 1) {
                continue;
            }
            if ($answer['error'] !== null) {
                $this->last_error = $answer['error'];
                if ($this->show_errors) {
                    $this->shown[] = $sql;
                }
                return [false, null];
            }
            $results = $answer['results'];
            if (count($results) > 1) {
                $this->answers[$i]['results'] = array_slice($results, 1);
            }
            $result = $results[0] ?? null;
            return [true, $result instanceof \Closure ? $result($sql) : $result];
        }
        return [true, null];
    }
}
