<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\TestCase;
use WpSync\Anonymizer;

final class AnonymizerTest extends TestCase
{
    private const KEY   = '000102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f';
    private const OTHER = 'ff0102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f';

    /**
     * @param array<string, string|null> $row
     * @return array<string, string|null>
     */
    private function one(string $table, array $row, string $key = self::KEY): array
    {
        return (new Anonymizer($key, 'wp_'))->rows($table, [$row])[0];
    }

    public function testUsersLoseEveryPersonalValue(): void
    {
        $row = $this->one('wp_users', [
            'ID' => '7', 'user_login' => 'erika', 'user_pass' => '$P$Babcdefghijklmnopqrstuv', 'user_nicename' => 'erika',
            'user_email' => 'Erika@Example.com', 'user_url' => 'https://erika.example', 'user_registered' => '2024-01-01 00:00:00',
            'user_activation_key' => '1700000000:$P$Bxyz', 'user_status' => '0', 'display_name' => 'Erika Mustermann',
        ]);

        $this->assertSame('7', $row['ID']);
        $this->assertSame('2024-01-01 00:00:00', $row['user_registered']);
        $this->assertMatchesRegularExpression('/^user_[0-9a-f]{16}\z/', $row['user_login']);
        $this->assertMatchesRegularExpression('/^user-[0-9a-f]{16}\z/', $row['user_nicename']);
        $this->assertMatchesRegularExpression('/^user-[0-9a-f]{16}@example\.invalid\z/', $row['user_email']);
        $this->assertMatchesRegularExpression('/^Nutzer [0-9a-f]{6}\z/', $row['display_name']);
        $this->assertSame(Anonymizer::NO_LOGIN, $row['user_pass']);
        $this->assertSame('', $row['user_url']);
        $this->assertSame('', $row['user_activation_key']);
        $this->assertStringNotContainsStringIgnoringCase('erika', implode('|', $row));
    }

    /** AC-33: gleiche Eingabe, gleiches Pseudonym – in jeder Tabelle und unabhängig von der Schreibweise der E-Mail. */
    public function testSameInputGivesSamePseudonymAcrossTables(): void
    {
        $user    = $this->one('wp_users', ['user_email' => 'erika@example.com', 'user_login' => 'erika']);
        $comment = $this->one('wp_comments', ['comment_author_email' => ' Erika@Example.COM ', 'comment_type' => 'comment']);
        $meta    = $this->one('wp_usermeta', ['meta_key' => 'nickname', 'meta_value' => 'erika']);

        $this->assertSame($user['user_email'], $comment['comment_author_email']);
        $this->assertSame($user['user_login'], $meta['meta_value'], 'nickname defaults to the login');
        $this->assertSame($user, $this->one('wp_users', ['user_email' => 'erika@example.com', 'user_login' => 'erika']), 'stable across calls');
    }

    public function testAnotherKeyGivesAnotherPseudonym(): void
    {
        $a = $this->one('wp_users', ['user_email' => 'erika@example.com']);
        $b = $this->one('wp_users', ['user_email' => 'erika@example.com'], self::OTHER);
        $this->assertNotSame($a['user_email'], $b['user_email']);
    }

    public function testNullStaysNullAndEmptyStaysEmptyExceptForFixed(): void
    {
        $row = $this->one('wp_users', ['user_email' => '', 'display_name' => null, 'user_pass' => '', 'user_url' => null]);
        $this->assertSame('', $row['user_email']);
        $this->assertNull($row['display_name']);
        $this->assertSame(Anonymizer::NO_LOGIN, $row['user_pass'], 'fixed replaces empty values too');
        $this->assertNull($row['user_url']);
    }

