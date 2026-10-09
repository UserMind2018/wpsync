<?php
namespace WpSync;

defined('ABSPATH') || exit;

/**
 * Prüfungen eines Inhalts-Pakets gegen ein Ziel (Spec Content-Push §7.2 Nr. 1–9) – im Probelauf
 * ohne, beim Anwenden mit Sperre. Die Listen des Agents entscheiden, nie die des Pakets (§11).
 * Schreibt nichts. Jede Ablehnung nennt alle betroffenen Schlüssel, nie einen Wert.
 */
final class ContentCheck
{
    /**
     * So weit über der höchsten vergebenen ID des Ziels darf ein neues Objekt liegen. Der Korridor
     * kommt aus dem Paket und begrenzt allein nichts: ein Insert weit darüber verschöbe den
     * AUTO_INCREMENT der Tabelle auf Dauer – auch eine Rücknahme setzt ihn nicht zurück.
     */
    public const ID_HEADROOM = 1000000;
    /** Darüber ist eine ID in JSON und JavaScript keine genaue Zahl mehr (2^53 − 1). */
    public const MAX_ID = 9007199254740991;

    /** Optionen, deren Wert die ID eines Beitrags ist (Studio §5.1, W7). */
    private const POST_OPTIONS = ['page_on_front', 'page_for_posts', 'site_icon', 'elementor_active_kit'];

    /** @var ContentPackage */
    private $package;
    /** @var ContentTarget */
    private $target;
    /** @var array<string, array<string, array<string, mixed>|null>> Tabelle → Schlüssel → Rohzustand des Ziels */
    private $state = [];
    /** @var array<string, list<string>> term_id → Taxonomien auf dem Ziel */
    private $termTaxonomies = [];
    /** @var array<string, array<string, array<string, mixed>>> Tabelle → Schlüssel → Zeile des Pakets (insert, update) */
    private $rows = [];
    /** @var array<string, array<string, array<string, mixed>>> wie $rows, die Origin des Ziels eingesetzt */
    private $inserted = [];
    /** @var list<array{table: string, key: string}> Zeilen ohne Objekt auf dem Ziel oder im Paket */
    private $dangling = [];
    /** @var array<string, int> Zähler-Tabelle → höchste ID, die ein neues Objekt auf diesem Ziel haben darf */
    private $ceilings = [];

    public function __construct(ContentPackage $package, ContentTarget $target)
    {
        $this->package = $package;
        $this->target  = $target;
        foreach ($package->rows() as $row) {
            if ($row['row'] !== null) {
                $this->rows[$row['table']][$row['key']] = $row['row'];
            }
        }
    }

    /**
     * Alle Prüfungen in der Reihenfolge der Spec.
     *
     * @param array<string, mixed> $uploads Dateien der Einheit uploads desselben Pushs, relativ zu uploads/ (nur die Schlüssel zählen)
     * @param bool                 $lock    beim Anwenden: jede Zeile wird mit FOR UPDATE gelesen
     * @throws ContentException
     */
    public function run(array $uploads = [], bool $lock = false): void
    {
        $this->dangling = [];
        $this->inserted = [];
        $this->ceilings = [];
        $this->head();
        $this->engines();
        $this->load($lock);
        $this->lists();
        $this->values();
        $this->ids();
        $this->orphans($lock);
        $this->references();
        $this->files($uploads);
    }

    /**
     * Rohzustand jedes Schlüssels des Pakets, wie run() ihn gelesen hat.
     *
     * @return array<string, array<string, mixed>|null>
     */
    public function state(string $table): array
    {
        return $this->state[$table] ?? [];
    }

    /**
     * Zeile des Pakets mit der Origin des Ziels – was geschrieben wird. Nur nach run().
     *
     * @return array<string, mixed>|null null: das Paket hat für diesen Schlüssel keine Zeile mit Werten
     */
    public function value(string $table, string $key): ?array
    {
        return $this->inserted[$table][$key] ?? null;
    }

