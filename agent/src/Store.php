<?php
namespace WpSync;

defined('ABSPATH') || exit;

/**
 * Eigene Tabellen – nie exportiert, nie checksummiert (Spike B6, AC-27).
 */
final class Store
{
    /** Autoload-Option, damit die Prüfung keine Query kostet; „wpsync_%“ verlässt den Server nie (AC-27). */
    public const SCHEMA_OPTION = 'wpsync_schema';

    public static function table(string $name): string
    {
        global $wpdb;
        return $wpdb->base_prefix . 'wpsync_' . $name;
    }

    /** @return list<string> */
    public static function ownTables(): array
    {
        return [self::table('pairings'), self::table('nonces'), self::table('state'), self::table('pushes')];
    }

    /** @var list<string>|null */
    private static $dataTables = null;

    /**
     * Tabellen, die exportiert werden dürfen (siehe TableList); pro Request einmal ermittelt.
     *
     * @return list<string>
     */
    public static function dataTables(): array
    {
        global $wpdb;
        if (self::$dataTables === null) {
            $rows             = (array) $wpdb->get_results("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'", ARRAY_N);
            self::$dataTables = TableList::filter($rows, (string) $wpdb->base_prefix);
        }
        return self::$dataTables;
    }

    /**
     * Legt die Tabellen an – nur bei Aktivierung und nach einem Versionswechsel (ZIP-Update ohne
     * Aktivierung), nicht bei jedem Request (SEC-09).
     */
    public static function install(): void
    {
        global $wpdb;
        if (get_option(self::SCHEMA_OPTION) === WPSYNC_VERSION) {
            return;
        }
        $collate = $wpdb->get_charset_collate();
        $wpdb->query('CREATE TABLE IF NOT EXISTS `' . self::table('pairings') . '` (
            key_id CHAR(16) NOT NULL PRIMARY KEY,
            secret CHAR(64) NOT NULL,
            device VARCHAR(100) NOT NULL,
            created INT UNSIGNED NOT NULL,
            last_used INT UNSIGNED NULL,
            push_until INT UNSIGNED NOT NULL DEFAULT 0
        ) ' . $collate);
        $wpdb->query('CREATE TABLE IF NOT EXISTS `' . self::table('nonces') . '` (
            nonce CHAR(32) NOT NULL PRIMARY KEY,
            expires INT UNSIGNED NOT NULL,
            KEY expires (expires)
        ) ' . $collate);
        $wpdb->query('CREATE TABLE IF NOT EXISTS `' . self::table('state') . '` (
            name VARCHAR(64) NOT NULL PRIMARY KEY,
            value LONGTEXT NOT NULL
        ) ' . $collate);
        // Protokoll der Pushs (Spec Stufe 2, 5.2). Die Wahrheit über den Tausch steht in rescue.json.
        $wpdb->query('CREATE TABLE IF NOT EXISTS `' . self::table('pushes') . '` (
            push_id VARCHAR(32) NOT NULL PRIMARY KEY,
            key_id CHAR(16) NOT NULL,
            device VARCHAR(100) NOT NULL,
            target VARCHAR(16) NOT NULL,
            status VARCHAR(16) NOT NULL,
            forced TINYINT UNSIGNED NOT NULL DEFAULT 0,
            pruned TINYINT UNSIGNED NOT NULL DEFAULT 0,
            units LONGTEXT NOT NULL,
            created INT UNSIGNED NOT NULL,
            committed INT UNSIGNED NULL,
            finished INT UNSIGNED NULL,
            KEY created (created)
        ) ' . $collate);
        // Update von einem Agent vor 0.4.0: die Tabelle existiert schon ohne die Spalte.
        $columns = (array) $wpdb->get_col('SHOW COLUMNS FROM `' . self::table('pairings') . '`', 0);
        if (!in_array('push_until', $columns, true)) {
            $wpdb->query('ALTER TABLE `' . self::table('pairings') . '` ADD COLUMN push_until INT UNSIGNED NOT NULL DEFAULT 0');
        }
        update_option(self::SCHEMA_OPTION, WPSYNC_VERSION, true);
    }

