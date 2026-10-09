<?php
namespace WpSync;

defined('ABSPATH') || exit;

/**
 * Wendet ein Inhalts-Paket in einer Transaktion an (Spec Content-Push §7.3): alle Prüfungen
 * erneut unter Sperre, Vorher-Abbild nach before.json, schreiben mit der Origin des Ziels,
 * Nachher-Abdrücke nach after.json, COMMIT. Ohne geschriebenes Vorher-Abbild wird nichts
 * geschrieben; wirft irgendetwas, nimmt die Datenbank alles zurück.
 */
final class ContentApply
{
    public const BEFORE = 'before.json';
    public const AFTER  = 'after.json';

    /** Optionen, deren Änderung die Rewrite-Regeln betrifft (Studio §7.4 Nr. 5). */
    private const REWRITE_OPTIONS = ['page_on_front', 'page_for_posts', 'show_on_front'];

    /**
     * @param string   $dir      Ordner content im Arbeitsordner des Pushs
     * @param int|null $author   post_author neuer Beiträge: wer das Push-Fenster geöffnet hat (§9)
     * @param int      $now      Unix-Zeit des Pushs
     * @param string   $nowLocal dieselbe Zeit in der Zeitzone der Site, 'Y-m-d H:i:s' (post_modified)
     * @return array{rows: int, after: list<array{t: string, k: string, h: string|null}>, changes: array<string, mixed>}
     * @throws ContentException
     */
    public static function run(ContentPackage $package, ContentTarget $target, string $dir, ?int $author, int $now, string $nowLocal): array
    {
        return $target->store->transaction(static function () use ($package, $target, $dir, $author, $now, $nowLocal): array {
            $check = new ContentCheck($package, $target);
            $check->run([], true); // die Uploads des Satzes liegen schon an ihrem Platz
            $writes = self::writes($package, $check, $target, $author, $now, $nowLocal);
            $wanted = [];
            foreach ($package->rows() as $row) {
                if ($row['row'] !== null) {
                    $wanted[$row['table']][$row['key']] = ContentState::isSet($row['table']) && $row['row']['values'] === []
                        ? 'absent'
                        : ContentState::desired($row['table'], $row['key'], $row['row']);
                }
            }
            $before = [];
            foreach ($writes as $write) {
                list($table, $key) = $write;
                $before[] = ['t' => $table, 'k' => $key, 'state' => ContentState::encode($table, $check->state($table)[$key] ?? null)];
            }
            self::put($dir, self::BEFORE, ['keys' => $before]);
            foreach ($writes as $write) {
                $target->store->write($write[0], $write[1], $write[2]);
            }
            $after = self::verify($writes, $target, $wanted);
            $out   = ['rows' => count($package->rows()), 'after' => $after, 'changes' => self::changes($writes, $check)];
            self::put($dir, self::AFTER, ['keys' => $after, 'changes' => $out['changes']]);
            return $out;
        });
    }

    /**
     * Was geschrieben wird, in der Reihenfolge von ContentState::ORDER: [Tabelle, Schlüssel,
     * neuer Rohzustand oder null]. Für op trash kommen die beiden Papierkorb-Meta dazu, die das
     * Paket nicht setzen darf (Studio §5.2, W8).
     *
     * @return list<array{0: string, 1: string, 2: array<string, mixed>|null}>
     */
    private static function writes(ContentPackage $package, ContentCheck $check, ContentTarget $target, ?int $author, int $now, string $nowLocal): array
    {
        $modified = ['post_modified' => $nowLocal, 'post_modified_gmt' => gmdate('Y-m-d H:i:s', $now)];
        $byTable  = array_fill_keys(ContentState::ORDER, []);
        foreach ($package->rows() as $row) {
            $table   = $row['table'];
            $key     = $row['key'];
            $current = $check->state($table)[$key] ?? null;
            if ($row['op'] === 'trash') {
                if ($current === null || ($current['post_status'] ?? '') === 'trash') {
                    continue; // liegt schon im Papierkorb: nichts zu schreiben
                }
                $byTable['posts'][]    = ['posts', $key, array_merge($current, ['post_status' => 'trash'], $modified)];
                $byTable['postmeta'][] = ['postmeta', Canon::pairKey($key, '_wp_trash_meta_status'), ['values' => [(string) $current['post_status']]]];
                $byTable['postmeta'][] = ['postmeta', Canon::pairKey($key, '_wp_trash_meta_time'), ['values' => [(string) $now]]];
                continue;
            }
            $values = (array) $check->value($table, $key);
            if (ContentState::isSet($table)) {
                $byTable[$table][] = [$table, $key, $values['values'] === [] ? null : $values];
                continue;
            }
            if ($table === 'posts') {
                if ($current === null && ($author === null || $author < 1)) {
                    throw new ContentException(ContentException::AUTHOR, 'Neue Beiträge brauchen einen Autor: das Push-Fenster muss im WP-Admin geöffnet sein, nicht per WP-CLI.');
                }
                $values += $current === null
                    ? ['post_author' => (string) $author, 'guid' => self::guid($key, $check, $target), 'to_ping' => '', 'pinged' => '', 'comment_count' => '0']
                    : [];
                $values = array_merge($values, $modified);
            } elseif ($table === 'term_taxonomy' && $current === null) {
                $values['count'] = '0';
            } elseif ($table === 'options' && $current === null) {
                $values['autoload'] = $target->autoload;
            }
            $byTable[$table][] = [$table, $key, $current === null ? $values : array_merge($current, $values)];
        }
        return array_merge(...array_values($byTable));
    }

