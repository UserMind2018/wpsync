<?php
namespace WpSync;

defined('ABSPATH') || exit;

/**
 * Was bleibt, wenn eine Transaktion des Inhaltskanals ihre Verbindung verliert (ContentStore::alive()):
 * der Server hat alles verworfen, was sie geschrieben hat – höchstens der eine Schreibzugriff, bei
 * dem es auffiel, lief auf der neuen Verbindung für sich. repair() bringt genau diesen Schlüssel
 * auf den Stand davor zurück, in einer eigenen Transaktion und mit Rücklesen. settled() sieht nach
 * einem COMMIT mit offenem Ausgang nach, welcher Stand in der Datenbank steht.
 */
final class ContentRepair
{
    private const LOST = 'Die Verbindung zur Datenbank ging während der Transaktion verloren – nichts wurde übernommen.';

    /** Die Transaktion lebt nicht mehr, bevor etwas geschrieben wurde: nichts zu tun, nur abbrechen. */
    public static function lost(): ContentException
    {
        return new ContentException(ContentException::FAILED, self::LOST);
    }

    /**
     * @param array<string, mixed>|null $raw Rohzustand des Schlüssels vor dem verlorenen Schreibzugriff; null: es gab ihn nicht
     * @return ContentException content_failed – mit dem Schlüssel und unrestored, wenn er sich nicht zurückschreiben liess
     */
    public static function repair(ContentTarget $target, string $table, string $key, ?array $raw): ContentException
    {
        $store = $target->store;
        try {
            // Schrieb die neue Verbindung gar nicht (ContentSql bindet jede Anweisung an die Marke), steht er schon so.
            if (!self::same($target, $table, $key, $store->read($table, [$key], false)[$key] ?? null, $raw)) {
                $store->transaction(static function () use ($target, $store, $table, $key, $raw): void {
                    $store->write($table, $key, $raw);
                    if (!$store->alive() || !self::same($target, $table, $key, $store->read($table, [$key], false)[$key] ?? null, $raw)) {
                        throw new ContentException(ContentException::FAILED, 'repair failed');
                    }
                });
            }
        } catch (\Throwable $e) {
            return new ContentException(
                ContentException::FAILED,
                'Die Verbindung zur Datenbank ging während der Transaktion verloren, und eine Zeile liess sich nicht auf ihren Stand davor zurücksetzen – bitte auf der Site prüfen.',
                [ContentException::key($table, $key)],
                ['unrestored' => true]
            );
        }
        return self::lost();
    }

    /**
     * Stehen alle Schlüssel so in der Datenbank? Ohne Sperre, ausserhalb einer Transaktion.
     *
     * @param list<array{t: string, k: string, h: string|null}> $prints Abdruck je Schlüssel; null: es gibt ihn nicht
     * @throws ContentException
     */
    public static function settled(ContentTarget $target, array $prints): bool
    {
        $keys = [];
        foreach ($prints as $entry) {
            $keys[$entry['t']][] = $entry['k'];
        }
        $now = [];
        foreach ($keys as $table => $list) {
            $now[$table] = $target->store->read($table, $list, false);
        }
        foreach ($prints as $entry) {
            $record = ContentState::record($target->reader(), $entry['t'], $entry['k'], $now[$entry['t']][$entry['k']] ?? null);
            if (($record === null ? null : ($record['h'] ?? '!')) !== $entry['h']) {
                return false;
            }
        }
        return true;
    }

    /**
     * Derselbe Stand? Am Abdruck gemessen; lässt sich einer nicht rechnen, an den rohen Werten.
     *
     * @param array<string, mixed>|null $a
     * @param array<string, mixed>|null $b
     */
    private static function same(ContentTarget $target, string $table, string $key, ?array $a, ?array $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }
        $x = ContentState::record($target->reader(), $table, $key, $a);
        $y = ContentState::record($target->reader(), $table, $key, $b);
        if (isset($x['h'], $y['h'])) {
            return $x['h'] === $y['h'];
        }
        if (ContentState::isSet($table)) {
            $a = array_map('strval', (array) ($a['values'] ?? []));
            $b = array_map('strval', (array) ($b['values'] ?? []));
            sort($a, SORT_STRING);
            sort($b, SORT_STRING);
        }
        return $a == $b;
    }
}
