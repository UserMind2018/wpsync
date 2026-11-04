<?php
namespace WpSync;

defined('ABSPATH') || exit;

/**
 * Nachtbericht: gleicht die installierte Software mit dem letzten Schnappschuss
 * ab, schreibt die Unterschiede mit ihrem Auslöser ins Protokoll und liefert den Bericht für
 * /activity. Kein Abgleich bricht ein Update ab: Hooks laufen über safely().
 */
final class Activity
{
    public const CRON          = 'wpsync_activity_check';
    public const SNAPSHOT      = 'activity_snapshot';
    public const SINCE         = 'activity_since';
    public const MAX_RANGE     = 31 * 86400;
    public const DEFAULT_LIMIT = 200;

    /** @var bool pre_auto_update lief in diesem Request */
    private static $auto = false;
    /** @var string|null Push, dessen Commit oder Rücknahme gerade läuft ('' = ID unbrauchbar) */
    private static $push = null;

    public static function register(): void
    {
        add_action('pre_auto_update', [self::class, 'markAuto'], 1, 0);
        foreach (['upgrader_process_complete', 'update_option_active_plugins', 'deleted_plugin', 'deleted_theme', 'switch_theme'] as $hook) {
            add_action($hook, [self::class, 'onChange'], 99, 0);
        }
        // Vor _redirect_to_about_wordpress (Priorität 10), das im WP-Admin mit exit endet.
        add_action('_core_updated_successfully', [self::class, 'onChange'], 1, 0);
        add_action('automatic_updates_complete', [self::class, 'onAutoUpdates'], 99, 1);
        add_action(self::CRON, [self::class, 'onCron']);
        // Auch nach einem ZIP-Update ohne erneute Aktivierung planen (wie Infosheet).
        add_action('init', static function (): void {
            if (!wp_next_scheduled(self::CRON)) {
                wp_schedule_event(time() + 300, 'hourly', self::CRON);
            }
        });
    }

    public static function unschedule(): void
    {
        wp_clear_scheduled_hook(self::CRON);
    }

    public static function markAuto(): void
    {
        self::$auto = true;
    }

    /** @param mixed $results */
    public static function onAutoUpdates($results): void
    {
        self::safely(static function () use ($results): void {
            $now = time();
            self::reconcile(new WpActivityProbe(), ['auto', null, ''], $now);
            $events = ActivityDiff::results(self::normalize($results));
            if ($events !== []) {
                ActivityLog::add($events, $now, 'auto', null, '');
            }
        });
    }

    public static function onCron(): void
    {
        self::safely(static function (): void {
            $now = time();
            self::reconcile(new WpActivityProbe(), ['detected', null, ''], $now);
            ActivityLog::prune($now - ActivityLog::KEEP_DAYS * 86400);
        });
    }

