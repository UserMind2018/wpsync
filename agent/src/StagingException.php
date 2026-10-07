<?php
namespace WpSync;

defined('ABSPATH') || exit;

/**
 * Abbruch eines Staging-Schritts (Spec Stufe 2b 5.2). reason() steuert Aufräumen und Exit-Code
 * der CLI: guard (Leitplanke 2 verletzt), disk_full, staging_unsupported, failed.
 */
final class StagingException extends \RuntimeException
{
    public const GUARD       = 'guard';
    public const SPACE       = 'disk_full';
    public const UNSUPPORTED = 'staging_unsupported';
    public const FAILED      = 'failed';

    /** @var string */
    private $reason;

    public function __construct(string $reason, string $message)
    {
        parent::__construct($message);
        $this->reason = $reason;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public static function guard(string $message): self
    {
        return new self(self::GUARD, $message);
    }

    public static function space(string $message): self
    {
        return new self(self::SPACE, $message);
    }

    public static function unsupported(string $message): self
    {
        return new self(self::UNSUPPORTED, $message);
    }

    public static function failed(string $message): self
    {
        return new self(self::FAILED, $message);
    }
}
