<?php
namespace WpSync;

defined('ABSPATH') || exit;

/**
 * Wendet ein Inhalts-Paket in einer Transaktion an (Spec Content-Push §7.3): alle Prüfungen
 * erneut unter Sperre, Vorher-Abbild nach before.json, schreiben mit der Origin des Ziels,
 * Nachher-Abdrücke nach after.json, COMMIT. Ohne geschriebenes Vorher-Abbild wird nichts
 * geschrieben; wirft irgendetwas, nimmt die Datenbank alles zurück. Beide Abbilder liegen
 * geschützt (ContentImage).
 *
 * Seit P4 (Spec Content-Push P4 §7.3) schreibt derselbe Schritt auch den Plugin-Zustand: die Liste
 * active_plugins des Ziels, unter Sperre gelesen und auf dem Server neu gebaut (ContentPlugins) –
 * mit oder ohne Paket. Im Vorher-Abbild steht dafür nur, was hinzukam und was ging.
 */
final class ContentApply
{
    /** Verweise: die Namen der beiden Abbilder gehören ContentImage (auch ohne WordPress geladen). */
    public const BEFORE = ContentImage::BEFORE;
    public const AFTER  = ContentImage::AFTER;

    /** Optionen, deren Änderung die Rewrite-Regeln betrifft (Studio §7.4 Nr. 5). */
    private const REWRITE_OPTIONS = ['page_on_front', 'page_for_posts', 'show_on_front'];

