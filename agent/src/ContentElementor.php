<?php
namespace WpSync;

defined('ABSPATH') || exit;

/**
 * Umfang der Nacharbeit elementor_css (Spec 0.9.1 Block E): ein Paket, das eine Seite ändert, leert
 * nur deren CSS-Cache – nicht den der ganzen Site. Global bleibt es, sobald etwas im Paket steht,
 * das auf andere Seiten wirkt (Template, Kit, Option von Elementor) oder sich nicht einordnen lässt.
 * Reine Funktionen über Store und Dateien: Live und die Staging-Kopie tun dasselbe, ohne die API
 * von Elementor.
 */
final class ContentElementor
{
    public const NONE   = 'none';
    public const POSTS  = 'posts';
    public const ALL    = 'global';

    /** Was Elementor je Beitrag an Erzeugtem ablegt; ohne diese Einträge baut es CSS und Assets neu. */
    public const META = ['_elementor_css', '_elementor_element_cache', '_elementor_page_assets'];

    /** Darüber ist alles zu leeren billiger als einzeln (E3 Nr. 5). */
    public const MAX_POSTS = 200;

    private const KIT      = 'elementor_active_kit';
    private const TEMPLATE = 'elementor_library';

    /**
     * Welche Beiträge die Schreibzugriffe eines Pakets berühren (E1) und ob etwas darunter global wirkt
     * (E3). Läuft in der Transaktion, nach dem Schreiben: was vom Ziel gelesen wird, ist der Stand danach.
     *
     * @param list<array{0: string, 1: string, 2: array<string, mixed>|null}> $writes [Tabelle, Schlüssel, neuer Rohzustand oder null]
     * @param array<string, array<string, mixed>|null>                       $before Beiträge des Ziels vor dem Paket (ContentCheck::state('posts'))
     * @return array{posts: list<int>, global: bool}
     */
    public static function touched(array $writes, array $before, ContentStore $store): array
    {
        $global = ['posts' => [], 'global' => true];
        $after  = []; // ID → Zeile, wie das Paket sie schreibt
        $ids    = [];
        foreach ($writes as $write) {
            list($table, $key, $new) = $write;
            if ($table === 'posts') {
                $ids[(string) $key] = true;
                if ($new !== null) {
                    $after[(string) $key] = $new;
                }
            } elseif ($table === 'postmeta') {
                $ids[ContentState::split((string) $key)[0]] = true;
            } elseif ($table === 'options' && self::option((string) $key)) {
                return $global;
            }
        }
        if ($ids === []) {
            return ['posts' => [], 'global' => false];
        }
        // Stand eines Beitrags vor und nach dem Paket; für die Eltern einer Revision, die das Paket nicht nennt, vom Ziel.
        $rows = static function (string $id) use ($before, $after): array {
            return array_values(array_filter([$before[$id] ?? null, $after[$id] ?? null], 'is_array'));
        };
        $posts   = [];
        $parents = [];
        foreach (array_keys($ids) as $id) {
            $states = $rows((string) $id);
            if ($states === []) {
                return $global; // gelöscht und kein Vorher-Stand greifbar, oder Meta an einem Beitrag, den es nicht gibt
            }
            foreach ($states as $row) {
                $type = (string) ($row['post_type'] ?? '');
                if ($type === '' || $type === self::TEMPLATE) {
                    return $global;
                }
                if ($type !== 'revision') {
                    $posts[(int) $id] = true;
                    continue;
                }
                $parent = (string) ($row['post_parent'] ?? '');
                if (!ctype_digit($parent) || (int) $parent <= 0) {
                    return $global;
                }
                $parents[(string) (int) $parent] = true;
            }
        }
        try {
            $missing = [];
            foreach (array_keys($parents) as $parent) {
                if ($rows((string) $parent) === []) {
                    $missing[] = (string) $parent;
                }
            }
            $read = $missing === [] ? [] : $store->read('posts', $missing, false);
            foreach (array_keys($parents) as $parent) {
                $states = $rows((string) $parent);
                if ($states === []) {
                    $states = array_values(array_filter([$read[(string) $parent] ?? null], 'is_array'));
                }
                if ($states === []) {
                    return $global;
                }
                foreach ($states as $row) {
                    $type = (string) ($row['post_type'] ?? '');
                    if ($type === '' || $type === self::TEMPLATE || $type === 'revision') {
                        return $global;
                    }
                }
                $posts[(int) $parent] = true;
            }
            // Das Kit: seine Einstellungen stehen im CSS jeder Seite. Stünde die Option selbst im Paket, wäre oben schon Schluss.
            $kit = $store->read('options', [self::KIT], false)[self::KIT] ?? null;
        } catch (ContentException $e) {
            return $global; // nicht lesbar: im Zweifel alles
        }
        $kitId = is_array($kit) ? (string) ($kit['option_value'] ?? '') : '';
        if (ctype_digit($kitId) && isset($posts[(int) $kitId])) {
            return $global;
        }
        if (count($posts) > self::MAX_POSTS) {
            return $global;
        }
        $list = array_keys($posts);
        sort($list);
        return ['posts' => $list, 'global' => false];
    }