    public function testUsermetaOnlyKnownKeysChange(): void
    {
        $first = $this->one('wp_usermeta', ['umeta_id' => '1', 'user_id' => '7', 'meta_key' => 'first_name', 'meta_value' => 'Erika']);
        $this->assertMatchesRegularExpression('/^Vorname [0-9a-f]{6}\z/', $first['meta_value']);
        $this->assertSame('7', $first['user_id']);

        $tokens = $this->one('wp_usermeta', ['meta_key' => 'session_tokens', 'meta_value' => 'a:1:{s:64:"x";a:1:{s:2:"ip";s:11:"203.0.113.7";}}']);
        $this->assertSame('', $tokens['meta_value']);

        $caps = ['meta_key' => 'wp_capabilities', 'meta_value' => 'a:1:{s:13:"administrator";b:1;}'];
        $this->assertSame($caps, $this->one('wp_usermeta', $caps));
    }

    public function testCommentsKeepTheirTextExceptOrderNotes(): void
    {
        $comment = $this->one('wp_comments', [
            'comment_ID' => '3', 'comment_author' => 'Erika Mustermann', 'comment_author_email' => 'erika@example.com',
            'comment_author_url' => 'https://erika.example', 'comment_author_IP' => '203.0.113.7',
            'comment_content' => 'Schöner Beitrag', 'comment_agent' => 'Mozilla/5.0', 'comment_type' => 'comment',
        ]);
        $this->assertMatchesRegularExpression('/^Gast [0-9a-f]{6}\z/', $comment['comment_author']);
        $this->assertSame('0.0.0.0', $comment['comment_author_IP']);
        $this->assertSame('', $comment['comment_author_url']);
        $this->assertSame('', $comment['comment_agent']);
        $this->assertSame('Schöner Beitrag', $comment['comment_content']);

        $note = $this->one('wp_comments', ['comment_content' => 'Rechnung an erika@example.com gesendet', 'comment_type' => 'order_note']);
        $this->assertSame('Bestellnotiz (anonymisiert)', $note['comment_content']);
    }

    public function testCommentmetaAndOptions(): void
    {
        $akismet = $this->one('wp_commentmeta', ['meta_key' => 'akismet_as_submitted', 'meta_value' => 'a:1:{s:7:"user_ip";s:11:"203.0.113.7";}']);
        $this->assertSame('', $akismet['meta_value']);
        $rating = ['meta_key' => 'rating', 'meta_value' => '5'];
        $this->assertSame($rating, $this->one('wp_commentmeta', $rating));

        $admin = $this->one('wp_options', ['option_id' => '6', 'option_name' => 'admin_email', 'option_value' => 'chef@kunde.de', 'autoload' => 'yes']);
        $this->assertMatchesRegularExpression('/^user-[0-9a-f]{16}@example\.invalid\z/', $admin['option_value']);
        $home = ['option_id' => '2', 'option_name' => 'home', 'option_value' => 'https://kunde.de', 'autoload' => 'yes'];
        $this->assertSame($home, $this->one('wp_options', $home));
    }

    public function testTablesWithoutRuleStayUntouched(): void
    {
        $row = ['id' => '1', 'value' => 'erika@example.com'];
        $this->assertSame($row, $this->one('wp_e_submissions_values', $row));
        $this->assertSame([], (new Anonymizer(self::KEY, 'wp_'))->rows('wp_users', []));
    }

    public function testCovers(): void
    {
        $this->assertTrue(Anonymizer::covers('wp_users', 'wp_'));
        $this->assertTrue(Anonymizer::covers('kd_usermeta', 'kd_'));
        $this->assertTrue(Anonymizer::covers('wp_options', 'wp_'));
        $this->assertFalse(Anonymizer::covers('wp_e_submissions_values', 'wp_'));
        $this->assertFalse(Anonymizer::covers('wp_terms', 'wp_'));
    }

    public function testIdCombinesRulesVersionAndKeyFingerprint(): void
    {
        $id = Anonymizer::id(self::KEY);
        $this->assertMatchesRegularExpression('/^' . Anonymizer::RULES_VERSION . '\.[0-9a-f]{8}\z/', $id);
        $this->assertSame($id, Anonymizer::id(self::KEY));
        $this->assertNotSame($id, Anonymizer::id(self::OTHER));
        $this->assertStringNotContainsString(substr(self::KEY, 0, 8), $id, 'the key itself never leaves the server');
    }
}