    /** Aktivierung erzwingt das Anlegen, auch wenn die Marke von einer früheren Installation stammt. */
    public static function activate(): void
    {
        delete_option(self::SCHEMA_OPTION);
        self::install();
    }

    /** Deaktivieren entfernt alle eigenen Daten und damit alle Pairings. */
    public static function uninstall(): void
    {
        global $wpdb;
        foreach (self::ownTables() as $table) {
            $wpdb->query('DROP TABLE IF EXISTS `' . $table . '`');
        }
        delete_option(self::SCHEMA_OPTION);
        delete_option('wpsync_secret'); // Altlast aus dem Spike
    }

    /** @return array<string, mixed>|null */
    public static function getState(string $name): ?array
    {
        global $wpdb;
        $raw = $wpdb->get_var($wpdb->prepare('SELECT value FROM `' . self::table('state') . '` WHERE name = %s', $name));
        if ($raw === null) {
            return null;
        }
        $value = json_decode((string) $raw, true);
        return is_array($value) ? $value : null;
    }

    /** @param array<string, mixed>|null $value */
    public static function setState(string $name, ?array $value): void
    {
        global $wpdb;
        if ($value === null) {
            $wpdb->delete(self::table('state'), ['name' => $name]);
            return;
        }
        $wpdb->replace(self::table('state'), ['name' => $name, 'value' => wp_json_encode($value)]);
    }

    /**
     * Schlüssel der Pseudonyme (Spec 11.2): einmal erzeugt, bleibt er bis zur Deaktivierung des
     * Plugins gleich – sonst wechselten die Pseudonyme zwischen zwei Pulls. Liegt in
     * wpsync_state und verlässt den Server damit nie (AC-27).
     */
    public static function anonKey(): string
    {
        $state = self::getState('anon_key');
        if ($state === null || !is_string($state['key'] ?? null) || strlen($state['key']) !== 64) {
            self::setState('anon_key', ['key' => bin2hex(random_bytes(32))]);
            $state = self::getState('anon_key'); // gewinnt ein paralleler Request, gilt dessen Schlüssel
        }
        return (string) ($state['key'] ?? '');
    }

    public static function issuePairingCode(): string
    {
        self::install();
        $code = Pairing::newCode();
        self::setState('pairing_code', Pairing::stored($code, time()));
        return $code;
    }

    /** @return bool false, wenn die Zeile nicht geschrieben wurde */
    public static function addPairing(string $keyId, string $secret, string $device): bool
    {
        global $wpdb;
        return 1 === $wpdb->insert(self::table('pairings'), [
            'key_id'  => $keyId,
            'secret'  => $secret,
            'device'  => $device,
            'created' => time(),
        ]);
    }

    /** MySQL-Locks gelten serverweit – der Name muss Datenbank und Präfix enthalten. */
    public static function lockName(string $purpose): string
    {
        global $wpdb;
        return 'wpsync_' . $purpose . '_' . substr(md5(DB_NAME . '|' . $wpdb->base_prefix), 0, 12);
    }

    public static function secretFor(string $keyId): ?string
    {
        global $wpdb;
        $secret = $wpdb->get_var($wpdb->prepare('SELECT secret FROM `' . self::table('pairings') . '` WHERE key_id = %s', $keyId));
        return $secret === null ? null : (string) $secret;
    }

    public static function touchPairing(string $keyId): void
    {
        global $wpdb;
        $wpdb->update(self::table('pairings'), ['last_used' => time()], ['key_id' => $keyId]);
    }

    /** @return list<array{key_id: string, device: string, created: int, last_used: int|null, push_until: int}> */
    public static function pairings(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results('SELECT key_id, device, created, last_used, push_until FROM `' . self::table('pairings') . '` ORDER BY created', ARRAY_A);
        return array_map(static function (array $row): array {
            return [
                'key_id'     => (string) $row['key_id'],
                'device'     => (string) $row['device'],
                'created'    => (int) $row['created'],
                'last_used'  => $row['last_used'] === null ? null : (int) $row['last_used'],
                'push_until' => (int) $row['push_until'],
            ];
        }, (array) $rows);
    }