    /** @return list<int> veröffentlichte Beiträge des Ziels, die das Paket ändert – für den Health-Check (§7.4) */
    public function published(): array
    {
        $ids = [];
        foreach ($this->package->rows() as $row) {
            if (in_array($row['table'], ['posts', 'postmeta', 'term_relationships'], true)) {
                $id  = ContentState::split($row['key'])[0];
                $raw = $this->state['posts'][$id] ?? null;
                if ($raw !== null && ($raw['post_status'] ?? '') === 'publish') {
                    $ids[(int) $id] = true;
                }
            }
        }
        return array_keys($ids);
    }

    /** Nr. 1 (Rest): das Paket ist gegen genau diese Site gebaut, und sie nimmt Inhalte an (C8). */
    private function head(): void
    {
        $head = $this->package->head();
        if ($head['home'] !== $this->target->home) {
            throw new ContentException(ContentException::ORIGIN, 'Das Paket ist für eine andere Adresse gebaut als die dieser Site.');
        }
        $origin = ContentManifest::origin($this->target->home);
        if ($origin === '' || $origin !== ContentManifest::origin($this->target->siteurl)) {
            throw new ContentException(ContentException::ORIGIN, 'WordPress-Adresse und Website-Adresse dieser Site haben verschiedene Origins – Inhalte lassen sich nicht pushen.');
        }
        $host = strtolower((string) parse_url($this->target->home, PHP_URL_HOST));
        $port = parse_url($this->target->home, PHP_URL_PORT);
        if ($head['local_host'] === $host || $head['local_host'] === $host . ':' . $port) {
            throw new ContentException(ContentException::INVALID, 'Das Paket ist ungültig: local_host ist der Host der Site selbst.');
        }
    }

    /** Nr. 3: ohne InnoDB keine Transaktion. */
    private function engines(): void
    {
        $tables = array_keys($this->package->counts());
        foreach ($this->package->rows() as $row) {
            if ($row['op'] === 'trash' && !in_array('postmeta', $tables, true)) {
                $tables[] = 'postmeta'; // die Papierkorb-Meta schreibt der Agent selbst
            }
        }
        $bad = [];
        foreach ($this->target->store->engines($tables) as $table => $engine) {
            if (strtolower($engine) !== 'innodb') {
                $bad[] = (string) $table;
            }
        }
        if ($bad !== []) {
            throw new ContentException(ContentException::ENGINE, 'Nicht InnoDB: ' . implode(', ', $bad) . ' – Inhalte lassen sich nicht sicher übertragen.', [], ['tables' => $bad]);
        }
    }

    /** Liest den Zustand jedes Schlüssels und der Objekte, auf die das Paket verweist. */
    private function load(bool $lock): void
    {
        $keys = array_fill_keys(ContentState::ORDER, []);
        foreach ($this->package->rows() as $row) {
            $keys[$row['table']][] = $row['key'];
            list($object) = ContentState::split($row['key']);
            if (in_array($row['table'], ['postmeta', 'term_relationships'], true)) {
                $keys['posts'][] = $object;
            } elseif ($row['table'] === 'termmeta') {
                $keys['terms'][] = $object;
            }
            if ($row['op'] === 'trash') {
                foreach (ContentPackage::TRASH_META as $meta) {
                    $keys['postmeta'][] = Canon::pairKey($row['key'], $meta);
                }
            }
        }
        foreach ($this->rows['term_relationships'] ?? [] as $row) {
            foreach ($row['values'] as $entry) {
                $keys['term_taxonomy'][] = explode(':', (string) $entry)[0];
            }
        }
        foreach ($this->rows['term_taxonomy'] ?? [] as $row) {
            $keys['terms'][] = $row['term_id'];
        }
        list($posts, $terms) = $this->optionTargets();
        $keys['posts']       = array_merge($keys['posts'], $posts);
        $keys['terms']       = array_merge($keys['terms'], $terms);
        $keys['options'][]   = 'stylesheet';
        $this->state         = [];
        foreach (ContentState::ORDER as $table) {
            $wanted              = array_values(array_unique(array_map('strval', $keys[$table])));
            $this->state[$table] = $wanted === [] ? [] : $this->target->store->read($table, $wanted, $lock);
        }
        $this->termTaxonomies = $this->target->store->taxonomies(array_map('strval', array_keys($this->state['terms'])));
    }

