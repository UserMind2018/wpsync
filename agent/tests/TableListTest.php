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
}
