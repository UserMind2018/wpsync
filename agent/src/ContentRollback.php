<?php
namespace WpSync;

defined('ABSPATH') || defined('WPSYNC_RESCUE') || exit;

/**
 * Nimmt den DB-Anteil eines Pushs zurück (Spec Content-Push §7.6): in einer Transaktion, nur wenn
 * jede betroffene Zeile noch genau den Abdruck trägt, den der Push hinterlassen hat, und an den
 * eingefügten Objekten nichts hängt, was nicht vom Push stammt (grown()). Sonst
 * changed_since_push – dann wird nichts zurückgenommen, und der Aufrufer lässt auch Code und
 * Uploads stehen. Stehen alle Zeilen noch im Vorher-Zustand, kam die Transaktion des Pushs nie
 * an, und es ist nichts zu tun.
 *
 * Dieselbe Rücknahme läuft ohne WordPress in rescue.php (Spec Content-Push P3 R4). Dort – und nur
 * dort – gilt der Schalter $leave (R15): was an eingefügten Objekten hängt und nicht vom Push
 * stammt, lehnt die Rücknahme dann nicht ab; es bleibt stehen (verwaist) und wird genannt. Der
 * Abdruckvergleich bleibt in beiden Fällen hart.
 *
 * Für genau eine Zeile gilt statt des Abdrucks ein Delta (Spec Content-Push P4 A8, A21, §8.3):
 * options/active_plugins. Die Liste ändert jeder, der im WP-Admin ein Plugin schaltet – ein harter
 * Abdruck sperrte die Rücknahme des ganzen Satzes nach der ersten fremden Änderung. Das
 * Vorher-Abbild nennt deshalb nicht den Wert, sondern was der Push hinzugefügt (plugins.added) und
 * was er gestrichen hat (plugins.removed): gestrichen wird, was davon hinzugefügt wurde und noch in
 * der Liste steht; zurück kommt, was gestrichen wurde und noch fehlt. Sonst wird an der Liste
 * nichts angefasst, kein Wert kommt aus dem Abbild zurück, und kein Hook läuft (A17).
 */
final class ContentRollback
{
    public const NOTHING = 'nothing';
    public const DONE    = 'rolled_back';

