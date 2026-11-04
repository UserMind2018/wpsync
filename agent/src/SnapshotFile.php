<?php
namespace WpSync;

defined('ABSPATH') || exit;

/**
 * Datei eines Datenbank-Schnappschusses (Spec 0.11.0 D1–D3): der Klartext gzip-komprimiert, der
 * gzip-Strom in Blöcke zu BLOCK Bytes geteilt, jeder Block mit XChaCha20-Poly1305 versiegelt. Ein
 * Block auf der Platte: Nonce (24) | Chiffrat (Länge + 16). Die Zusatzdaten binden jeden Block an
 * Job, Nummer und daran, ob er der letzte ist – vertauschte, fehlende oder abgeschnittene Blöcke
 * öffnen nicht. Der Schlüssel liegt nur in wpsync_state; eine kopierte Datei ist ohne ihn wertlos.
 */
final class SnapshotFile implements SnapshotSink
{
    public const BLOCK = 1048576;

    private const NONCE = 24;
    private const TAG   = 16;
    private const KEY   = 32;

    /** @var resource */
    private $handle;
    /** @var resource|object deflate-Kontext (PHP 7: resource, PHP 8: DeflateContext) */
    private $deflate;
    /** @var resource|object hash-Kontext */
    private $hash;
    /** @var string */
    private $key;
    /** @var string */
    private $jobId;
    /** @var string */
    private $buffer = '';
    /** @var int */
    private $blocks = 0;
    /** @var int */
    private $size = 0;
    /** @var int */
    private $plain = 0;

    /**
     * @param resource        $handle
     * @param resource|object $deflate
     */
    private function __construct($handle, $deflate, string $key, string $jobId)
    {
        $this->handle  = $handle;
        $this->deflate = $deflate;
        $this->hash    = hash_init('sha256');
        $this->key     = $key;
        $this->jobId   = $jobId;
    }

    public static function available(): bool
    {
        return function_exists('deflate_init') && function_exists('deflate_add')
            && function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')
            && function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_decrypt');
    }

    /**
     * Legt die Datei neu an (nie über eine bestehende, nie über einen Symlink: fopen 'x'), Rechte 0600.
     *
     * @throws \InvalidArgumentException bei einem Schlüssel, der nicht 32 Byte lang ist
     * @throws \RuntimeException
     */
    public static function create(string $path, string $key, string $jobId): self
    {
        if (strlen($key) !== self::KEY) {
            throw new \InvalidArgumentException('snapshot key must be 32 bytes');
        }
        $handle = @fopen($path, 'xb');
        if ($handle === false) {
            throw new \RuntimeException('snapshot file not creatable');
        }
        @chmod($path, 0600);
        $deflate = deflate_init(ZLIB_ENCODING_GZIP, ['level' => 6]);
        if ($deflate === false) {
            fclose($handle);
            @unlink($path);
            throw new \RuntimeException('deflate_init failed');
        }
        return new self($handle, $deflate, $key, $jobId);
    }

    public function write(string $data): void
    {
        $this->plain += strlen($data);
        $out = deflate_add($this->deflate, $data, ZLIB_NO_FLUSH);
        if ($out === false) {
            throw new \RuntimeException('deflate failed');
        }
        $this->push($out);
    }

    /** Bytes Klartext bisher (für den Fortschritt). */
    public function plainBytes(): int
    {
        return $this->plain;
    }

    /**
     * Schliesst gzip und Datei.
     *
     * @return array{blocks: int, size: int, sha256: string, plain: int}
     */
    public function finish(): array
    {
        $out = deflate_add($this->deflate, '', ZLIB_FINISH);
        if ($out === false) {
            throw new \RuntimeException('deflate failed');
        }
        $this->push($out);
        // Der Rest (1 … BLOCK Bytes – gzip schreibt immer einen Abschluss) ist der letzte Block.
        $this->seal($this->buffer, true);
        $this->buffer = '';
        if (!fclose($this->handle)) {
            throw new \RuntimeException('snapshot file not closed');
        }
        return ['blocks' => $this->blocks, 'size' => $this->size, 'sha256' => hash_final($this->hash), 'plain' => $this->plain];
    }

    /** Bricht ab, ohne die Datei zu löschen (das macht Snapshot). */
    public function abort(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
    }

    /** Klartext (gzip) des Blocks $index von $total; null, wenn er fehlt oder nicht öffnet. */
    public static function block(string $path, string $key, string $jobId, int $index, int $total): ?string
    {
        if ($index < 0 || $index >= $total || strlen($key) !== self::KEY) {
            return null;
        }
        $record = self::NONCE + self::BLOCK + self::TAG;
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }
        $data = false;
        if (fseek($handle, $index * $record) === 0) {
            $data = fread($handle, $record);
        }
        fclose($handle);
        if (!is_string($data) || strlen($data) <= self::NONCE + self::TAG) {
            return null;
        }
        $last = $index === $total - 1;
        if (!$last && strlen($data) !== $record) {
            return null;
        }
        try {
            $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                substr($data, self::NONCE),
                self::ad($jobId, $index, $last),
                substr($data, 0, self::NONCE),
                $key
            );
        } catch (\Throwable $e) {
            return null;
        }
        return is_string($plain) ? $plain : null;
    }

    private function push(string $data): void
    {
        if ($data === '') {
            return;
        }
        hash_update($this->hash, $data);
        $this->size   += strlen($data);
        $this->buffer .= $data;
        // Ein voller Block bleibt im Puffer, bis feststeht, dass noch etwas kommt: erst dann ist er nicht der letzte.
        while (strlen($this->buffer) > self::BLOCK) {
            $this->seal(substr($this->buffer, 0, self::BLOCK), false);
            $this->buffer = (string) substr($this->buffer, self::BLOCK);
        }
    }

    private function seal(string $plain, bool $last): void
    {
        $nonce  = random_bytes(self::NONCE);
        $sealed = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plain, self::ad($this->jobId, $this->blocks, $last), $nonce, $this->key);
        $record = $nonce . $sealed;
        if (fwrite($this->handle, $record) !== strlen($record)) {
            throw new \RuntimeException('snapshot write failed');
        }
        $this->blocks++;
    }

    private static function ad(string $jobId, int $index, bool $last): string
    {
        return 'wpsync-snapshot-v1|' . $jobId . '|' . $index . '|' . ($last ? 'last' : 'more');
    }
}
