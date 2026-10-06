<?php
namespace WpSync;

defined('ABSPATH') || exit;

/**
 * Die eine Prüf-Funktion für Staging (Spec Stufe 2b 5.1, Leitplanke 2): Schreiben und Löschen
 * nehmen nur Tabellen mit dem Staging-Präfix und Pfade im Staging-Ordner an. SQL für Staging
 * entsteht nur über die Methoden hier, mit geprüften Namen. Lesend dürfen Live-Tabellen
 * (source) vorkommen, schreibend nie.
 */
final class StagingGuard
{
    public const DIR_PREFIX = 'wpsync-staging-';
    public const DIR_RE     = '/^wpsync-staging-[a-f0-9]{12}\z/';
    public const PREFIX_RE  = '/^stg[a-f0-9]{6}_\z/';
    private const NAME_RE   = '/^[A-Za-z0-9_$]{1,64}\z/';

    /** @var string */
    private $live;
    /** @var string */
    private $staging;
    /** @var string Webroot, aufgelöst und ohne Slash am Ende */
    private $base;
    /** @var string */
    private $root;

    /** @throws StagingException */
    public function __construct(string $livePrefix, string $stagingPrefix, string $absPath, string $dirName)
    {
        $base = realpath($absPath);
        if ($base === false) {
            throw StagingException::guard('webroot not resolvable');
        }
        if (preg_match(self::PREFIX_RE, $stagingPrefix) !== 1 || self::overlaps($livePrefix, $stagingPrefix)) {
            throw StagingException::guard('invalid staging prefix ' . self::printable($stagingPrefix));
        }
        if (preg_match(self::DIR_RE, $dirName) !== 1) {
            throw StagingException::guard('invalid staging directory ' . self::printable($dirName));
        }
        $this->live    = $livePrefix;
        $this->staging = $stagingPrefix;
        $this->base    = rtrim(str_replace('\\', '/', $base), '/');
        $this->root    = $this->base . '/' . $dirName;
    }

    public static function newDirName(): string
    {
        return self::DIR_PREFIX . bin2hex(random_bytes(6));
    }

    /**
     * @param list<string>              $existing alle Tabellennamen der Datenbank
     * @param (callable(): string)|null $random   liefert 6 Hex-Zeichen; für Tests
     * @throws StagingException
     */
    public static function newPrefix(string $live, array $existing, ?callable $random = null): string
    {
        $random = $random ?? static function (): string {
            return bin2hex(random_bytes(3));
        };
        for ($try = 0; $try < 20; $try++) {
            $candidate = 'stg' . $random() . '_';
            if (preg_match(self::PREFIX_RE, $candidate) !== 1 || self::overlaps($live, $candidate)) {
                continue;
            }
            foreach ($existing as $name) {
                if (strpos($name, $candidate) === 0) {
                    continue 2;
                }
            }
            return $candidate;
        }
        throw StagingException::unsupported('Neben dem Tabellen-Präfix „' . self::printable($live) . '“ gibt es kein freies Staging-Präfix stg…_ – Staging ist hier nicht möglich.');
    }

    /** Leitplanke 3: keins beginnt mit dem anderen. Ein leeres Live-Präfix überschneidet sich mit allem. */
    public static function overlaps(string $live, string $staging): bool
    {
        return $live === '' || strpos($staging, $live) === 0 || strpos($live, $staging) === 0;
    }

    public function livePrefix(): string
    {
        return $this->live;
    }

    public function stagingPrefix(): string
    {
        return $this->staging;
    }

    public function root(): string
    {
        return $this->root;
    }

    /** @throws StagingException */
    public function stagingName(string $live): string
    {
        return $this->table($this->staging . substr($this->source($live), strlen($this->live)));
    }

    /**
     * Eine Tabelle, in die geschrieben oder die gelöscht werden darf.
     *
     * @throws StagingException
     */
    public function table(string $name): string
    {
        if (preg_match(self::NAME_RE, $name) !== 1 || strpos($name, $this->staging) !== 0 || strpos($name, $this->live) === 0) {
            throw StagingException::guard('table outside staging: ' . self::printable($name));
        }
        return $name;
    }

