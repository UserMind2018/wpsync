<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WpSync\Scope;

final class ScopeTest extends TestCase
{
    private const CORE = [
        'posts'              => 'wp_posts',
        'postmeta'           => 'wp_postmeta',
        'term_relationships' => 'wp_term_relationships',
        'comments'           => 'wp_comments',
    ];

    private function escape(): callable
    {
        return static function (string $v): string {
            return addslashes($v);
        };
    }

    public function testEmptyScopeMeansEverything(): void
    {
        $s = Scope::fromArray(null);
        $this->assertSame('full', $s->tableMode('wp_posts'));
        $this->assertFalse($s->excludesPath('plugins/x', true));
        $this->assertSame(['join' => '', 'where' => ''], $s->rowFilter('wp_posts', self::CORE, $this->escape()));
        $this->assertSame('full', Scope::fromArray([])->tableMode('wp_x'));
    }

    public function testTableModes(): void
    {
        $s = Scope::fromArray(['tables' => ['wp_e_submissions_values' => 'structure', 'wp_log' => 'skip']]);
        $this->assertSame('structure', $s->tableMode('wp_e_submissions_values'));
        $this->assertSame('skip', $s->tableMode('wp_log'));
        $this->assertSame('full', $s->tableMode('wp_posts'));
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function invalidScopes(): array
    {
        return [
            'bad mode'       => [['tables' => ['wp_x' => 'drop']]],
            'bad table name' => [['tables' => ['wp_x`; DROP' => 'skip']]],
            'bad post type'  => [['exclude_post_types' => ["rev'ision"]]],
            'bad plugin'     => [['exclude_plugins' => ['../etc']]],
            'bad year'       => [['uploads_since' => '25']],
            'not a list'     => [['exclude_themes' => 'astra']],
        ];
    }

    /** Ungültiges wird abgelehnt statt ignoriert – sonst verliessen ausgeschlossene Daten den Server. */
    #[DataProvider('invalidScopes')]
    public function testInvalidScopesAreRejected(array $raw): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Scope::fromArray($raw);
    }

    public function testExcludesPath(): void
    {
        $s = Scope::fromArray(['exclude_plugins' => ['duplicator-pro', 'hello'], 'exclude_themes' => ['twentytwenty'], 'uploads_since' => '2024']);
        $this->assertTrue($s->excludesPath('plugins/duplicator-pro', true));
        $this->assertTrue($s->excludesPath('plugins/hello.php', false));
        $this->assertFalse($s->excludesPath('plugins/elementor', true));
        $this->assertFalse($s->excludesPath('plugins/duplicator-pro/x.php', false), 'only the top level decides');
        $this->assertTrue($s->excludesPath('themes/twentytwenty', true));
        $this->assertFalse($s->excludesPath('themes/astra', true));
        $this->assertTrue($s->excludesPath('uploads/2019', true));
        $this->assertFalse($s->excludesPath('uploads/2024', true));
        $this->assertFalse($s->excludesPath('uploads/2025', true));
        $this->assertFalse($s->excludesPath('uploads/elementor', true), 'non-year folders always come along');
        $this->assertFalse($s->excludesPath('uploads/2019', false));
    }

    public function testRowFilters(): void
    {
        $s = Scope::fromArray(['exclude_post_types' => ['revision', 'iwp_log']]);
        $e = $this->escape();
        $this->assertSame(['join' => '', 'where' => "`post_type` NOT IN ('revision','iwp_log')"], $s->rowFilter('wp_posts', self::CORE, $e));
        $this->assertSame(
            ['join' => 'JOIN `wp_posts` p ON p.`ID` = t.`post_id`', 'where' => "p.`post_type` NOT IN ('revision','iwp_log')"],
            $s->rowFilter('wp_postmeta', self::CORE, $e)
        );
        $this->assertSame(
            ['join' => 'LEFT JOIN `wp_posts` p ON p.`ID` = t.`object_id`', 'where' => "(p.`ID` IS NULL OR p.`post_type` NOT IN ('revision','iwp_log'))"],
            $s->rowFilter('wp_term_relationships', self::CORE, $e)
        );
        $this->assertSame('LEFT JOIN `wp_posts` p ON p.`ID` = t.`comment_post_ID`', $s->rowFilter('wp_comments', self::CORE, $e)['join']);
        $this->assertSame(['join' => '', 'where' => ''], $s->rowFilter('wp_options', self::CORE, $e));
    }

    public function testPostTypeWithTrailingNewlineIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Scope::fromArray(['exclude_post_types' => ["revision\n"]]);
    }

    public function testTableNameWithTrailingNewlineIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Scope::fromArray(['tables' => ["wp_users\n" => 'skip']]);
    }

    public function testUploadsSinceWithTrailingNewlineIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Scope::fromArray(['uploads_since' => "2024\n"]);
    }
}
