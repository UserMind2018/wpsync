<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\TestCase;
use WpSync\Anonymizer;
use WpSync\Scope;
use WpSync\TableDump;

final class TableDumpTest extends TestCase
{
    private const CORE = [
        'posts' => 'wp_posts', 'postmeta' => 'wp_postmeta',
        'term_relationships' => 'wp_term_relationships', 'comments' => 'wp_comments',
    ];

    /** @param array<string, mixed> $scope */
    private function dump(FakeRescueLink $link, array $scope = [], ?Anonymizer $anon = null): TableDump
    {
        return new TableDump($link, Scope::fromArray($scope), self::CORE, 'wp_options', $anon);
    }

    public function testStructureIsDropAndCreate(): void
    {
        $link = new FakeRescueLink();
        $link->answer('/^SHOW CREATE TABLE `wp_users`/', [['Table' => 'wp_users', 'Create Table' => 'CREATE TABLE `wp_users` (`ID` int)']]);
        $this->assertSame("DROP TABLE IF EXISTS `wp_users`;\nCREATE TABLE `wp_users` (`ID` int);\n", $this->dump($link)->structure('wp_users'));
    }

    public function testStructureReadErrorIsNull(): void
    {
        $link = new FakeRescueLink();
        $link->fail('/^SHOW CREATE TABLE/', 1412);
        $this->assertNull($this->dump($link)->structure('wp_users'));
    }

    public function testPrimaryKeyOnlyForASingleColumn(): void
    {
        $link = new FakeRescueLink();
        $link->answer('/^SHOW KEYS FROM `wp_options`/', [['Column_name' => 'option_id']]);
        $link->answer('/^SHOW KEYS FROM `wp_term_relationships`/', [['Column_name' => 'object_id'], ['Column_name' => 'term_taxonomy_id']]);
        $dump = $this->dump($link);
        $this->assertSame('option_id', $dump->primaryKey('wp_options'));
        $this->assertNull($dump->primaryKey('wp_term_relationships'));
        $this->assertSame("SHOW KEYS FROM `wp_options` WHERE Key_name = 'PRIMARY'", $link->queries[0]);
    }

    public function testFirstChunkHasStructureCursorAndSkipsOwnOptions(): void
    {
        $link = new FakeRescueLink();
        $link->answer('/^SELECT \* FROM `wp_options`/', [
            ['option_id' => '1', 'option_name' => 'siteurl', 'option_value' => "https://kunde.example/'x", 'autoload' => 'yes'],
            ['option_id' => '7', 'option_name' => '_transient_a', 'option_value' => null, 'autoload' => 'no'],
        ]);
        $link->answer('/^SHOW CREATE TABLE `wp_options`/', [['Table' => 'wp_options', 'Create Table' => 'CREATE TABLE `wp_options` (x)']]);
        $chunk = $this->dump($link)->chunk('wp_options', 'option_id', null, 0, 2);
        $this->assertNotNull($chunk);
        $this->assertSame(2, $chunk['rows']);
        $this->assertSame('7', $chunk['next']);
        $this->assertTrue($chunk['keyset']);
        $this->assertSame(
            "DROP TABLE IF EXISTS `wp_options`;\nCREATE TABLE `wp_options` (x);\n"
            . "INSERT INTO `wp_options` VALUES ('1','siteurl','https://kunde.example/\\'x','yes'),\n('7','_transient_a',NULL,'no');\n",
            $chunk['sql']
        );
        $this->assertSame("SELECT * FROM `wp_options` WHERE option_name NOT LIKE 'wpsync\\_%' ORDER BY `option_id` LIMIT 2", $link->queries[0]);
    }

    public function testLaterChunkHasNoStructureAndUsesTheCursor(): void
    {
        $link = new FakeRescueLink();
        $link->answer('/^SELECT \* FROM `wp_options`/', [['option_id' => '9', 'option_name' => 'b', 'option_value' => 'v', 'autoload' => 'no']]);
        $chunk = $this->dump($link)->chunk('wp_options', 'option_id', '7', 2, 2);
        $this->assertNotNull($chunk);
        $this->assertSame("INSERT INTO `wp_options` VALUES ('9','b','v','no');\n", $chunk['sql']);
        $this->assertStringContainsString("`option_id` > '7'", $link->queries[0]);
        $this->assertCount(1, $link->queries, 'no SHOW CREATE TABLE after the first chunk');
    }

    public function testOffsetWithoutPrimaryKey(): void
    {
        $link = new FakeRescueLink();
        $link->answer('/^SELECT \* FROM `wp_term_relationships`/', [['object_id' => '1', 'term_taxonomy_id' => '2', 'term_order' => '0']]);
        $chunk = $this->dump($link)->chunk('wp_term_relationships', null, null, 4, 2);
        $this->assertNotNull($chunk);
        $this->assertNull($chunk['next']);
        $this->assertFalse($chunk['keyset']);
        $this->assertStringEndsWith('LIMIT 4, 2', $link->queries[0]);
    }

    public function testPostTypeFilterJoinsPosts(): void
    {
        $link = new FakeRescueLink();
        $this->dump($link, ['exclude_post_types' => ['revision']])->chunk('wp_postmeta', 'meta_id', '5', 1, 10);
        $this->assertSame(
            "SELECT STRAIGHT_JOIN t.* FROM `wp_postmeta` t JOIN `wp_posts` p ON p.`ID` = t.`post_id` WHERE p.`post_type` NOT IN ('revision') AND t.`meta_id` > '5' ORDER BY t.`meta_id` LIMIT 10",
            $link->queries[0]
        );
    }

    public function testReadErrorIsNull(): void
    {
        $link = new FakeRescueLink();
        $link->fail('/^SELECT/', 1412);
        $this->assertNull($this->dump($link)->chunk('wp_options', 'option_id', null, 0, 2));
    }

    public function testPseudonymizesWithTheAnonymizer(): void
    {
        $link = new FakeRescueLink();
        $link->answer('/^SELECT \* FROM `wp_users`/', [[
            'ID' => '2', 'user_login' => 'erika', 'user_pass' => 'x', 'user_nicename' => 'erika',
            'user_email' => 'erika@kunde-echt.example', 'user_url' => '', 'user_registered' => '2020-01-01 00:00:00',
            'user_activation_key' => '', 'user_status' => '0', 'display_name' => 'Erika Mustermann',
        ]]);
        $link->answer('/^SHOW CREATE TABLE `wp_users`/', [['Table' => 'wp_users', 'Create Table' => 'CREATE TABLE `wp_users` (x)']]);
        $chunk = $this->dump($link, [], new Anonymizer(str_repeat('k', 32), 'wp_'))->chunk('wp_users', 'ID', null, 0, 10);
        $this->assertNotNull($chunk);
        $this->assertStringNotContainsString('erika@kunde-echt.example', $chunk['sql']);
    }
}
