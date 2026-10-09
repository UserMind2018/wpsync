<?php
namespace WpSync;

defined('ABSPATH') || exit;

/**
 * Nimmt den DB-Anteil eines Pushs zurück (Spec Content-Push §7.6): in einer Transaktion, nur wenn
 * jede betroffene Zeile noch genau den Abdruck trägt, den der Push hinterlassen hat. Sonst
 * changed_since_push – dann wird nichts zurückgenommen, und der Aufrufer lässt auch Code und
 * Uploads stehen. Stehen alle Zeilen noch im Vorher-Zustand, kam die Transaktion des Pushs nie
 * an, und es ist nichts zu tun.
 */
final class ContentRollback
{
    public const NOTHING = 'nothing';
    public const DONE    = 'rolled_back';

    /**
     * @param string $dir Ordner content im Arbeitsordner des Pushs
     * @return array{state: string, changes: array<string, mixed>|null} changes: was die Nacharbeiten wissen müssen; null, wenn nichts zu tun war
     * @throws ContentException changed_since_push oder content_failed
     */
    public static function run(ContentTarget $target, string $dir): array
    {
        if (!file_exists($dir . '/' . ContentApply::BEFORE)) {
            return ['state' => self::NOTHING, 'changes' => null]; // ohne Vorher-Abbild wurde nie geschrieben
        }
        $before = self::keys($dir . '/' . ContentApply::BEFORE, true);
        if ($before === null) {
            throw new ContentException(ContentException::FAILED, 'Das Vorher-Abbild dieses Pushs ist nicht lesbar – die Inhalte lassen sich nicht zurücknehmen.');
        }
        $after   = self::keys($dir . '/' . ContentApply::AFTER, false);
        $changes = is_array($after) ? json_decode((string) file_get_contents($dir . '/' . ContentApply::AFTER), true)['changes'] ?? null : null;
        $store = $target->store;
        $lost  = null; // [Tabelle, Schlüssel, Rohzustand davor]: bei diesem Schreibzugriff ging die Verbindung verloren
        try {
            return $store->transaction(static function () use ($target, $store, $before, $after, $changes, &$lost): array {
                $byTable = array_fill_keys(ContentState::ORDER, []);
                foreach ($before as $entry) {
                    $byTable[$entry['t']][] = $entry['k'];
                }
                $now = [];
                foreach ($byTable as $table => $keys) {
                    $now[$table] = $keys === [] ? [] : $store->read($table, $keys, true);
                }
                $untouched = true;
                $changed   = [];
                foreach ($before as $i => $entry) {
                    $table   = $entry['t'];
                    $key     = $entry['k'];
                    $current = self::fingerprint($target, $table, $key, $now[$table][$key] ?? null);
                    if ($current !== self::fingerprint($target, $table, $key, $entry['state'])) {
                        $untouched = false;
                    }
                    $pushed = $after === null ? null : ($after[$i] ?? null);
                    if ($pushed === null || $pushed['t'] !== $table || $pushed['k'] !== $key || $current !== ($pushed['h'] ?? 'absent')) {
                        $changed[] = ContentException::key($table, $key);
                    }
                }
                // Was unter Sperre gelesen wurde, gilt nur auf der Verbindung der Transaktion.
                if (!$store->alive()) {
                    throw ContentRepair::lost();
                }
                if ($untouched) {
                    return ['state' => self::NOTHING, 'changes' => null];
                }
                if ($changed !== []) {
                    throw new ContentException(ContentException::CHANGED, 'Seit dem Push auf dem Ziel geändert: ' . count($changed) . ' Zeile(n) – nichts wird zurückgenommen, auch Code und Uploads nicht.', $changed);
                }
                foreach (array_reverse($before) as $entry) {
                    $table = $entry['t'];
                    $key   = $entry['k'];
                    try {
                        if ($entry['state'] === null && isset(ContentState::PK[$table])) {
                            $store->purge($table, $key); // was am eingefügten Objekt hängt, geht mit
                        }
                        // Eine Zeile, die es vor dem Push gab, bekommt nur zurück, was der Push geschrieben hat.
                        $state = $entry['state'];
                        if ($state !== null && !ContentState::isSet($table) && ($now[$table][$key] ?? null) !== null) {
                            $state = ContentState::written($table, $state);
                        }
                        $store->write($table, $key, $state);
                    } catch (ContentException $e) {
                        if (!$store->alive()) {
                            $lost = [$table, $key, $now[$table][$key] ?? null];
                        }
                        throw $e;
                    }
                    if (!$store->alive()) {
                        $lost = [$table, $key, $now[$table][$key] ?? null];
                        throw ContentRepair::lost();
                    }
                }
                return ['state' => self::DONE, 'changes' => is_array($changes) ? $changes : null];
            });
        } catch (ContentException $e) {
            if ($lost !== null) {
                // Der Satz bleibt ganz: der eine Schlüssel geht zurück auf den gepushten Stand.
                throw ContentRepair::repair($target, $lost[0], $lost[1], $lost[2]);
            }
            if ($e->reason() !== ContentException::UNCLEAR) {
                throw $e;
            }
            // Die Verbindung ging im COMMIT verloren: er ist ganz angekommen oder gar nicht.
            $prints = [];
            foreach ($before as $entry) {
                $print    = self::fingerprint($target, $entry['t'], $entry['k'], $entry['state']);
                $prints[] = ['t' => $entry['t'], 'k' => $entry['k'], 'h' => $print === 'absent' ? null : $print];
            }
            if (ContentRepair::settled($target, $prints)) {
                return ['state' => self::DONE, 'changes' => is_array($changes) ? $changes : null];
            }
            throw new ContentException(ContentException::FAILED, 'Die Verbindung zur Datenbank ging beim Abschluss der Transaktion verloren – nichts wurde zurückgenommen.');
        }
    }