    /**
     * @param ContentPackage|null $package das Paket; null: der Satz hat keins, nur einen Plugin-Zustand (P4 A7)
     * @param string   $dir      Ordner content im Arbeitsordner des Pushs
     * @param int|null $author   post_author neuer Beiträge: wer das Push-Fenster geöffnet hat (§9)
     * @param int      $now      Unix-Zeit des Pushs
     * @param string   $nowLocal dieselbe Zeit in der Zeitzone der Site, 'Y-m-d H:i:s' (post_modified)
     * @param (callable(): bool)|null $gate Naht unmittelbar vor COMMIT (Spec Content-Push P3 R10): gilt der Push
     *                                      noch? Der Commit nimmt darin die Sperre des Pushs und liest rescue.json
     *                                      neu. false: rescue.php hat ihn inzwischen zurückgenommen – ROLLBACK
     * @param array{add: list<string>, drop: list<string>}|null $plugins der Plugin-Zustand des Satzes: add – aufgelöste
     *                                      Einträge <slug>/<hauptdatei>.php, die in die Liste sollen; drop – Ordner, deren
     *                                      Einträge gehen. null: der Satz hat keinen
     * @return array{rows: int, after: list<array{t: string, k: string, h: string|null}>, changes: array<string, mixed>, plugins?: array{added: list<string>, removed: list<string>}}
     *         plugins nur mit $plugins: was an der Liste wirklich geändert wurde
     * @throws ContentException
     */
    public static function run(?ContentPackage $package, ContentTarget $target, string $dir, ?int $author, int $now, string $nowLocal, ?callable $gate = null, ?array $plugins = null): array
    {
        $store = $target->store;
        $lost  = null; // [Tabelle, Schlüssel, Rohzustand davor]: bei diesem Schreibzugriff ging die Verbindung verloren
        $done  = null; // das Ergebnis, sobald alles geschrieben und zurückgelesen ist
        if ($package === null) {
            // Ohne Paket läuft kein ContentCheck: die Engine prüft der Schritt dann selbst – ohne InnoDB keine Transaktion.
            ContentState::innodb($store);
        }
        try {
            return $store->transaction(static function () use ($package, $target, $store, $dir, $author, $now, $nowLocal, $gate, $plugins, &$lost, &$done): array {
                $check  = null;
                $writes = [];
                $wanted = [];
                if ($package !== null) {
                    $check = new ContentCheck($package, $target);
                    $check->run([], true); // die Uploads des Satzes liegen schon an ihrem Platz
                    $writes = self::writes($package, $check, $target, $author, $now, $nowLocal);
                    foreach ($package->rows() as $row) {
                        if ($row['row'] !== null) {
                            $wanted[$row['table']][$row['key']] = ContentState::isSet($row['table']) && $row['row']['values'] === []
                                ? 'absent'
                                : ContentState::desired($row['table'], $row['key'], $row['row']);
                        }
                    }
                }
                $before = [];
                foreach ($writes as $write) {
                    list($table, $key) = $write;
                    $before[] = ['t' => $table, 'k' => $key, 'state' => ContentState::encode($table, $check->state($table)[$key] ?? null)];
                }
                // Der Plugin-Zustand (P4 §7.3): die Liste des Ziels unter Sperre – nach den Zeilen des Pakets, in
                // derselben Reihenfolge wie bei der Rücknahme. Aus ihr und dem Auftrag entsteht das Delta.
                $option = null; // die Zeile der Option, wie sie vor dem Push steht
                $list   = [];
                $delta  = null;
                if ($plugins !== null) {
                    $option = $store->read('options', [ContentPlugins::OPTION], true)[ContentPlugins::OPTION] ?? null;
                    $have   = $option === null ? null : ContentPlugins::parse($option['option_value'] ?? null);
                    $delta  = $have === null ? null : ContentPlugins::change($have, $plugins['add'], $plugins['drop']);
                    if ($have === null || $delta === null) {
                        throw ContentPlugins::failed();
                    }
                    $list = $have;
                }
                // Was unter Sperre gelesen wurde, gilt nur auf der Verbindung der Transaktion.
                if (!$store->alive()) {
                    throw ContentRepair::lost();
                }
                // Vom Plugin-Zustand steht im Vorher-Abbild nur das Delta – nie die Liste, nie active_plugins als Schlüssel.
                ContentImage::put($dir, self::BEFORE, ['keys' => $before] + ($delta === null ? [] : ['plugins' => $delta]));
                foreach ($writes as $write) {
                    list($table, $key, $state) = $write;
                    try {
                        $store->write($table, $key, $state);
                    } catch (ContentException $e) {
                        if (!$store->alive()) {
                            $lost = [$table, $key, $check->state($table)[$key] ?? null];
                        }
                        throw $e;
                    }
                    if (!$store->alive()) {
                        $lost = [$table, $key, $check->state($table)[$key] ?? null];
                        throw ContentRepair::lost();
                    }
                }
                $after   = self::verify($writes, $target, $wanted);
                $changes = $check === null ? self::noChanges() : self::changes($writes, $check);
                if ($delta !== null) {
                    $changes['plugins'] = $delta + ['h' => null];
                    if ($delta['added'] !== [] || $delta['removed'] !== []) {
                        $value = ContentPlugins::pack(ContentPlugins::apply($list, $delta['added'], $delta['removed']));
                        try {
                            // Nur der Wert: autoload und option_id der Zeile bleiben, wie sie sind.
                            $store->write('options', ContentPlugins::OPTION, ['option_value' => $value]);
                        } catch (ContentException $e) {
                            if (!$store->alive()) {
                                $lost = ['options', ContentPlugins::OPTION, $option];
                                throw $e;
                            }
                            throw ContentPlugins::failed();
                        }
                        if (!$store->alive()) {
                            $lost = ['options', ContentPlugins::OPTION, $option];
                            throw ContentRepair::lost();
                        }
                        // Zurücklesen: jeder neue Eintrag steht in der Liste, keiner der gestrichenen.
                        $written = $store->read('options', [ContentPlugins::OPTION], false)[ContentPlugins::OPTION] ?? null;
                        if (!$store->alive()) {
                            throw ContentRepair::lost();
                        }
                        $stands = $written === null ? null : ContentPlugins::parse($written['option_value'] ?? null);
                        if ($stands === null || !ContentPlugins::settled($stands, $delta['added'], $delta['removed'])) {
                            throw ContentPlugins::failed();
                        }
                        // Für die Nacharbeiten (A15): die Option aus dem Object-Cache, frische Rewrite-Regeln. Der
                        // Abdruck des Werts zählt bei der Rücknahme nur, solange offen ist, ob der COMMIT ankam (V1).
                        $changes['options'][]    = ContentPlugins::OPTION;
                        $changes['rewrite']      = true;
                        $changes['plugins']['h'] = hash('sha256', $value);
                    }
                }
                $out = ['rows' => $package === null ? 0 : count($package->rows()), 'after' => $after, 'changes' => $changes]
                    + ($delta === null ? [] : ['plugins' => $delta]);
                ContentImage::put($dir, self::AFTER, ['keys' => $after, 'changes' => $out['changes']]);
                // Zuletzt, vor COMMIT: ohne das schriebe ein Commit Inhalte fest, deren Push rescue.php
                // gerade als zurückgenommen abgeschlossen hat – Inhalte ohne Rückweg.
                if ($gate !== null && !$gate()) {
                    throw new ContentException(ContentException::FAILED, 'Der Push wurde inzwischen zurückgenommen – nichts wurde übernommen.');
                }
                $done = $out;
                return $out;
            });
        } catch (ContentException $e) {
            if ($lost !== null) {
                throw ContentRepair::repair($target, $lost[0], $lost[1], $lost[2]);
            }
            if ($e->reason() !== ContentException::UNCLEAR) {
                throw $e;
            }
            // Die Verbindung ging im COMMIT verloren: er ist ganz angekommen oder gar nicht. Bei einem Satz ohne
            // Paketzeilen sagt das allein die Liste.
            if ($done !== null && ContentRepair::settled($target, $done['after'])
                && (!isset($done['plugins']) || ContentPlugins::landed($store, $done['plugins']['added'], $done['plugins']['removed']))) {
                return $done;
            }
            throw new ContentException(ContentException::FAILED, 'Die Verbindung zur Datenbank ging beim Abschluss der Transaktion verloren – nichts wurde übernommen.');
        }
    }

