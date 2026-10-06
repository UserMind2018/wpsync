<?php
namespace WpSync;

defined('ABSPATH') || exit;

/**
 * Datenbank-Arbeit des Staging-Jobs (Spec Stufe 2b 5.2 Phasen 4–8). Die Daten verlassen die
 * Datenbank nicht (INSERT … SELECT). Jede Methode arbeitet bis $deadline, mindestens einen
 * Schritt, und gibt einen Cursor zurück. Geschrieben wird nur über StagingGuard (Leitplanke 2):
 * jedes Ziel ist eine dort geprüfte Staging-Tabelle, Live-Tabellen kommen nur als Quelle vor.
 * Auch Lesefehler brechen ab – ein leeres Ergebnis sähe sonst aus wie „fertig“ (T2).
 */
final class StagingDb
{
    public const SMALL_TABLE = 50000;
    public const MIN_CHUNK   = 200;
    public const MAX_CHUNK   = 50000;
    /** Zeilen pro Lese-Schritt beim Anonymisieren und Umschreiben – longtext kann gross sein. */
    public const READ_ROWS = 100;

    /** Wie LocalDisabledPlugins in cli/internal/pull/postsetup.go – StagingDbTest prüft die Gleichheit. */
    public const DISABLED_PLUGINS = [
        'wp-mail-smtp', 'wp-mail-smtp-pro', 'post-smtp', 'fluent-smtp', 'easy-wp-smtp',
        'password-protected', 'better-wp-security', 'ithemes-security-pro', 'wps-hide-login',
        'wordfence', 'all-in-one-wp-security-and-firewall', 'sucuri-scanner',
        'wp-rocket', 'w3-total-cache', 'litespeed-cache', 'wp-super-cache', 'wp-fastest-cache',
    ];

    /** Zahlungsarten ohne Anbieter – bleiben aktiv (S2). */
    public const OFFLINE_GATEWAYS = ['bacs', 'cheque', 'cod'];

    /** Verbreitete Online-Zahlungsarten, auch wenn woocommerce_gateway_order sie nicht nennt (V16). */
    public const KNOWN_GATEWAYS = [
        'stripe', 'stripe_sepa', 'stripe_klarna', 'ppcp-gateway', 'ppcp-credit-card-gateway', 'paypal', 'ppec_paypal',
        'woocommerce_payments', 'klarna_payments', 'mollie_wc_gateway_creditcard', 'mollie_wc_gateway_paypal',
        'mollie_wc_gateway_ideal', 'payone', 'sumup',
    ];

    /**
     * @return list<array{live: string, stg: string, mode: string}>
     * @throws StagingException
     */
    public static function plan(StagingGuard $guard, Scope $scope): array
    {
        $out = [];
        foreach (Store::dataTables() as $live) {
            $out[] = ['live' => $live, 'stg' => $guard->stagingName($live), 'mode' => $scope->tableMode($live)];
        }
        return $out;
    }

    /**
     * Daten und Indizes der Tabellen, die kopiert würden (Spec 5.7).
     *
     * @throws StagingException
     */
    public static function bytes(Scope $scope): int
    {
        global $wpdb;
        $names = [];
        foreach (Store::dataTables() as $table) {
            if ($scope->tableMode($table) === Scope::FULL) {
                $names[] = $table;
            }
        }
        if ($names === []) {
            return 0;
        }
        $in = implode(',', array_fill(0, count($names), '%s'));
        return (int) self::value($wpdb->prepare(
            'SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.TABLES WHERE table_schema = DATABASE() AND table_name IN (' . $in . ')',
            ...$names
        ));
    }

    /**
     * Daten und Indizes der Staging-Tabellen (staging status).
     *
     * @throws StagingException
     */
    public static function stagingBytes(StagingGuard $guard): int
    {
        global $wpdb;
        return (int) self::value($wpdb->prepare(
            'SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.TABLES WHERE table_schema = DATABASE() AND table_name LIKE %s',
            $wpdb->esc_like($guard->stagingPrefix()) . '%'
        ));
    }

