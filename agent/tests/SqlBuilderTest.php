<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\TestCase;
use WpSync\SqlBuilder;

final class SqlBuilderTest extends TestCase
{
    private function escape(): callable
    {
        return static function (string $v): string {
            return addslashes($v);
        };
    }

    public function testInsertsQuoteValuesAndKeepNull(): void
    {
        $sql = SqlBuilder::inserts('wp_t', [['1', "it's", null]], $this->escape());
        $this->assertSame("INSERT INTO `wp_t` VALUES ('1','it\\'s',NULL);\n", $sql);
    }

    public function testInsertsAreBatched(): void
    {
        $rows = [['1'], ['2'], ['3']];
        $sql  = SqlBuilder::inserts('wp_t', $rows, $this->escape(), 2);
        $this->assertSame(2, substr_count($sql, 'INSERT INTO'));
    }

    public function testNoRowsNoInsert(): void
    {
        $this->assertSame('', SqlBuilder::inserts('wp_t', [], $this->escape()));
    }

    public function testKeysetSelectFirstPage(): void
    {
        $this->assertSame(
            'SELECT * FROM `wp_posts` ORDER BY `ID` LIMIT 2000',
            SqlBuilder::select('wp_posts', '', 'ID', null, 0, 2000, $this->escape())
        );
    }

    public function testKeysetSelectFollowingPageWithWhere(): void
    {
        $this->assertSame(
            "SELECT * FROM `wp_options` WHERE option_name NOT LIKE 'x' AND `option_id` > '42' ORDER BY `option_id` LIMIT 10",
            SqlBuilder::select('wp_options', "option_name NOT LIKE 'x'", 'option_id', '42', 0, 10, $this->escape())
        );
    }

    public function testOffsetSelectWithoutPrimaryKey(): void
    {
        $this->assertSame(
            'SELECT * FROM `wp_log` LIMIT 4000, 2000',
            SqlBuilder::select('wp_log', '', null, null, 4000, 2000, $this->escape())
        );
    }

    public function testPreamble(): void
    {
        $this->assertSame(
            "DROP TABLE IF EXISTS `wp_t`;\nCREATE TABLE `wp_t` (id int);\n",
            SqlBuilder::preamble('wp_t', 'CREATE TABLE `wp_t` (id int)')
        );
    }

    public function testJoinFilterQualifiesKeysetColumn(): void
    {
        $this->assertSame(
            "SELECT STRAIGHT_JOIN t.* FROM `wp_postmeta` t JOIN `wp_posts` p ON p.`ID` = t.`post_id` WHERE p.`post_type` NOT IN ('revision') AND t.`meta_id` > '42' ORDER BY t.`meta_id` LIMIT 10",
            SqlBuilder::select('wp_postmeta', "p.`post_type` NOT IN ('revision')", 'meta_id', '42', 0, 10, $this->escape(), 'JOIN `wp_posts` p ON p.`ID` = t.`post_id`')
        );
    }

    public function testJoinFilterWithOffset(): void
    {
        $this->assertSame(
            'SELECT STRAIGHT_JOIN t.* FROM `wp_term_relationships` t LEFT JOIN `wp_posts` p ON p.`ID` = t.`object_id` WHERE (p.`ID` IS NULL) LIMIT 0, 5',
            SqlBuilder::select('wp_term_relationships', '(p.`ID` IS NULL)', null, null, 0, 5, $this->escape(), 'LEFT JOIN `wp_posts` p ON p.`ID` = t.`object_id`')
        );
    }
}