    /** Nr. 4: jede Zeile gegen die Listen des Agents; das Objekt kommt vom Ziel oder aus dem Paket. */
    private function lists(): void
    {
        $head = $this->package->head();
        if ($head['list_version'] !== ContentLists::VERSION) {
            throw new ContentException(ContentException::LIST_VERSION, 'Das Paket ist mit einer anderen Version der Listen gebaut (Paket ' . $head['list_version'] . ', Agent ' . ContentLists::VERSION . ').');
        }
        $ext     = $head['extensions'];
        $sheet   = (string) ($this->state['options']['stylesheet']['option_value'] ?? '');
        $blocked = [];
        $invalid = [];
        $named   = ['postmeta' => [], 'termmeta' => [], 'options' => []];
        foreach ($this->package->rows() as $row) {
            $table = $row['table'];
            $key   = $row['key'];
            list($object, $name) = ContentState::split($key);
            // Namen streng (Härtung S4): was die Kollation der Datenbank mit einem anderen Namen
            // gleichsetzen könnte – Leerraum am Ende, Akzente, Nicht-ASCII –, nennt kein Paket.
            if (isset($named[$table])) {
                $named[$table][] = $key;
                $label           = $table === 'options' ? $key : $name;
                if (preg_match(ContentLists::NAME, $label) !== 1 || ($table === 'options' && strlen($label) > 191)) {
                    $blocked[] = ContentException::key($table, $key);
                    continue;
                }
            }
            $ctx = ['prefix' => $this->target->prefix, 'stylesheet' => $sheet];
            switch ($table) {
                case 'posts':
                case 'postmeta':
                case 'term_relationships':
                    $ctx['post_type'] = $this->postType($object);
                    break;
                case 'terms':
                case 'termmeta':
                    $ctx['taxonomies'] = $this->taxonomiesOf($object);
                    break;
                case 'term_taxonomy':
                    $ctx['taxonomies'] = array_values(array_unique(array_filter([
                        $this->state['term_taxonomy'][$key]['taxonomy'] ?? null,
                        $this->rows['term_taxonomy'][$key]['taxonomy'] ?? null,
                    ], 'is_string')));
                    break;
            }
            $why = ContentLists::blocked($table, $key, $ctx, $ext);
            if ($why === null && $table === 'posts') {
                $why = $this->blockedPost($row, $ext, $invalid);
            }
            if ($why === null && $table === 'term_relationships') {
                $why = $this->blockedRelations($key, $row['row']['values']);
            }
            // Eine per Erweiterung freigegebene Taxonomie trifft nur Beiträge, für deren Typ die Site sie führt.
            if ($why === null && $table === 'term_relationships' && $this->foreignTaxonomy($name, $ctx['post_type'] ?? null)) {
                $why = 'taxonomy';
            }
            foreach ($why === null && isset($ctx['taxonomies']) ? $ctx['taxonomies'] : [] as $taxonomy) {
                $why = $this->foreignTaxonomy((string) $taxonomy, null) ? 'taxonomy' : $why;
            }
            if ($why === 'no_object') {
                $this->dangling[] = ContentException::key($table, $key);
            } elseif ($why === 'key') {
                $invalid[] = ContentException::key($table, $key);
            } elseif ($why !== null) {
                $blocked[] = ContentException::key($table, $key);
            }
        }
        // Ein Zwilling auf dem Ziel (gleich für die Datenbank, andere Bytes) wäre ein Weg an den Listen vorbei.
        foreach ($named as $table => $keys) {
            foreach ($keys === [] ? [] : $this->target->store->aliases($table, $keys) as $key) {
                $entry = ContentException::key($table, (string) $key);
                if (!in_array($entry, $blocked, true)) {
                    $blocked[] = $entry;
                }
            }
        }
        if ($invalid !== []) {
            throw new ContentException(ContentException::INVALID, 'Das Paket ist ungültig: ein Schlüssel hat nicht die Form der Tabelle, oder ein Beitrag soll ohne op trash in den Papierkorb.', $invalid);
        }
        if ($blocked !== []) {
            throw new ContentException(ContentException::BLOCKED, count($blocked) . ' Zeile(n) stehen auf der Sperrliste des Agents oder nicht auf seiner Whitelist.', $blocked);
        }
    }

