<?php
namespace WpSync;

defined('ABSPATH') || exit;

/**
 * Pseudonymisiert personenbezogene Werte beim Export, damit echte Daten den Server nicht
 * verlassen (Konzept 5.1a, Spec 11). Deterministisch per HMAC mit einem Schlüssel pro Site:
 * dieselbe Eingabe ergibt in jeder Tabelle und bei jedem Pull dasselbe Pseudonym, Relationen
 * bleiben erhalten. Best effort – Tabellen ohne Regel kommen unverändert, covers() macht das
 * für Scan und Pull sichtbar.
 *
 * Strategien: email · ip · tag:<Präfix> (Präfix + 16 Hex) · name:<Label> (Label + 6 Hex) ·
 * fixed:<Wert>. NULL bleibt NULL; leere Werte bleiben leer, ausser bei fixed.
 */
final class Anonymizer
{
    /** Erhöhen, wenn sich Regeln ändern – das CLI lädt die betroffenen Tabellen dann neu (Spec 11.3). */
    public const RULES_VERSION = 2;

    /** Kein Hash: höchstens 32 Zeichen vergleicht WordPress mit md5(), das nie mit „!“ beginnt. */
    public const NO_LOGIN = '!wpsync-anonymized';

    /**
     * Starke Muster (Spec Content-Push §6.2): was der Anonymizer erzeugt und echte Inhalte praktisch
     * nie enthalten. Schwache Platzhalter (Musterstadt, 00000, 0.0.0.0) gehören nicht dazu. Ändert
     * sich eine Strategie oder ein Präfix in rules(), müssen die Muster mit – AnonymizerTest prüft es.
     */
    public const PATTERNS = [
        'email' => 'user-[0-9a-f]{16}@example\.invalid',
        'tag'   => '\b(user_|user-|wc_order_|ck_|cs_|tok_)[0-9a-f]{16}\b',
        'name'  => '\b(Vorname|Nachname|Nutzer|Gast) [0-9a-f]{6}\b',
        'fixed' => '!wpsync-anonymized|Bestellnotiz \(anonymisiert\)',
    ];

    /** Adressfelder von WooCommerce – als Spalten (HPOS) und, mit Präfix, als Meta-Schlüssel. Land und Bundesland bleiben. */
    private const ADDRESS = [
        'first_name' => 'name:Vorname',
        'last_name'  => 'name:Nachname',
        'company'    => 'fixed:',
        'address_1'  => 'fixed:Musterstraße 1',
        'address_2'  => 'fixed:',
        'city'       => 'fixed:Musterstadt',
        'postcode'   => 'fixed:00000',
        'email'      => 'email',
        'phone'      => 'fixed:',
    ];

    /** @var string */
    private $key;
    /** @var string */
    private $prefix;

    public function __construct(string $key, string $prefix)
    {
        $this->key    = $key;
        $this->prefix = $prefix;
    }

    /** Regelversion plus Fingerabdruck des Schlüssels – ändert sich eines, sind alte Pseudonyme überholt. */
    public static function id(string $key): string
    {
        return self::RULES_VERSION . '.' . substr(hash('sha256', $key), 0, 8);
    }

    /** Gibt es für die Tabelle eine Regel? Auch eine leere: geprüft, enthält keine Personendaten. */
    public static function covers(string $table, string $prefix): bool
    {
        return array_key_exists(self::name($table, $prefix), self::rules());
    }

    /** Ersetzt eine Regel in der Tabelle Werte? Geprüfte Tabellen ohne Personendaten sind abgedeckt, ändern aber nichts. */
    public static function changes(string $table, string $prefix): bool
    {
        return (self::rules()[self::name($table, $prefix)] ?? []) !== [];
    }

    /** @return array{rules_version: int, patterns: list<array{id: string, pattern: string}>} für den Manifest-Kopf */
    public static function patterns(): array
    {
        $patterns = [];
        foreach (self::PATTERNS as $id => $pattern) {
            $patterns[] = ['id' => $id, 'pattern' => $pattern];
        }
        return ['rules_version' => self::RULES_VERSION, 'patterns' => $patterns];
    }

