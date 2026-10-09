<?php
namespace WpSync;

defined('ABSPATH') || exit;

/**
 * Endpunkt /content/manifest (Spec Content-Push §4.2): JSON-Lines mit einem Kopf und je Zeile der
 * sieben Inhaltstabellen einem Fingerabdruck – eingeschränkt auf das, was der Pull-Scope ohnehin
 * überträgt, ohne wpsync_%-Optionen und Transients. Keine Werte, und für das, was der Pull
 * pseudonymisiert, auch kein Abdruck (h null, why "pseudonymized"). Seitenweise mit Zeitbudget und
 * einer Obergrenze der Datensätze; die letzte Zeile jeder Seite nennt den Cursor der nächsten.
 */
final class ContentManifest
{
    private const FLAGS = JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

    /**
     * Datensätze je Seite, zusätzlich zum Zeitbudget: ein schneller Server mit grossem Budget hielte
     * sonst hunderttausende Zeilen in einer Antwort. Der Leser beendet den laufenden Schritt noch.
     */
    public const PAGE_RECORDS = 20000;

    /**
     * Der Cursor aus dem Request: null oder genau das, was eine Seite als next genannt hat.
     *
     * @param mixed $raw
     * @return array{t: int, a: string}|null null: erste Seite
     * @throws \InvalidArgumentException
     */
    public static function cursor($raw): ?array
    {
        if ($raw === null) {
            return null;
        }
        $t = is_array($raw) ? ($raw['t'] ?? null) : null;
        $a = is_array($raw) ? ($raw['a'] ?? null) : null;
        if (!is_int($t) || $t < 0 || $t >= count(Canon::TABLES) || !is_string($a) || preg_match('/^\d{0,20}\z/', $a) !== 1) {
            throw new \InvalidArgumentException('invalid cursor');
        }
        return ['t' => $t, 'a' => $a];
    }

    /**
     * Eine Seite, wie sie der Endpunkt ausgibt. Mitten im Stream gibt es keinen Statuscode mehr:
     * lässt sich etwas nicht lesen, endet die Seite mit einer Fehlerzeile statt mit next, und die
     * CLI bricht dort ab. Was die Datenbank dazu gemeldet hat, bleibt auf dem Server.
     *
     * @param array{t: int, a: string}|null $cursor aus cursor()
     * @param callable(string): void        $write  bekommt jede Zeile ohne Zeilenende
     */
    public static function send(?array $cursor, Scope $scope, callable $write, float $deadline): void
    {
        try {
            self::page($cursor, $scope, $write, $deadline);
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            $write((string) json_encode(['error' => 'read_failed']));
        }
    }

    /**
     * @param array<string, mixed>|null $cursor null: erste Seite, mit Kopf
     * @param callable(string): void    $write  bekommt jede Zeile ohne Zeilenende
     * @throws \RuntimeException|\InvalidArgumentException wenn sich die Tabellen nicht lesen lassen oder home ungültig ist
     */
    public static function page(?array $cursor, Scope $scope, callable $write, float $deadline): void
    {
        global $wpdb;
        // Vor dem Kopf: ohne gültiges home gibt es nichts zu normalisieren.
        // Pseudonymisiert der Pull, gibt es für diese Zeilen auch keinen Abdruck des echten Werts.
        $reader = new ContentReader($wpdb, (string) $wpdb->prefix, new ContentOrigin((string) get_option('home')), $scope->excludedPostTypes(), $scope->anonymize());
        if ($cursor === null) {
            $write((string) json_encode(['head' => self::head($scope)], self::FLAGS));
        }
        $next = $reader->read($cursor, $deadline, false, static function (array $record) use ($write): void {
            $write((string) json_encode($record, self::FLAGS));
        }, self::tables($scope), self::PAGE_RECORDS);
        $write((string) json_encode(['next' => $next]));
    }

    /**
     * Engine und Zählerstand nennt der Kopf nur für die Tabellen, deren Zeilen das Manifest auch
     * liefert – was das Pull-Profil auslässt, verrät auch hier nichts.
     *
     * @return array<string, mixed>
     * @throws \RuntimeException wenn sich Engine oder Zählerstand einer Tabelle nicht lesen lassen
     */
    public static function head(Scope $scope): array
    {
        global $wpdb;
        $home    = rtrim((string) get_option('home'), '/');
        $siteurl = rtrim((string) get_option('siteurl'), '/');
        $prefix  = (string) $wpdb->prefix;
        $tables  = self::tables($scope);
        $engines = [];
        $idMax   = [];
        foreach ($tables as $name) {
            // Dieselbe Rechnung prüft später jede neue ID eines Pakets (ContentCheck::ID_HEADROOM).
            $status         = ContentSql::status($wpdb, $prefix . $name);
            $engines[$name] = (string) ($status['Engine'] ?? '');
            if (isset(ContentState::PK[$name])) {
                $idMax[$name] = ContentSql::counter($wpdb, $prefix . $name, ContentState::PK[$name], $status);
            }
        }
        $head = [
            'canon_version' => Canon::VERSION,
            'list_version'  => ContentLists::VERSION,
            'variants'      => ContentOrigin::VARIANTS,
            'origins'       => ['home' => $home, 'siteurl' => $siteurl],
            'id_max'        => $idMax,
            'engines'       => $engines,
            'tables'        => $tables,
            'pseudonym'     => Anonymizer::patterns(),
            'prefix'        => $prefix,
            'charset'       => (string) $wpdb->charset,
            'pushable'      => true,
            'lists'         => ContentLists::export(),
        ];
        $why = self::unpushable($home, $siteurl);
        if ($why !== null) {
            $head['pushable'] = false;
            $head['why']      = $why;
        }
        return $head;
    }

    /** @return string|null warum diese Site keinen Inhalts-Push annimmt (C8, kein Multisite) */
    public static function unpushable(string $home, string $siteurl): ?string
    {
        if (is_multisite()) {
            return 'multisite';
        }
        return self::origin($home) !== '' && self::origin($home) === self::origin($siteurl) ? null : 'origin_mismatch';
    }

    /** Schema, Host und Port in einer Form, in der sich zwei URLs vergleichen lassen; '' wenn unlesbar. */
    public static function origin(string $url): string
    {
        $parts  = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host   = strtolower((string) ($parts['host'] ?? ''));
        if (!is_array($parts) || $host === '' || !in_array($scheme, ['http', 'https'], true)) {
            return '';
        }
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        return $scheme . '://' . $host . ':' . $port;
    }

    /**
     * @return list<string> Inhaltstabellen ohne Präfix, die der Scope mit Daten überträgt.
     *                      term_relationships nur zusammen mit term_taxonomy: der Leser nimmt die
     *                      Taxonomie jeder Zuordnung von dort.
     */
    private static function tables(Scope $scope): array
    {
        global $wpdb;
        $out = [];
        foreach (Canon::TABLES as $name) {
            if ($scope->tableMode((string) $wpdb->prefix . $name) === Scope::FULL) {
                $out[] = $name;
            }
        }
        if (!in_array('term_taxonomy', $out, true)) {
            $out = array_values(array_diff($out, ['term_relationships']));
        }
        return $out;
    }

}
