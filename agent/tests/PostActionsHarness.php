<?php
/**
 * Umgebung für ContentPostActionsTest: die WordPress-Funktionen der Nacharbeiten als Attrappen,
 * jeder Aufruf steht in $GLOBALS['wpsync_post_actions']. Darf nur in einem eigenen Prozess geladen
 * werden (RunTestsInSeparateProcesses). Funktionen von Plugins definiert der Test selbst.
 */
namespace {
    $GLOBALS['wpsync_post_actions'] = [];
    /** @var list<string> Funktionen, die eine Exception werfen */
    $GLOBALS['wpsync_post_actions_fail'] = [];

    /** @param mixed ...$args */
    function wpsync_note(string $what, ...$args): void
    {
        $GLOBALS['wpsync_post_actions'][] = $args === [] ? $what : $what . ' ' . json_encode($args);
        if (in_array($what, $GLOBALS['wpsync_post_actions_fail'], true)) {
            throw new \RuntimeException($what . ' failed');
        }
    }

    function clean_post_cache(int $id): void
    {
        wpsync_note('clean_post_cache', $id);
    }

    /** @param list<int> $ids */
    function clean_term_cache(array $ids): void
    {
        wpsync_note('clean_term_cache', $ids);
    }

    function wp_cache_delete(string $key, string $group = ''): bool
    {
        wpsync_note('wp_cache_delete', $key, $group);
        return true;
    }

    /** @return array<string, mixed> was WordPress beim nächsten Laden aus alloptions liest – im Test gesetzt */
    function wp_load_alloptions(bool $force_cache = false): array
    {
        $all = $GLOBALS['wpsync_alloptions'] ?? [];
        return (array) (is_callable($all) ? $all() : $all);
    }

    /** @return mixed der Object-Cache im Test: $GLOBALS['wpsync_cache'][gruppe][schlüssel], sonst false */
    function wp_cache_get(string $key, string $group = '', bool $force = false)
    {
        return $GLOBALS['wpsync_cache'][$group][$key] ?? false;
    }

    function delete_option(string $name): bool
    {
        wpsync_note('delete_option', $name);
        return false; // wie WordPress, wenn es die Option nicht gab
    }

    /** @return object|false */
    function get_term_by(string $field, int $id)
    {
        $taxonomy = $GLOBALS['wpsync_test_tt'][$id] ?? null;
        return $taxonomy === null ? false : (object) ['term_taxonomy_id' => $id, 'taxonomy' => $taxonomy];
    }

    function taxonomy_exists(string $taxonomy): bool
    {
        return $taxonomy !== 'unregistered';
    }

    /** @param mixed $thing */
    function is_wp_error($thing): bool
    {
        return false;
    }

    /** @param list<int> $ids */
    function wp_update_term_count_now(array $ids, string $taxonomy): bool
    {
        wpsync_note('wp_update_term_count_now', $ids, $taxonomy);
        return true;
    }

    function wp_save_post_revision(int $id): void
    {
        wpsync_note('wp_save_post_revision', $id);
        if (isset($GLOBALS['wpsync_test_revision_db'])) {
            // Wie WordPress: die Revision trägt den Inhalt des Beitrags durch $wpdb.
            $GLOBALS['wpsync_test_revision_db']->query("INSERT INTO `wp_posts` (`post_content`) VALUES ('Inhalt von " . $id . "')");
        }
    }

    /** @param mixed ...$args */
    function do_action(string $hook, ...$args): void
    {
        wpsync_note('do_action', $hook);
    }
}