    /**
     * Legt eine Staging-Tabelle an und kopiert ihre Zeilen in Keyset-Schritten (Spec 5.2 Phase 4).
     * Abgewählte Tabellen bleiben leer.
     *
     * @param array{live: string, stg: string, mode: string} $entry
     * @param array<string, mixed>|null                      $cursor null: Tabelle ist noch nicht angelegt
     * @return array{cursor: array<string, mixed>|null, done: bool, chunk: int}
     * @throws StagingException
     */
    public static function copyTable(StagingGuard $guard, Scope $scope, array $entry, ?array $cursor, int $chunk, float $deadline, float $budget): array
    {
        global $wpdb;
        $live = $guard->source((string) ($entry['live'] ?? ''));
        $stg  = $guard->table((string) ($entry['stg'] ?? ''));
        if ($stg !== $guard->stagingName($live)) {
            throw StagingException::guard('staging table ' . $stg . ' does not belong to ' . $live);
        }
        $chunk = max(1, $chunk);
        if ($cursor === null) {
            self::exec($guard->createLike($stg, $live));
            if (($entry['mode'] ?? '') !== Scope::FULL) {
                return ['cursor' => null, 'done' => true, 'chunk' => $chunk];
            }
            $status = self::results($wpdb->prepare('SHOW TABLE STATUS LIKE %s', $wpdb->esc_like($live)));
            $cursor = ['pk' => self::primaryKey($live), 'after' => null, 'offset' => 0, 'rows' => (int) ($status[0]['Rows'] ?? 0)];
        }
        $filter = self::rowFilter($guard, $scope, $live);
        $key    = isset($cursor['pk']) ? (string) $cursor['pk'] : null;
        $offset = max(0, (int) ($cursor['offset'] ?? 0));
        do {
            $started = microtime(true);
            if ($key !== null) {
                $after = $cursor['after'] ?? null;
                $pk    = 't.' . self::ident($key);
                $where = self::where([$filter['where'], $after === null ? '' : $pk . ' > ' . $wpdb->prepare('%s', (string) $after)]);
                $rows  = self::exec($guard->insertSelect($stg, $live, $filter['join'], $where, $pk, $chunk));
                if ($rows > 0) {
                    $cursor['after'] = (string) self::value($guard->select($stg, 'MAX(' . self::ident($key) . ')'));
                }
                $limit = $chunk;
            } elseif ($offset === 0 && (int) ($cursor['rows'] ?? 0) < self::SMALL_TABLE) {
                $rows  = self::exec($guard->insertSelect($stg, $live, $filter['join'], $filter['where'], '', 0));
                $limit = $rows + 1; // ein Schritt genügt
            } else {
                $rows             = self::exec($guard->insertSelect($stg, $live, $filter['join'], $filter['where'], '', $chunk, $offset));
                $offset          += $rows;
                $cursor['offset'] = $offset;
                $limit            = $chunk;
            }
            $chunk = self::adapt($chunk, microtime(true) - $started, $budget);
            if ($rows < $limit) {
                return ['cursor' => null, 'done' => true, 'chunk' => $chunk];
            }
        } while (microtime(true) < $deadline);
        return ['cursor' => $cursor, 'done' => false, 'chunk' => $chunk];
    }