    /**
     * Die Felder der Nacharbeiten für einen Satz ohne Paket.
     *
     * @return array{posts: list<int>, revisions: list<int>, terms: list<int>, term_taxonomy: list<int>, options: list<string>, rewrite: bool}
     */
    private static function noChanges(): array
    {
        return ['posts' => [], 'revisions' => [], 'terms' => [], 'term_taxonomy' => [], 'options' => [], 'rewrite' => false];
    }

    /**
     * Was geschrieben wird, in der Reihenfolge von ContentState::ORDER: [Tabelle, Schlüssel,
     * neuer Rohzustand oder null]. Für op trash kommen die Papierkorb-Meta dazu, die das
     * Paket nicht setzen darf (Studio §5.2, W8).
     *
     * @return list<array{0: string, 1: string, 2: array<string, mixed>|null}>
     */
    private static function writes(ContentPackage $package, ContentCheck $check, ContentTarget $target, ?int $author, int $now, string $nowLocal): array
    {
        $modified = ['post_modified' => $nowLocal, 'post_modified_gmt' => gmdate('Y-m-d H:i:s', $now)];
        $byTable  = array_fill_keys(ContentState::ORDER, []);
        $known    = []; // Autoren, die es gibt
        foreach ($package->rows() as $row) {
            $table   = $row['table'];
            $key     = $row['key'];
            $current = $check->state($table)[$key] ?? null;
            if ($row['op'] === 'trash') {
                if ($current === null || ($current['post_status'] ?? '') === 'trash') {
                    continue; // liegt schon im Papierkorb: nichts zu schreiben
                }
                // Wie wp_trash_post() und wp_insert_post(): Status, __trashed am Namen, der alte Name in
                // _wp_desired_post_slug, Status und Zeit des Papierkorbs. add_post_meta() hängt an, es ersetzt nicht.
                $meta = ['_wp_trash_meta_status' => (string) $current['post_status'], '_wp_trash_meta_time' => (string) $now];
                $name = (string) ($current['post_name'] ?? '');
                // Trägt die Zeile post_date und post_date_gmt (WordPress setzt sie beim Papierkorb eines nie
                // veröffentlichten Entwurfs), gehen sie mit – der Abdruck ist dann der der Arbeitskopie.
                $row  = ['post_status' => 'trash'] + ($row['dates'] ?? []);
                if (substr($name, -9) !== '__trashed') {
                    $meta['_wp_desired_post_slug'] = $name;
                    $row['post_name']              = self::trashedName($name, $key, $current, $target);
                }
                $byTable['posts'][] = ['posts', $key, array_merge($current, $row, $modified)];
                foreach ($meta as $metaKey => $value) {
                    $pair                  = Canon::pairKey($key, $metaKey);
                    $have                  = (array) (($check->state('postmeta')[$pair] ?? [])['values'] ?? []);
                    $byTable['postmeta'][] = ['postmeta', $pair, ['values' => array_merge($have, [$value])]];
                }
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
                // Der Öffner kann gelöscht worden sein, seit er das Fenster geöffnet hat: kein Beitrag ohne Autor.
                if ($current === null && !isset($known[$author])) {
                    if ($target->userExists !== null && !($target->userExists)($author)) {
                        throw new ContentException(ContentException::AUTHOR, 'Der Benutzer, der das Push-Fenster geöffnet hat, existiert nicht mehr – neue Beiträge hätten keinen Autor. Das Fenster im WP-Admin neu öffnen.');
                    }
                    $known[$author] = true;
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

    /**
     * post_name eines Beitrags im Papierkorb, wie wp_add_trashed_suffix_to_post_name_for_post():
     * _truncate_post_slug( $name, 191 ) . '__trashed', danach – auf Live – wp_unique_post_slug().
     *
     * @param array<string, mixed> $current die Zeile des Beitrags
     */
    private static function trashedName(string $name, string $id, array $current, ContentTarget $target): string
    {
        if (strlen($name) > 191) {
            $decoded = urldecode($name);
            $name    = $decoded === $name || !function_exists('utf8_uri_encode') ? substr($name, 0, 191) : (string) utf8_uri_encode($decoded, 191, true);
        }
        $name = rtrim($name, '-') . '__trashed';
        return $target->slug === null ? $name : (string) ($target->slug)($name, $id, (string) ($current['post_type'] ?? ''), (string) ($current['post_parent'] ?? '0'));
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
        // Auf einer neuen Verbindung gelesen, stünde hier der alte Stand – das ist kein write_mismatch.
        if (!$target->store->alive()) {
            throw ContentRepair::lost();
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

}
