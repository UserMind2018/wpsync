<?php
namespace WpSync;

defined('ABSPATH') || exit;

/**
 * Nimmt den DB-Anteil eines Pushs zurück (Spec Content-Push §7.6): in einer Transaktion, nur wenn
 * jede betroffene Zeile noch genau den Abdruck trägt, den der Push hinterlassen hat, und an den
 * eingefügten Objekten nichts hängt, was nicht vom Push stammt (grown()). Sonst
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
     * @throws ContentException changed_since_push, before_image_invalid, engine_unsupported oder content_failed
     */
    public static function run(ContentTarget $target, string $dir): array
    {
        // Beide Abbilder müssen unverändert die sein, die der Push abgelegt hat (ContentImage) – und
        // zueinander passen: zurückgeschrieben wird nur ein Schlüssel, den der Push geschrieben hat.
        $image = ContentImage::get($dir, ContentApply::BEFORE);
        if ($image === null) {
            return ['state' => self::NOTHING, 'changes' => null]; // ohne Vorher-Abbild wurde nie geschrieben
        }
        $before = self::keys($image, true);
        $pushed = ContentImage::get($dir, ContentApply::AFTER);
        $after  = $pushed === null ? null : self::keys($pushed, false);
        if ($before === null || ($pushed !== null && $after === null)) {
            throw ContentImage::invalid();
        }
        if ($after !== null) {
            if (count($after) !== count($before)) {
                throw ContentImage::invalid();
            }
            foreach ($before as $i => $entry) {
                if ($after[$i]['t'] !== $entry['t'] || $after[$i]['k'] !== $entry['k']) {
                    throw ContentImage::invalid();
                }
            }
        }
        $changes = $pushed['changes'] ?? null;
        unset($image, $pushed);
        // Ohne InnoDB gäbe es keine Transaktion – auch nicht, wenn die Tabelle erst seit dem Push eine andere Engine hat.
        ContentCheck::innodb($target->store);
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
                    // '!' gleicht keinem Stand, auch nicht einem zweiten '!'.
                    if ($current === '!' || $current !== self::fingerprint($target, $table, $key, $entry['state'])) {
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
                // Was nach dem Push an einem eingefügten Objekt entstand und nicht vom Push stammt, löschte
                // purge() gleich mit – Inhalte, die niemand zurücknehmen wollte (M3).
                $grown = self::grown($store, $before, $now);
                if (!$store->alive()) {
                    throw ContentRepair::lost();
                }
                if ($grown !== []) {
                    throw new ContentException(ContentException::CHANGED, 'Seit dem Push kam an eingefügten Objekten etwas dazu (Meta, Zuordnungen, Kommentare, Kinder): ' . count($grown) . ' Stelle(n) – nichts wird zurückgenommen, auch Code und Uploads nicht.', $grown);
                }
                // Zuerst geht, was an den eingefügten Objekten hängt: was der Push dort geschrieben hat und
                // die Meta der festen Sperrliste, die WordPress selbst anlegt (_edit_lock …).
                // Lag an derselben ID schon vor dem Push etwas (verwaiste Meta oder Zuordnungen eines
                // früher gelöschten Objekts), bringt es das Vorher-Abbild danach zurück.
                foreach (array_reverse($before) as $entry) {
                    $table = $entry['t'];
                    $key   = $entry['k'];
                    if ($entry['state'] !== null || !isset(ContentState::PK[$table])) {
                        continue;
                    }
                    try {
                        $store->purge($table, $key);
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
                foreach (array_reverse($before) as $entry) {
                    $table = $entry['t'];
                    $key   = $entry['k'];
                    try {
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
     * Was an den vom Push eingefügten Beiträgen, Termen und term_taxonomy-Zeilen hängt, ohne dass
     * der Push es geschrieben hat (M3): Meta-Schlüssel, Zuordnungen – an einer eingefügten
     * term_taxonomy die fremder Objekte –, Kommentare, Revisionen und Kindbeiträge, weitere
     * Taxonomien eines Terms, Kind-Terme. Meta der festen Sperrliste (ContentLists::systemMeta())
     * zählt nicht. Kommentare stehen als {table: "comments", key: "<post-id>"}.
     *
     * @param list<array<string, mixed>>                              $before Schlüssel des Pushs mit dem Rohzustand davor
     * @param array<string, array<string, array<string, mixed>|null>> $now    Rohzustand jetzt
     * @return list<array{table: string, key: string}>
     * @throws ContentException
     */
    private static function grown(ContentStore $store, array $before, array $now): array
    {
        $written  = [];
        $inserted = [];
        foreach ($before as $entry) {
            $written[$entry['t']][$entry['k']] = true;
            if ($entry['state'] === null && isset(ContentState::PK[$entry['t']]) && ($now[$entry['t']][$entry['k']] ?? null) !== null) {
                $inserted[$entry['t']][] = $entry['k'];
            }
        }
        $out = [];
        foreach ($inserted as $table => $ids) {
            $meta  = $table === 'posts' ? 'postmeta' : 'termmeta';
            $child = $table === 'posts' ? 'posts' : 'term_taxonomy';
            foreach ($store->attached($table, $ids, true) as $id => $have) {
                $id = (string) $id;
                foreach ($have['meta'] as $name) {
                    $pair = Canon::pairKey($id, (string) $name);
                    if (!isset($written[$meta][$pair]) && !ContentLists::systemMeta((string) $name)) {
                        $out[$meta . "\0\0" . $pair] = ContentException::key($meta, $pair);
                    }
                }
                foreach ($have['relations'] as $pair) {
                    if (!isset($written['term_relationships'][$pair])) {
                        $out["term_relationships\0\0" . $pair] = ContentException::key('term_relationships', (string) $pair);
                    }
                }
                if ($have['comments'] > 0) {
                    $out["comments\0\0" . $id] = ContentException::key('comments', $id);
                }
                foreach ($have['children'] as $other) {
                    if (!isset($written[$child][$other])) {
                        $out[$child . "\0\0" . $other] = ContentException::key($child, (string) $other);
                    }
                }
            }
        }
        return array_values($out);
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
     * Die Schlüssel eines der beiden Abbilder, geprüft: nur die sieben Inhaltstabellen, jeder
     * Schlüssel in der Form seiner Tabelle (wie im Paket), keiner doppelt. before.json:
     * [{t, k, state}] mit dem Rohzustand dekodiert; after.json: [{t, k, h}].
     *
     * @param array<string, mixed> $data aus ContentImage::get()
     * @return list<array<string, mixed>>|null null: nicht die Form eines Abbilds
     */
    private static function keys(array $data, bool $states): ?array
    {
        if (!is_array($data['keys'] ?? null) || count($data['keys']) > 4 * ContentPackage::MAX_ROWS) {
            return null;
        }
        $out  = [];
        $seen = [];
        foreach ($data['keys'] as $entry) {
            if (!is_array($entry) || !in_array($entry['t'] ?? null, Canon::TABLES, true) || !is_string($entry['k'] ?? null) || !self::validKey($entry['t'], $entry['k'])) {
                return null;
            }
            if (isset($seen[$entry['t'] . "\0\0" . $entry['k']])) {
                return null;
            }
            $seen[$entry['t'] . "\0\0" . $entry['k']] = true;
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

    /** Hat der Schlüssel die Form seiner Tabelle – eine reine ID, ein Paar <ID>\0<Name>, ein Optionsname? */
    private static function validKey(string $table, string $key): bool
    {
        if (isset(ContentState::PK[$table])) {
            return preg_match(ContentLists::OBJECT_ID, $key) === 1;
        }
        if ($table === 'options') {
            return $key !== '' && strlen($key) <= 191;
        }
        list($object, $name) = ContentState::split($key);
        return preg_match(ContentLists::OBJECT_ID, $object) === 1 && $name !== '' && strlen($name) <= 255;
    }
}