    /**
     * Pseudonymisiert eine Staging-Tabelle mit den Regeln und dem Schlüssel des Pulls (T2, AC-88).
     * Der Anonymizer entsteht hier mit dem Staging-Präfix: mit einem anderen fände er keine Regel
     * und liesse die Zeilen still im Klartext. Tabellen, deren Regeln nichts ersetzen, sind sofort
     * fertig; alle anderen brauchen einen einspaltigen Primärschlüssel (V18).
     *
     * @param string                                         $key   Store::anonKey()
     * @param array{live: string, stg: string, mode: string} $entry
     * @return array{after: string|null, done: bool}
     * @throws StagingException
     */
    public static function anonymize(StagingGuard $guard, string $key, array $entry, ?string $after, float $deadline): array
    {
        global $wpdb;
        $stg = $guard->table((string) ($entry['stg'] ?? ''));
        if ($key === '') {
            throw StagingException::failed('Kein Schlüssel zum Anonymisieren – die Kopie bliebe im Klartext.');
        }
        if (!Anonymizer::changes($stg, $guard->stagingPrefix())) {
            return ['after' => null, 'done' => true];
        }
        $pk = self::primaryKey($stg);
        if ($pk === null) {
            throw StagingException::failed('Tabelle ' . $stg . ' hat keinen einspaltigen Primärschlüssel und lässt sich nicht anonymisieren.');
        }
        $anonymizer = new Anonymizer($key, $guard->stagingPrefix());
        $id         = self::ident($pk);
        do {
            $where = $after === null ? '' : 'WHERE ' . $id . ' > ' . $wpdb->prepare('%s', $after) . ' ';
            $rows  = self::results($guard->select($stg, '*', $where . 'ORDER BY ' . $id . ' LIMIT ' . self::READ_ROWS));
            $new   = $anonymizer->rows($stg, $rows);
            foreach ($rows as $i => $row) {
                $set = [];
                foreach ($new[$i] as $column => $value) {
                    if ($value !== $row[$column]) {
                        $set[$column] = $value;
                    }
                }
                if ($set === []) {
                    continue;
                }
                if (self::exec($guard->update($stg, self::assignments($set), $id . ' = ' . $wpdb->prepare('%s', (string) $row[$pk]))) < 1) {
                    throw StagingException::failed('Eine Zeile in ' . $stg . ' liess sich nicht anonymisieren.');
                }
            }
            if (count($rows) < self::READ_ROWS) {
                return ['after' => null, 'done' => true];
            }
            $after = (string) $rows[count($rows) - 1][$pk];
        } while (microtime(true) < $deadline);
        return ['after' => $after, 'done' => false];
    }

    /**
     * Präfixgebundene Schlüssel umbenennen (Spec 5.2 Phase 6, AC-89).
     *
     * @throws StagingException
     */
    public static function fixPrefix(StagingGuard $guard): void
    {
        global $wpdb;
        $live     = $guard->livePrefix();
        $stg      = $guard->stagingPrefix();
        $options  = $guard->table($stg . 'options');
        $usermeta = $guard->table($stg . 'usermeta');
        self::exec($guard->update($options, '`option_name` = ' . $wpdb->prepare('%s', $stg . 'user_roles'), '`option_name` = ' . $wpdb->prepare('%s', $live . 'user_roles')));
        self::exec($guard->update(
            $usermeta,
            $wpdb->prepare('`meta_key` = CONCAT(%s, SUBSTRING(`meta_key`, %d))', $stg, strlen($live) + 1),
            $wpdb->prepare('`meta_key` LIKE %s', $wpdb->esc_like($live) . '%')
        ));
    }

    /**
     * Text-Spalten aller vollständig kopierten Tabellen – ohne posts.guid (AC-87) und ohne den
     * Primärschlüssel selbst.
     *
     * @param list<array{live: string, stg: string, mode: string}> $tables
     * @return list<array{table: string, column: string, pk: string|null}>
     * @throws StagingException
     */
    public static function textColumns(StagingGuard $guard, array $tables): array
    {
        $posts = $guard->stagingPrefix() . 'posts';
        $out   = [];
        foreach ($tables as $entry) {
            if (($entry['mode'] ?? '') !== Scope::FULL) {
                continue;
            }
            $stg = $guard->table((string) ($entry['stg'] ?? ''));
            $pk  = self::primaryKey($stg);
            foreach (self::results('SHOW COLUMNS FROM ' . self::ident($stg)) as $column) {
                $name = (string) $column['Field'];
                if (self::isText((string) $column['Type']) && $name !== $pk && !($stg === $posts && $name === 'guid')) {
                    $out[] = ['table' => $stg, 'column' => $name, 'pk' => $pk];
                }
            }
        }
        return $out;
    }

