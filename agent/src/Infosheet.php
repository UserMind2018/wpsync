<?php
namespace WpSync;

defined('ABSPATH') || exit;

/**
 * Führt das Inventar in Häppchen aus (Spec 4.4): per WP-Cron mit 10 s Pause dazwischen oder
 * synchron über /infosheet/refresh, wenn WP-Cron nicht läuft (AC-9). Ein MySQL-Lock verhindert
 * parallele Häppchen von Cron und CLI.
 */
final class Infosheet
{
    public const CRON_STEP  = 'wpsync_infosheet_step';
    public const CRON_DAILY = 'wpsync_infosheet_daily';
    public const SLICE      = 5.0;
    public const MAX_JOB_AGE = 3600;
    private const LOCK      = 'wpsync_infosheet';

    public static function register(): void
    {
        add_action(self::CRON_STEP, static function (): void {
            $status = self::run(self::SLICE);
            if ($status['running']) {
                wp_schedule_single_event(time() + 10, self::CRON_STEP);
            }
        });
        add_action(self::CRON_DAILY, [self::class, 'daily']);
        // Auch nach einem ZIP-Update ohne erneute Aktivierung planen.
        add_action('init', static function (): void {
            if (!wp_next_scheduled(self::CRON_DAILY)) {
                wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_DAILY);
            }
        });
    }

    public static function unschedule(): void
    {
        wp_clear_scheduled_hook(self::CRON_STEP);
        wp_clear_scheduled_hook(self::CRON_DAILY);
    }

    /**
     * Ein Job, der länger als MAX_JOB_AGE läuft, hängt – etwa weil jedes Häppchen abbricht (CR-09).
     *
     * @param array<string, mixed> $job
     */
    public static function stale(array $job, float $now): bool
    {
        return $now - (float) ($job['started'] ?? 0) > self::MAX_JOB_AGE;
    }

    /** Legt eine neue Erhebung an, falls keine läuft oder die laufende hängt. */
    public static function begin(): void
    {
        Store::install();
        $job = Store::getState('infosheet_job');
        if ($job === null || self::stale($job, microtime(true))) {
            Store::setState('infosheet_job', Inventory::initial(microtime(true)));
        }
    }

    /** Täglicher Lauf – nur, wenn überhaupt ein Gerät gekoppelt ist (CR-09). */
    public static function daily(): void
    {
        Store::install();
        if (Store::pairings() !== []) {
            self::start();
        }
    }

    /** Neue Erhebung per WP-Cron (nach dem Pairing, täglich). */
    public static function start(): void
    {
        self::begin();
        if (!wp_next_scheduled(self::CRON_STEP)) {
            wp_schedule_single_event(time(), self::CRON_STEP);
        }
    }

    /**
     * Ein Häppchen von höchstens $seconds. Läuft schon eines, kommt nur der Status zurück.
     *
     * @return array{running: bool, phase: string, done: int, total: int, generated_at: int}
     */
    public static function run(float $seconds): array
    {
        global $wpdb;
        Store::install();
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', self::LOCK)) !== 1) {
            return self::status();
        }
        try {
            $job = Store::getState('infosheet_job');
            if ($job !== null) {
                $inventory = new Inventory(new WpProbe(), new SizeScan(WP_CONTENT_DIR));
                $job       = $inventory->step($job, microtime(true) + $seconds);
                if ($job['phase'] === 'done') {
                    Store::setState('infosheet', $inventory->sheet($job, microtime(true)));
                    Store::setState('infosheet_job', null);
                } else {
                    Store::setState('infosheet_job', $job);
                }
            }
        } finally {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::LOCK));
        }
        return self::status();
    }

    /** @return array{running: bool, phase: string, done: int, total: int, generated_at: int} */
    public static function status(): array
    {
        $job   = Store::getState('infosheet_job');
        $sheet = Store::getState('infosheet');
        return ['running' => $job !== null]
            + Inventory::progress($job)
            + ['generated_at' => $sheet === null ? 0 : (int) $sheet['generated_at']];
    }
}
