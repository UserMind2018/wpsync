<?php
declare(strict_types=1);

namespace WpSync\Tests;

use WpSync\ContentException;
use WpSync\ContentState;
use WpSync\ContentStore;

/**
 * ContentStore im Speicher für Unit-Tests: Tabelle → Schlüssel → Rohzustand. Eine Transaktion
 * ist eine Kopie der Daten, die bei einer Exception zurückkommt. Was SQL daraus macht, prüfen
 * ContentSqlTest (Form der Abfragen) und scripts/e2e-content-push.sh (echte Datenbank).
 */
final class ContentMemory implements ContentStore
{
    /** @var array<string, array<string, array<string, mixed>>> */
    public $data = [];
    /** @var array<string, string> Tabelle → Engine; fehlt eine, gilt InnoDB */
    public $engines = [];
    /** @var list<string> "tabelle:schlüssel" jedes gesperrt gelesenen Schlüssels */
    public $locked = [];
    /** @var list<string> "write tabelle:schlüssel", "delete …", "purge …", "begin", "commit", "rollback" */
    public $log = [];
    /** @var int|null der wievielte write() scheitert (1 = der erste) */
    public $failWrite = null;
    /** @var bool jedes read() scheitert */
    public $failRead = false;
    /** @var (callable(self): void)|null läuft einmal, direkt vor dem ersten gesperrten Lesen */
    public $beforeLock = null;
    /** @var (callable(string, string, array<string, mixed>): array<string, mixed>)|null verändert, was write() speichert – wie eine Spalte, die abschneidet */
    public $mangle = null;
    /**
     * @var int|null vor dem wievielten write() die Verbindung verloren geht (1 = dem ersten): was die
     *               Transaktion bis dahin schrieb, ist weg, dieser eine Schreibzugriff steht für sich
     */
    public $loseAtWrite = null;
    /** @var string|null die Verbindung geht im COMMIT verloren: 'landed' – er kam an, 'discarded' – er kam nicht an */
    public $loseAtCommit = null;
    /** @var int */
    private $writes = 0;
    /** @var bool */
    private $open = false;
    /** @var bool die laufende Transaktion hat ihre Verbindung verloren */
    private $lost = false;
    /** @var array<string, array<string, array<string, mixed>>> Stand zu Beginn der laufenden Transaktion */
    private $snapshot = [];

    /** @param array<string, array<string, array<string, mixed>>> $data */
    public function __construct(array $data = [])
    {
        $this->data = $data;
    }

    public function engines(array $tables): array
    {
        $out = [];
        foreach ($tables as $table) {
            $out[$table] = $this->engines[$table] ?? 'InnoDB';
        }
        return $out;
    }

    public function read(string $table, array $keys, bool $lock): array
    {
        if ($this->failRead) {
            throw new ContentException(ContentException::FAILED, 'read failed');
        }
        if ($lock && !$this->open) {
            throw new \LogicException('locked read outside a transaction');
        }
        if ($lock && $this->beforeLock !== null) {
            $hook             = $this->beforeLock;
            $this->beforeLock = null;
            $hook($this);
        }
        $out = [];
        foreach ($keys as $key) {
            $key       = (string) $key;
            $out[$key] = $this->data[$table][$key] ?? null;
            if ($lock) {
                $this->locked[] = $table . ':' . $key;
            }
        }
        return $out;
    }

    public function write(string $table, string $key, ?array $state): void
    {
        if (!$this->open) {
            throw new \LogicException('write outside a transaction');
        }
        $this->writes++;
        if ($this->failWrite !== null && $this->writes === $this->failWrite) {
            throw new ContentException(ContentException::FAILED, 'write failed');
        }
        if ($this->loseAtWrite !== null && $this->writes === $this->loseAtWrite) {
            $this->lose();
        }
        if ($state === null) {
            unset($this->data[$table][$key]);
            $this->log[] = 'delete ' . $table . ':' . $key;
            return;
        }
        $this->data[$table][$key] = $this->mangle === null ? $state : ($this->mangle)($table, $key, $state);
        $this->log[]              = 'write ' . $table . ':' . $key;
    }

