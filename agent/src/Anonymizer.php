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
    public const RULES_VERSION = 1;

    /** Kein Hash: höchstens 32 Zeichen vergleicht WordPress mit md5(), das nie mit „!“ beginnt. */
    public const NO_LOGIN = '!wpsync-anonymized';

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
        ];

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
        ];
        return $rules;
    }
}