    /**
     * @param string $dir   Ordner content im Arbeitsordner des Pushs
     * @param bool   $leave nur für rescue.php (R15): Fremdes an eingefügten Objekten stehen lassen und in left
     *                      nennen, statt abzulehnen. Gelöscht und überschrieben wird dann nur, was der Push
     *                      selbst geschrieben hat – purge() läuft nicht. Unter WordPress immer false (D31)
     * @return array{state: string, changes: array<string, mixed>|null, left?: list<array{table: string, key: string}>, plugins?: array{deactivated: list<string>, reactivated: list<string>}}
     *         changes: was die Nacharbeiten wissen müssen; null, wenn nichts zu tun war. left nur mit $leave.
     *         plugins nur, wenn der Push einen Plugin-Zustand hatte: was diese Rücknahme an der Liste geändert hat
     * @throws ContentException changed_since_push, before_image_invalid, engine_unsupported oder content_failed
     */
    public static function run(ContentTarget $target, string $dir, bool $leave = false): array
    {
        $none = ['state' => self::NOTHING, 'changes' => null] + ($leave ? ['left' => []] : []);
        // Beide Abbilder müssen unverändert die sein, die der Push abgelegt hat (ContentImage) – und
        // zueinander passen: zurückgeschrieben wird nur ein Schlüssel, den der Push geschrieben hat.
        $image = ContentImage::get($dir, ContentImage::BEFORE);
        if ($image === null) {
            return $none; // ohne Vorher-Abbild wurde nie geschrieben
        }
        $before = self::keys($image, true);
        // Der Plugin-Zustand des Pushs (P4 §8.3 Nr. 1): was er der Liste hinzugefügt und was er gestrichen
        // hat – aus demselben authentisierten Abbild, in fester Form. Ohne das Feld hatte der Push keinen.
        $switched = array_key_exists('plugins', $image);
        $delta    = ContentPlugins::delta($image['plugins'] ?? null);
        $pushed   = ContentImage::get($dir, ContentImage::AFTER);
        $after    = $pushed === null ? null : self::keys($pushed, false);
        if ($before === null || $delta === null || ($pushed !== null && $after === null)) {
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
        $undo = ['deactivated' => [], 'reactivated' => []]; // was diese Rücknahme an der Liste ändert
        if ($switched) {
            $none['plugins'] = $undo;
        }
        // Ohne InnoDB gäbe es keine Transaktion – auch nicht, wenn die Tabelle erst seit dem Push eine andere Engine hat.
        ContentState::innodb($target->store);
        $store = $target->store;
        $lost  = null; // [Tabelle, Schlüssel, Rohzustand davor]: bei diesem Schreibzugriff ging die Verbindung verloren
        $left  = [];   // mit $leave: was an eingefügten Objekten stehen bleibt
        try {
            return $store->transaction(static function () use ($target, $store, $before, $after, $changes, $leave, $delta, $switched, $none, &$lost, &$left, &$undo): array {
                $byTable = array_fill_keys(ContentState::ORDER, []);
                foreach ($before as $entry) {
                    $byTable[$entry['t']][] = $entry['k'];
                }
                $now = [];
                foreach ($byTable as $table => $keys) {
                    $now[$table] = $keys === [] ? [] : $store->read($table, $keys, true);
                }
                $untouched = true; // jede Zeile des Pakets steht im Vorher-Zustand (auch: der Push hatte keine)
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
                // Die Liste der aktiven Plugins: unter Sperre, nach den Zeilen – in derselben Reihenfolge wie im
                // Commit. Gelesen wird sie nur, wenn der Push an ihr etwas geändert hat.
                $option = null;
                $list   = [];
                if ($delta['added'] !== [] || $delta['removed'] !== []) {
                    $option = $store->read('options', [ContentPlugins::OPTION], true)[ContentPlugins::OPTION] ?? null;
                    $have   = $option === null ? null : ContentPlugins::parse($option['option_value'] ?? null);
                    if ($have === null) {
                        // Nicht lesbar ist der einzige Stand der Liste, den die Rücknahme nicht deuten kann (§8.3 Nr. 3).
                        throw new ContentException(
                            ContentException::CHANGED,
                            'Die Liste der aktiven Plugins des Ziels ist nicht lesbar – nichts wird zurückgenommen, auch Code und Uploads nicht.',
                            [ContentException::key('options', ContentPlugins::OPTION)]
                        );
                    }
                    $list = $have;
                    $undo = ContentPlugins::undo($list, $delta);
                }
                // Was unter Sperre gelesen wurde, gilt nur auf der Verbindung der Transaktion.
                if (!$store->alive()) {
                    throw ContentRepair::lost();
                }
                $switch = $undo['deactivated'] !== [] || $undo['reactivated'] !== [];
                if ($untouched && !$switch) {
                    return $none; // „nichts zu tun“ gilt nur, wenn Zeilen UND Liste im Stand vor dem Push stehen (§8.3 Nr. 5)
                }
                if (!$untouched) {
                    if ($changed !== []) {
                        // Der Satz bleibt ganz: auch der Plugin-Zustand geht dann nicht zurück (§8.3 Nr. 2).
                        throw new ContentException(ContentException::CHANGED, 'Seit dem Push auf dem Ziel geändert: ' . count($changed) . ' Zeile(n) – nichts wird zurückgenommen, auch Code und Uploads nicht.', $changed);
                    }
                    // Was nach dem Push an einem eingefügten Objekt entstand und nicht vom Push stammt, löschte
                    // purge() gleich mit – Inhalte, die niemand zurücknehmen wollte (M3).
                    $grown = self::grown($store, $before, $now);
                    if (!$store->alive()) {
                        throw ContentRepair::lost();
                    }
                    if ($grown !== [] && !$leave) {
                        throw new ContentException(ContentException::CHANGED, 'Seit dem Push kam an eingefügten Objekten etwas dazu (Meta, Zuordnungen, Kommentare, Kinder): ' . count($grown) . ' Stelle(n) – nichts wird zurückgenommen, auch Code und Uploads nicht.', $grown);
                    }
                    $left = $grown;
                    // Zuerst geht, was an den eingefügten Objekten hängt: was der Push dort geschrieben hat und
                    // die Meta der festen Sperrliste, die WordPress selbst anlegt (_edit_lock …).
                    // Lag an derselben ID schon vor dem Push etwas (verwaiste Meta oder Zuordnungen eines
                    // früher gelöschten Objekts), bringt es das Vorher-Abbild danach zurück.
                    // Mit $leave nicht: purge() nähme das Fremde mit. Was der Push an das Objekt geschrieben hat,
                    // steht mit eigenem Schlüssel im Vorher-Abbild und geht in der Schleife danach.
                    foreach ($leave ? [] : array_reverse($before) as $entry) {
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
                }
                if ($switch) {
                    // Die Liste, wie sie jetzt steht, ohne die Einträge des Pushs und mit denen, die er strich –
                    // fremde Änderungen bleiben. Nur der Wert: autoload und option_id gehören der Site.
                    $value = ContentPlugins::pack(ContentPlugins::apply($list, $undo['reactivated'], $undo['deactivated']));
                    try {
                        $store->write('options', ContentPlugins::OPTION, ['option_value' => $value]);
                    } catch (ContentException $e) {
                        if (!$store->alive()) {
                            $lost = ['options', ContentPlugins::OPTION, $option];
                        }
                        throw $e;
                    }
                    if (!$store->alive()) {
                        $lost = ['options', ContentPlugins::OPTION, $option];
                        throw ContentRepair::lost();
                    }
                }
                return ['state' => self::DONE, 'changes' => is_array($changes) ? $changes : null]
                    + ($leave ? ['left' => $left] : []) + ($switched ? ['plugins' => $undo] : []);
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
                return ['state' => self::DONE, 'changes' => is_array($changes) ? $changes : null]
                    + ($leave ? ['left' => $left] : []) + ($switched ? ['plugins' => $undo] : []);
            }
            throw new ContentException(ContentException::FAILED, 'Die Verbindung zur Datenbank ging beim Abschluss der Transaktion verloren – nichts wurde zurückgenommen.');
        }
    }

    /**
     * Räumt auf, was eine Rücknahme mit $leave an den vom Push eingefügten Objekten stehen liess
     * (R15; Security-Review P3 M2) – für den Agent, sobald WordPress wieder lädt. Für jeden Beitrag,
     * jeden Term und jede term_taxonomy-Zeile, die der Push eingefügt hat und die es weiterhin nicht
     * gibt, gehen die Meta an dieser ID (die der festen Sperrliste eingeschlossen) und die
     * Zuordnungen: an einem Term und einer term_taxonomy-Zeile alle, an einem Beitrag nur die in
     * Taxonomien, die für Beiträge gelten (ContentTarget::postTaxonomy()) – object_id ist dort nicht
     * nur die ID eines Beitrags, eine Zuordnung in einer Taxonomie für Benutzer oder Links gehört
     * einem anderen Objekt mit derselben Zahl. Was sich nicht zuordnen lässt (ohne
     * term_taxonomy-Zeile, nicht registrierte Taxonomie), bleibt ebenfalls. Was geht, ist für
     * WordPress unerreichbar, solange das Objekt fehlt – und hinge sich an das nächste mit dieser ID.
     * Gibt es das Objekt wieder, gehört ihm, was an ihm hängt: dann geschieht dort nichts.
     * Kommentare und Kinder (Revisionen, Kindseiten, weitere Taxonomien eines Terms, Kind-Terme)
     * bleiben – das sind eigene Zeilen, die niemand ungefragt löscht. Für alles, was bleibt und einem
     * Beitrag gehören könnte, lehnt ContentCheck ein neues Objekt an dieser ID ab (id_has_leftovers).
     *
     * @param string $dir Ordner content im Arbeitsordner des Pushs
     * @return int an so vielen Objekten hing etwas, das entfernt wurde
     * @throws ContentException before_image_invalid, engine_unsupported oder content_failed
     */
    public static function sweep(ContentTarget $target, string $dir): int
    {
        $image = ContentImage::get($dir, ContentImage::BEFORE);
        if ($image === null) {
            return 0; // ohne Vorher-Abbild wurde nie geschrieben
        }
        $before = self::keys($image, true);
        if ($before === null) {
            throw ContentImage::invalid();
        }
        unset($image);
        $inserted = [];
        foreach ($before as $entry) {
            if ($entry['state'] === null && isset(ContentState::PK[$entry['t']])) {
                $inserted[$entry['t']][] = $entry['k'];
            }
        }
        if ($inserted === []) {
            return 0;
        }
        ContentState::innodb($target->store);
        $store = $target->store;
        try {
            return $store->transaction(static function () use ($store, $target, $inserted): int {
                $swept   = 0;
                $recount = [];
                foreach ($inserted as $table => $ids) {
                    $gone = [];
                    foreach ($store->read($table, $ids, true) as $id => $raw) {
                        if ($raw === null) {
                            $gone[] = (string) $id;
                        }
                    }
                    if ($gone === []) {
                        continue;
                    }
                    $hanging = $store->attached($table, $gone, true);
                    // Die Zähler der Terme, aus denen ein verschwundener Beitrag dabei fällt, stimmen danach nicht mehr.
                    $terms = $table === 'posts' ? $store->relations($gone, true) : [];
                    foreach ($gone as $id) {
                        $have = $hanging[$id] ?? ['meta' => [], 'relations' => []];
                        if ($table === 'posts') {
                            // Nicht purge(): das löschte jede Zuordnung mit dieser object_id – auch die eines
                            // Benutzers oder Links mit derselben Zahl. Nur Taxonomien, die für Beiträge gelten.
                            $pairs = [];
                            foreach ($have['relations'] as $pair) {
                                if ($target->postTaxonomy(ContentState::split((string) $pair)[1]) === true) {
                                    $pairs[] = (string) $pair;
                                }
                            }
                            if ($have['meta'] === [] && $pairs === []) {
                                continue;
                            }
                            foreach ($have['meta'] as $name) {
                                $store->write('postmeta', Canon::pairKey($id, (string) $name), null);
                            }
                            foreach ($pairs as $pair) {
                                $store->write('term_relationships', $pair, null);
                            }
                        } else {
                            if ($have['meta'] === [] && $have['relations'] === []) {
                                continue;
                            }
                            $store->purge($table, $id); // termmeta nach term_id, Zuordnungen nach term_taxonomy_id: eindeutig
                        }
                        $swept++;
                        foreach ($terms[$id] ?? [] as $tt) {
                            $recount[(string) $tt] = true;
                        }
                    }
                }
                if ($recount !== []) {
                    $store->recount(array_map('strval', array_keys($recount)));
                }
                // Ging die Verbindung verloren, hat der Server verworfen, was die Transaktion schrieb.
                if (!$store->alive()) {
                    throw ContentRepair::lost();
                }
                return $swept;
            });
        } catch (ContentException $e) {
            // Auch ein unklarer COMMIT ist hier nur ein Fehlschlag: ein zweiter Lauf fände nichts oder räumte zu Ende.
            throw $e->reason() === ContentException::UNCLEAR ? new ContentException(ContentException::FAILED, 'Die Verbindung zur Datenbank ging beim Aufräumen verloren.') : $e;
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
        if (!is_array($data['keys'] ?? null) || count($data['keys']) > ContentState::MAX_KEYS) {
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
