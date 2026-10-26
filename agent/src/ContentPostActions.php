<?php
namespace WpSync;

defined('ABSPATH') || exit;

/**
 * Nacharbeiten nach dem Anwenden und nach der Rücknahme eines Inhalts-Pakets (Spec Content-Push
 * §7.7, Studio §7.4): direktes SQL umgeht save_post & Co., also räumt der Agent selbst auf – in
 * fester Reihenfolge, jeder Schritt für sich. Ein Fehlschlag ist kein Push-Fehler; er steht als
 * {step, ok: false} im Ergebnis. Schritte für Plugins laufen nur, wenn das Plugin da ist.
 * In der Staging-Kopie läuft kein Agent: dort gibt es nur, was sich mit SQL und Dateien sagen lässt.
 */
final class ContentPostActions
{
    private const NAME = '/^[A-Za-z0-9_$]{1,64}\z/';

    /**
     * Auf Live, WordPress ist geladen.
     *
     * @param array<string, mixed> $changes    aus ContentApply (posts, revisions, terms, term_taxonomy, options, rewrite)
     * @param object               $db         $wpdb
     * @param string               $indexables voller Name der Tabelle yoast_indexable; '' wenn es sie nicht gibt
     * @param ContentStore|null    $store      Store von Live – für das gezielte Leeren des Elementor-Cache; ohne ihn wird dort alles geleert
     * @param string               $uploadsDir Ordner uploads von Live; '' wenn dort keine Datei anzufassen ist
     * @return list<array{step: string, ok: bool, scope?: string, count?: int}>
     */
    public static function live(array $changes, $db, string $indexables, ?ContentStore $store = null, string $uploadsDir = ''): array
    {
        // Revisionen, Indexables, Caches: was hier durch $wpdb geht, trägt Werte des Pakets. Kein
        // Fehler daraus steht samt Abfrage im Fehlerprotokoll (ContentSql::silent()).
        return ContentSql::silent($db, static function () use ($changes, $db, $indexables, $store, $uploadsDir): array {
            return self::liveSteps($changes, $db, $indexables, $store, $uploadsDir);
        });
    }