    /** @return string|null id des ersten Musters, das im Wert vorkommt */
    public static function find(string $value): ?string
    {
        foreach (self::PATTERNS as $id => $pattern) {
            if (preg_match('/' . $pattern . '/', $value) === 1) {
                return $id;
            }
        }
        return null;
    }

    /** @return list<string> Beitragstypen, deren Zeilen in posts ersetzt werden */
    public static function postTypes(): array
    {
        $types = [];
        foreach (self::rules()['posts'] ?? [] as $rule) {
            if (isset($rule['when']) && $rule['when'][0] === 'post_type') {
                $types = array_merge($types, $rule['when'][1]);
            }
        }
        return array_values(array_unique($types));
    }

    /**
     * @param string $table Tabelle ohne Präfix
     * @return list<string> Schlüssel, deren Wert eine meta-Regel der Tabelle ersetzt
     */
    public static function metaKeys(string $table): array
    {
        $keys = [];
        foreach (self::rules()[$table] ?? [] as $rule) {
            if (isset($rule['meta'])) {
                $keys = array_merge($keys, array_map('strval', array_keys($rule['meta'][2])));
            }
        }
        return array_values(array_unique($keys));
    }

    /**
     * @param string $table Tabelle ohne Präfix
     * @param bool   $unconditional nur Regeln ohne when
     * @return list<string> Spalten, die eine set-Regel der Tabelle ersetzt
     */
    public static function columns(string $table, bool $unconditional): array
    {
        $columns = [];
        foreach (self::rules()[$table] ?? [] as $rule) {
            if ($unconditional && isset($rule['when'])) {
                continue;
            }
            $columns = array_merge($columns, array_keys($rule['set'] ?? []));
        }
        return array_values(array_unique($columns));
    }

