<?php
declare(strict_types=1);

namespace WpSync\Tests;

use WpSync\RescueLink;

/**
 * Ersatz für eine mysqli-Verbindung in Unit-Tests: merkt sich jede Abfrage und antwortet nach
 * Muster – wie FakeWpdb, mit denselben Antworten (eine Liste von Zeilen, ein einzelner Wert für
 * get_var). Führt kein SQL aus; was die Datenbank daraus macht, prüft scripts/e2e-rescue-db.sh.
 */
final class FakeRescueLink implements RescueLink
{
    /** @var list<string> */
    public $queries = [];
    /** @var list<string> charset:…, select:…, close – in der Reihenfolge der Aufrufe, Abfragen als query:… */
    public $calls = [];
    /** @var bool */
    public $closed = false;
    /** @var bool mysqli_set_charset scheitert */
    public $failCharset = false;
    /** @var bool mysqli_select_db scheitert */
    public $failSelect = false;
    /** @var int */
    private $errno = 0;
    /** @var list<array{pattern: string, results: list<mixed>, errno: int}> */
    private $answers = [];

    /** Antworten in dieser Reihenfolge; die letzte gilt für alle weiteren Treffer. Eine Closure wird mit dem SQL gefragt. */
    public function answer(string $pattern, ...$results): void
    {
        $this->answers[] = ['pattern' => $pattern, 'results' => $results, 'errno' => 0];
    }

    /** Jede Abfrage auf dieses Muster scheitert mit der Fehlernummer – $times Mal, 0 = immer. */
    public function fail(string $pattern, int $errno, int $times = 0): void
    {
        $this->answers[] = ['pattern' => $pattern, 'results' => [$times], 'errno' => $errno];
    }

    /** @return list<string> alles ausser SELECT und SHOW */
    public function writes(): array
    {
        return array_values(array_filter($this->queries, static function (string $sql): bool {
            return preg_match('/^\s*(SELECT|SHOW)\b/i', $sql) !== 1;
        }));
    }

    public function query(string $sql)
    {
        $this->errno     = 0;
        $this->queries[] = $sql;
        $this->calls[]   = 'query:' . $sql;
        foreach ($this->answers as $i => $answer) {
            if (preg_match($answer['pattern'], $sql) !== 1) {
                continue;
            }
            if ($answer['errno'] !== 0) {
                $left = (int) $answer['results'][0];
                if ($left === 1) {
                    unset($this->answers[$i]);
                    $this->answers = array_values($this->answers);
                } elseif ($left > 1) {
                    $this->answers[$i]['results'][0] = $left - 1;
                }
                $this->errno = $answer['errno'];
                return false;
            }
            $results = $answer['results'];
            if (count($results) > 1) {
                $this->answers[$i]['results'] = array_slice($results, 1);
            }
            $result = $results[0] ?? null;
            if ($result instanceof \Closure) {
                $result = $result($sql);
            }
            if (is_array($result)) {
                return array_values($result);
            }
            if (is_scalar($result)) {
                return [['v' => (string) $result]];
            }
            break;
        }
        return preg_match('/^\s*(SELECT|SHOW)\b/i', $sql) === 1 ? [] : true;
    }

    public function escape(string $value): string
    {
        return addslashes($value);
    }

    public function errno(): int
    {
        return $this->errno;
    }

    public function charset(string $charset): bool
    {
        $this->calls[] = 'charset:' . $charset;
        return !$this->failCharset;
    }

    public function select(string $database): bool
    {
        $this->calls[] = 'select:' . $database;
        return !$this->failSelect;
    }

    public function close(): void
    {
        $this->calls[] = 'close';
        $this->closed  = true;
    }
}
