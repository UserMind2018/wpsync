<?php
namespace WpSync;

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
    private const LOCK      = 'wpsync_infosheet';

    public static function register(): void
    {
        add_action(self::CRON_STEP, static function (): void {
            $status = self::run(self::SLICE);
            if ($status['running']) {
                wp_schedule_single_event(time() + 10, self::CRON_STEP);
            }
        });
        add_action(self::CRON_DAILY, [self::class, 'start']);
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

    /** Legt eine neue Erhebung an, falls keine läuft. */
    public static function begin(): void
    {
        Store::install();
        if (Store::getState('infosheet_job') === null) {
            Store::setState('infosheet_job', Inventory::initial(microtime(true)));
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