    public static function isText(string $type): bool
    {
        return preg_match('/^((var)?char\(|(tiny|medium|long)?text\b)/i', $type) === 1;
    }

    /**
     * Schreibt die Live-URL einer Spalte um (Spec 5.6). Mit Primärschlüssel per Keyset, ohne über
     * die verschiedenen Werte selbst – das Umschreiben ist idempotent (V4).
     *
     * @param array{table: string, column: string, pk: string|null} $column
     * @return array{cursor: string|null, done: bool, changed: int}
     * @throws StagingException
     */
    public static function replaceUrls(StagingGuard $guard, StagingReplace $replace, array $column, ?string $cursor, float $deadline): array
    {
        global $wpdb;
        $stg     = $guard->table((string) ($column['table'] ?? ''));
        $col     = self::ident((string) ($column['column'] ?? ''));
        $pk      = isset($column['pk']) ? self::ident((string) $column['pk']) : null;
        $like    = $col . ' LIKE ' . $wpdb->prepare('%s', '%' . $wpdb->esc_like($replace->host()) . '%');
        $changed = 0;
        do {
            if ($pk !== null) {
                $rest = 'WHERE ' . $like . ($cursor === null ? '' : ' AND ' . $pk . ' > ' . $wpdb->prepare('%s', $cursor)) . ' ORDER BY ' . $pk . ' LIMIT ' . self::READ_ROWS;
                $rows = self::results($guard->select($stg, $pk . ' AS k, ' . $col . ' AS v', $rest));
            } else {
                $rest = 'WHERE ' . $like . ($cursor === null ? '' : ' AND BINARY ' . $col . ' > BINARY ' . $wpdb->prepare('%s', $cursor)) . ' ORDER BY k LIMIT ' . self::READ_ROWS;
                $rows = self::results($guard->select($stg, 'DISTINCT BINARY ' . $col . ' AS k, ' . $col . ' AS v', $rest));
            }
            foreach ($rows as $row) {
                $old = (string) $row['v'];
                $new = $replace->value($old);
                if ($new === $old) {
                    continue;
                }
                $where    = $pk !== null ? $pk . ' = ' . $wpdb->prepare('%s', (string) $row['k']) : 'BINARY ' . $col . ' = BINARY ' . $wpdb->prepare('%s', $old);
                $changed += self::exec($guard->update($stg, $col . ' = ' . $wpdb->prepare('%s', $new), $where));
            }
            if (count($rows) < self::READ_ROWS) {
                return ['cursor' => null, 'done' => true, 'changed' => $changed];
            }
            $cursor = (string) $rows[count($rows) - 1]['k'];
        } while (microtime(true) < $deadline);
        return ['cursor' => $cursor, 'done' => false, 'changed' => $changed];
    }