    /**
     * Kann rows() an dieser Zeile etwas ersetzen? Dieselben Regeln, ohne Schlüssel und ohne die
     * Werte anzusehen – für alles, was statt des Pseudonyms nur einen Abdruck des echten Werts
     * herausgäbe (Inhalts-Manifest). Im Zweifel ja: fehlt der Zeile die Spalte einer Bedingung oder
     * die Schlüsselspalte einer meta-Regel, gilt die Regel.
     *
     * @param string                     $table Tabelle ohne Präfix
     * @param array<string, string|null> $row   die Spalten, die der Aufrufer kennt
     */
    public static function touches(string $table, array $row): bool
    {
        foreach (self::rules()[$table] ?? [] as $rule) {
            if (isset($rule['when'])) {
                list($column, $values) = $rule['when'];
                if (array_key_exists($column, $row) && !in_array((string) $row[$column], $values, true)) {
                    continue;
                }
            }
            if (($rule['set'] ?? []) !== []) {
                return true;
            }
            if (isset($rule['meta'])) {
                list($keyColumn, , $keys) = $rule['meta'];
                if (!array_key_exists($keyColumn, $row) || isset($keys[(string) $row[$keyColumn]])) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * @param list<array<string, string|null>> $rows Zeilen mit Spaltennamen als Schlüssel
     * @return list<array<string, string|null>>
     */
    public function rows(string $table, array $rows): array
    {
        $rules = self::rules()[self::name($table, $this->prefix)] ?? [];
        if ($rules === []) {
            return $rows;
        }
        foreach ($rows as $i => $row) {
            $rows[$i] = $this->row($rules, $row);
        }
        return $rows;
    }

    /**
     * @param list<array<string, mixed>>   $rules
     * @param array<string, string|null>   $row
     * @return array<string, string|null>
     */
    private function row(array $rules, array $row): array
    {
        foreach ($rules as $rule) {
            if (isset($rule['when'])) {
                list($column, $values) = $rule['when'];
                if (!in_array((string) ($row[$column] ?? ''), $values, true)) {
                    continue;
                }
            }
            foreach ($rule['set'] ?? [] as $column => $strategy) {
                if (array_key_exists($column, $row)) {
                    $row[$column] = $this->value($strategy, $row[$column]);
                }
            }
            if (isset($rule['meta'])) {
                list($keyColumn, $valueColumn, $keys) = $rule['meta'];
                $strategy = $keys[(string) ($row[$keyColumn] ?? '')] ?? null;
                if ($strategy !== null && array_key_exists($valueColumn, $row)) {
                    $row[$valueColumn] = $this->value($strategy, $row[$valueColumn]);
                }
            }
        }
        return $row;
    }

    /**
     * @param string|null $value
     * @return string|null
     */
    private function value(string $strategy, $value)
    {
        if ($value === null) {
            return null;
        }
        $value = (string) $value;
        $parts = explode(':', $strategy, 2);
        $kind  = $parts[0];
        $arg   = $parts[1] ?? '';
        if ($kind === 'fixed') {
            return $arg;
        }
        if ($value === '') {
            return '';
        }
        switch ($kind) {
            case 'email':
                return 'user-' . $this->hash(strtolower(trim($value)), 16) . '@example.invalid';
            case 'ip':
                return '0.0.0.0';
            case 'tag':
                return $arg . $this->hash($value, 16);
            case 'name':
                return $arg . ' ' . $this->hash($value, 6);
        }
        throw new \LogicException('unknown anonymizer strategy ' . $strategy);
    }

    private function hash(string $value, int $length): string
    {
        return substr(hash_hmac('sha256', $value, $this->key), 0, $length);
    }

    /** Wie Classifier: ohne Tabellen-Prefix, klein geschrieben. */
    private static function name(string $table, string $prefix): string
    {
        return strtolower(strpos($table, $prefix) === 0 ? substr($table, strlen($prefix)) : $table);
    }

    /**
     * Explizite Schlüssel statt Wildcard: WooCommerce Subscriptions speichert _billing_period.
     *
     * @return array<string, string>
     */
    private static function address(string $prefix): array
    {
        $out = [];
        foreach (self::ADDRESS as $field => $strategy) {
            $out[$prefix . $field] = $strategy;
        }
        return $out;
    }

    /**
     * Pro Tabelle eine Liste von Regeln; jede Regel hat optional
     *  - when: [Spalte, erlaubte Werte] – gilt nur für solche Zeilen
     *  - set:  Spalte → Strategie
     *  - meta: [Schlüssel-Spalte, Wert-Spalte, Schlüssel → Strategie]
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private static function rules(): array
    {
        static $rules = null;
        if ($rules !== null) {
            return $rules;
        }

        $userMeta = [
            'first_name'                => 'name:Vorname',
            'last_name'                 => 'name:Nachname',
            'nickname'                  => 'tag:user_',
            'description'               => 'fixed:',
            'session_tokens'            => 'fixed:',
            '_application_passwords'    => 'fixed:',
            'community-events-location' => 'fixed:',
        ] + self::address('billing_') + self::address('shipping_');

        $orderMeta = [
            '_customer_ip_address'             => 'ip',
            '_customer_user_agent'             => 'fixed:',
            '_order_key'                       => 'tag:wc_order_',
            '_transaction_id'                  => 'fixed:',
            '_billing_address_index'           => 'fixed:',
            '_shipping_address_index'          => 'fixed:',
            // Order Attribution: dieselbe Angabe wie _customer_user_agent, auch ohne HPOS
            '_wc_order_attribution_user_agent' => 'fixed:',
        ] + self::address('_billing_') + self::address('_shipping_');

        $rules = [
            // WordPress Core
            'users' => [['set' => [
                'user_login'          => 'tag:user_',
                'user_pass'           => 'fixed:' . self::NO_LOGIN,
                'user_nicename'       => 'tag:user-',
                'user_email'          => 'email',
                'user_url'            => 'fixed:',
                'user_activation_key' => 'fixed:',
                'display_name'        => 'name:Nutzer',
            ]]],
            'usermeta' => [['meta' => ['meta_key', 'meta_value', $userMeta]]],
            'comments' => [
                ['set' => [
                    'comment_author'       => 'name:Gast',
                    'comment_author_email' => 'email',
                    'comment_author_url'   => 'fixed:',
                    'comment_author_IP'    => 'ip',
                    'comment_agent'        => 'fixed:',
                ]],
                // WooCommerce-Bestellnotizen enthalten Namen, Adressen, E-Mails im Fliesstext.
                ['when' => ['comment_type', ['order_note']], 'set' => ['comment_content' => 'fixed:Bestellnotiz (anonymisiert)']],
            ],
            'commentmeta' => [['meta' => ['meta_key', 'meta_value', [
                'akismet_as_submitted' => 'fixed:',
                'akismet_history'      => 'fixed:',
            ]]]],
            'options' => [['meta' => ['option_name', 'option_value', [
                'admin_email'     => 'email',
                'new_admin_email' => 'email',
            ]]]],
            // WooCommerce: klassische Bestellungen (Posts) – post_password ist der Order-Key, post_excerpt die Kundennotiz
            'posts' => [[
                'when' => ['post_type', ['shop_order', 'shop_order_refund', 'shop_subscription']],
                'set'  => ['post_password' => 'tag:wc_order_', 'post_excerpt' => 'fixed:'],
            ]],
            'postmeta' => [['meta' => ['meta_key', 'meta_value', $orderMeta]]],
            // WooCommerce: HPOS
            'wc_orders' => [['set' => [
                'billing_email'  => 'email',
                'ip_address'     => 'ip',
                'user_agent'     => 'fixed:',
                'customer_note'  => 'fixed:',
                'transaction_id' => 'fixed:',
            ]]],
            'wc_order_addresses' => [['set' => self::ADDRESS]],
            'wc_orders_meta'     => [['meta' => ['meta_key', 'meta_value', [
                '_billing_address_index'           => 'fixed:',
                '_shipping_address_index'          => 'fixed:',
                '_wc_order_attribution_user_agent' => 'fixed:',
            ]]]],
            'wc_order_operational_data' => [['set' => ['order_key' => 'tag:wc_order_']]],
            'wc_customer_lookup'        => [['set' => [
                'username'   => 'tag:user_',
                'first_name' => 'name:Vorname',
                'last_name'  => 'name:Nachname',
                'email'      => 'email',
                'city'       => 'fixed:Musterstadt',
                'postcode'   => 'fixed:00000',
            ]]],
            'wc_download_log'                              => [['set' => ['user_ip_address' => 'ip']]],
            'woocommerce_downloadable_product_permissions' => [['set' => ['user_email' => 'email', 'order_key' => 'tag:wc_order_']]],
            // WooCommerce: Sitzungen und Geheimnisse
            'woocommerce_sessions'          => [['set' => ['session_value' => 'fixed:a:0:{}']]],
            'woocommerce_api_keys'          => [['set' => ['consumer_key' => 'tag:ck_', 'consumer_secret' => 'tag:cs_', 'truncated_key' => 'fixed:0000000']]],
            'woocommerce_payment_tokens'    => [['set' => ['token' => 'tag:tok_']]],
            'woocommerce_payment_tokenmeta' => [['set' => ['meta_value' => 'fixed:']]],
            'wc_webhooks'                   => [['set' => ['secret' => 'fixed:']]],
            // WooCommerce: geprüft, keine Personendaten (D4)
            'wc_order_stats'             => [],
            'wc_order_product_lookup'    => [],
            'wc_order_tax_lookup'        => [],
            'wc_order_coupon_lookup'     => [],
            'woocommerce_order_items'    => [],
            'woocommerce_order_itemmeta' => [],
        ];
        return $rules;
    }
}