    /**
     * @param array{0: string, 1: int|null, 2: string} $source Auslöser, Benutzer, Detail
     * @return int geschriebene Ereignisse; 0 auch, wenn die Sperre nicht frei wurde
     */
    public static function reconcile(ActivityProbe $probe, array $source, int $now): int
    {
        global $wpdb;
        $lock = Store::lockName('activity');
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $lock)) !== 1) {
            return 0;
        }
        try {
            $new = $probe->snapshot();
            $old = Store::getState(self::SNAPSHOT);
            if ($old === null) {
                Store::setState(self::SNAPSHOT, $new);
                Store::setState(self::SINCE, ['at' => $now]);
                return 0;
            }
            $events = ActivityDiff::diff($old, $new);
            if ($events === []) {
                return 0;
            }
            // Erst die Ereignisse, dann der Schnappschuss: fällt das zweite aus, meldet der nächste
            // Abgleich doppelt statt gar nicht.
            $written = ActivityLog::add($events, $now, $source[0], $source[1], $source[2]);
            if ($written === 0) {
                // Nichts ging ins Protokoll (Tabelle fehlt, Datenbank gestört): der Schnappschuss bleibt,
                // der nächste Abgleich holt die Ereignisse nach – ohne Doppelte, es wurde ja nichts geschrieben.
                return 0;
            }
            Store::setState(self::SNAPSHOT, $new);
            return $written;
        } finally {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    /**
     * @param array<string, mixed> $params since, until, limit (Unix-Sekunden, Anzahl)
     * @return array<string, mixed>|\WP_Error
     */
    public static function report(array $params, ActivityProbe $probe, int $now)
    {
        $until = min(isset($params['until']) ? (int) $params['until'] : $now, $now);
        $since = isset($params['since']) ? (int) $params['since'] : $until - 86400;
        $limit = isset($params['limit']) ? (int) $params['limit'] : self::DEFAULT_LIMIT;
        if ($since < 0 || $since > $until || $until - $since > self::MAX_RANGE || $limit < 1 || $limit > ActivityLog::MAX_LIMIT) {
            return new \WP_Error('wpsync_activity_range', 'since/until/limit ausserhalb der Grenzen (höchstens 31 Tage, 1–500 Ereignisse).', ['status' => 400]);
        }
        self::reconcile($probe, ['detected', null, ''], $now);
        $rows   = ActivityLog::between($since, $until, $limit + 1);
        $events = [];
        $logins = [];
        foreach (array_slice($rows, 0, $limit) as $row) {
            $user = null;
            if ($row['user_id'] !== null) {
                $id = $row['user_id'];
                if (!array_key_exists($id, $logins)) {
                    $logins[$id] = $probe->userLogin($id);
                }
                $user = $logins[$id] === null ? ['id' => $id] : ['id' => $id, 'login' => ActivityDiff::clip($logins[$id])];
            }
            $events[] = [
                'at'     => $row['at'],
                'kind'   => $row['kind'],
                'type'   => $row['type'],
                'slug'   => $row['slug'],
                'name'   => $row['name'],
                'from'   => $row['from'],
                'to'     => $row['to'],
                'source' => $row['source'],
                'user'   => $user,
                'detail' => $row['detail'],
            ];
        }
        $snapshot = Store::getState(self::SNAPSHOT) ?? [];
        $begun    = Store::getState(self::SINCE);
        return [
            'since'         => $since,
            'until'         => $until,
            'checked'       => $now,
            'log_since'     => isset($begun['at']) ? (int) $begun['at'] : null,
            'agent_version' => WPSYNC_VERSION,
            'core'          => (string) ($snapshot['core'] ?? ''),
            'events'        => $events,
            'truncated'     => count($rows) > $limit,
            'auto_updates'  => $probe->autoUpdates(),
        ];
    }

    /**
     * Commit oder Rücknahme eines Pushs: was dabei an der Software geändert wird, trägt die Push-ID.
     * Danach ein Abgleich – der Tausch der Ordner löst keinen Hook von WordPress aus.
     *
     * @param mixed             $pushId ungeprüft aus der Anfrage; nur ein passender Text wird zur Kennung
     * @param callable(): mixed $run
     * @return mixed was $run liefert
     */
    public static function attributed($pushId, callable $run)
    {
        self::$push = is_string($pushId) && preg_match('/^[A-Za-z0-9_-]{1,32}\z/', $pushId) === 1 ? $pushId : '';
        try {
            return $run();
        } finally {
            self::onChange();
            self::$push = null;
        }
    }

    /** @return array{0: string, 1: int|null, 2: string} Auslöser, Benutzer, Detail */
    public static function source(): array
    {
        if (self::$push !== null) {
            return ['wpsync', null, self::$push === '' ? '' : 'push:' . self::$push];
        }
        if (self::$auto) {
            return ['auto', null, ''];
        }
        if (defined('WP_CLI') && WP_CLI) {
            return ['cli', null, ''];
        }
        $user = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        return $user > 0 ? ['user', $user, ''] : ['other', null, ''];
    }

    /**
     * Ergebnis von automatic_updates_complete (WP_Automatic_Updater::run) als flache Liste.
     *
     * @param mixed $results
     * @return list<array{type: string, slug: string, name: string, ok: bool, error: string}>
     */
    public static function normalize($results): array
    {
        $out = [];
        foreach ((array) $results as $type => $list) {
            foreach ((array) $list as $r) {
                if (!is_object($r)) {
                    continue;
                }
                $item   = isset($r->item) && is_object($r->item) ? $r->item : new \stdClass();
                $result = $r->result ?? null;
                $ok     = $result === true || (is_string($result) && $result !== '');
                $slug   = '';
                foreach (['slug', 'theme', 'language', 'current'] as $field) {
                    if (isset($item->$field) && is_scalar($item->$field) && (string) $item->$field !== '') {
                        $slug = (string) $item->$field;
                        break;
                    }
                }
                $out[] = [
                    'type'  => (string) $type,
                    'slug'  => $type === 'core' ? 'wordpress' : $slug,
                    'name'  => isset($r->name) && is_scalar($r->name) ? (string) $r->name : $slug,
                    'ok'    => $ok,
                    'error' => $ok ? '' : (is_object($result) && method_exists($result, 'get_error_code') ? (string) $result->get_error_code() : 'failed'),
                ];
            }
        }
        return $out;
    }

    public static function onChange(): void
    {
        self::safely(static function (): void {
            self::reconcile(new WpActivityProbe(), self::source(), time());
        });
    }

    /**
     * Läuft einen Hook-Aufruf so, dass weder eine Ausnahme noch ein Datenbankfehler das Update, den
     * WP-Admin oder die Antwort erreicht. Ins Protokoll nur der Klassenname.
     */
    private static function safely(callable $work): void
    {
        global $wpdb;
        try {
            ContentSql::silent($wpdb, static function () use ($work): void {
                // Nach einem ZIP-Update ohne erneute Aktivierung fehlt die Tabelle, bis eine Kopplung
                // sich meldet; ein Hook davor würde sonst ins Leere schreiben.
                Store::install();
                $work();
            });
        } catch (\Throwable $e) {
            error_log('wpsync: activity check skipped (' . get_class($e) . ')');
        }
    }
}
