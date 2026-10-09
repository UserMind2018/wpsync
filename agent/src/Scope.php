<?php
namespace WpSync;

defined('ABSPATH') || exit;

/**
 * Umfang eines Pulls laut Profil des CLI (Spec 5.2): Tabellenmodi, abgewählte Post-Typen,
 * Plugins, Themes und Upload-Jahre. Ungültige Angaben lehnt der Agent ab, statt sie still zu
 * ignorieren – sonst verliessen Daten den Server, die das Profil ausschliesst.
 */
final class Scope
{
    public const FULL      = 'full';
    public const STRUCTURE = 'structure';
    public const SKIP      = 'skip';

    /** @var array<string, string> */
    private $tables = [];
    /** @var list<string> */
    private $postTypes = [];
    /** @var list<string> */
    private $plugins = [];
    /** @var list<string> */
    private $themes = [];
    /** @var string */
    private $uploadsSince = '';
    /** @var bool */
    private $plainPii = false;

    /**
     * @param mixed $raw JSON-Objekt aus dem Request; null oder leer = alles
     * @throws \InvalidArgumentException
     */
    public static function fromArray($raw): self
    {
        $scope = new self();
        if ($raw === null || $raw === []) {
            return $scope;
        }
        if (!is_array($raw)) {
            throw new \InvalidArgumentException('scope must be an object');
        }
        $tables = $raw['tables'] ?? [];
        if (!is_array($tables)) {
            throw new \InvalidArgumentException('tables must be an object');
        }
        foreach ($tables as $table => $mode) {
            if (!is_string($table) || preg_match('/^[A-Za-z0-9_$]{1,64}\z/', $table) !== 1 || !in_array($mode, [self::STRUCTURE, self::SKIP], true)) {
                throw new \InvalidArgumentException('invalid table mode for ' . (string) $table);
            }
            $scope->tables[$table] = $mode;
        }
        $scope->postTypes = self::names($raw['exclude_post_types'] ?? [], '/^[a-z0-9_-]{1,20}\z/', 'post type');
        $scope->plugins   = self::names($raw['exclude_plugins'] ?? [], '/^[A-Za-z0-9._-]{1,100}\z/', 'plugin');
        $scope->themes    = self::names($raw['exclude_themes'] ?? [], '/^[A-Za-z0-9._-]{1,100}\z/', 'theme');
        $since            = (string) ($raw['uploads_since'] ?? '');
        if ($since !== '' && preg_match('/^\d{4}\z/', $since) !== 1) {
            throw new \InvalidArgumentException('invalid uploads_since');
        }
        $scope->uploadsSince = $since;
        $plain = $raw['plain_pii'] ?? false;
        if (!is_bool($plain)) {
            throw new \InvalidArgumentException('plain_pii must be a boolean');
        }
        $scope->plainPii = $plain;
        return $scope;
    }

    public function tableMode(string $table): string
    {
        return $this->tables[$table] ?? self::FULL;
    }

    /** Personenbezogene Werte werden pseudonymisiert, solange nicht ausdrücklich Klartext verlangt ist (Spec 11.3). */
    public function anonymize(): bool
    {
        return !$this->plainPii;
    }

    /** @return list<string> abgewählte Beitragstypen – für das Inhalts-Manifest (Spec Content-Push §4.2) */
    public function excludedPostTypes(): array
    {
        return $this->postTypes;
    }

    /** Pfad relativ zu wp-content ohne führenden Slash; entschieden wird nur auf der obersten Ebene. */
    public function excludesPath(string $rel, bool $isDir): bool
    {
        $parts = explode('/', $rel);
        if (count($parts) !== 2) {
            return false;
        }
        list($top, $name) = $parts;
        if ($top === 'plugins') {
            if ($isDir) {
                return in_array($name, $this->plugins, true);
            }
            return substr($name, -4) === '.php' && in_array(substr($name, 0, -4), $this->plugins, true);
        }
        if ($top === 'themes') {
            return $isDir && in_array($name, $this->themes, true);
        }
        if ($top === 'uploads') {
            return $isDir && $this->uploadsSince !== '' && preg_match('/^\d{4}$/', $name) === 1 && strcmp($name, $this->uploadsSince) < 0;
        }
        return false;
    }

    /**
     * Zeilenfilter für abgewählte Post-Typen (Spec 5.2, D5): posts direkt; postmeta nur mit
     * Eltern-Post eines gewählten Typs (entfernt auch Waisen, AC-14); term_relationships und
     * comments behalten Zeilen ohne Post (Links, Bestellnotizen).
     *
     * @param array{posts: string, postmeta: string, term_relationships: string, comments: string} $core
     * @param callable(string): string                                                             $escape
     * @return array{join: string, where: string}
     */
    public function rowFilter(string $table, array $core, callable $escape): array
    {
        if ($this->postTypes === []) {
            return ['join' => '', 'where' => ''];
        }
        $quoted = [];
        foreach ($this->postTypes as $type) {
            $quoted[] = "'" . $escape($type) . "'";
        }
        $list  = implode(',', $quoted);
        $posts = '`' . $core['posts'] . '`';
        if ($table === $core['posts']) {
            return ['join' => '', 'where' => '`post_type` NOT IN (' . $list . ')'];
        }
        if ($table === $core['postmeta']) {
            return ['join' => 'JOIN ' . $posts . ' p ON p.`ID` = t.`post_id`', 'where' => 'p.`post_type` NOT IN (' . $list . ')'];
        }
        if ($table === $core['term_relationships']) {
            return ['join' => 'LEFT JOIN ' . $posts . ' p ON p.`ID` = t.`object_id`', 'where' => '(p.`ID` IS NULL OR p.`post_type` NOT IN (' . $list . '))'];
        }
        if ($table === $core['comments']) {
            return ['join' => 'LEFT JOIN ' . $posts . ' p ON p.`ID` = t.`comment_post_ID`', 'where' => '(p.`ID` IS NULL OR p.`post_type` NOT IN (' . $list . '))'];
        }
        return ['join' => '', 'where' => ''];
    }

    /**
     * @param mixed $list
     * @return list<string>
     */
    private static function names($list, string $pattern, string $what): array
    {
        if (!is_array($list)) {
            throw new \InvalidArgumentException($what . ' list must be an array');
        }
        $out = [];
        foreach ($list as $name) {
            if (!is_string($name) || preg_match($pattern, $name) !== 1) {
                throw new \InvalidArgumentException('invalid ' . $what);
            }
            $out[] = $name;
        }
        return $out;
    }
}
