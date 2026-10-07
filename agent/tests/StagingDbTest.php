<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WpSync\Anonymizer;
use WpSync\Scope;
use WpSync\StagingDb;
use WpSync\StagingException;
use WpSync\StagingGuard;
use WpSync\StagingReplace;

require_once __DIR__ . '/FakeWpdb.php';

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

final class StagingDbTest extends TestCase
{
    private const KEY = '000102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f';
    private const DIR = 'wpsync-staging-0123456789ab';

    /** @var FakeWpdb */
    private $db;

    protected function setUp(): void
    {
        $this->db        = new FakeWpdb();
        $GLOBALS['wpdb'] = $this->db;
    }

    protected function tearDown(): void
    {
        $this->assertWritesStayInStaging();
        unset($GLOBALS['wpdb']);
    }

    private function guard(): StagingGuard
    {
        return new StagingGuard('wp_', 'stgabc123_', sys_get_temp_dir(), self::DIR);
    }

    /** @return array{live: string, stg: string, mode: string} */
    private static function entry(string $name, string $mode = Scope::FULL): array
    {
        return ['live' => 'wp_' . $name, 'stg' => 'stgabc123_' . $name, 'mode' => $mode];
    }

    /**
     * Leitplanke 2: geschrieben wird nur in Tabellen mit dem Staging-Präfix; Live-Tabellen stehen
     * höchstens als Quelle hinter FROM, JOIN oder LIKE.
     */
    private function assertWritesStayInStaging(): void
    {
        foreach ($this->db->writes() as $sql) {
            $this->assertMatchesRegularExpression('/^(CREATE TABLE|INSERT INTO|UPDATE|DROP TABLE IF EXISTS) `stgabc123_[A-Za-z0-9_$]+`( |\z)/', $sql);
            $rest = (string) preg_replace('/ (FROM|JOIN|LIKE) `wp_[A-Za-z0-9_$]+`/', '', $sql);
            $this->assertStringNotContainsString('`wp_', $rest, $sql);
        }
    }

    /** V16: dieselbe Liste wie LocalDisabledPlugins der CLI */
    public function testDisabledPluginsMatchTheCli(): void
    {
        $go = (string) file_get_contents(__DIR__ . '/../../cli/internal/pull/postsetup.go');
        $this->assertSame(1, preg_match('/LocalDisabledPlugins = \[\]string\{(.*?)\n\}/s', $go, $m));
        preg_match_all('/"([a-z0-9-]+)"/', $m[1], $slugs);
        $this->assertSame($slugs[1], StagingDb::DISABLED_PLUGINS);
    }

    /** AC-90, V16 */
    public function testActivePluginsLoseDisabledExcludedAndTheAgent(): void
    {
        $active = ['woocommerce/woocommerce.php', 'wp-mail-smtp/wp_mail_smtp.php', 'wpsync-agent/wpsync-agent.php', 'excluded/excluded.php', 'hello.php', 'WP-Rocket/wp-rocket.php', 42];
        $scope  = Scope::fromArray(['exclude_plugins' => ['excluded', 'hello']]);
        $this->assertSame(['woocommerce/woocommerce.php'], StagingDb::activePlugins($active, $scope));
    }

    /** AC-95, V16 */
    public function testGatewaysToDisable(): void
    {
        $ids = StagingDb::gatewayIds(['bacs' => 0, 'stripe' => 1, 'my-pay' => 2, 'cod' => 3, 'bad id' => 4, 7 => 5]);
        $this->assertContains('stripe', $ids);
        $this->assertContains('my-pay', $ids);
        $this->assertContains('ppcp-gateway', $ids);
        $this->assertNotContains('bacs', $ids);
        $this->assertNotContains('cod', $ids);
        $this->assertNotContains('cheque', $ids);
        $this->assertNotContains('bad id', $ids);
        $this->assertSame($ids, array_values(array_unique($ids)));
    }

