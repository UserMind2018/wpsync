<?php
namespace WpSync;

defined('ABSPATH') || exit;

/**
 * Zugang zur Staging-Kopie (Spec Stufe 2b 5.4, 5.9, T1). Liegt als wp-content/wpsync-staging.json
 * in der Kopie: der Agent auf Live legt Einmal-Tokens an, der Riegel der Kopie löst sie ein und
 * prüft Zugangs-Cookies. Gespeichert werden nur sha256-Hashes. Die Datei wird unverändert in die
 * Kopie kopiert (mu-plugins/wpsync-staging/) und darf nichts anderes aus dem Agent nutzen.
 */
final class StagingAccess
{
    public const FILE         = 'wp-content/wpsync-staging.json';
    public const COOKIE       = 'wpsync_stg';
    public const TOKEN_TTL    = 300;
    public const COOKIE_TTL   = 43200;
    public const EXPIRE_AFTER = 1209600;
    public const TOUCH_EVERY  = 3600;
    private const SECRET_RE   = '/^[a-f0-9]{64}\z/';

    /** @var string */
    private $file;

    public function __construct(string $file)
    {
        $this->file = $file;
    }

    /** Nach create und refresh: alte Links und Cookies gelten nicht mehr. */
    public function init(string $liveUrl, string $uploadsUrl, string $stagingPath, int $copiedAt, int $now): void
    {
        $this->change(static function (array $state) use ($liveUrl, $uploadsUrl, $stagingPath, $copiedAt, $now): array {
            return [
                'live_url'     => $liveUrl,
                'uploads_url'  => $uploadsUrl,
                'staging_path' => $stagingPath,
                'copied_at'    => $copiedAt,
                'last_used'    => $now,
                'locked'       => false,
                'tokens'       => [],
                'cookies'      => [],
            ];
        });
        @chmod($this->file, 0640);
    }

    /**
     * Ohne lesbare Datei gilt die Kopie als gesperrt.
     *
     * @return array{live_url: string, uploads_url: string, staging_path: string, copied_at: int, last_used: int, locked: bool, tokens: array<string, int>, cookies: array<string, int>}
     */
    public function read(): array
    {
        $raw = @file_get_contents($this->file);
        return self::normalize(is_string($raw) ? json_decode($raw, true) : null);
    }

    /** Einmal-Link (T1). Hebt eine Sperre auf und zählt als Nutzung. Liefert das Token im Klartext. */
    public function issueToken(int $now): string
    {
        $token = bin2hex(random_bytes(32));
        $this->change(static function (array $state) use ($token, $now): array {
            $state['tokens']                       = self::fresh($state['tokens'], $now);
            $state['tokens'][hash('sha256', $token)] = $now + self::TOKEN_TTL;
            $state['locked']                       = false;
            $state['last_used']                    = max($state['last_used'], $now);
            return $state;
        });
        return $token;
    }

    /** Löst ein Token genau einmal ein und liefert den Wert des Zugangs-Cookies – oder null. */
    public function redeemToken(string $token, int $now): ?string
    {
        if (preg_match(self::SECRET_RE, $token) !== 1) {
            return null;
        }
        $cookie = null;
        $this->change(static function (array $state) use ($token, $now, &$cookie): array {
            $hash  = hash('sha256', $token);
            $valid = !$state['locked'] && ($state['tokens'][$hash] ?? 0) >= $now;
            unset($state['tokens'][$hash]); // auch ein abgelaufenes Token ist danach verbraucht
            if ($valid) {
                $cookie                                   = bin2hex(random_bytes(32));
                $state['cookies']                         = self::fresh($state['cookies'], $now);
                $state['cookies'][hash('sha256', $cookie)] = $now + self::COOKIE_TTL;
                $state['last_used']                       = max($state['last_used'], $now);
            }
            return $state;
        });
        return $cookie;
    }

    /**
     * Das Token eines Einmal-Links – nur am Einstieg der Kopie (Staging-Pfad mit oder ohne /,
     * index.php). An jeder anderen Stelle gilt die Anfrage als eine ohne Token.
     *
     * @param array<mixed> $query
     */
    public static function loginToken(array $query, string $requestUri, string $stagingPath): ?string
    {
        if (!isset($query['wpsync_login']) || !is_string($query['wpsync_login'])) {
            return null;
        }
        $base = rtrim($stagingPath, '/');
        $path = explode('?', $requestUri, 2)[0];
        if ($base === '' || !in_array($path, [$base, $base . '/', $base . '/index.php'], true)) {
            return null;
        }
        return $query['wpsync_login'];
    }