    /** guid eines neuen Beitrags (§7.3): bei Attachments die Adresse der Datei, sonst <ziel>/?p=<ID>. */
    private static function guid(string $id, ContentCheck $check, ContentTarget $target): string
    {
        $row  = (array) $check->value('posts', $id);
        $file = $check->value('postmeta', Canon::pairKey($id, '_wp_attached_file'));
        if (($row['post_type'] ?? '') === 'attachment' && is_string($file['values'][0] ?? null) && $file['values'][0] !== '') {
            return $target->uploadsUrl . '/' . $file['values'][0];
        }
        return $target->url . '/?p=' . $id;
    }

    /**
     * Liest jeden geschriebenen Schlüssel noch einmal und rechnet seinen Abdruck: er muss der der
     * Zeile des Pakets sein – die Datenbank hat nichts abgeschnitten oder umgewandelt, und nach dem
     * Einsetzen der Origin steht dort genau der Inhalt, den die Arbeitskopie hat (sonst
     * write_mismatch, die Transaktion geht zurück). Liefert die Abdrücke danach – null für einen
     * gelöschten Schlüssel.
     *
     * @param list<array{0: string, 1: string, 2: array<string, mixed>|null}> $writes
     * @param array<string, array<string, string>>                           $wanted Tabelle → Schlüssel → Abdruck der Paketzeile bzw. 'absent'
     * @return list<array{t: string, k: string, h: string|null}>
     */
    private static function verify(array $writes, ContentTarget $target, array $wanted): array
    {
        $keys = [];
        foreach ($writes as $write) {
            $keys[$write[0]][] = $write[1];
        }
        $now = [];
        foreach ($keys as $table => $list) {
            $now[$table] = $target->store->read($table, $list, false);
        }
        $after = [];
        foreach ($writes as $write) {
            list($table, $key) = $write;
            $record = ContentState::record($target->reader(), $table, $key, $now[$table][$key] ?? null);
            $print  = $record === null ? 'absent' : (string) ($record['h'] ?? '!');
            if ($print === '!' || (isset($wanted[$table][$key]) && $wanted[$table][$key] !== $print)) {
                throw new ContentException(ContentException::MISMATCH, 'Was in der Datenbank steht, ergibt nicht den Abdruck der Zeile des Pakets – nichts wurde übernommen.', [ContentException::key($table, $key)]);
            }
            $after[] = ['t' => $table, 'k' => $key, 'h' => $record === null ? null : (string) $record['h']];
        }
        return $after;
    }

    /**
     * Was die Nacharbeiten wissen müssen (§7.7) – steht auch in after.json, damit die Rücknahme
     * dieselben Schritte geht.
     *
     * @param list<array{0: string, 1: string, 2: array<string, mixed>|null}> $writes
     * @return array{posts: list<int>, revisions: list<int>, terms: list<int>, term_taxonomy: list<int>, options: list<string>, rewrite: bool}
     */
    private static function changes(array $writes, ContentCheck $check): array
    {
        $posts     = [];
        $revisions = [];
        $terms     = [];
        $tts       = [];
        $options   = [];
        $rewrite   = false;
        foreach ($writes as $write) {
            list($table, $key, $new) = $write;
            $old = $check->state($table)[$key] ?? null;
            list($object) = ContentState::split($key);
            switch ($table) {
                case 'posts':
                    $posts[(int) $key] = true;
                    if ($old !== null && $new !== null && $new['post_status'] !== 'trash') {
                        $revisions[(int) $key] = true;
                    }
                    foreach (['post_name', 'post_status', 'post_type', 'post_parent'] as $column) {
                        $rewrite = $rewrite || $old === null || (string) $old[$column] !== (string) $new[$column];
                    }
                    break;
                case 'postmeta':
                    $posts[(int) $object] = true;
                    break;
                case 'terms':
                case 'termmeta':
                    $terms[(int) $object] = true;
                    break;
                case 'term_taxonomy':
                    $tts[(int) $key] = true;
                    if ($new !== null) {
                        $terms[(int) $new['term_id']] = true;
                    }
                    break;
                case 'term_relationships':
                    $posts[(int) $object] = true;
                    foreach (array_merge((array) ($old['values'] ?? []), (array) ($new['values'] ?? [])) as $entry) {
                        $tts[(int) explode(':', (string) $entry)[0]] = true;
                    }
                    break;
                case 'options':
                    $options[$key] = true;
                    $rewrite       = $rewrite || in_array($key, self::REWRITE_OPTIONS, true);
                    break;
            }
        }
        return [
            'posts'         => array_keys($posts),
            'revisions'     => array_keys($revisions),
            'terms'         => array_keys($terms),
            'term_taxonomy' => array_keys($tts),
            'options'       => array_map('strval', array_keys($options)),
            'rewrite'       => $rewrite,
        ];
    }

    /**
     * Schreibt eine Datei des Pushs vollständig oder gar nicht.
     *
     * @param array<string, mixed> $data
     * @throws ContentException
     */
    private static function put(string $dir, string $name, array $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $file = $dir . '/' . $name;
        if (!is_string($json) || @file_put_contents($file . '.tmp', $json) !== strlen($json) || !@rename($file . '.tmp', $file)) {
            throw new ContentException(ContentException::FAILED, 'Das Abbild der betroffenen Zeilen liess sich nicht schreiben – nichts wurde übernommen.');
        }
    }
}
