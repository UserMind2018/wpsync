<?php
declare(strict_types=1);

namespace WpSync\Tests;

use WpSync\ContentApply;
use WpSync\ContentException;
use WpSync\ContentImage;
use WpSync\ContentPackage;
use WpSync\ContentPlugins;
use WpSync\ContentRollback;

require_once __DIR__ . '/ContentApplyCase.php';

/**
 * Aufbau der Tests zur Rücknahme des Plugin-Zustands: ein Ziel im Speicher mit einer Liste aktiver
 * Plugins, ein Push über ContentApply, und was ein Administrator im WP-Admin danach tut – mit den
 * Schritten des Core nachgestellt.
 */
abstract class ContentRollbackPluginsCase extends ContentApplyCase
{
    protected const ACTIVE = ['akismet/akismet.php', 'old/old.php'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->store->data['options']['active_plugins'] = ContentFixtures::option('active_plugins', serialize(self::ACTIVE), '33');
    }

    protected function tearDown(): void
    {
        ContentImage::$keys    = null;
        ContentImage::$encrypt = null;
        parent::tearDown();
    }

    /**
     * Ein Push: Zeilen eines Pakets (oder keins) und der Plugin-Zustand, in den Ordner $dir.
     *
     * @param list<array<string, mixed>>|null $rows
     * @param list<string>                    $add
     * @param list<string>                    $drop
     * @return array<string, mixed>
     */
    protected function push(?array $rows, array $add, array $drop, ?string $dir = null, ?callable $gate = null): array
    {
        $package = null;
        if ($rows !== null) {
            $this->files[] = $file = ContentFixtures::file($rows);
            $package       = ContentPackage::read($file);
        }
        return ContentApply::run($package, ContentFixtures::live($this->store), $dir ?? $this->dir, 7, self::NOW, '2026-10-09 14:13:20', $gate, ['add' => $add, 'drop' => $drop]);
    }

    /** @return array<string, mixed> */
    protected function back(bool $leave = false, bool $landed = true, ?string $dir = null): array
    {
        return ContentRollback::run(ContentFixtures::live($this->store), $dir ?? $this->dir, $leave, $landed);
    }

    /** Die Rücknahme, die ablehnen muss: liefert die Ablehnung, und die Datenbank steht danach wie zuvor. */
    protected function refused(bool $leave = false, bool $landed = true, ?string $dir = null): ContentException
    {
        $stands = $this->store->data;
        try {
            $this->back($leave, $landed, $dir);
        } catch (ContentException $e) {
            $this->assertSame($stands, $this->store->data, 'nach einer Ablehnung ist nichts geschrieben');
            return $e;
        }
        $this->fail('die Rücknahme hat nicht abgelehnt');
    }

    protected function value(): string
    {
        return (string) $this->store->data['options']['active_plugins']['option_value'];
    }

    /** @return list<string> */
    protected function active(): array
    {
        return (array) ContentPlugins::parse($this->value());
    }

    /** Ein zweiter Arbeitsordner unter demselben temporären Ordner (für einen zweiten Push). */
    protected function otherDir(string $name): string
    {
        return dirname($this->dir) . '/' . $name . '/content';
    }

    /** Wie activate_plugin() im WP-Admin: anhängen, sort(), speichern. */
    protected function adminActivates(string $entry): void
    {
        $current   = (array) unserialize($this->value(), ['allowed_classes' => false]);
        $current[] = $entry;
        sort($current);
        $this->store->data['options']['active_plugins']['option_value'] = serialize($current);
    }

    /** Wie deactivate_plugins() im WP-Admin: unset() – die Schlüssel behalten ihre Lücke. */
    protected function adminDeactivates(string $entry): void
    {
        $current = (array) unserialize($this->value(), ['allowed_classes' => false]);
        $key     = array_search($entry, $current, true);
        $this->assertNotFalse($key, $entry . ' ist nicht aktiv');
        unset($current[$key]);
        $this->store->data['options']['active_plugins']['option_value'] = serialize($current);
    }
}
