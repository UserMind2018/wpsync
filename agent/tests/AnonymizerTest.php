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

    /** Staging anonymisiert in der Datenbank und braucht nur dort einen Primärschlüssel, wo etwas ersetzt wird. */
    public function testChangesOnlyWhereARuleReplacesValues(): void
    {
        $this->assertTrue(Anonymizer::changes('wp_users', 'wp_'));
        $this->assertTrue(Anonymizer::changes('stgabc123_wc_orders', 'stgabc123_'));
        $this->assertFalse(Anonymizer::changes('wp_wc_order_tax_lookup', 'wp_'));
        $this->assertFalse(Anonymizer::changes('wp_terms', 'wp_'));
        $this->assertFalse(Anonymizer::changes('wp_users', 'stgabc123_'));
    }

    public function testIdCombinesRulesVersionAndKeyFingerprint(): void
    {
        $id = Anonymizer::id(self::KEY);
        $this->assertMatchesRegularExpression('/^' . Anonymizer::RULES_VERSION . '\.[0-9a-f]{8}\z/', $id);
        $this->assertSame($id, Anonymizer::id(self::KEY));
        $this->assertNotSame($id, Anonymizer::id(self::OTHER));
        $this->assertStringNotContainsString(substr(self::KEY, 0, 8), $id, 'the key itself never leaves the server');
    }

    /** AC-40: Adressen weg, Land und Bundesland bleiben (Steuer- und Versandtests). */
    public function testWooAddressesKeepCountryAndState(): void
    {
        $row = $this->one('wp_wc_order_addresses', [
            'id' => '1', 'order_id' => '10', 'address_type' => 'billing', 'first_name' => 'Erika', 'last_name' => 'Mustermann',
            'company' => 'Muster GmbH', 'address_1' => 'Heidestraße 17', 'address_2' => 'Hinterhaus', 'city' => 'Köln',
            'state' => 'NW', 'postcode' => '51147', 'country' => 'DE', 'email' => 'erika@example.com', 'phone' => '+49 221 123',
        ]);
        $this->assertSame(['10', 'billing', 'NW', 'DE'], [$row['order_id'], $row['address_type'], $row['state'], $row['country']]);
        $this->assertMatchesRegularExpression('/^Vorname [0-9a-f]{6}\z/', $row['first_name']);
        $this->assertMatchesRegularExpression('/^Nachname [0-9a-f]{6}\z/', $row['last_name']);
        $this->assertSame(['', 'Musterstraße 1', '', 'Musterstadt', '00000', ''], [
            $row['company'], $row['address_1'], $row['address_2'], $row['city'], $row['postcode'], $row['phone'],
        ]);
    }

    /** AC-33: eine Person, ein Pseudonym – in Core, HPOS, klassischer Postmeta, Usermeta und Lookup. */
    public function testWooEmailMatchesTheUserEmailEverywhere(): void
    {
        $expected = $this->one('wp_users', ['user_email' => 'erika@example.com'])['user_email'];
        $this->assertSame($expected, $this->one('wp_wc_orders', ['billing_email' => 'Erika@example.com'])['billing_email']);
        $this->assertSame($expected, $this->one('wp_wc_order_addresses', ['email' => 'erika@example.com'])['email']);
        $this->assertSame($expected, $this->one('wp_wc_customer_lookup', ['email' => 'erika@example.com'])['email']);
        $this->assertSame($expected, $this->one('wp_postmeta', ['meta_key' => '_billing_email', 'meta_value' => 'erika@example.com'])['meta_value']);
        $this->assertSame($expected, $this->one('wp_usermeta', ['meta_key' => 'billing_email', 'meta_value' => 'erika@example.com'])['meta_value']);
        $this->assertSame($expected, $this->one('wp_woocommerce_downloadable_product_permissions', ['user_email' => 'erika@example.com'])['user_email']);
    }

    public function testWooOrderColumns(): void
    {
        $order = $this->one('wp_wc_orders', [
            'id' => '10', 'status' => 'wc-completed', 'total_amount' => '19.99', 'customer_id' => '7', 'billing_email' => 'erika@example.com',
            'ip_address' => '203.0.113.7', 'user_agent' => 'Mozilla/5.0', 'customer_note' => 'Bitte bei Nachbar Meier abgeben', 'transaction_id' => 'pi_3abc',
        ]);
        $this->assertSame(['10', 'wc-completed', '19.99', '7'], [$order['id'], $order['status'], $order['total_amount'], $order['customer_id']]);
        $this->assertSame(['0.0.0.0', '', '', ''], [$order['ip_address'], $order['user_agent'], $order['customer_note'], $order['transaction_id']]);
    }

    /** Der Order-Key steht an drei Stellen und muss überall gleich bleiben. */
    public function testOrderKeyStaysConsistent(): void
    {
        $post = $this->one('wp_posts', ['ID' => '10', 'post_type' => 'shop_order', 'post_password' => 'wc_order_AbC123', 'post_excerpt' => 'Bitte klingeln']);
        $meta = $this->one('wp_postmeta', ['meta_key' => '_order_key', 'meta_value' => 'wc_order_AbC123']);
        $hpos = $this->one('wp_wc_order_operational_data', ['order_id' => '10', 'order_key' => 'wc_order_AbC123']);

        $this->assertMatchesRegularExpression('/^wc_order_[0-9a-f]{16}\z/', $post['post_password']);
        $this->assertSame($post['post_password'], $meta['meta_value']);
        $this->assertSame($post['post_password'], $hpos['order_key']);
        $this->assertSame('', $post['post_excerpt']);
    }

    public function testPostsOfOtherTypesStayUntouched(): void
    {
        $page = ['ID' => '2', 'post_type' => 'page', 'post_password' => 'geheim', 'post_excerpt' => 'Auszug', 'post_title' => 'Über uns'];
        $this->assertSame($page, $this->one('wp_posts', $page));
    }

    /** D5: WooCommerce Subscriptions speichert _billing_period – nur explizite Adress-Schlüssel ersetzen. */
    public function testPostmetaReplacesOnlyExplicitAddressKeys(): void
    {
        $first = $this->one('wp_postmeta', ['meta_id' => '1', 'post_id' => '10', 'meta_key' => '_billing_first_name', 'meta_value' => 'Erika']);
        $this->assertMatchesRegularExpression('/^Vorname [0-9a-f]{6}\z/', $first['meta_value']);
        $this->assertSame('0.0.0.0', $this->one('wp_postmeta', ['meta_key' => '_customer_ip_address', 'meta_value' => '203.0.113.7'])['meta_value']);
        $this->assertSame('', $this->one('wp_postmeta', ['meta_key' => '_billing_address_index', 'meta_value' => 'Erika Mustermann Köln'])['meta_value']);
        // Order Attribution schreibt den User-Agent ein zweites Mal – in HPOS und in klassischer Postmeta.
        foreach (['wp_postmeta', 'wp_wc_orders_meta'] as $table) {
            $this->assertSame('', $this->one($table, ['meta_key' => '_wc_order_attribution_user_agent', 'meta_value' => 'Mozilla/5.0'])['meta_value'], $table);
        }

        foreach ([['_billing_period', 'month'], ['_billing_country', 'DE'], ['_shipping_state', 'NW'], ['_edit_lock', '1700000000:1'], ['_elementor_data', '[]']] as $kv) {
            $row = ['meta_key' => $kv[0], 'meta_value' => $kv[1]];
            $this->assertSame($row, $this->one('wp_postmeta', $row), $kv[0] . ' must stay');
        }
    }

    public function testWooSecretsAndSessions(): void
    {
        $this->assertSame('a:0:{}', $this->one('wp_woocommerce_sessions', ['session_key' => '7', 'session_value' => 'a:1:{s:8:"customer";s:3:"...";}'])['session_value']);
        $key = $this->one('wp_woocommerce_api_keys', ['key_id' => '1', 'description' => 'ERP', 'consumer_key' => 'abc', 'consumer_secret' => 'cs_live', 'truncated_key' => 'a1b2c3d']);
        $this->assertSame('ERP', $key['description']);
        $this->assertMatchesRegularExpression('/^ck_[0-9a-f]{16}\z/', $key['consumer_key']);
        $this->assertMatchesRegularExpression('/^cs_[0-9a-f]{16}\z/', $key['consumer_secret']);
        $this->assertSame('', $this->one('wp_wc_webhooks', ['webhook_id' => '1', 'secret' => 'whsec'])['secret']);
        $this->assertSame('', $this->one('wp_woocommerce_payment_tokenmeta', ['meta_key' => 'last4', 'meta_value' => '4242'])['meta_value']);
        $this->assertSame('0.0.0.0', $this->one('wp_wc_download_log', ['user_ip_address' => '203.0.113.7'])['user_ip_address']);
    }

    /** D4: geprüfte Tabellen ohne Personendaten gelten als abgedeckt und bleiben unverändert. */
    public function testWooTablesWithoutPersonalColumnsAreCovered(): void
    {
        foreach (['wc_order_stats', 'wc_order_product_lookup', 'wc_order_tax_lookup', 'wc_order_coupon_lookup', 'woocommerce_order_items', 'woocommerce_order_itemmeta'] as $table) {
            $this->assertTrue(Anonymizer::covers('wp_' . $table, 'wp_'), $table);
        }
        $row = ['order_id' => '10', 'net_total' => '16.80', 'customer_id' => '3'];
        $this->assertSame($row, $this->one('wp_wc_order_stats', $row));
    }
}
