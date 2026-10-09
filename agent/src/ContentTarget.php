<?php
namespace WpSync;

defined('ABSPATH') || exit;

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

    /** Rechnet Abdrücke aus dem Rohzustand – mit der Origin des Ziels (ContentState::record()). */
    public function reader(): ContentReader
    {
        if ($this->reader === null) {
            $this->reader = new ContentReader(null, '', $this->origin);
        }
        return $this->reader;
    }
}