    /**
     * Was die Liste allein nicht sieht: ein update darf den Typ nicht in einen gesperrten ändern,
     * Attachments kommen nie in den Papierkorb (S3), und in den Papierkorb führt nur op trash.
     *
     * @param array<string, mixed>                    $row
     * @param array<string, list<string>>             $ext
     * @param list<array{table: string, key: string}> $invalid
     */
    private function blockedPost(array $row, array $ext, array &$invalid): ?string
    {
        $current = $this->state['posts'][$row['key']] ?? null;
        if ($row['op'] === 'trash') {
            return $current !== null && ($current['post_type'] ?? '') === 'attachment' ? 'post_type' : null;
        }
        if ($current !== null && $row['row']['post_status'] === 'trash' && ($current['post_status'] ?? '') !== 'trash') {
            $invalid[] = ContentException::key('posts', $row['key']);
        }
        return ContentLists::postType($row['row']['post_type'], $ext) ? null : 'post_type';
    }

    /**
     * Gehört eine Taxonomie aus einer Projekt-Erweiterung auf der Site nicht zu Beiträgen? In
     * term_relationships steht für eine Benutzer-Taxonomie die ID eines Benutzers – von der eines
     * Beitrags nicht zu unterscheiden. Ist die Taxonomie registriert, darf sie deshalb nicht für
     * Benutzer gelten, und eine Zuordnung braucht einen Beitrag, für dessen Typ sie registriert ist.
     * Die Whitelist des Agents bleibt davon unberührt; eine nicht registrierte Taxonomie auch.
     */
    private function foreignTaxonomy(string $taxonomy, ?string $postType): bool
    {
        if ($this->target->objectTypes === null || in_array($taxonomy, ContentLists::TAXONOMIES, true)) {
            return false;
        }
        $types = ($this->target->objectTypes)($taxonomy);
        if ($types === null) {
            return false;
        }
        return in_array('user', $types, true) || ($postType !== null && !in_array($postType, $types, true));
    }

    /**
     * Jede Zuordnung zeigt auf eine term_taxonomy-Zeile genau dieser Taxonomie – auf dem Ziel oder
     * im Paket. Sonst käme über den Schlüssel einer erlaubten Taxonomie eine fremde mit.
     *
     * @param list<string> $values
     */
    private function blockedRelations(string $key, array $values): ?string
    {
        $taxonomy = ContentState::split($key)[1];
        foreach ($values as $entry) {
            $id    = explode(':', (string) $entry)[0];
            $found = $this->rows['term_taxonomy'][$id]['taxonomy'] ?? ($this->state['term_taxonomy'][$id]['taxonomy'] ?? null);
            if ($found === null) {
                return 'no_object';
            }
            if ((string) $found !== $taxonomy) {
                return 'taxonomy';
            }
        }
        return null;
    }