    /**
     * Der Umfang für die Nacharbeit – aus changes, wie sie beim Anwenden entstanden und im Nachher-Abbild
     * stehen (die Rücknahme geht denselben Weg). Ein Abbild von vor 0.9.1 nennt ihn nicht: dann wie bisher
     * alles, ausser das Paket hat weder einen Beitrag noch eine Option von Elementor angefasst.
     *
     * @param array<string, mixed> $changes
     * @return array{scope: string, posts: list<int>}
     */
    public static function scope(array $changes): array
    {
        $global = ['scope' => self::ALL, 'posts' => []];
        $none   = ['scope' => self::NONE, 'posts' => []];
        if (!array_key_exists('elementor', $changes)) {
            foreach ((array) ($changes['options'] ?? []) as $name) {
                if (is_string($name) && self::option($name)) {
                    return $global;
                }
            }
            return self::ints($changes['posts'] ?? []) === [] ? $none : $global;
        }
        $elementor = $changes['elementor'];
        if (!is_array($elementor) || !empty($elementor['global']) || !is_array($elementor['posts'] ?? null)) {
            return $global;
        }
        $posts = self::ints($elementor['posts']);
        if (count($posts) > self::MAX_POSTS) {
            return $global;
        }
        return $posts === [] ? $none : ['scope' => self::POSTS, 'posts' => $posts];
    }

    /**
     * Leert gezielt (E2): je Beitrag die Meta aus META und seine CSS-Datei. Den Meta-Cache von WordPress
     * leert der Aufrufer – hier gibt es keins.
     *
     * @param string    $uploadsDir Ordner uploads des Ziels; '' wenn dort keine Datei anzufassen ist
     * @param list<int> $posts
     * @throws ContentException
     */
    public static function clear(ContentStore $store, string $uploadsDir, array $posts): void
    {
        $posts = self::ints($posts);
        $store->transaction(static function () use ($store, $posts): void {
            foreach ($posts as $id) {
                foreach (self::META as $meta) {
                    $store->write('postmeta', Canon::pairKey((string) $id, $meta), null);
                }
            }
        });
        if ($uploadsDir === '') {
            return;
        }
        // Nur post-<id>.css, der Name aus der Zahl gebaut; kein Symlink – weder die Datei noch der Ordner davor.
        $dir = $uploadsDir . '/elementor/css';
        if (is_link($uploadsDir . '/elementor') || is_link($dir) || !is_dir($dir)) {
            return;
        }
        foreach ($posts as $id) {
            $file = $dir . '/post-' . $id . '.css';
            if (is_file($file) && !is_link($file)) {
                @unlink($file);
            }
        }
    }

    /** Eine Option von Elementor (E3 Nr. 3). */
    private static function option(string $name): bool
    {
        return strpos($name, 'elementor_') === 0 || strpos($name, '_elementor_') === 0;
    }

    /**
     * @param mixed $raw
     * @return list<int> positive Zahlen, jede einmal
     */
    private static function ints($raw): array
    {
        $out = [];
        foreach ((array) $raw as $value) {
            if ((is_int($value) || (is_string($value) && ctype_digit($value))) && (int) $value > 0) {
                $out[(int) $value] = true;
            }
        }
        return array_keys($out);
    }
}