    public function aliases(string $table, array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            foreach (array_keys($this->data[$table] ?? []) as $have) {
                if ((string) $have !== $key && strcasecmp((string) $have, $key) === 0) {
                    $out[] = $key;
                    break;
                }
            }
        }
        return $out;
    }

    public function taxonomies(array $termIds): array
    {
        $out = [];
        foreach ($this->data['term_taxonomy'] ?? [] as $row) {
            if (in_array((string) $row['term_id'], array_map('strval', $termIds), true)) {
                $out[(string) $row['term_id']][] = (string) $row['taxonomy'];
            }
        }
        return $out;
    }

    public function purge(string $table, string $key): void
    {
        $this->log[] = 'purge ' . $table . ':' . $key;
        $hanging     = ['posts' => ['postmeta', 'term_relationships'], 'terms' => ['termmeta'], 'term_taxonomy' => []][$table] ?? [];
        foreach ($hanging as $set) {
            foreach (array_keys($this->data[$set] ?? []) as $pair) {
                if (ContentState::split((string) $pair)[0] === $key) {
                    unset($this->data[$set][$pair]);
                }
            }
        }
        if ($table !== 'term_taxonomy') {
            return;
        }
        foreach ($this->data['term_relationships'] ?? [] as $pair => $state) {
            $left = array_values(array_filter($state['values'], static function ($entry) use ($key): bool {
                return explode(':', (string) $entry)[0] !== $key;
            }));
            if ($left === []) {
                unset($this->data['term_relationships'][$pair]);
            } else {
                $this->data['term_relationships'][$pair]['values'] = $left;
            }
        }
    }

    public function recount(array $termTaxonomyIds): void
    {
        foreach ($termTaxonomyIds as $id) {
            $id = (string) $id;
            if (!isset($this->data['term_taxonomy'][$id])) {
                continue;
            }
            $count = 0;
            foreach ($this->data['term_relationships'] ?? [] as $state) {
                foreach ($state['values'] as $entry) {
                    $count += explode(':', (string) $entry)[0] === $id ? 1 : 0;
                }
            }
            $this->data['term_taxonomy'][$id]['count'] = (string) $count;
        }
        $this->log[] = 'recount ' . implode(',', array_map('strval', $termTaxonomyIds));
    }

    public function dropMeta(string $metaKey): void
    {
        foreach (array_keys($this->data['postmeta'] ?? []) as $pair) {
            if (ContentState::split((string) $pair)[1] === $metaKey) {
                unset($this->data['postmeta'][$pair]);
            }
        }
        $this->log[] = 'dropMeta ' . $metaKey;
    }

    public function transaction(callable $do)
    {
        $this->snapshot = $this->data;
        $this->open     = true;
        $this->lost     = false;
        $this->log[]    = 'begin';
        try {
            $result = $do();
            if ($this->lost) {
                throw new ContentException(ContentException::FAILED, 'connection lost');
            }
        } catch (\Throwable $e) {
            if (!$this->lost) {
                $this->data = $this->snapshot; // nach einem Verlust hat der Server schon verworfen; was danach kam, steht
            }
            $this->open  = false;
            $this->log[] = 'rollback';
            throw $e;
        }
        $this->open = false;
        if ($this->loseAtCommit !== null) {
            if ($this->loseAtCommit === 'discarded') {
                $this->data = $this->snapshot;
            }
            $this->loseAtCommit = null;
            $this->log[]        = 'commit?';
            throw new ContentException(ContentException::UNCLEAR, 'connection lost at commit');
        }
        $this->log[] = 'commit';
        return $result;
    }

    public function alive(): bool
    {
        return $this->open && !$this->lost;
    }

    /** Die Verbindung geht jetzt verloren: der Server verwirft, was die Transaktion geschrieben hat. */
    public function lose(): void
    {
        if ($this->open && !$this->lost) {
            $this->data  = $this->snapshot;
            $this->lost  = true;
            $this->log[] = 'lost';
        }
    }
}