    /**
     * @param array<string, mixed> $changes
     * @param object               $db
     * @return list<array{step: string, ok: bool, scope?: string, count?: int}>
     */
    private static function liveSteps(array $changes, $db, string $indexables, ?ContentStore $store, string $uploadsDir): array
    {
        $posts   = self::ints($changes['posts'] ?? []);
        $terms   = self::ints($changes['terms'] ?? []);
        $tts     = self::ints($changes['term_taxonomy'] ?? []);
        $options = array_values(array_filter((array) ($changes['options'] ?? []), 'is_string'));
        $steps   = [];

        self::step($steps, 'object_cache', static function () use ($posts, $terms, $options): void {
            foreach ($posts as $id) {
                clean_post_cache($id);
            }
            if ($terms !== []) {
                clean_term_cache($terms);
            }
            foreach ($options as $name) {
                wp_cache_delete($name, 'options');
            }
            wp_cache_delete('alloptions', 'options');
            wp_cache_delete('notoptions', 'options');
        });
        if (in_array(ContentPlugins::OPTION, $options, true)) {
            // Der Push hat die Liste der aktiven Plugins geändert (P4 A15): was get_plugins() sich gemerkt hat,
            // gilt nicht mehr. Im Core ist die Gruppe nicht persistent – der Schritt ist für Drop-ins, die das
            // nicht beachten. Die Option selbst samt alloptions hat object_cache eben geleert.
            self::step($steps, 'plugins_cache', static function (): void {
                wp_cache_delete('plugins', 'plugins');
            });
        }
        // Die Liste ging per SQL am Object-Cache vorbei; wirksam wird sie erst, wenn WordPress sie neu liest.
        // Zurücklesen, was der nächste Request laden wird (alloptions – ohne die Filter von get_option()): hält
        // ein persistenter Cache noch den alten Stand, prüfte der Health-Check die falsche Liste, und die neue
        // griffe später ohne Notfallweg (Security-Review P4 S4). Der Aufrufer nennt, was jetzt gelten muss.
        $expect = is_array($changes['plugins_expect'] ?? null) ? $changes['plugins_expect'] : null;
        if ($expect !== null) {
            $present = array_values(array_filter((array) ($expect['present'] ?? []), 'is_string'));
            $absent  = array_values(array_filter((array) ($expect['absent'] ?? []), 'is_string'));
            if ($present !== [] || $absent !== []) {
                // Mit einer Erwartung fehlt der Schritt nie (Nach-Review NR-3): „nicht geprüft“ ist nicht „bestanden“.
                self::step($steps, 'plugins_effective', static function () use ($db, $present, $absent): bool {
                    $list = self::effectivePlugins($db);
                    return $list !== null && ContentPlugins::settled($list, $present, $absent);
                });
            }
        }
        if (class_exists('\Elementor\Plugin', false)) {
            // Nur so weit, wie das Paket reicht (Spec 0.9.1 E): die berührten Beiträge, alles – oder nichts.
            $elementor = ContentElementor::scope($changes);
            if ($elementor['scope'] === ContentElementor::POSTS && $store !== null) {
                $touched = $elementor['posts'];
                self::step($steps, 'elementor_css', static function () use ($store, $uploadsDir, $touched): void {
                    ContentElementor::clear($store, $uploadsDir, $touched);
                    // Die Meta gingen per SQL: clean_post_cache() von object_cache lief davor.
                    foreach ($touched as $id) {
                        wp_cache_delete($id, 'post_meta');
                    }
                }, ['scope' => ContentElementor::POSTS, 'count' => count($touched)]);
            } elseif ($elementor['scope'] !== ContentElementor::NONE) {
                self::step($steps, 'elementor_css', static function (): void {
                    \Elementor\Plugin::$instance->files_manager->clear_cache();
                }, ['scope' => ContentElementor::ALL]);
            }
        }
        if ($indexables !== '' && $posts !== []) {
            self::step($steps, 'seo_indexables', static function () use ($db, $indexables, $posts): bool {
                return self::indexables($db, $indexables, $posts);
            });
        }
        if (function_exists('rocket_clean_domain')) {
            self::step($steps, 'cache_wp_rocket', static function (): void {
                rocket_clean_domain();
            });
        }
        if (function_exists('w3tc_flush_all')) {
            self::step($steps, 'cache_w3tc', static function (): void {
                w3tc_flush_all();
            });
        }
        if (defined('LSCWP_V')) {
            self::step($steps, 'cache_litespeed', static function (): void {
                do_action('litespeed_purge_all');
            });
        }
        if (function_exists('sg_cachepress_purge_cache')) {
            self::step($steps, 'cache_sg_optimizer', static function (): void {
                sg_cachepress_purge_cache();
            });
        }
        if (defined('BREEZE_VERSION')) {
            self::step($steps, 'cache_breeze', static function (): void {
                do_action('breeze_clear_all_cache');
            });
        }
        if (!empty($changes['rewrite'])) {
            self::step($steps, 'rewrite_rules', static function (): void {
                delete_option('rewrite_rules'); // WordPress baut sie beim nächsten Aufruf neu
            });
        }
        if ($tts !== []) {
            self::step($steps, 'term_counts', static function () use ($tts): void {
                $byTaxonomy = [];
                foreach ($tts as $id) {
                    $term = get_term_by('term_taxonomy_id', $id);
                    if (is_object($term) && !is_wp_error($term) && taxonomy_exists((string) $term->taxonomy)) {
                        $byTaxonomy[(string) $term->taxonomy][] = $id;
                    }
                }
                foreach ($byTaxonomy as $taxonomy => $ids) {
                    wp_update_term_count_now($ids, $taxonomy);
                }
            });
        }
        $revisions = self::ints($changes['revisions'] ?? []);
        if ($revisions !== []) {
            // Nach clean_post_cache(), sonst entstünde die Revision aus dem alten Cache-Stand (O6).
            self::step($steps, 'revisions', static function () use ($revisions): void {
                foreach ($revisions as $id) {
                    wp_save_post_revision($id);
                }
            });
        }
        return $steps;
    }

    /**
     * In der Staging-Kopie: dieselben Wirkungen, soweit sie sich ohne ihr WordPress erreichen
     * lassen. Einen Object-Cache und Cache-Plugins hat die Kopie nicht (Drop-ins werden nie kopiert).
     *
     * @param array<string, mixed> $changes
     * @param object               $db         $wpdb
     * @param string               $indexables voller, geprüfter Name der Tabelle der Kopie; '' wenn es sie nicht gibt
     * @param string               $uploadsDir Ordner uploads der Kopie; '' wenn er nicht in der Kopie liegt
     * @param bool                 $elementor  Elementor ist auf Live aktiv
     * @return list<array{step: string, ok: bool, scope?: string, count?: int}>
     */
    public static function staging(array $changes, ContentStore $store, $db, string $indexables, string $uploadsDir, bool $elementor): array
    {
        return ContentSql::silent($db, static function () use ($changes, $store, $db, $indexables, $uploadsDir, $elementor): array {
            return self::stagingSteps($changes, $store, $db, $indexables, $uploadsDir, $elementor);
        });
    }

