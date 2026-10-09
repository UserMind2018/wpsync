<?php
namespace WpSync;

defined('ABSPATH') || defined('WPSYNC_RESCUE') || exit;

/**
 * Die Option active_plugins als Liste (Spec Content-Push P4 §7.3, §8.3): lesen, ändern, packen –
 * ohne eine Funktion von WordPress, weil dieselbe Regel in rescue.php läuft. Aus einem Request
 * kommt hier nie etwas an: was hinzukommt, hat der Agent aus dem gebauten Verzeichnis aufgelöst
 * (PushPlugins), was geht, steht in der Liste des Ziels selbst, und die Rücknahme liest beides aus
 * dem authentisierten Vorher-Abbild.
 *
 * Gelesen wird der Wert ohne Klassen: steht dort ein Objekt, entsteht es nie, und der Wert gilt
 * als nicht lesbar. Geschrieben wird wie activate_plugin() es hinterlässt – sort(), serialize().
 */
final class ContentPlugins
{
    public const OPTION = 'active_plugins';
    /** Höchstens so viele Einträge je Richtung trägt ein Vorher-Abbild (added, removed). */
    public const MAX = 100;

    /** Mehr liest parse() nicht: die Liste einer echten Site hat wenige Kilobyte. */
    private const MAX_BYTES = 1048576;
    /** <slug>/<pfad>.php – der Slug wie eine Einheit, der Pfad ohne Steuerzeichen und Backslash. */
    private const ENTRY = '#^[A-Za-z0-9][A-Za-z0-9._-]*/[^\x00-\x1f\x7f\\\\]{1,200}\.php\z#';

    /**
     * Hat ein Eintrag die Form, die ein Abbild nennen darf? Ein Plugin als einzelne Datei
     * (hello.php) gehört nicht dazu – P4 schaltet nur Plugins in einem Ordner (A4).
     *
     * @param mixed $entry
     */
    public static function valid($entry): bool
    {
        if (!is_string($entry) || strlen($entry) > 255 || preg_match('//u', $entry) !== 1 || preg_match(self::ENTRY, $entry) !== 1) {
            return false;
        }
        foreach (explode('/', $entry) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }
        return true;
    }

    /** Ordner eines Eintrags: alles vor dem ersten Schrägstrich. */
    public static function slug(string $entry): string
    {
        $cut = strpos($entry, '/');
        return $cut === false ? $entry : (string) substr($entry, 0, $cut);
    }

    /**
     * Der Optionswert als Liste von Zeichenketten, in seiner Reihenfolge und ohne die Schlüssel
     * (deactivate_plugins() hinterlässt Lücken). null: kein serialisiertes Array aus Zeichenketten.
     *
     * @param mixed $value option_value, wie die Datenbank ihn liefert
     * @return list<string>|null
     */
    public static function parse($value): ?array
    {
        if (!is_string($value) || $value === '' || strlen($value) > self::MAX_BYTES) {
            return null;
        }
        // Ohne @: ein Fehler-Handler der Site (oder eines Plugins) sähe die Meldung trotzdem und könnte werfen.
        set_error_handler(static function (): bool {
            return true;
        });
        try {
            $list = unserialize($value, ['allowed_classes' => false]);
        } catch (\Throwable $e) {
            $list = false;
        } finally {
            restore_error_handler();
        }
        if (!is_array($list)) {
            return null;
        }
        $out = [];
        foreach ($list as $entry) {
            if (!is_string($entry)) {
                return null; // auch ein Objekt ohne Klasse (__PHP_Incomplete_Class) ist keine Zeichenkette
            }
            $out[] = $entry;
        }
        return $out;
    }

    /**
     * Der Wert, wie activate_plugin() ihn über update_option() schreibt: sort(), serialize().
     *
     * @param list<string> $list
     */
    public static function pack(array $list): string
    {
        $list = array_values($list);
        sort($list);
        return serialize($list);
    }

    /**
     * Was ein Push an der Liste wirklich ändert (A11: schon im gewünschten Zustand ist kein
     * Eintrag): hinzu kommt, was noch nicht darin steht; es geht jeder Eintrag, der mit dem
     * <slug>/ einer abzuschaltenden Einheit beginnt.
     *
     * @param list<string> $list  die Liste des Ziels, unter Sperre gelesen
     * @param list<string> $add   aufgelöste Einträge <slug>/<hauptdatei>.php
     * @param list<string> $slugs Ordner der abzuschaltenden Plugins
     * @return array{added: list<string>, removed: list<string>}|null null: ein Eintrag hat nicht die Form,
     *         die das Vorher-Abbild verlangt, oder es sind mehr als MAX – dann wird nichts geschrieben
     */
    public static function change(array $list, array $add, array $slugs): ?array
    {
        $removed = [];
        foreach ($list as $entry) {
            foreach ($slugs as $slug) {
                $prefix = $slug . '/';
                if (strncmp((string) $entry, $prefix, strlen($prefix)) === 0) {
                    $removed[(string) $entry] = true;
                    break;
                }
            }
        }
        $added = [];
        foreach ($add as $entry) {
            if (!in_array($entry, $list, true)) {
                $added[(string) $entry] = true;
            }
        }
        return self::delta(['added' => array_map('strval', array_keys($added)), 'removed' => array_map('strval', array_keys($removed))]);
    }

