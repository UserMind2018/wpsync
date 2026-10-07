<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\TestCase;
use WpSync\TableList;

final class TableListTest extends TestCase
{
    /**
     * @param list<string> $names
     * @return list<array{0: string, 1: string}>
     */
    private function base(array $names): array
    {
        return array_map(static function (string $name): array {
            return [$name, 'BASE TABLE'];
        }, $names);
    }

    public function testKeepsOnlyBaseTablesWithPrefix(): void
    {
        $rows = array_merge($this->base(['wp_options', 'wp_posts', 'other_posts']), [['wp_v', 'VIEW']]);
        $this->assertSame(['wp_options', 'wp_posts'], TableList::filter($rows, 'wp_'));
    }

    public function testDropsWpsyncTablesOfAnyInstallation(): void
    {
        $rows = $this->base(['wp_options', 'wp_wpsync_pairings', 'wp_wpsync_nonces', 'wp_wpsync_state', 'wp_x_wpsync_pairings', 'wp_wpsync_statements']);
        $this->assertSame(['wp_options', 'wp_wpsync_statements'], TableList::filter($rows, 'wp_'));
    }

    public function testDropsNestedInstallations(): void
    {
        $rows = $this->base([
            'wp_options', 'wp_postmeta', 'wp_posts', 'wp_users',
            'wp_stg_options', 'wp_stg_postmeta', 'wp_stg_posts', 'wp_stg_users',
            'wp_2_options', 'wp_2_postmeta', 'wp_2_posts',
        ]);
        $this->assertSame(['wp_options', 'wp_postmeta', 'wp_posts', 'wp_users'], TableList::filter($rows, 'wp_'));
    }

    public function testPluginTablesNamedOptionsAreNotAnInstallation(): void
    {
        $rows = $this->base(['wp_options', 'wp_postmeta', 'wp_posts', 'wp_shop_options', 'wp_shop_orders']);
        $this->assertSame(['wp_options', 'wp_postmeta', 'wp_posts', 'wp_shop_options', 'wp_shop_orders'], TableList::filter($rows, 'wp_'));
    }

    public function testEmptyPrefixKeepsEverything(): void
    {
        $this->assertSame(['a', 'b'], TableList::filter($this->base(['a', 'b']), ''));
    }

    /** AC-71 */
    public function testDropsThePushLog(): void
    {
        $rows = $this->base(['wp_options', 'wp_wpsync_pushes', 'wp_wpsync_pushes_archive']);
        $this->assertSame(['wp_options', 'wp_wpsync_pushes_archive'], TableList::filter($rows, 'wp_'));
    }

    /** AC-83, Spec 5.10 */
    public function testHiddenPrefixesNeverLeaveTheServer(): void
    {
        $rows = [['wp_options', 'BASE TABLE'], ['wp_posts', 'BASE TABLE'], ['stgabc123_options', 'BASE TABLE'], ['wp_stgx_options', 'BASE TABLE']];
        $this->assertSame(['wp_options', 'wp_posts', 'wp_stgx_options'], TableList::filter($rows, 'wp_', ['stgabc123_']));
        $this->assertSame(['wp_options', 'wp_posts'], TableList::filter($rows, 'wp_', ['wp_stgx_']));
        $this->assertSame(['wp_options', 'wp_posts', 'wp_stgx_options'], TableList::filter($rows, 'wp_'));
    }

    /** Leeres Live-Präfix: nur das versteckte Präfix trennt die Kopie; "_" ist kein Platzhalter. */
    public function testHiddenPrefixIsComparedLiterally(): void
    {
        $rows = $this->base(['options', 'posts', 'stgabc123_options', 'stgabc123xoptions']);
        $this->assertSame(['options', 'posts', 'stgabc123xoptions'], TableList::filter($rows, '', ['stgabc123_', '']));
    }

    /**
     * Verwaiste Staging-Tabellen ohne Datensatz (abgebrochenes create, Datensatz verloren): das
     * Namensmuster genügt. Sichtbar wären sie nur neben einem Live-Präfix, das selbst Anfang von
     * „stg“ ist – jedes andere Präfix lässt sie schon am Anfang fallen.
     */
    public function testOrphanedStagingTablesAreHiddenByPattern(): void
    {
        $names = ['options', 'posts', 'stgabc123_options', 'stgabc123_wc_orders', 'stg_notes', 'stgabc12_x', 'stgabcxyz_x', 'STGABC123_x'];
        $this->assertSame(
            ['options', 'posts', 'stg_notes', 'stgabc12_x', 'stgabcxyz_x', 'STGABC123_x'],
            TableList::filter($this->base($names), '')
        );
        $rows = $this->base(['stgoptions', 'stgposts', 'stgabc123_options', 'stg0a1b2c_posts']);
        $this->assertSame(['stgoptions', 'stgposts'], TableList::filter($rows, 'stg'));
        $this->assertSame(['stgoptions', 'stgposts'], TableList::filter($rows, 'st'));
    }

    /** Ein Live-Präfix, das selbst in den zufälligen Teil reicht, behält seine eigenen Tabellen. */
    public function testLiveTablesBehindAStagingLookingPrefixStay(): void
    {
        $rows = $this->base(['stgabc123_options', 'stgabc123_posts', 'stgdef456_options']);
        $this->assertSame(['stgabc123_options', 'stgabc123_posts'], TableList::filter($rows, 'stgabc123_'));
        $this->assertSame(['stgabc123_options', 'stgabc123_posts'], TableList::filter($rows, 'stga'));
    }

    public function testThePatternNeedsNoRecordAndNoHiddenPrefix(): void
    {
        $rows = $this->base(['wp_options', 'wp_posts', 'stgabc123_options']);
        $this->assertSame(['wp_options', 'wp_posts'], TableList::filter($rows, 'wp_'));
        $this->assertSame(['wp_options', 'wp_posts'], TableList::filter($rows, 'wp_', []));
    }
}
