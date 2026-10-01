<?php
namespace WpSync;

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
        return [self::table('pairings'), self::table('nonces'), self::table('state')];
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
            last_used INT UNSIGNED NULL
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

    public static function issuePairingCode(): string
    {
        self::install();
        $code = Pairing::newCode();
        self::setState('pairing_code', Pairing::stored($code, time()));
        return $code;
    }

    public static function addPairing(string $keyId, string $secret, string $device): void
    {
        global $wpdb;
        $wpdb->insert(self::table('pairings'), [
            'key_id'  => $keyId,
            'secret'  => $secret,
            'device'  => $device,
            'created' => time(),
        ]);
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

    /** @return list<array{key_id: string, device: string, created: int, last_used: int|null}> */
    public static function pairings(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results('SELECT key_id, device, created, last_used FROM `' . self::table('pairings') . '` ORDER BY created', ARRAY_A);
        return array_map(static function (array $row): array {
            return [
                'key_id'    => (string) $row['key_id'],
                'device'    => (string) $row['device'],
                'created'   => (int) $row['created'],
                'last_used' => $row['last_used'] === null ? null : (int) $row['last_used'],
            ];
        }, (array) $rows);
    }

    public static function deletePairing(string $keyId): void
    {
        global $wpdb;
        $wpdb->delete(self::table('pairings'), ['key_id' => $keyId]);
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
