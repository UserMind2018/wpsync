<?php
namespace WpSync;

defined('ABSPATH') || exit;

/**
 * Push-Fenster pro Pairing (Spec Stufe 2, 5.1): ein Administrator öffnet es für eine feste
 * Dauer. Ausserhalb kann auch ein gültiger Schlüssel nichts schreiben.
 */
final class PushWindow
{
    /** Sekunden → Beschriftung in der Admin-Seite. */
    public const DURATIONS = [900 => '15 Minuten', 3600 => '1 Stunde', 28800 => '8 Stunden'];

    public static function until(int $seconds, int $now): int
    {
        if (!isset(self::DURATIONS[$seconds])) {
            throw new \InvalidArgumentException('invalid push window duration');
        }
        return $now + $seconds;
    }

    public static function open(int $until, int $now): bool
    {
        return $until > $now;
    }

    public static function remaining(int $until, int $now): int
    {
        return max(0, $until - $now);
    }

    public static function label(int $until, int $now): string
    {
        if (!self::open($until, $now)) {
            return 'geschlossen';
        }
        return 'offen, noch ' . (int) ceil(self::remaining($until, $now) / 60) . ' Min';
    }

    /** Derselbe Fehler für Push, Rollback und die Staging-Aufrufe, die eins brauchen (Spec 2b S6, U44). */
    public static function closed(): \WP_Error
    {
        return new \WP_Error('wpsync_push_window', 'Das Push-Fenster ist geschlossen – im WP-Admin unter Werkzeuge → wpsync öffnen.', ['status' => 403]);
    }
}