    public function testTextColumnsAreFoundByType(): void
    {
        foreach (['varchar(255)', 'char(32)', 'text', 'tinytext', 'mediumtext', 'longtext', 'LONGTEXT'] as $type) {
            $this->assertTrue(StagingDb::isText($type), $type);
        }
        foreach (['bigint(20) unsigned', 'datetime', 'longblob', 'enum(\'a\')', 'json'] as $type) {
            $this->assertFalse(StagingDb::isText($type), $type);
        }
    }

    /** NFA: Zeilensperren auf Live kurz halten */
    public function testChunkAdaptsToTheLastStep(): void
    {
        $this->assertSame(4000, StagingDb::adapt(2000, 0.1, 12.0));
        $this->assertSame(1000, StagingDb::adapt(2000, 5.0, 12.0));
        $this->assertSame(2000, StagingDb::adapt(2000, 2.0, 12.0));
        $this->assertSame(StagingDb::MAX_CHUNK, StagingDb::adapt(StagingDb::MAX_CHUNK, 0.0, 12.0));
        $this->assertSame(StagingDb::MIN_CHUNK, StagingDb::adapt(StagingDb::MIN_CHUNK, 99.0, 12.0));
    }

    public function testDiskFullIsRecognized(): void
    {
        foreach (['The table \'stgabc123_posts\' is full', 'Disk full (/tmp/#sql); waiting', 'Got error 28 from storage engine', 'Error writing file (Errcode: 28 "No space left on device")'] as $error) {
            $this->assertTrue(StagingDb::diskFull($error), $error);
        }
        $this->assertFalse(StagingDb::diskFull("Duplicate entry '1' for key 'PRIMARY'"));
    }

    public function testIdentifiersAreQuoted(): void
    {
        $this->assertSame('`ID`', StagingDb::ident('ID'));
        $this->assertSame('`a``b`', StagingDb::ident('a`b'));
        $this->assertSame('`50% off`', StagingDb::ident('50% off'));
    }

    /** Leitplanke 2: SQL läuft nur über die Methoden, die ihr Ziel über StagingGuard prüfen. */
    public function testNoPublicWayToRunArbitrarySql(): void
    {
        $this->assertTrue((new \ReflectionMethod(StagingDb::class, 'exec'))->isPrivate());
    }

    public function testCopyCreatesTheTableAndFillsItInKeysetSteps(): void
    {
        $this->db->answer('/^SHOW KEYS FROM `wp_posts`/', ['ID']);
        $this->db->answer('/^SHOW TABLE STATUS/', [['Rows' => '5']]);
        $this->db->answer('/^INSERT/', 2, 1);
        $this->db->answer('/^SELECT MAX/', '2');

        $result = StagingDb::copyTable($this->guard(), Scope::fromArray(null), self::entry('posts'), null, 2, microtime(true) + 60, 12.0);

        $this->assertTrue($result['done']);
        $this->assertNull($result['cursor']);
        $this->assertSame([
            'CREATE TABLE `stgabc123_posts` LIKE `wp_posts`',
            'INSERT INTO `stgabc123_posts` SELECT t.* FROM `wp_posts` t ORDER BY t.`ID` LIMIT 0, 2',
            "INSERT INTO `stgabc123_posts` SELECT t.* FROM `wp_posts` t WHERE t.`ID` > '2' ORDER BY t.`ID` LIMIT 0, 4",
        ], $this->db->writes());
        $this->assertContains('SELECT MAX(`ID`) FROM `stgabc123_posts`', $this->db->queries);
    }

    public function testCopyStopsAtTheDeadlineWithACursor(): void
    {
        $this->db->answer('/^SHOW KEYS/', ['ID']);
        $this->db->answer('/^INSERT/', 2);
        $this->db->answer('/^SELECT MAX/', '17');

        $result = StagingDb::copyTable($this->guard(), Scope::fromArray(null), self::entry('posts'), null, 2, 0.0, 12.0);

        $this->assertFalse($result['done']);
        $this->assertSame('ID', $result['cursor']['pk']);
        $this->assertSame('17', $result['cursor']['after']);
        $this->assertCount(2, $this->db->writes());
    }