    /**
     * Abdruck eines Rohzustands zum Vergleichen: der Hash, 'absent' wenn es den Schlüssel nicht
     * gibt, '!' wenn er sich nicht normalisieren lässt (gleicht dann keinem anderen).
     *
     * @param array<string, mixed>|null $raw
     */
    private static function fingerprint(ContentTarget $target, string $table, string $key, ?array $raw): string
    {
        $record = ContentState::record($target->reader(), $table, $key, $raw);
        if ($record === null) {
            return 'absent';
        }
        return $record['h'] === null ? '!' : (string) $record['h'];
    }

    /**
     * Die Schlüssel einer der beiden Dateien, geprüft. before.json: [{t, k, state}] mit dem
     * Rohzustand dekodiert; after.json: [{t, k, h}].
     *
     * @return list<array<string, mixed>>|null null: nicht lesbar
     */
    private static function keys(string $file, bool $states): ?array
    {
        $data = is_file($file) && !is_link($file) ? json_decode((string) @file_get_contents($file), true) : null;
        if (!is_array($data) || !is_array($data['keys'] ?? null)) {
            return null;
        }
        $out = [];
        foreach ($data['keys'] as $entry) {
            if (!is_array($entry) || !in_array($entry['t'] ?? null, Canon::TABLES, true) || !is_string($entry['k'] ?? null)) {
                return null;
            }
            $row = ['t' => $entry['t'], 'k' => $entry['k']];
            if ($states) {
                try {
                    $row['state'] = ContentState::decode($entry['t'], $entry['state'] ?? null);
                } catch (ContentException $e) {
                    return null;
                }
            } else {
                $h = $entry['h'] ?? null;
                if ($h !== null && (!is_string($h) || preg_match('/^[a-f0-9]{64}\z/', $h) !== 1)) {
                    return null;
                }
                $row['h'] = $h;
            }
            $out[] = $row;
        }
        return $out;
    }
}