    /**
     * Riegel in der Datenbank der Kopie (Spec 5.2 Phase 8, AC-90, AC-95).
     *
     * @throws StagingException
     */
    public static function settings(StagingGuard $guard, Scope $scope): void
    {
        global $wpdb;
        $options = $guard->table($guard->stagingPrefix() . 'options');
        self::setOption($guard, $options, 'blog_public', '0');
        self::setOption($guard, $options, 'active_plugins', serialize(self::activePlugins(self::arrayOption($guard, $options, 'active_plugins') ?? [], $scope)));
        foreach (self::gatewayIds(self::arrayOption($guard, $options, 'woocommerce_gateway_order') ?? []) as $id) {
            $name     = 'woocommerce_' . $id . '_settings';
            $settings = self::arrayOption($guard, $options, $name);
            // Ohne „enabled“ gälte die Vorgabe der Zahlungsart – die kennt hier niemand.
            if ($settings !== null && ($settings['enabled'] ?? '') !== 'no') {
                $settings['enabled'] = 'no';
                self::setOption($guard, $options, $name, serialize($settings));
            }
        }
        $hooks = $guard->table($guard->stagingPrefix() . 'wc_webhooks');
        if (self::value($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($hooks))) === $hooks) {
            self::exec($guard->update($hooks, "`status` = 'paused'", "`status` <> 'paused'"));
        }
    }

    /**
     * @param array<mixed> $active Option active_plugins von Live
     * @return list<string>
     */
    public static function activePlugins(array $active, Scope $scope): array
    {
        $out = [];
        foreach ($active as $file) {
            if (!is_string($file) || $file === '') {
                continue;
            }
            $single = strpos($file, '/') === false;
            $slug   = strtolower($single ? (string) preg_replace('/\.php\z/', '', $file) : explode('/', $file)[0]);
            $gone   = $single ? $scope->excludesPath('plugins/' . $file, false) : $scope->excludesPath('plugins/' . explode('/', $file)[0], true);
            if ($slug !== 'wpsync-agent' && !in_array($slug, self::DISABLED_PLUGINS, true) && !$gone) {
                $out[] = $file;
            }
        }
        return $out;
    }

    /**
     * @param array<mixed> $order Option woocommerce_gateway_order: Zahlungsart → Position
     * @return list<string> Zahlungsarten, die auf Staging aus sein müssen
     */
    public static function gatewayIds(array $order): array
    {
        $ids = self::KNOWN_GATEWAYS;
        foreach (array_keys($order) as $id) {
            if (is_string($id) && preg_match('/^[A-Za-z0-9_-]{1,100}\z/', $id) === 1) {
                $ids[] = $id;
            }
        }
        return array_values(array_diff(array_values(array_unique($ids)), self::OFFLINE_GATEWAYS));
    }

    /**
     * Löscht alle Tabellen mit dem Staging-Präfix; true, wenn keine mehr da ist.
     *
     * @throws StagingException
     */
    public static function dropAll(StagingGuard $guard, float $deadline): bool
    {
        $shown = self::column($guard->showTables());
        foreach ($shown as $i => $table) {
            if ($i > 0 && microtime(true) > $deadline) {
                return false;
            }
            self::exec($guard->drop($table, $shown));
        }
        return true;
    }

    /** Schrittgrösse nach der Laufzeit des letzten Schritts (NFA: Zeilensperren kurz halten). */
    public static function adapt(int $chunk, float $elapsed, float $budget): int
    {
        if ($elapsed < $budget / 8) {
            return min(self::MAX_CHUNK, $chunk * 2);
        }
        if ($elapsed > $budget / 3) {
            return max(self::MIN_CHUNK, intdiv($chunk, 2));
        }
        return $chunk;
    }

    public static function diskFull(string $error): bool
    {
        return preg_match('/is full|disk full|quota|errcode: 28|error 28/i', $error) === 1;
    }

    /** Ein Spalten- oder Tabellenname als Bezeichner – Namen aus der Datenbank sind nicht auf [A-Za-z0-9_] beschränkt. */
    public static function ident(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    /**
     * Führt SQL aus, das eine Methode von StagingGuard gebaut hat. Bewusst privat: einen anderen
     * Weg zu schreiben gibt es nicht (Leitplanke 2).
     *
     * @throws StagingException
     */
    private static function exec(string $sql): int
    {
        global $wpdb;
        $result = $wpdb->query($sql);
        if ($result === false) {
            throw self::error();
        }
        return (int) $result;
    }

    /**
     * @return list<array<string, string|null>>
     * @throws StagingException
     */
    private static function results(string $sql): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($sql, ARRAY_A);
        self::check();
        return is_array($rows) ? array_values($rows) : [];
    }

    /**
     * @return list<string>
     * @throws StagingException
     */
    private static function column(string $sql): array
    {
        global $wpdb;
        $values = $wpdb->get_col($sql);
        self::check();
        return array_map('strval', array_values((array) $values));
    }

    /** @throws StagingException */
    private static function value(string $sql): ?string
    {
        global $wpdb;
        $value = $wpdb->get_var($sql);
        self::check();
        return $value === null ? null : (string) $value;
    }

    /** @throws StagingException */
    private static function primaryKey(string $table): ?string
    {
        $pk = Rest::singlePrimaryKey($table);
        self::check();
        return $pk;
    }

    /**
     * $wpdb liefert bei einem Fehler dasselbe wie bei „nichts gefunden“.
     *
     * @throws StagingException
     */
    private static function check(): void
    {
        global $wpdb;
        if ((string) $wpdb->last_error !== '') {
            throw self::error();
        }
    }

    private static function error(): StagingException
    {
        global $wpdb;
        $error = (string) $wpdb->last_error;
        return self::diskFull($error) ? StagingException::space('Datenbank voll: ' . $error) : StagingException::failed('SQL-Fehler: ' . $error);
    }

    /**
     * Wie der Pull: abgewählte Post-Typen und eigene Optionen bleiben auf Live (V2, AC-27).
     *
     * @return array{join: string, where: string}
     * @throws StagingException
     */
    private static function rowFilter(StagingGuard $guard, Scope $scope, string $live): array
    {
        global $wpdb;
        $filter = $scope->rowFilter($live, [
            'posts'              => $guard->source((string) $wpdb->posts),
            'postmeta'           => $guard->source((string) $wpdb->postmeta),
            'term_relationships' => $guard->source((string) $wpdb->term_relationships),
            'comments'           => $guard->source((string) $wpdb->comments),
        ], static function (string $value) use ($wpdb): string {
            return (string) $wpdb->_real_escape($value);
        });
        if ($live === $wpdb->options) {
            $filter['where'] = self::where([$filter['where'], "t.`option_name` NOT LIKE 'wpsync\\_%'"]);
        }
        return $filter;
    }

    /** @param list<string> $parts */
    private static function where(array $parts): string
    {
        $parts = array_values(array_filter($parts, 'strlen'));
        return count($parts) > 1 ? '(' . implode(') AND (', $parts) . ')' : (string) ($parts[0] ?? '');
    }

    /** @param array<string, string|null> $set */
    private static function assignments(array $set): string
    {
        global $wpdb;
        $out = [];
        foreach ($set as $column => $value) {
            $out[] = self::ident((string) $column) . ' = ' . ($value === null ? 'NULL' : $wpdb->prepare('%s', $value));
        }
        return implode(', ', $out);
    }

    /**
     * Eine serialisierte Option der Kopie als Array; null, wenn sie fehlt oder kein Array ist.
     * Objekte werden dabei nicht erzeugt.
     *
     * @return array<mixed>|null
     * @throws StagingException
     */
    private static function arrayOption(StagingGuard $guard, string $options, string $name): ?array
    {
        global $wpdb;
        $raw = self::value($guard->select($options, '`option_value`', 'WHERE `option_name` = ' . $wpdb->prepare('%s', $name)));
        if ($raw === null || $raw === '') {
            return null;
        }
        $value = @unserialize($raw, ['allowed_classes' => false]);
        return is_array($value) ? $value : null;
    }

    /** @throws StagingException */
    private static function setOption(StagingGuard $guard, string $options, string $name, string $value): void
    {
        global $wpdb;
        self::exec($guard->update($options, '`option_value` = ' . $wpdb->prepare('%s', $value), '`option_name` = ' . $wpdb->prepare('%s', $name)));
    }
}