    /** Nr. 5 und 6: Platzhalter, Reste der lokalen Origin, Pseudonym-Muster – am Wert, wie er geschrieben würde. */
    private function values(): void
    {
        $host    = $this->package->head()['local_host'];
        $unknown = [];
        $unsafe  = [];
        $local   = [];
        $pseudo  = [];
        $unequal = [];
        foreach ($this->rows as $table => $rows) {
            foreach ($rows as $key => $row) {
                $key   = (string) $key;
                $isSet = ContentState::isSet($table);
                $out   = $isSet ? ['values' => []] : [];
                foreach ($isSet ? $row['values'] : $row as $name => $value) {
                    $new = $value;
                    if ($value !== null && $table !== 'term_relationships') {
                        // Härtung S1: WordPress reicht Meta und Optionen an maybe_unserialize() – kein Objekt, nichts Unlesbares.
                        if (SerializedWalker::hasObject((string) $value) !== false) {
                            $unsafe[] = ContentException::key($table, $key);
                            continue 2;
                        }
                        // Ein Fehler beim Einsetzen oder Normalisieren kostet diese Zeile, nicht die Prüfung
                        // (wie ContentReader::normal()). Was der Fehler war, bleibt hier.
                        try {
                            $new  = $this->target->origin->insert((string) $value);
                            $back = $new === null ? null : $this->target->origin->normalize($new);
                        } catch (\Throwable $e) {
                            $new = null;
                        }
                        if ($new === null) {
                            $unknown[] = ContentException::key($table, $key);
                            continue 2;
                        }
                        if ($new !== (string) $value && SerializedWalker::hasObject($new) !== false) {
                            $unsafe[] = ContentException::key($table, $key);
                            continue 2;
                        }
                        if (self::hasHost((string) $value, $host) || self::hasHost($new, $host)) {
                            $local[] = ContentException::key($table, $key);
                            continue 2;
                        }
                        $pattern = Anonymizer::find($new);
                        if ($pattern !== null) {
                            $pseudo[] = ContentException::key($table, $key, $pattern);
                            continue 2;
                        }
                        // Was geschrieben würde, muss normalisiert wieder der Wert des Pakets sein – sonst hätte
                        // die Zeile auf dem Ziel einen anderen Abdruck als in der Arbeitskopie (etwa: die
                        // Adresse des Ziels steht wörtlich im Wert).
                        if ($back !== (string) $value) {
                            $unequal[] = ContentException::key($table, $key);
                            continue 2;
                        }
                    }
                    if ($isSet) {
                        $out['values'][] = $new;
                    } else {
                        $out[$name] = $new;
                    }
                }
                $this->inserted[$table][$key] = $out;
            }
        }
        if ($unsafe !== []) {
            throw new ContentException(ContentException::UNSAFE, 'Ein Wert trägt ein serialisiertes Objekt oder sieht serialisiert aus und lässt sich nicht lesen.', $unsafe);
        }
        if ($unknown !== []) {
            throw new ContentException(ContentException::INVALID, 'Das Paket ist ungültig: ein Wert trägt einen Platzhalter in unbekannter Form oder lässt sich nicht lesen.', $unknown);
        }
        if ($local !== []) {
            throw new ContentException(ContentException::LOCAL_ORIGIN, 'Im Paket steckt noch die Adresse der Arbeitskopie – sie ginge als Link live.', $local);
        }
        if ($pseudo !== []) {
            throw new ContentException(ContentException::PSEUDONYM, 'Im Paket steckt ein pseudonymisierter Wert.', $pseudo);
        }
        if ($unequal !== []) {
            throw new ContentException(ContentException::MISMATCH, 'Ein Wert ergäbe auf dem Ziel einen anderen Abdruck als in der Arbeitskopie – enthält er die Adresse des Ziels wörtlich?', $unequal);
        }
    }

