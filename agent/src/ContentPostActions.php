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
     * @return list<array{step: string, ok: bool}>
     */
    public static function live(array $changes, $db, string $indexables): array
    {
        // Revisionen, Indexables, Caches: was hier durch $wpdb geht, trägt Werte des Pakets. Kein
        // Fehler daraus steht samt Abfrage im Fehlerprotokoll (ContentSql::silent()).
        return ContentSql::silent($db, static function () use ($changes, $db, $indexables): array {
            return self::liveSteps($changes, $db, $indexables);
        });
    }

    /**
     * @param array<string, mixed> $changes
     * @param object               $db
     * @return list<array{step: string, ok: bool}>
     */
    private static function liveSteps(array $changes, $db, string $indexables): array
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
        if ($expect !== null && function_exists('wp_load_alloptions')) {
            $present = array_values(array_filter((array) ($expect['present'] ?? []), 'is_string'));
            $absent  = array_values(array_filter((array) ($expect['absent'] ?? []), 'is_string'));
            $all     = ($present === [] && $absent === []) ? [] : (array) wp_load_alloptions();
            // Steht die Option nicht in alloptions (autoload aus), gibt es hier nichts zu beurteilen.
            if (array_key_exists(ContentPlugins::OPTION, $all)) {
                self::step($steps, 'plugins_effective', static function () use ($all, $present, $absent): bool {
                    $raw  = $all[ContentPlugins::OPTION];
                    $list = is_array($raw) ? array_values(array_filter($raw, 'is_string')) : ContentPlugins::parse($raw);
                    return $list !== null && ContentPlugins::settled($list, $present, $absent);
                });
            }
        }
        if (class_exists('\Elementor\Plugin', false)) {
            self::step($steps, 'elementor_css', static function (): void {
                \Elementor\Plugin::$instance->files_manager->clear_cache();
            });
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
     * @return list<array{step: string, ok: bool}>
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
     * @return list<array{step: string, ok: bool}>
     */
    private static function stagingSteps(array $changes, ContentStore $store, $db, string $indexables, string $uploadsDir, bool $elementor): array
    {
        $posts = self::ints($changes['posts'] ?? []);
        $tts   = self::ints($changes['term_taxonomy'] ?? []);
        $steps = [];
        if ($elementor) {
            // Was files_manager->clear_cache() täte: ohne diese Einträge erzeugt Elementor das CSS neu.
            self::step($steps, 'elementor_css', static function () use ($store, $uploadsDir): void {
                $store->transaction(static function () use ($store): void {
                    $store->dropMeta('_elementor_css');
                    $store->write('options', '_elementor_global_css', null);
                    $store->write('options', 'elementor-custom-breakpoints-files', null);
                });
                foreach ($uploadsDir === '' ? [] : PushSwap::entries($uploadsDir . '/elementor/css', '/^[^.].*\.css\z/') as $file) {
                    if (is_file($file) && !is_link($file)) {
                        @unlink($file);
                    }
                }
            });
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
     * @param list<array{step: string, ok: bool}> $steps
     * @param callable(): mixed                   $do    false oder eine Exception heisst: nicht gelungen
     */
    private static function step(array &$steps, string $name, callable $do): void
    {
        try {
            $ok = $do() !== false;
        } catch (\Throwable $e) {
            $ok = false;
        }
        $steps[] = ['step' => $name, 'ok' => $ok];
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