    /**
     * Das Feld plugins eines Vorher-Abbilds, geprüft (§8.3 Nr. 1): zwei Listen aus gültigen
     * Einträgen, je höchstens MAX, keiner doppelt, keiner in beiden. Weitere Felder daneben zählen
     * hier nicht.
     *
     * @param mixed $raw
     * @return array{added: list<string>, removed: list<string>}|null null: nicht die Form – das Abbild gilt
     *         dann nicht; ohne Feld (null als Eingabe) hatte der Push keinen Plugin-Zustand
     */
    public static function delta($raw): ?array
    {
        if ($raw === null) {
            return ['added' => [], 'removed' => []];
        }
        if (!is_array($raw) || !is_array($raw['added'] ?? null) || !is_array($raw['removed'] ?? null)) {
            return null;
        }
        $out  = [];
        $seen = [];
        foreach (['added', 'removed'] as $side) {
            if (count($raw[$side]) > self::MAX) {
                return null;
            }
            $out[$side] = [];
            foreach ($raw[$side] as $entry) {
                if (!self::valid($entry) || isset($seen[$entry])) {
                    return null;
                }
                $seen[$entry] = true;
                $out[$side][] = $entry;
            }
        }
        return $out;
    }

    /**
     * Die Liste ohne $remove (jedes Vorkommen) und mit $add (keiner doppelt) – noch unsortiert,
     * pack() sortiert.
     *
     * @param list<string> $list
     * @param list<string> $add
     * @param list<string> $remove
     * @return list<string>
     */
    public static function apply(array $list, array $add, array $remove): array
    {
        $out = [];
        foreach ($list as $entry) {
            if (!in_array($entry, $remove, true)) {
                $out[] = $entry;
            }
        }
        foreach ($add as $entry) {
            if (!in_array($entry, $out, true)) {
                $out[] = $entry;
            }
        }
        return $out;
    }

    /**
     * Steht jeder Eintrag aus $present in der Liste und keiner aus $absent?
     *
     * @param list<string> $list
     * @param list<string> $present
     * @param list<string> $absent
     */
    public static function settled(array $list, array $present, array $absent): bool
    {
        foreach ($present as $entry) {
            if (!in_array($entry, $list, true)) {
                return false;
            }
        }
        foreach ($absent as $entry) {
            if (in_array($entry, $list, true)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Was die Rücknahme eines Pushs an der Liste zu tun hat (A21): streichen, was er hinzugefügt
     * hat und noch darin steht; zurückbringen, was er gestrichen hat und noch fehlt. Sonst nichts.
     *
     * @param list<string>                                         $list
     * @param array{added: list<string>, removed: list<string>} $delta aus delta()
     * @return array{deactivated: list<string>, reactivated: list<string>}
     */
    public static function undo(array $list, array $delta): array
    {
        $out = ['deactivated' => [], 'reactivated' => []];
        foreach ($delta['added'] as $entry) {
            if (in_array($entry, $list, true)) {
                $out['deactivated'][] = $entry;
            }
        }
        foreach ($delta['removed'] as $entry) {
            if (!in_array($entry, $list, true)) {
                $out['reactivated'][] = $entry;
            }
        }
        return $out;
    }

    /**
     * Nach einem COMMIT mit offenem Ausgang: steht die Liste so in der Datenbank? Ohne Sperre,
     * ausserhalb einer Transaktion. Ohne Delta gibt es nichts nachzusehen.
     *
     * @param list<string> $present
     * @param list<string> $absent
     * @throws ContentException wenn sich die Datenbank nicht lesen lässt
     */
    public static function landed(ContentStore $store, array $present, array $absent): bool
    {
        if ($present === [] && $absent === []) {
            return true;
        }
        $row  = $store->read('options', [self::OPTION], false)[self::OPTION] ?? null;
        $list = $row === null ? null : self::parse($row['option_value'] ?? null);
        return $list !== null && self::settled($list, $present, $absent);
    }

    /** Die Ablehnung, wenn die Liste des Ziels nicht lesbar ist oder sich nicht schreiben liess – ohne einen Eintrag daraus. */
    public static function failed(): ContentException
    {
        return new ContentException(
            ContentException::PLUGINS_FAILED,
            'Die Liste der aktiven Plugins des Ziels (active_plugins) ist nicht lesbar oder liess sich nicht schreiben – nichts wurde übernommen.'
        );
    }
}