    /** Nr. 7: neue Schlüssel im Korridor, nah an der höchsten ID des Ziels (M1) und frei, bestehende unverändert seit dem Pull. */
    private function ids(): void
    {
        $corridor   = $this->package->head()['corridor'];
        $outside    = [];
        $taken      = [];
        $unfaithful = [];
        $conflict   = [];
        foreach ($this->package->rows() as $row) {
            $table = $row['table'];
            $key   = $row['key'];
            $raw   = $this->state[$table][$key] ?? null;
            $entry = ContentException::key($table, $key);
            if ($row['op'] === 'insert') {
                $counter = isset(ContentState::PK[$table]);
                if ($counter && ((int) $key < $corridor[$table][0] || (int) $key > $corridor[$table][1] || (int) $key > $this->ceiling($table))) {
                    $outside[] = $entry;
                } elseif ($raw !== null) {
                    if ($counter) {
                        $taken[] = $entry;
                    } else {
                        $conflict[] = $entry;
                    }
                }
                continue;
            }
            $record = ContentState::record($this->target->reader(), $table, $key, $raw);
            if ($record === null) {
                $conflict[] = $entry;
            } elseif ($record['h'] === null) {
                $unfaithful[] = $entry;
            } elseif (!hash_equals((string) $row['expected'], (string) $record['h'])) {
                $conflict[] = $entry;
            }
        }
        if ($outside !== []) {
            throw new ContentException(ContentException::CORRIDOR, 'Neue Objekte liegen ausserhalb des ID-Korridors oder mehr als ' . self::ID_HEADROOM . ' über der höchsten ID des Ziels.', $outside);
        }
        if ($taken !== []) {
            throw new ContentException(ContentException::ID_TAKEN, 'Auf dem Ziel sind IDs neuer Objekte schon belegt – erneut ziehen.', $taken);
        }
        if ($unfaithful !== []) {
            throw new ContentException(ContentException::UNFAITHFUL, 'Zeilen auf dem Ziel lassen sich nicht normalisieren und deshalb nicht vergleichen.', $unfaithful);
        }
        if ($conflict !== []) {
            throw new ContentException(ContentException::CONFLICT, 'Auf dem Ziel seit dem Pull geändert: ' . count($conflict) . ' Zeile(n) – erneut ziehen.', $conflict);
        }
    }

    /**
     * Höchste ID eines neuen Objekts auf diesem Ziel: ID_HEADROOM über dem, was die Tabelle schon
     * vergeben hat (auf Staging die der Kopie), und nie über MAX_ID.
     */
    private function ceiling(string $table): int
    {
        if (!isset($this->ceilings[$table])) {
            $this->ceilings[$table] = min(self::MAX_ID, $this->target->store->idMax($table) + self::ID_HEADROOM);
        }
        return $this->ceilings[$table];
    }

    /**
     * Eine Zuordnung, die das Paket schreibt, darf auf dem Ziel nicht schon verwaist liegen: eine
     * Zeile (object_id, term_taxonomy_id) ohne term_taxonomy-Zeile gehört zu keiner Taxonomie, steht
     * also in keinem Abdruck – und das Schreiben scheiterte an ihrem Primärschlüssel.
     */
    private function orphans(bool $lock): void
    {
        $objects = [];
        foreach ($this->rows['term_relationships'] ?? [] as $key => $row) {
            if ($row['values'] !== []) {
                $objects[] = ContentState::split((string) $key)[0];
            }
        }
        if ($objects === []) {
            return;
        }
        $raw = $this->target->store->relations($objects, $lock);
        $bad = [];
        foreach ($this->rows['term_relationships'] ?? [] as $key => $row) {
            $key   = (string) $key;
            $known = [];
            foreach ((array) (($this->state['term_relationships'][$key] ?? [])['values'] ?? []) as $entry) {
                $known[] = explode(':', (string) $entry)[0];
            }
            foreach ($row['values'] as $entry) {
                $id = explode(':', (string) $entry)[0];
                // Auf dem Ziel vorhanden, aber nicht unter dieser Taxonomie sichtbar – und eine andere hat blockedRelations() schon abgelehnt.
                if (in_array($id, $raw[ContentState::split($key)[0]] ?? [], true) && !in_array($id, $known, true)) {
                    $bad[] = ContentException::key('term_relationships', $key);
                    continue 2;
                }
            }
        }
        if ($bad !== []) {
            throw new ContentException(ContentException::BLOCKED, 'Auf dem Ziel liegen an denselben Stellen verwaiste Zuordnungen (ohne term_taxonomy-Zeile) – erst dort aufräumen.', $bad);
        }
    }