    public function testCopyWithoutPrimaryKeyTakesSmallTablesInOneStep(): void
    {
        $this->db->answer('/^SHOW TABLE STATUS/', [['Rows' => '40']]);
        $this->db->answer('/^INSERT/', 40);

        $result = StagingDb::copyTable($this->guard(), Scope::fromArray(null), self::entry('term_relationships'), null, 2, microtime(true) + 60, 12.0);

        $this->assertTrue($result['done']);
        $this->assertSame('INSERT INTO `stgabc123_term_relationships` SELECT t.* FROM `wp_term_relationships` t', $this->db->writes()[1]);
    }

    public function testDeselectedTablesStayEmpty(): void
    {
        foreach ([Scope::STRUCTURE, Scope::SKIP] as $mode) {
            $this->db->queries = [];
            $result            = StagingDb::copyTable($this->guard(), Scope::fromArray(null), self::entry('wc_orders', $mode), null, 2, microtime(true) + 60, 12.0);
            $this->assertTrue($result['done']);
            $this->assertSame(['CREATE TABLE `stgabc123_wc_orders` LIKE `wp_wc_orders`'], $this->db->queries);
        }
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function foreignTargets(): array
    {
        return [
            'live table as target'      => ['wp_posts', 'wp_posts'],
            'another live table'        => ['wp_posts', 'wp_users'],
            'staging table as source'   => ['stgabc123_posts', 'stgabc123_posts'],
            'pair does not belong'      => ['wp_posts', 'stgabc123_users'],
            'sql in the target'         => ['wp_posts', 'stgabc123_posts` SELECT 1; DROP TABLE `wp_posts'],
            'sql in the source'         => ['wp_posts` t; DROP TABLE `wp_users', 'stgabc123_posts'],
            'other prefix by wildcard'  => ['wp_posts', 'stgabc1230posts'],
        ];
    }

    /** Leitplanke 2 */
    #[DataProvider('foreignTargets')]
    public function testCopyRefusesEverythingButItsOwnStagingTable(string $live, string $stg): void
    {
        try {
            StagingDb::copyTable($this->guard(), Scope::fromArray(null), ['live' => $live, 'stg' => $stg, 'mode' => Scope::FULL], null, 2, microtime(true) + 60, 12.0);
            $this->fail('expected a guard exception');
        } catch (StagingException $e) {
            $this->assertSame(StagingException::GUARD, $e->reason());
        }
        $this->assertSame([], $this->db->queries);
    }

    /** V2: abgewählte Post-Typen und eigene Optionen bleiben wie beim Pull auf Live. */
    public function testRowFilterOfThePullAppliesToTheCopy(): void
    {
        $scope = Scope::fromArray(['exclude_post_types' => ['shop_order']]);
        $this->db->answer('/^SHOW KEYS/', ['meta_id']);

        StagingDb::copyTable($this->guard(), $scope, self::entry('postmeta'), null, 500, microtime(true) + 60, 12.0);
        $this->assertSame(
            "INSERT INTO `stgabc123_postmeta` SELECT t.* FROM `wp_postmeta` t JOIN `wp_posts` p ON p.`ID` = t.`post_id` WHERE p.`post_type` NOT IN ('shop_order') ORDER BY t.`meta_id` LIMIT 0, 500",
            $this->db->writes()[1]
        );

        $this->db->queries = [];
        StagingDb::copyTable($this->guard(), Scope::fromArray(null), self::entry('options'), null, 500, microtime(true) + 60, 12.0);
        $this->assertStringContainsString("WHERE t.`option_name` NOT LIKE 'wpsync\\_%' ORDER BY", $this->db->writes()[1]);
    }

    public function testCursorColumnIsQuoted(): void
    {
        $cursor = ['pk' => 'a`b', 'after' => "x' OR '1", 'offset' => 0, 'rows' => 0];
        StagingDb::copyTable($this->guard(), Scope::fromArray(null), self::entry('posts'), $cursor, 2, microtime(true) + 60, 12.0);
        $this->assertSame(
            ["INSERT INTO `stgabc123_posts` SELECT t.* FROM `wp_posts` t WHERE t.`a``b` > 'x\\' OR \\'1' ORDER BY t.`a``b` LIMIT 0, 2"],
            $this->db->queries
        );
    }

    public function testCopyReportsAFullDisk(): void
    {
        $this->db->fail('/^INSERT/', "The table 'stgabc123_posts' is full");
        try {
            StagingDb::copyTable($this->guard(), Scope::fromArray(null), self::entry('posts'), null, 2, microtime(true) + 60, 12.0);
            $this->fail('expected an exception');
        } catch (StagingException $e) {
            $this->assertSame(StagingException::SPACE, $e->reason());
        }
    }

    /** T2, AC-88: dieselben Pseudonyme wie beim Pull, obwohl die Tabelle anders heisst. */
    public function testAnonymizeWritesThePseudonymsOfThePull(): void
    {
        $row = [
            'ID' => '7', 'user_login' => 'erika', 'user_pass' => '$P$Babcdefghijklmnopqrstuv', 'user_nicename' => 'erika',
            'user_email' => 'Erika@Example.com', 'user_url' => 'https://erika.example', 'user_registered' => '2024-01-01 00:00:00',
            'user_activation_key' => '', 'user_status' => '0', 'display_name' => 'Erika Mustermann',
        ];
        $pull = (new Anonymizer(self::KEY, 'wp_'))->rows('wp_users', [$row])[0];
        $this->db->answer('/^SHOW KEYS FROM `stgabc123_users`/', ['ID']);
        $this->db->answer('/^SELECT \*/', [$row]);

        $result = StagingDb::anonymize($this->guard(), self::KEY, self::entry('users'), null, microtime(true) + 60);

        $this->assertSame(['after' => null, 'done' => true], $result);
        $this->assertContains('SELECT * FROM `stgabc123_users` ORDER BY `ID` LIMIT ' . StagingDb::READ_ROWS, $this->db->queries);
        $writes = $this->db->writes();
        $this->assertCount(1, $writes);
        $this->assertStringStartsWith('UPDATE `stgabc123_users` SET ', $writes[0]);
        $this->assertStringEndsWith(" WHERE `ID` = '7'", $writes[0]);
        foreach (['user_login', 'user_pass', 'user_nicename', 'user_email', 'user_url', 'display_name'] as $column) {
            $this->assertStringContainsString('`' . $column . "` = '" . $pull[$column] . "'", $writes[0]);
        }
        $this->assertStringContainsString("`user_pass` = '" . Anonymizer::NO_LOGIN . "'", $writes[0]);
        $this->assertStringNotContainsStringIgnoringCase('erika', $writes[0]);
        $this->assertStringNotContainsString('user_registered', $writes[0]);
    }

    public function testAnonymizeContinuesBehindTheCursor(): void
    {
        $rows = [];
        for ($i = 1; $i <= StagingDb::READ_ROWS; $i++) {
            $rows[] = ['umeta_id' => (string) $i, 'user_id' => '1', 'meta_key' => 'rich_editing', 'meta_value' => 'true'];
        }
        $this->db->answer('/^SHOW KEYS/', ['umeta_id']);
        $this->db->answer('/^SELECT \*/', $rows);

        $result = StagingDb::anonymize($this->guard(), self::KEY, self::entry('usermeta'), '50', 0.0);

        $this->assertSame(['after' => (string) StagingDb::READ_ROWS, 'done' => false], $result);
        $this->assertStringContainsString("WHERE `umeta_id` > '50' ORDER BY `umeta_id`", $this->db->queries[1]);
        $this->assertSame([], $this->db->writes());
    }

    /** V18: lieber Abbruch als Klartext */
    public function testAnonymizeAbortsWithoutSinglePrimaryKey(): void
    {
        $this->db->answer('/^SHOW KEYS/', []);
        try {
            StagingDb::anonymize($this->guard(), self::KEY, self::entry('users'), null, microtime(true) + 60);
            $this->fail('expected an exception');
        } catch (StagingException $e) {
            $this->assertSame(StagingException::FAILED, $e->reason());
            $this->assertStringContainsString('stgabc123_users', $e->getMessage());
        }
        $this->assertCount(1, $this->db->queries);
    }

    /** Geprüfte Tabellen ohne Personendaten (D4) haben teils zusammengesetzte Schlüssel – sie brauchen keinen. */
    public function testTablesWithoutReplacingRulesNeedNoPrimaryKey(): void
    {
        foreach (['wc_order_tax_lookup', 'wc_order_coupon_lookup', 'terms', 'e_submissions_values'] as $name) {
            $this->assertSame(['after' => null, 'done' => true], StagingDb::anonymize($this->guard(), self::KEY, self::entry($name), null, microtime(true) + 60));
        }
        $this->assertSame([], $this->db->queries);
    }

    /** T2: ein Lesefehler darf nicht wie „fertig“ aussehen. */
    public function testAnonymizeIsNeverDoneAfterAFailedRead(): void
    {
        $this->db->answer('/^SHOW KEYS/', ['ID']);
        $this->db->fail('/^SELECT \*/', 'MySQL server has gone away');
        $this->expectException(StagingException::class);
        $this->expectExceptionMessage('gone away');
        StagingDb::anonymize($this->guard(), self::KEY, self::entry('users'), null, microtime(true) + 60);
    }

    public function testAnonymizeIsNeverDoneWhenThePrimaryKeyCannotBeRead(): void
    {
        $this->db->fail('/^SHOW KEYS/', 'Lock wait timeout exceeded');
        $this->expectException(StagingException::class);
        $this->expectExceptionMessage('Lock wait timeout');
        StagingDb::anonymize($this->guard(), self::KEY, self::entry('users'), null, microtime(true) + 60);
    }

    /** T2: eine Zeile, die sich nicht ändern liess, steht noch im Klartext. */
    public function testAnonymizeAbortsWhenARowStaysAsItWas(): void
    {
        $this->db->answer('/^SHOW KEYS/', ['ID']);
        $this->db->answer('/^SELECT \*/', [['ID' => '7', 'user_email' => 'erika@example.com']]);
        $this->db->answer('/^UPDATE/', 0);
        $this->expectException(StagingException::class);
        StagingDb::anonymize($this->guard(), self::KEY, self::entry('users'), null, microtime(true) + 60);
    }

    public function testAnonymizeNeedsAKey(): void
    {
        try {
            StagingDb::anonymize($this->guard(), '', self::entry('users'), null, microtime(true) + 60);
            $this->fail('expected an exception');
        } catch (StagingException $e) {
            $this->assertSame(StagingException::FAILED, $e->reason());
        }
        $this->assertSame([], $this->db->queries);
    }

    public function testAnonymizeRefusesALiveTable(): void
    {
        try {
            StagingDb::anonymize($this->guard(), self::KEY, ['live' => 'wp_users', 'stg' => 'wp_users', 'mode' => Scope::FULL], null, microtime(true) + 60);
            $this->fail('expected a guard exception');
        } catch (StagingException $e) {
            $this->assertSame(StagingException::GUARD, $e->reason());
        }
        $this->assertSame([], $this->db->queries);
    }

    /** AC-89 */
    public function testPrefixBoundKeysAreRenamed(): void
    {
        StagingDb::fixPrefix($this->guard());
        $this->assertSame([
            "UPDATE `stgabc123_options` SET `option_name` = 'stgabc123_user_roles' WHERE `option_name` = 'wp_user_roles'",
            "UPDATE `stgabc123_usermeta` SET `meta_key` = CONCAT('stgabc123_', SUBSTRING(`meta_key`, 4)) WHERE `meta_key` LIKE 'wp\\\\_%'",
        ], $this->db->queries);
    }

    /** AC-87 */
    public function testTextColumnsSkipGuidKeysAndDeselectedTables(): void
    {
        $this->db->answer('/^SHOW KEYS FROM `stgabc123_posts`/', ['ID']);
        $this->db->answer('/^SHOW COLUMNS FROM `stgabc123_posts`/', [
            ['Field' => 'ID', 'Type' => 'bigint(20) unsigned'], ['Field' => 'post_content', 'Type' => 'longtext'],
            ['Field' => 'guid', 'Type' => 'varchar(255)'], ['Field' => 'post_date', 'Type' => 'datetime'],
        ]);
        $this->db->answer('/^SHOW KEYS FROM `stgabc123_woocommerce_sessions`/', ['session_key']);
        $this->db->answer('/^SHOW COLUMNS FROM `stgabc123_woocommerce_sessions`/', [
            ['Field' => 'session_key', 'Type' => 'char(32)'], ['Field' => 'guid', 'Type' => 'varchar(255)'],
        ]);

        $columns = StagingDb::textColumns($this->guard(), [self::entry('posts'), self::entry('woocommerce_sessions'), self::entry('wc_orders', Scope::STRUCTURE)]);

        $this->assertSame([
            ['table' => 'stgabc123_posts', 'column' => 'post_content', 'pk' => 'ID'],
            ['table' => 'stgabc123_woocommerce_sessions', 'column' => 'guid', 'pk' => 'session_key'],
        ], $columns);
    }

    /** Ohne Spaltenliste blieben Live-URLs in der Kopie – das ist ein Fehler, kein leeres Ergebnis. */
    public function testTextColumnsFailWhenTheColumnsCannotBeRead(): void
    {
        $this->db->answer('/^SHOW KEYS/', ['ID']);
        $this->db->fail('/^SHOW COLUMNS/', 'Table is marked as crashed');
        $this->expectException(StagingException::class);
        StagingDb::textColumns($this->guard(), [self::entry('posts')]);
    }

    private function replace(): StagingReplace
    {
        return new StagingReplace('https://example.com', '/' . self::DIR);
    }

    public function testUrlsAreRewrittenRowByRow(): void
    {
        $this->db->answer('/^SELECT/', [
            ['k' => '1', 'v' => '<a href="https://example.com/shop/">Shop</a>'],
            ['k' => '2', 'v' => 'mail an info@example.com'],
        ]);

        $result = StagingDb::replaceUrls($this->guard(), $this->replace(), ['table' => 'stgabc123_posts', 'column' => 'post_content', 'pk' => 'ID'], '0', microtime(true) + 60);

        $this->assertSame(['cursor' => null, 'done' => true, 'changed' => 1], $result);
        $this->assertSame(
            "SELECT `ID` AS k, `post_content` AS v FROM `stgabc123_posts` WHERE `post_content` LIKE '%example.com%' AND `ID` > '0' ORDER BY `ID` LIMIT " . StagingDb::READ_ROWS,
            $this->db->queries[0]
        );
        $this->assertSame(
            ['UPDATE `stgabc123_posts` SET `post_content` = \'<a href=\\"https://example.com/' . self::DIR . '/shop/\\">Shop</a>\' WHERE `ID` = \'1\''],
            $this->db->writes()
        );
    }

    public function testUrlsWithoutPrimaryKeyGoByValue(): void
    {
        $this->db->answer('/^SELECT/', [['k' => 'https://example.com', 'v' => 'https://example.com']]);

        $result = StagingDb::replaceUrls($this->guard(), $this->replace(), ['table' => 'stgabc123_links', 'column' => 'we`ird', 'pk' => null], null, microtime(true) + 60);

        $this->assertSame(1, $result['changed']);
        $this->assertSame(
            "SELECT DISTINCT BINARY `we``ird` AS k, `we``ird` AS v FROM `stgabc123_links` WHERE `we``ird` LIKE '%example.com%' ORDER BY k LIMIT " . StagingDb::READ_ROWS,
            $this->db->queries[0]
        );
        $this->assertSame(
            ["UPDATE `stgabc123_links` SET `we``ird` = 'https://example.com/" . self::DIR . "' WHERE BINARY `we``ird` = BINARY 'https://example.com'"],
            $this->db->writes()
        );
    }

    public function testUrlsRefuseALiveTableAndFailOnAReadError(): void
    {
        try {
            StagingDb::replaceUrls($this->guard(), $this->replace(), ['table' => 'wp_posts', 'column' => 'post_content', 'pk' => 'ID'], null, microtime(true) + 60);
            $this->fail('expected a guard exception');
        } catch (StagingException $e) {
            $this->assertSame(StagingException::GUARD, $e->reason());
        }
        $this->assertSame([], $this->db->queries);

        $this->db->fail('/^SELECT/', 'Out of sort memory');
        $this->expectException(StagingException::class);
        StagingDb::replaceUrls($this->guard(), $this->replace(), ['table' => 'stgabc123_posts', 'column' => 'post_content', 'pk' => 'ID'], null, microtime(true) + 60);
    }

    /** AC-90, AC-95, V16 */
    public function testSettingsLockTheCopyInTheDatabase(): void
    {
        $this->db->answer("/`option_name` = 'active_plugins'\\z/", serialize(['woocommerce/woocommerce.php', 'wpsync-agent/wpsync-agent.php', 'wp-mail-smtp/wp_mail_smtp.php']));
        $this->db->answer("/`option_name` = 'woocommerce_gateway_order'\\z/", serialize(['bacs' => 0, 'my-pay' => 1]));
        $this->db->answer("/`option_name` = 'woocommerce_stripe_settings'\\z/", serialize(['enabled' => 'yes', 'title' => 'Karte']));
        $this->db->answer("/`option_name` = 'woocommerce_my-pay_settings'\\z/", serialize(['title' => 'ohne enabled']));
        $this->db->answer("/`option_name` = 'woocommerce_paypal_settings'\\z/", serialize(['enabled' => 'no']));
        $this->db->answer("/`option_name` = 'woocommerce_bacs_settings'\\z/", serialize(['enabled' => 'yes']));
        $this->db->answer('/^SHOW TABLES LIKE/', 'stgabc123_wc_webhooks');

        StagingDb::settings($this->guard(), Scope::fromArray(null));

        $writes = $this->db->writes();
        $this->assertContains("UPDATE `stgabc123_options` SET `option_value` = '0' WHERE `option_name` = 'blog_public'", $writes);
        $this->assertContains("UPDATE `stgabc123_options` SET `option_value` = '" . addslashes(serialize(['woocommerce/woocommerce.php'])) . "' WHERE `option_name` = 'active_plugins'", $writes);
        $this->assertContains("UPDATE `stgabc123_options` SET `option_value` = '" . addslashes(serialize(['enabled' => 'no', 'title' => 'Karte'])) . "' WHERE `option_name` = 'woocommerce_stripe_settings'", $writes);
        $this->assertContains("UPDATE `stgabc123_options` SET `option_value` = '" . addslashes(serialize(['title' => 'ohne enabled', 'enabled' => 'no'])) . "' WHERE `option_name` = 'woocommerce_my-pay_settings'", $writes);
        $this->assertContains("UPDATE `stgabc123_wc_webhooks` SET `status` = 'paused' WHERE `status` <> 'paused'", $writes);
        // Leitplanke 4: kein Upload-Ordner von Live
        $this->assertContains("UPDATE `stgabc123_options` SET `option_value` = '' WHERE `option_name` = 'upload_path'", $writes);
        $this->assertContains("UPDATE `stgabc123_options` SET `option_value` = '' WHERE `option_name` = 'upload_url_path'", $writes);
        $this->assertCount(7, $writes);
        $this->assertNotContains("SELECT `option_value` FROM `stgabc123_options` WHERE `option_name` = 'woocommerce_bacs_settings'", $this->db->queries);
    }

    public function testSettingsWithoutWebhookTableAndWithBrokenOptions(): void
    {
        $this->db->answer("/`option_name` = 'active_plugins'\\z/", 'O:8:"stdClass":0:{}');

        StagingDb::settings($this->guard(), Scope::fromArray(null));

        $writes = $this->db->writes();
        $this->assertContains("UPDATE `stgabc123_options` SET `option_value` = 'a:0:{}' WHERE `option_name` = 'active_plugins'", $writes);
        $this->assertCount(4, $writes);
    }

    /** Ein Lesefehler darf weder Plugins noch Webhooks aktiv lassen. */
    public function testSettingsFailWhenAnOptionCannotBeRead(): void
    {
        $this->db->fail('/^SHOW TABLES LIKE/', 'MySQL server has gone away');
        $this->expectException(StagingException::class);
        StagingDb::settings($this->guard(), Scope::fromArray(null));
    }

    public function testDropTakesOnlyListedStagingTables(): void
    {
        $this->db->answer('/^SHOW TABLES/', ['stgabc123_posts', 'stgabc123_users']);

        $this->assertTrue(StagingDb::dropAll($this->guard(), microtime(true) + 60));
        $this->assertSame([
            "SHOW TABLES LIKE 'stgabc123\\_%'",
            'DROP TABLE IF EXISTS `stgabc123_posts`',
            'DROP TABLE IF EXISTS `stgabc123_users`',
        ], $this->db->queries);
    }

    public function testDropStopsAtTheDeadline(): void
    {
        $this->db->answer('/^SHOW TABLES/', ['stgabc123_posts', 'stgabc123_users']);
        $this->assertFalse(StagingDb::dropAll($this->guard(), 0.0));
        $this->assertSame(['DROP TABLE IF EXISTS `stgabc123_posts`'], $this->db->writes());
    }

    /** „_“ als Platzhalter: was SHOW TABLES auch liefert, gelöscht wird nur mit dem wörtlichen Präfix. */
    public function testDropRefusesATableThatOnlyMatchesTheWildcard(): void
    {
        $this->db->answer('/^SHOW TABLES/', ['stgabc1230posts', 'stgabc123_posts']);
        try {
            StagingDb::dropAll($this->guard(), microtime(true) + 60);
            $this->fail('expected a guard exception');
        } catch (StagingException $e) {
            $this->assertSame(StagingException::GUARD, $e->reason());
        }
        $this->assertSame([], $this->db->writes());
    }

    /** Sonst gälte die Kopie als gelöscht, während ihre Tabellen noch da sind. */
    public function testDropFailsWhenTheListCannotBeRead(): void
    {
        $this->db->fail('/^SHOW TABLES/', 'MySQL server has gone away');
        $this->expectException(StagingException::class);
        StagingDb::dropAll($this->guard(), microtime(true) + 60);
    }

    public function testSizesAreReadForTheRightTables(): void
    {
        $this->db->answer('/table_name LIKE/', '4096');
        $this->assertSame(4096, StagingDb::stagingBytes($this->guard()));
        $this->assertStringEndsWith("table_name LIKE 'stgabc123\\\\_%'", $this->db->queries[0]);
    }
}