    /**
     * Eine Live-Tabelle, aus der nur gelesen wird.
     *
     * @throws StagingException
     */
    public function source(string $name): string
    {
        if (preg_match(self::NAME_RE, $name) !== 1 || strpos($name, $this->live) !== 0 || strpos($name, $this->staging) === 0) {
            throw StagingException::guard('not a live table: ' . self::printable($name));
        }
        return $name;
    }

    /**
     * Ein Pfad im Staging-Ordner oder der Ordner selbst – ohne „.“/„..“ und ohne Symlink auf dem
     * Weg dorthin.
     *
     * @throws StagingException
     */
    public function path(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        if ($path !== $this->root && strpos($path, $this->root . '/') !== 0) {
            throw StagingException::guard('path outside staging: ' . self::printable($path));
        }
        if (preg_match('#/\.\.?(/|\z)|[\x00-\x1f]#', $path) === 1) {
            throw StagingException::guard('unsafe path: ' . self::printable($path));
        }
        if (is_link($path)) {
            throw StagingException::guard('symlink: ' . self::printable($path));
        }
        // Der nächste existierende Vorfahr muss aufgelöst genau so heissen – sonst führt ein Symlink hinaus.
        $dir = dirname($path);
        while (strlen($dir) > strlen($this->base) && !file_exists($dir)) {
            $dir = dirname($dir);
        }
        $real = realpath($dir);
        if ($real === false || rtrim(str_replace('\\', '/', $real), '/') !== $dir) {
            throw StagingException::guard('symlink on the way to ' . self::printable($path));
        }
        return $path;
    }

    /** @throws StagingException */
    public function createLike(string $staging, string $live): string
    {
        return 'CREATE TABLE `' . $this->table($staging) . '` LIKE `' . $this->source($live) . '`';
    }

    /**
     * INSERT … SELECT aus einer Live-Tabelle (Alias t). $join, $where und $order baut nur der Agent.
     *
     * @throws StagingException
     */
    public function insertSelect(string $staging, string $live, string $join, string $where, string $order, int $limit, int $offset = 0): string
    {
        $sql = 'INSERT INTO `' . $this->table($staging) . '` SELECT t.* FROM `' . $this->source($live) . '` t';
        if ($join !== '') {
            $sql .= ' ' . $join;
        }
        if ($where !== '') {
            $sql .= ' WHERE ' . $where;
        }
        if ($order !== '') {
            $sql .= ' ORDER BY ' . $order;
        }
        if ($limit > 0) {
            $sql .= ' LIMIT ' . max(0, $offset) . ', ' . $limit;
        }
        return $sql;
    }

    /** @throws StagingException */
    public function update(string $staging, string $set, string $where): string
    {
        return 'UPDATE `' . $this->table($staging) . '` SET ' . $set . ($where === '' ? '' : ' WHERE ' . $where);
    }

    /** @throws StagingException */
    public function select(string $staging, string $columns, string $rest = ''): string
    {
        return 'SELECT ' . $columns . ' FROM `' . $this->table($staging) . '`' . ($rest === '' ? '' : ' ' . $rest);
    }

    public function showTables(): string
    {
        return "SHOW TABLES LIKE '" . str_replace('_', '\\_', $this->staging) . "%'";
    }

    /**
     * DROP nur für Namen, die SHOW TABLES für das Staging-Präfix geliefert hat (Spec 5.1).
     *
     * @param list<string> $shown
     * @throws StagingException
     */
    public function drop(string $staging, array $shown): string
    {
        if (!in_array($staging, $shown, true)) {
            throw StagingException::guard('drop of an unlisted table: ' . self::printable($staging));
        }
        return 'DROP TABLE IF EXISTS `' . $this->table($staging) . '`';
    }

    private static function printable(string $value): string
    {
        return substr((string) preg_replace('/[^\x20-\x7e]/', '?', $value), 0, 200);
    }
}