    /**
     * @param array<string, mixed> $changes
     * @param object               $db
     * @return list<array{step: string, ok: bool, scope?: string, count?: int}>
     */
    private static function stagingSteps(array $changes, ContentStore $store, $db, string $indexables, string $uploadsDir, bool $elementor): array
    {
        $posts = self::ints($changes['posts'] ?? []);
        $tts   = self::ints($changes['term_taxonomy'] ?? []);
        $steps = [];
        $scope = $elementor ? ContentElementor::scope($changes) : ['scope' => ContentElementor::NONE, 'posts' => []];
        if ($scope['scope'] === ContentElementor::POSTS) {
            // Dasselbe wie auf Live (Spec 0.9.1 E2): nur die berührten Beiträge.
            $touched = $scope['posts'];
            self::step($steps, 'elementor_css', static function () use ($store, $uploadsDir, $touched): void {
                ContentElementor::clear($store, $uploadsDir, $touched);
            }, ['scope' => ContentElementor::POSTS, 'count' => count($touched)]);
        } elseif ($scope['scope'] === ContentElementor::ALL) {
            // Was files_manager->clear_cache() täte: ohne diese Einträge erzeugt Elementor CSS und Assets neu.
            self::step($steps, 'elementor_css', static function () use ($store, $uploadsDir): void {
                $store->transaction(static function () use ($store): void {
                    foreach (ContentElementor::META as $meta) {
                        $store->dropMeta($meta);
                    }
                    $store->write('options', '_elementor_global_css', null);
                    $store->write('options', 'elementor-custom-breakpoints-files', null);
                });
                foreach ($uploadsDir === '' ? [] : PushSwap::entries($uploadsDir . '/elementor/css', '/^[^.].*\.css\z/') as $file) {
                    if (is_file($file) && !is_link($file)) {
                        @unlink($file);
                    }
                }
            }, ['scope' => ContentElementor::ALL]);
        }
        if ($indexables !== '' && $posts !== []) {
            self::step($steps, 'seo_indexables', static function () use ($db, $indexables, $posts): bool {
                return self::indexables($db, $indexables, $posts);
            });
        }
        if (!empty($changes['rewrite'])) {
            self::step($steps, 'rewrite_rules', static function () use ($store): void {
                $store->transaction(static function () use ($store): void {
                    $store->write('options', 'rewrite_rules', null);
                });
            });
        }
        if ($tts !== []) {
            self::step($steps, 'term_counts', static function () use ($store, $tts): void {
                $store->recount(array_map('strval', $tts));
            });
        }
        return $steps;
    }

    /**
     * Löscht die Indexables der betroffenen Beiträge; Yoast baut sie beim nächsten Aufruf neu.
     *
     * @param object    $db
     * @param list<int> $posts
     */
    private static function indexables($db, string $table, array $posts): bool
    {
        if (preg_match(self::NAME, $table) !== 1) {
            return false;
        }
        foreach (array_chunk($posts, 100) as $chunk) {
            $sql = 'DELETE FROM `' . $table . '` WHERE `object_type` = %s AND `object_id` IN (' . implode(',', array_fill(0, count($chunk), '%d')) . ')';
            if ($db->query($db->prepare($sql, 'post', ...$chunk)) === false) {
                return false;
            }
        }
        return true;
    }

    /**
     * Die Liste der aktiven Plugins, wie WordPress sie im nächsten Request liest – am Zustand der Caches
     * vorbei, die dieser Request eben geleert hat: alloptions (frisch aus dem persistenten Cache, ohne die
     * Filter von get_option()); steht die Option dort nicht (autoload aus), ihr eigener Cache-Eintrag, sonst
     * die Zeile der Datenbank – ausser der Cache führt sie als „gibt es nicht“ (notoptions). null: nicht
     * lesbar, keine Liste.
     *
     * @param object $db
     * @return list<string>|null
     */
    private static function effectivePlugins($db): ?array
    {
        $name = ContentPlugins::OPTION;
        $raw  = null;
        $all  = function_exists('wp_load_alloptions') ? wp_load_alloptions(true) : [];
        if (is_array($all) && array_key_exists($name, $all)) {
            $raw = $all[$name];
        } elseif (function_exists('wp_cache_get')) {
            $not = wp_cache_get('notoptions', 'options', true);
            if (is_array($not) && isset($not[$name])) {
                return null;
            }
            $raw = wp_cache_get($name, 'options', true);
            if ($raw === false) {
                $raw = $db->get_var($db->prepare('SELECT option_value FROM ' . $db->options . ' WHERE option_name = %s LIMIT 1', $name));
            }
        }
        if (is_array($raw)) {
            $list = [];
            foreach ($raw as $entry) {
                if (!is_string($entry)) {
                    return null;
                }
                $list[] = $entry;
            }
            return $list;
        }
        return ContentPlugins::parse($raw);
    }

    /**
     * @param list<array<string, mixed>> $steps
     * @param callable(): mixed          $do    false oder eine Exception heisst: nicht gelungen
     * @param array<string, mixed>       $extra was der Schritt über sich sagt (elementor_css: scope, count)
     */
    private static function step(array &$steps, string $name, callable $do, array $extra = []): void
    {
        try {
            $ok = $do() !== false;
        } catch (\Throwable $e) {
            $ok = false;
        }
        $steps[] = ['step' => $name, 'ok' => $ok] + $extra;
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