    public static function deletePairing(string $keyId): void
    {
        global $wpdb;
        $wpdb->delete(self::table('pairings'), ['key_id' => $keyId]);
    }

    /** Ende des Push-Fensters als Unix-Zeit; 0 für unbekannte Pairings und nie geöffnete Fenster. */
    public static function pushUntil(string $keyId): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare('SELECT push_until FROM `' . self::table('pairings') . '` WHERE key_id = %s', $keyId));
    }

    public static function setPushUntil(string $keyId, int $until): void
    {
        global $wpdb;
        $wpdb->update(self::table('pairings'), ['push_until' => $until], ['key_id' => $keyId]);
    }

    public static function deviceFor(string $keyId): string
    {
        global $wpdb;
        return (string) $wpdb->get_var($wpdb->prepare('SELECT device FROM `' . self::table('pairings') . '` WHERE key_id = %s', $keyId));
    }

    /**
     * Name des Arbeitsordners unter wp-content. Der zufällige Teil wird einmal pro Site erzeugt:
     * er ist der einzige Schutz der Snapshots auf Servern, die keine .htaccess auswerten.
     */
    public static function pushDirName(): string
    {
        $state = self::getState('push_dir');
        if ($state === null || preg_match('/^[a-f0-9]{16}\z/', (string) ($state['name'] ?? '')) !== 1) {
            self::setState('push_dir', ['name' => bin2hex(random_bytes(8))]);
            $state = self::getState('push_dir');
        }
        return 'wpsync-push-' . (string) ($state['name'] ?? '');
    }

    /** @param array<string, mixed> $row */
    public static function addPush(array $row): bool
    {
        global $wpdb;
        return 1 === $wpdb->insert(self::table('pushes'), $row);
    }

    /** @param array<string, mixed> $fields */
    public static function updatePush(string $pushId, array $fields): void
    {
        global $wpdb;
        $wpdb->update(self::table('pushes'), $fields, ['push_id' => $pushId]);
    }

    /** @return array<string, mixed>|null */
    public static function getPush(string $pushId): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM `' . self::table('pushes') . '` WHERE push_id = %s', $pushId), ARRAY_A);
        return is_array($row) ? self::pushRow($row) : null;
    }

    /**
     * Neueste zuerst.
     *
     * @return list<array<string, mixed>>
     */
    public static function pushes(int $limit): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM `' . self::table('pushes') . '` ORDER BY created DESC, push_id DESC LIMIT %d', $limit), ARRAY_A);
        return array_map([self::class, 'pushRow'], (array) $rows);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function pushRow(array $row): array
    {
        $units = json_decode((string) $row['units'], true);
        return [
            'push_id'   => (string) $row['push_id'],
            'key_id'    => (string) $row['key_id'],
            'device'    => (string) $row['device'],
            'target'    => (string) $row['target'],
            'status'    => (string) $row['status'],
            'forced'    => (bool) $row['forced'],
            'pruned'    => (bool) $row['pruned'],
            'units'     => is_array($units) ? $units : [],
            'created'   => (int) $row['created'],
            'committed' => $row['committed'] === null ? null : (int) $row['committed'],
            'finished'  => $row['finished'] === null ? null : (int) $row['finished'],
        ];
    }

    public static function claimNonce(string $nonce, int $now): bool
    {
        global $wpdb;
        $table = self::table('nonces');
        if (random_int(0, 19) === 0) {
            $wpdb->query($wpdb->prepare('DELETE FROM `' . $table . '` WHERE expires < %d', $now));
        }
        return 1 === (int) $wpdb->query($wpdb->prepare(
            'INSERT IGNORE INTO `' . $table . '` (nonce, expires) VALUES (%s, %d)',
            $nonce,
            $now + 2 * Signature::MAX_SKEW
        ));
    }
}
