<?php
namespace WpSync;

defined('ABSPATH') || defined('WPSYNC_RESCUE') || exit;

/**
 * Ablehnung eines Inhalts-Pakets oder einer Rücknahme (Spec Content-Push §7.2, §7.6). reason()
 * ist der Code, den die CLI als error.reason weitergibt; keys() nennt die betroffenen Zeilen als
 * {table, key} – nie einen Wert. Die Meldung ist für Menschen und enthält ebenfalls keinen Wert.
 */
final class ContentException extends \RuntimeException
{
    public const INVALID          = 'package_invalid';
    public const MISSING          = 'package_missing';
    public const OUTDATED         = 'baseline_outdated';
    public const ORIGIN           = 'origin_mismatch';
    public const TOO_LARGE        = 'package_too_large';
    public const ENGINE           = 'engine_unsupported';
    public const BLOCKED          = 'blocked_row';
    public const LIST_VERSION     = 'list_version_mismatch';
    public const LOCAL_ORIGIN     = 'local_origin_in_package';
    public const PSEUDONYM        = 'pseudonym_in_package';
    public const CORRIDOR         = 'id_outside_corridor';
    public const ID_TAKEN         = 'id_taken';
    /**
     * Die ID eines neuen Objekts ist frei, aber an ihr hängt auf dem Ziel noch etwas (Meta,
     * Zuordnungen, Kommentare, Kinder) – Reste eines früheren Objekts, etwa nach einer Rücknahme
     * ohne WordPress (P3 R15). Sie hingen sich an das neue.
     */
    public const LEFTOVERS        = 'id_has_leftovers';
    public const CONFLICT         = 'conflict';
    public const UNFAITHFUL       = 'row_unfaithful';
    public const DANGLING         = 'dangling_reference';
    public const UPLOAD_MISSING   = 'upload_missing';
    public const AUTHOR           = 'author_unknown';
    public const CHANGED          = 'changed_since_push';
    /**
     * Nur bei der Rücknahme: das Vorher-Abbild des Pushs lässt sich nicht öffnen, wurde verändert
     * oder passt nicht zu dem, was der Push geschrieben hat – nichts wird zurückgenommen.
     */
    public const IMAGE            = 'before_image_invalid';
    /** Ein Wert trägt ein serialisiertes Objekt oder sieht serialisiert aus und lässt sich nicht lesen. */
    public const UNSAFE           = 'unsafe_value';
    /** Was geschrieben würde oder wurde, ergibt nicht den Abdruck der Zeile des Pakets. */
    public const MISMATCH         = 'write_mismatch';
    /** Datenbank oder Dateisystem haben versagt – nichts wurde geschrieben. */
    public const FAILED           = 'content_failed';

    /**
     * Nur zwischen ContentStore::transaction() und seinem Aufrufer: die Verbindung ging im COMMIT
     * verloren, ob er ankam, ist offen. Der Aufrufer sieht nach und macht daraus Erfolg oder
     * content_failed – dieser Grund verlässt den Agent nie.
     */
    public const UNCLEAR          = 'commit_unclear';

    /** Mehr Schlüssel nennt keine Antwort; total sagt, wie viele es sind. */
    public const MAX_KEYS = 200;

    /** @var string */
    private $reason;
    /** @var list<array{table: string, key: string, pattern?: string}> */
    private $keys;
    /** @var array<string, mixed> */
    private $extra;

    /**
     * @param list<array{table: string, key: string, pattern?: string}> $keys
     * @param array<string, mixed>                                      $extra weitere Felder der Antwort (paths, tables, limits)
     */
    public function __construct(string $reason, string $message, array $keys = [], array $extra = [])
    {
        parent::__construct($message);
        $this->reason = $reason;
        $this->keys   = array_values($keys);
        $this->extra  = $extra;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    /** @return list<array{table: string, key: string, pattern?: string}> */
    public function keys(): array
    {
        return $this->keys;
    }

    /** @return array{table: string, key: string, pattern?: string} */
    public static function key(string $table, string $key, string $pattern = ''): array
    {
        return $pattern === '' ? ['table' => $table, 'key' => $key] : ['table' => $table, 'key' => $key, 'pattern' => $pattern];
    }

    /** @return array<string, mixed> wie im Probelauf unter content.error */
    public function toArray(): array
    {
        $out = ['code' => $this->reason, 'message' => $this->getMessage()];
        if ($this->keys !== []) {
            $out['keys']  = array_slice($this->keys, 0, self::MAX_KEYS);
            $out['total'] = max(count($this->keys), (int) ($this->extra['total'] ?? 0));
        }
        return $out + $this->extra;
    }

    /**
     * Umkehr von toArray() – für eine Ablehnung, die erst als Teil einer Antwort entstand.
     *
     * @param array<string, mixed> $error
     */
    public static function fromArray(array $error): self
    {
        $keys = is_array($error['keys'] ?? null) ? $error['keys'] : [];
        unset($error['keys']);
        $reason  = (string) ($error['code'] ?? self::FAILED);
        $message = (string) ($error['message'] ?? '');
        unset($error['code'], $error['message']);
        return new self($reason, $message, $keys, $error);
    }

    /** Als Antwort einer Route: Code wpsync_content_<reason>, die Einzelheiten in den Fehlerdaten. */
    public function toError(): \WP_Error
    {
        $data = $this->toArray();
        unset($data['code'], $data['message']);
        return new \WP_Error('wpsync_content_' . $this->reason, $this->getMessage(), ['status' => $this->status()] + $data);
    }

    public function status(): int
    {
        switch ($this->reason) {
            case self::INVALID:
                return 400;
            case self::TOO_LARGE:
                return 413;
            case self::FAILED:
                return 500;
        }
        return 409;
    }
}
