<?php
namespace WpSync;

defined('ABSPATH') || defined('WPSYNC_RESCUE') || exit;

/**
 * Das Ziel eines Inhalts-Pushs (Spec Content-Push §7.8): Live oder die Staging-Kopie – mit dem
 * Zugriff auf seine sieben Tabellen, seiner Origin zum Normalisieren und Einsetzen und dem, was
 * Prüfen und Anwenden sonst über es wissen müssen. Reine Daten; aufgelöst wird es in PushContent.
 */
final class ContentTarget
{
    /** @var string live oder staging */
    public $name;
    /** @var ContentStore */
    public $store;
    /** @var ContentOrigin Origin des Ziels – für die Kopie samt ihrem StagingReplace */
    public $origin;
    /** @var string home von Live ohne Schrägstrich am Ende; gegen sie ist das Paket gebaut */
    public $home;
    /** @var string siteurl von Live */
    public $siteurl;
    /** @var string Adresse des Ziels ohne Schrägstrich: home bzw. die Adresse der Kopie */
    public $url;
    /** @var string Tabellen-Präfix des Ziels */
    public $prefix;
    /** @var string Ordner uploads des Ziels, ohne Schrägstrich am Ende */
    public $uploadsDir;
    /** @var string Adresse dieses Ordners */
    public $uploadsUrl;
    /** @var string autoload einer neuen Option: 'auto' ab WordPress 6.6, sonst 'yes' (S4) */
    public $autoload;
    /** @var string voller Name der Tabelle yoast_indexable des Ziels; '' wenn es sie nicht gibt (Nacharbeiten) */
    public $indexables = '';
    /**
     * @var (callable(string, string, string, string): string)|null eindeutiger post_name eines Beitrags im
     *      Papierkorb: Name, ID, Beitragstyp, post_parent – auf Live wp_unique_post_slug(); null: der Name bleibt
     */
    public $slug = null;
    /**
     * @var (callable(string): (list<string>|null))|null Objekttypen, für die eine Taxonomie auf der Site
     *      registriert ist (get_taxonomy()->object_type); null als Ergebnis: nicht registriert. Für
     *      Taxonomien aus Projekt-Erweiterungen (ContentCheck); null: nicht geprüft
     */
    public $objectTypes = null;
    /**
     * @var (callable(int): bool)|null gibt es diesen Benutzer auf der Site (get_userdata())? Für den
     *      Autor neuer Beiträge; null: nicht geprüft
     */
    public $userExists = null;
    /** @var ContentReader|null */
    private $reader = null;

    public function __construct(
        string $name,
        ContentStore $store,
        ContentOrigin $origin,
        string $home,
        string $siteurl,
        string $url,
        string $prefix,
        string $uploadsDir,
        string $uploadsUrl,
        string $autoload = 'yes'
    ) {
        $this->name       = $name;
        $this->store      = $store;
        $this->origin     = $origin;
        $this->home       = rtrim($home, '/');
        $this->siteurl    = rtrim($siteurl, '/');
        $this->url        = rtrim($url, '/');
        $this->prefix     = $prefix;
        $this->uploadsDir = rtrim($uploadsDir, '/');
        $this->uploadsUrl = rtrim($uploadsUrl, '/');
        $this->autoload   = $autoload;
    }

    /** Objekttypen einer Taxonomie, die keine Beiträge sind: dort trägt term_relationships.object_id die ID eines Benutzers bzw. Links. */
    private const OTHER_OBJECTS = ['user', 'link'];

    /**
     * Gilt eine Taxonomie auf dieser Site für Beiträge? term_relationships.object_id ist nicht nur
     * die ID eines Beitrags: in einer Taxonomie für Benutzer oder Links steht dort deren ID – dieselbe
     * Zahl, ein anderes Objekt.
     *
     * @return bool|null true: für Beiträge (Whitelist des Agents, oder registriert und weder für Benutzer
     *         noch für Links); false: registriert, aber nur für Benutzer bzw. Links; null: nicht zu sagen –
     *         keine Taxonomie (verwaiste Zuordnung), nicht registriert, für beides registriert, oder die
     *         Site gibt keine Auskunft (ohne WordPress)
     */
    public function postTaxonomy(string $taxonomy): ?bool
    {
        if ($taxonomy === '') {
            return null;
        }
        if (in_array($taxonomy, ContentLists::TAXONOMIES, true)) {
            return true;
        }
        $types = $this->objectTypes === null ? null : ($this->objectTypes)($taxonomy);
        if (!is_array($types) || $types === []) {
            return null;
        }
        $other = array_intersect($types, self::OTHER_OBJECTS);
        if ($other === []) {
            return true;
        }
        return count($other) === count($types) ? false : null;
    }

    /** Rechnet Abdrücke aus dem Rohzustand – mit der Origin des Ziels (ContentState::record()). */
    public function reader(): ContentReader
    {
        if ($this->reader === null) {
            $this->reader = new ContentReader(null, '', $this->origin);
        }
        return $this->reader;
    }
}