    /** Nr. 8: Zeilen ohne Objekt, term_taxonomy ohne Term, Optionen, die ins Leere zeigen. */
    private function references(): void
    {
        $dangling = $this->dangling;
        foreach ($this->rows['term_taxonomy'] ?? [] as $key => $row) {
            if (!$this->termExists($row['term_id'])) {
                $dangling[] = ContentException::key('term_taxonomy', (string) $key);
            }
        }
        foreach ($this->rows['options'] ?? [] as $name => $row) {
            list($posts, $terms) = self::refs((string) $name, $row['option_value']);
            foreach ($posts as $id) {
                if (($this->state['posts'][$id] ?? null) === null && !isset($this->rows['posts'][$id])) {
                    $dangling[] = ContentException::key('options', (string) $name);
                    continue 2;
                }
            }
            foreach ($terms as $id) {
                if (!$this->termExists($id)) {
                    $dangling[] = ContentException::key('options', (string) $name);
                    continue 2;
                }
            }
        }
        if ($dangling !== []) {
            throw new ContentException(ContentException::DANGLING, 'Zeilen verweisen auf Objekte, die es auf dem Ziel nicht gibt und die nicht mitgepusht werden.', $dangling);
        }
    }

    /**
     * Nr. 9: jede Datei eines Attachments liegt auf dem Ziel oder kommt mit der Einheit uploads (S10).
     * Geprüft wird jeder Wert des Paars; _wp_attached_file hat höchstens einen.
     *
     * @param array<string, mixed> $uploads
     */
    private function files(array $uploads): void
    {
        $missing = [];
        $invalid = [];
        foreach ($this->rows['postmeta'] ?? [] as $key => $row) {
            $name = ContentState::split((string) $key)[1];
            if (($name !== '_wp_attached_file' && $name !== '_wp_attachment_metadata') || $row['values'] === []) {
                continue;
            }
            // Ein Attachment hat genau eine Datei: WordPress liest den ersten Wert, wer sonst liest, vielleicht einen anderen.
            if ($name === '_wp_attached_file' && count($row['values']) > 1) {
                $invalid[] = ContentException::key('postmeta', (string) $key);
                continue;
            }
            foreach ($row['values'] as $value) {
                foreach (self::attachmentFiles($name, (string) $value) as $rel) {
                    // Härtung S3: derselbe Massstab wie für die Einheit uploads – Pfad, Name, Typ.
                    $ok = strpos($rel, '://') === false && PushUploads::validFile($rel) && !PushUploads::blockedName($rel)
                        && (!function_exists('wp_check_filetype') || PushUploads::allowedName($rel));
                    if (!$ok) {
                        $invalid[] = ContentException::key('postmeta', (string) $key);
                        continue 3;
                    }
                    $full = $this->target->uploadsDir . '/' . $rel;
                    if (!isset($uploads[$rel]) && !(is_file($full) && !is_link($full))) {
                        $missing[$rel] = true;
                    }
                }
            }
        }
        if ($invalid !== []) {
            throw new ContentException(ContentException::BLOCKED, 'Ein Attachment nennt eine Datei, die nicht unter uploads liegen darf (Pfad oder Dateityp), oder mehr als eine Datei.', $invalid);
        }
        if ($missing !== []) {
            $paths = array_slice(array_map('strval', array_keys($missing)), 0, ContentException::MAX_KEYS);
            throw new ContentException(ContentException::UPLOAD_MISSING, count($missing) . ' Datei(en) von Attachments liegen weder auf dem Ziel noch im Push.', [], ['paths' => $paths, 'total' => count($missing)]);
        }
    }