    /**
     * Prüfung vor WordPress (wp-config.php der Kopie): durch nur mit gültigem Cookie oder mit einem
     * offenen Einmal-Link, den danach der Riegel einlöst. Liest nur, schreibt nichts.
     */
    public function admits(?string $token, string $cookie, int $now): bool
    {
        if ($this->cookieValid($cookie, $now)) {
            return true;
        }
        if ($token === null || preg_match(self::SECRET_RE, $token) !== 1) {
            return false;
        }
        $state = $this->read();
        return !$state['locked'] && ($state['tokens'][hash('sha256', $token)] ?? 0) >= $now;
    }

    public function cookieValid(string $value, int $now): bool
    {
        if (preg_match(self::SECRET_RE, $value) !== 1) {
            return false;
        }
        $state = $this->read();
        return !$state['locked'] && ($state['cookies'][hash('sha256', $value)] ?? 0) >= $now;
    }

    /** Letzte Nutzung, höchstens einmal pro Stunde geschrieben (Riegel, Spec 5.5 Nr. 1). */
    public function touch(int $now): void
    {
        if ($now - $this->read()['last_used'] >= self::TOUCH_EVERY) {
            $this->markUsed($now);
        }
    }

    public function markUsed(int $now): void
    {
        $this->change(static function (array $state) use ($now): array {
            $state['last_used'] = max($state['last_used'], $now);
            return $state;
        });
    }

    /** Verfall (S4): kein Link und kein Cookie gilt mehr. */
    public function lock(): void
    {
        $this->change(static function (array $state): array {
            $state['locked']  = true;
            $state['tokens']  = [];
            $state['cookies'] = [];
            return $state;
        });
    }

    public static function expired(int $lastUsed, int $now): bool
    {
        return $now - $lastUsed > self::EXPIRE_AFTER;
    }

    /** Liest, ändert und schreibt unter Dateisperre – Agent und Riegel schreiben dieselbe Datei. */
    private function change(callable $change): void
    {
        $handle = @fopen($this->file, 'c+');
        if ($handle === false) {
            throw new \RuntimeException('cannot open the staging state file');
        }
        try {
            flock($handle, LOCK_EX);
            $raw   = stream_get_contents($handle);
            $state = $change(self::normalize(is_string($raw) && $raw !== '' ? json_decode($raw, true) : null));
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) json_encode($state));
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @param mixed $raw
     * @return array{live_url: string, uploads_url: string, staging_path: string, copied_at: int, last_used: int, locked: bool, tokens: array<string, int>, cookies: array<string, int>}
     */
    private static function normalize($raw): array
    {
        $raw = is_array($raw) ? $raw : [];
        return [
            'live_url'     => (string) ($raw['live_url'] ?? ''),
            'uploads_url'  => (string) ($raw['uploads_url'] ?? ''),
            'staging_path' => (string) ($raw['staging_path'] ?? ''),
            'copied_at'    => (int) ($raw['copied_at'] ?? 0),
            'last_used'    => (int) ($raw['last_used'] ?? 0),
            'locked'       => (bool) ($raw['locked'] ?? true),
            'tokens'       => self::hashes($raw['tokens'] ?? []),
            'cookies'      => self::hashes($raw['cookies'] ?? []),
        ];
    }

    /**
     * @param mixed $list
     * @return array<string, int>
     */
    private static function hashes($list): array
    {
        $out = [];
        foreach (is_array($list) ? $list : [] as $hash => $expires) {
            if (is_string($hash) && preg_match(self::SECRET_RE, $hash) === 1 && is_int($expires)) {
                $out[$hash] = $expires;
            }
        }
        return $out;
    }

    /**
     * @param array<string, int> $hashes
     * @return array<string, int>
     */
    private static function fresh(array $hashes, int $now): array
    {
        return array_filter($hashes, static function (int $expires) use ($now): bool {
            return $expires >= $now;
        });
    }
}