    /**
     * Dateien eines Attachments relativ zu uploads/ (S10): _wp_attached_file selbst; aus
     * _wp_attachment_metadata file, sizes.*.file und original_image im Ordner von file.
     *
     * @return list<string>
     */
    public static function attachmentFiles(string $metaKey, string $value): array
    {
        if ($metaKey === '_wp_attached_file') {
            return $value === '' ? [] : [$value];
        }
        $meta = SerializedWalker::looksSerialized($value) ? @unserialize($value, ['allowed_classes' => false]) : null;
        if (!is_array($meta) || !is_string($meta['file'] ?? null) || $meta['file'] === '') {
            return [];
        }
        $dir   = dirname($meta['file']) === '.' ? '' : dirname($meta['file']) . '/';
        $files = [$meta['file']];
        foreach ((array) ($meta['sizes'] ?? []) as $size) {
            if (is_array($size) && is_string($size['file'] ?? null) && $size['file'] !== '') {
                $files[] = $dir . $size['file'];
            }
        }
        if (is_string($meta['original_image'] ?? null) && $meta['original_image'] !== '') {
            $files[] = $dir . $meta['original_image'];
        }
        return array_values(array_unique($files));
    }

    /**
     * Objekte, auf die eine Option zeigt (Studio §5.1, W7).
     *
     * @return array{0: list<string>, 1: list<string>} IDs von Beiträgen und von Termen
     */
    private static function refs(string $name, string $value): array
    {
        $id = static function ($raw): ?string {
            return (is_int($raw) || (is_string($raw) && preg_match('/^[0-9]{1,18}\z/', $raw) === 1)) && (int) $raw > 0 ? (string) (int) $raw : null;
        };
        if (in_array($name, self::POST_OPTIONS, true)) {
            return [array_values(array_filter([$id($value)], 'is_string')), []];
        }
        if (strpos($name, 'theme_mods_') !== 0 || !SerializedWalker::looksSerialized($value)) {
            return [[], []];
        }
        $mods = @unserialize($value, ['allowed_classes' => false]);
        if (!is_array($mods)) {
            return [[], []];
        }
        $terms = [];
        foreach ((array) ($mods['nav_menu_locations'] ?? []) as $term) {
            $terms[] = $id($term);
        }
        return [array_values(array_filter([$id($mods['custom_css_post_id'] ?? null)], 'is_string')), array_values(array_filter($terms, 'is_string'))];
    }

    /** @return array{0: list<string>, 1: list<string>} alles, worauf die Optionen des Pakets zeigen */
    private function optionTargets(): array
    {
        $posts = [];
        $terms = [];
        foreach ($this->rows['options'] ?? [] as $name => $row) {
            list($p, $t) = self::refs((string) $name, $row['option_value']);
            $posts       = array_merge($posts, $p);
            $terms       = array_merge($terms, $t);
        }
        return [$posts, $terms];
    }

    /** Typ eines Beitrags: vom Ziel, sonst aus der Zeile des Pakets, die ihn einfügt; null: es gibt ihn nicht. */
    private function postType(string $id): ?string
    {
        $current = $this->state['posts'][$id] ?? null;
        if ($current !== null) {
            return (string) ($current['post_type'] ?? '');
        }
        return isset($this->rows['posts'][$id]) ? (string) $this->rows['posts'][$id]['post_type'] : null;
    }

    /** @return list<string> Taxonomien eines Terms: die des Ziels und die der term_taxonomy-Zeilen des Pakets */
    private function taxonomiesOf(string $termId): array
    {
        $out = $this->termTaxonomies[$termId] ?? [];
        foreach ($this->rows['term_taxonomy'] ?? [] as $row) {
            if ($row['term_id'] === $termId) {
                $out[] = $row['taxonomy'];
            }
        }
        return array_values(array_unique($out));
    }

    private function termExists(string $id): bool
    {
        return ($this->state['terms'][$id] ?? null) !== null || isset($this->rows['terms'][$id]);
    }

    /** Steht der Host im Wert – auch (mehrfach) URL-kodiert, gross/klein egal? */
    private static function hasHost(string $value, string $host): bool
    {
        for ($i = 0; $i < 3; $i++) {
            if (stripos($value, $host) !== false) {
                return true;
            }
            $decoded = rawurldecode($value);
            if ($decoded === $value) {
                return false;
            }
            $value = $decoded;
        }
        return stripos($value, $host) !== false;
    }
}
