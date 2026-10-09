<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\TestCase;
use WpSync\ContentCheck;
use WpSync\ContentException;
use WpSync\ContentOrigin;
use WpSync\ContentPackage;
use WpSync\ContentTarget;

require_once __DIR__ . '/ContentFixtures.php';
require_once __DIR__ . '/ContentMemory.php';

/**
 * Prüfungen eines Pakets gegen das Ziel (Spec Content-Push §7.2 Nr. 1–9, AC-149, AC-151, AC-152,
 * AC-155) – jeder Fehlercode mindestens einmal, dazu die Sicherheitsfälle.
 */
final class ContentCheckTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];
    private ContentMemory $store;

    protected function setUp(): void
    {
        $this->store = new ContentMemory([
            'posts' => [
                '219' => ContentFixtures::post('219'),
                '220' => ContentFixtures::post('220', ['post_status' => 'draft']),
                '300' => ContentFixtures::post('300', ['post_type' => 'attachment', 'post_status' => 'inherit']),
                '400' => ContentFixtures::post('400', ['post_type' => 'shop_order']),
            ],
            'postmeta' => [
                "219\0_elementor_data" => ['values' => ['[{"url":"https:\/\/kunde.de\/x"}]']],
                "219\0_edit_lock"      => ['values' => ['1:1']],
            ],
            'terms'         => ['5' => ContentFixtures::term('5', 'News'), '9' => ContentFixtures::term('9', 'Deutsch')],
            'term_taxonomy' => ['5' => ContentFixtures::taxonomy('5', '5', 'category'), '9' => ContentFixtures::taxonomy('9', '9', 'language')],
            'options'       => [
                'blogname'   => ContentFixtures::option('blogname', 'Kunde'),
                'stylesheet' => ContentFixtures::option('stylesheet', 'hello-child'),
                'siteurl'    => ContentFixtures::option('siteurl', ContentFixtures::HOME),
            ],
        ]);
    }

    protected function tearDown(): void
    {
        array_map('unlink', array_filter($this->files, 'is_file'));
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed>       $head
     */
    private function check(array $rows, array $head = [], ?ContentTarget $target = null): ContentCheck
    {
        $this->files[] = $file = ContentFixtures::file($rows, $head);
        return new ContentCheck(ContentPackage::read($file), $target ?? ContentFixtures::live($this->store));
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed>       $head
     * @param array<string, mixed>       $uploads
     */
    private function refused(string $reason, array $rows, array $head = [], ?ContentTarget $target = null, array $uploads = []): ContentException
    {
        try {
            $this->check($rows, $head, $target)->run($uploads);
        } catch (ContentException $e) {
            $this->assertSame($reason, $e->reason(), $e->getMessage());
            $this->assertSame([], $this->store->log, 'eine Prüfung schreibt nie');
            return $e;
        }
        $this->fail('accepted, expected ' . $reason);
    }

    private function h(string $table, string $key): string
    {
        return ContentFixtures::hash($table, $key, $this->store->data[$table][$key] ?? null);
    }

    /** @return array<string, mixed> update von Seite 219 mit neuem Titel */
    private function updatePost(array $over = ['post_title' => 'Neu']): array
    {
        return ContentFixtures::row('update', 'posts', '219', $this->h('posts', '219'), ContentFixtures::postRow('219', $over));
    }

    public function testAcceptsASetOfEveryKind(): void
    {
        $check = $this->check([
            $this->updatePost(),
            ContentFixtures::row('update', 'postmeta', "219\0_elementor_data", $this->h('postmeta', "219\0_elementor_data"), ['values' => ['[{"url":"' . ContentOrigin::ESC1 . '\/neu"}]']]),
            ContentFixtures::row('insert', 'postmeta', "219\0_thumbnail_id", 'absent', ['values' => ['300']]),
            ContentFixtures::row('trash', 'posts', '220', $this->h('posts', '220')),
            ContentFixtures::row('insert', 'posts', '1000001', 'absent', ContentFixtures::postRow('1000001', ['post_content' => 'Link ' . ContentOrigin::PLAIN . '/a'])),
            ContentFixtures::row('insert', 'postmeta', "1000001\0_wp_page_template", 'absent', ['values' => ['default']]),
            ContentFixtures::row('insert', 'terms', '1000001', 'absent', ['name' => 'Neu', 'slug' => 'neu', 'term_group' => '0']),
            ContentFixtures::row('insert', 'term_taxonomy', '1000002', 'absent', ['term_id' => '1000001', 'taxonomy' => 'category', 'description' => '', 'parent' => '0']),
            ContentFixtures::row('insert', 'term_relationships', "1000001\0category", 'absent', ['values' => ['1000002:0', '5:0']]),
            ContentFixtures::row('insert', 'termmeta', "1000001\0farbe", 'absent', ['values' => ['rot']]),
            ContentFixtures::row('update', 'options', 'blogname', $this->h('options', 'blogname'), ['option_value' => 'Kunde GmbH']),
            ContentFixtures::row('insert', 'options', 'page_on_front', 'absent', ['option_value' => '1000001']),
            ContentFixtures::row('insert', 'options', 'theme_mods_hello-child', 'absent', ['option_value' => serialize(['nav_menu_locations' => ['main' => 1000001, 'leer' => 0], 'custom_css_post_id' => -1])]),
        ]);
        $check->run();
        $this->assertSame('Link https://kunde.de/a', $check->value('posts', '1000001')['post_content'], 'die Origin des Ziels ist eingesetzt');
        $this->assertSame(['[{"url":"https:\/\/kunde.de\/neu"}]'], $check->value('postmeta', "219\0_elementor_data")['values']);
        $this->assertSame(['1000002:0', '5:0'], $check->value('term_relationships', "1000001\0category")['values']);
        $this->assertSame([219], $check->published(), 'nur veröffentlichte Beiträge, die es auf dem Ziel schon gibt');
        $this->assertSame('draft', $check->state('posts')['220']['post_status']);
        $this->assertSame([], $this->store->locked, 'der Probelauf sperrt nichts');
    }

    /** Beim Anwenden liest dieselbe Prüfung jede Zeile gesperrt – auch die Lücke eines neuen Schlüssels. */
    public function testRunUnderLockLocksEveryKey(): void
    {
        $check = $this->check([
            $this->updatePost(),
            ContentFixtures::row('insert', 'posts', '1000001', 'absent', ContentFixtures::postRow('1000001')),
            ContentFixtures::row('insert', 'postmeta', "219\0_neu", 'absent', ['values' => ['x']]),
            ContentFixtures::row('trash', 'posts', '220', $this->h('posts', '220')),
        ]);
        $this->store->transaction(static function () use ($check): void {
            $check->run([], true);
        });
        foreach (['posts:219', 'posts:1000001', "postmeta:219\0_neu", 'posts:220', "postmeta:220\0_wp_trash_meta_status", "postmeta:220\0_wp_trash_meta_time", "postmeta:220\0_wp_desired_post_slug"] as $key) {
            $this->assertContains($key, $this->store->locked);
        }
    }

    public function testOriginMismatch(): void
    {
        $this->refused('origin_mismatch', [$this->updatePost()], ['home' => 'https://anderer-kunde.de']);
        $this->refused('origin_mismatch', [$this->updatePost()], [], ContentFixtures::live($this->store, '/x', 'https://wp.kunde.de'));
        $this->check([$this->updatePost()], [], ContentFixtures::live($this->store, '/x', ContentFixtures::HOME . '/wp'))->run();
        $this->refused('package_invalid', [$this->updatePost()], ['local_host' => 'kunde.de']);
    }

    public function testEngineUnsupported(): void
    {
        $this->store->engines = ['postmeta' => 'MyISAM'];
        $e                    = $this->refused('engine_unsupported', [ContentFixtures::row('trash', 'posts', '220', $this->h('posts', '220'))]);
        $this->assertSame(['postmeta'], $e->toArray()['tables'], 'trash schreibt auch postmeta');
        $this->check([$this->updatePost()])->run();
    }

    public function testListVersionMismatch(): void
    {
        $this->refused('list_version_mismatch', [$this->updatePost()], ['list_version' => \WpSync\ContentLists::VERSION + 1]);
    }

    /** §11: die Listen des Agents entscheiden – jede gesperrte Zeile wird genannt. */
    public function testBlockedRows(): void
    {
        $e = $this->refused('blocked_row', [
            ContentFixtures::row('update', 'postmeta', "219\0_edit_lock", $this->h('postmeta', "219\0_edit_lock"), ['values' => ['2:2']]),
            ContentFixtures::row('update', 'options', 'siteurl', $this->h('options', 'siteurl'), ['option_value' => 'https://boese.example']),
            ContentFixtures::row('insert', 'options', 'active_plugins', 'absent', ['option_value' => 'a:0:{}']),
            ContentFixtures::row('insert', 'options', 'irgendeine_option', 'absent', ['option_value' => 'x']),
            ContentFixtures::row('update', 'posts', '400', $this->h('posts', '400'), ContentFixtures::postRow('400', ['post_type' => 'shop_order'])),
            ContentFixtures::row('insert', 'postmeta', "400\0_x", 'absent', ['values' => ['x']]),
            ContentFixtures::row('insert', 'postmeta', "219\0mein_api_key", 'absent', ['values' => ['x']]),
            ContentFixtures::row('insert', 'postmeta', "219\0_billing_email", 'absent', ['values' => ['x']]),
            ContentFixtures::row('insert', 'term_relationships', "219\0language", 'absent', ['values' => ['9:0']]),
            ContentFixtures::row('insert', 'termmeta', "9\0farbe", 'absent', ['values' => ['x']]),
            $this->updatePost(),
        ]);
        $this->assertSame([
            ['table' => 'postmeta', 'key' => "219\0_edit_lock"],
            ['table' => 'options', 'key' => 'siteurl'],
            ['table' => 'options', 'key' => 'active_plugins'],
            ['table' => 'options', 'key' => 'irgendeine_option'],
            ['table' => 'posts', 'key' => '400'],
            ['table' => 'postmeta', 'key' => "400\0_x"],
            ['table' => 'postmeta', 'key' => "219\0mein_api_key"],
            ['table' => 'postmeta', 'key' => "219\0_billing_email"],
            ['table' => 'term_relationships', 'key' => "219\0language"],
            ['table' => 'termmeta', 'key' => "9\0farbe"],
        ], $e->keys());
    }

    public function testBlockedWaysAroundTheLists(): void
    {
        // Typ einer erlaubten Seite in einen gesperrten ändern
        $this->refused('blocked_row', [$this->updatePost(['post_type' => 'shop_order'])]);
        // Neuer Beitrag eines gesperrten Typs
        $this->refused('blocked_row', [ContentFixtures::row('insert', 'posts', '1000001', 'absent', ContentFixtures::postRow('1000001', ['post_type' => 'revision']))]);
        // Attachment in den Papierkorb (S3)
        $e = $this->refused('blocked_row', [ContentFixtures::row('trash', 'posts', '300', $this->h('posts', '300'))]);
        $this->assertSame([['table' => 'posts', 'key' => '300']], $e->keys());
        // Über den Schlüssel einer erlaubten Taxonomie eine fremde Zuordnung mitbringen
        $this->refused('blocked_row', [ContentFixtures::row('insert', 'term_relationships', "219\0category", 'absent', ['values' => ['5:0', '9:0']])]);
        // term_taxonomy in eine gesperrte Taxonomie umhängen
        $this->refused('blocked_row', [ContentFixtures::row('update', 'term_taxonomy', '5', $this->h('term_taxonomy', '5'), ['term_id' => '5', 'taxonomy' => 'language', 'description' => '', 'parent' => '0'])]);
        // In den Papierkorb führt nur op trash – sonst fehlte die Papierkorb-Meta
        $e = $this->refused('package_invalid', [$this->updatePost(['post_status' => 'trash'])]);
        $this->assertSame([['table' => 'posts', 'key' => '219']], $e->keys());
    }

    /** Studio §5.4: ein Projekt gibt Beitragstypen, Taxonomien und einzelne Meta-Schlüssel frei – sonst nichts. */
    public function testExtensionsOfTheProject(): void
    {
        $this->store->data['posts']['500'] = ContentFixtures::post('500', ['post_type' => 'referenz']);
        $rows = [
            ContentFixtures::row('update', 'posts', '500', $this->h('posts', '500'), ContentFixtures::postRow('500', ['post_type' => 'referenz', 'post_title' => 'Neu'])),
            ContentFixtures::row('insert', 'postmeta', "219\0design_token", 'absent', ['values' => ['x']]),
            ContentFixtures::row('insert', 'term_relationships', "219\0language", 'absent', ['values' => ['9:0']]),
        ];
        $this->refused('blocked_row', $rows);
        $this->check($rows, ['extensions' => ['post_types' => ['referenz'], 'taxonomies' => ['language'], 'meta_exceptions' => ['design_token']]])->run();
    }

    public function testUnknownPlaceholderIsInvalid(): void
    {
        $e = $this->refused('package_invalid', [$this->updatePost(['post_content' => "\xE2\x9F\xA6wpsync:origin:esc9\xE2\x9F\xA7/x"])]);
        $this->assertSame([['table' => 'posts', 'key' => '219']], $e->keys());
    }

    /** Härtung S1: kein serialisiertes Objekt erreicht maybe_unserialize() auf dem Ziel – auch nicht verschachtelt. */
    public function testSerializedObjectsAreRefused(): void
    {
        $object = 'O:8:"stdClass":1:{s:1:"a";i:1;}';
        $e      = $this->refused('unsafe_value', [
            ContentFixtures::row('insert', 'postmeta', "219\0_objekt", 'absent', ['values' => ['harmlos', $object]]),
            ContentFixtures::row('insert', 'postmeta', "219\0_tief", 'absent', ['values' => [serialize(['a' => [serialize(['b' => new \stdClass()])]])]]),
            ContentFixtures::row('insert', 'postmeta', "219\0_rand", 'absent', ['values' => ["  " . $object . "\n"]]),
            ContentFixtures::row('insert', 'postmeta', "219\0_custom", 'absent', ['values' => ['C:11:"ArrayObject":21:{x:i:0;a:0:{};m:a:0:{}}']]),
            ContentFixtures::row('insert', 'postmeta', "219\0_kaputt", 'absent', ['values' => ['a:1:{s:1:"u";s:99:"zu kurz";}']]),
            ContentFixtures::row('insert', 'options', 'options_objekt', 'absent', ['option_value' => 'a:1:{i:0;' . $object . '}']),
            $this->updatePost(['post_content' => $object]),
            ContentFixtures::row('insert', 'postmeta', "219\0_gut", 'absent', ['values' => [serialize(['url' => 'x', 'n' => [1, 2.5, null]])]]),
        ]);
        $this->assertSame(["219\0_objekt", "219\0_tief", "219\0_rand", "219\0_custom", "219\0_kaputt", 'options_objekt', '219'], array_column($e->keys(), 'key'));
        $this->assertStringNotContainsString('stdClass', json_encode($e->toArray()));
    }

    /** Härtung S4: Namen, die die Kollation der Datenbank mit einem anderen gleichsetzen könnte, nennt kein Paket. */
    public function testStrictNamesAndAliases(): void
    {
        $e = $this->refused('blocked_row', [
            ContentFixtures::row('insert', 'postmeta', "219\0_edit_lock ", 'absent', ['values' => ['x']]),
            ContentFixtures::row('insert', 'postmeta', "219\0_édit_lock", 'absent', ['values' => ['x']]),
            ContentFixtures::row('insert', 'postmeta', "219\0mit leerzeichen", 'absent', ['values' => ['x']]),
            ContentFixtures::row('insert', 'postmeta', "219\0_EDIT_LOCK", 'absent', ['values' => ['x']]),
            ContentFixtures::row('insert', 'options', 'options_100%', 'absent', ['option_value' => 'x']),
            ContentFixtures::row('insert', 'options', 'SiteURL', 'absent', ['option_value' => 'x']),
            ContentFixtures::row('insert', 'postmeta', "219\0acf.feld:1-a", 'absent', ['values' => ['erlaubte Zeichen']]),
        ]);
        $this->assertCount(6, $e->keys());

        // Auf dem Ziel liegt derselbe Schlüssel in anderer Schreibweise: das Paket träfe ihn über die Kollation.
        $this->store->data['postmeta']["219\0_Mein_Feld"] = ['values' => ['fremd']];
        $this->store->data['options']['Options_Footer']    = ContentFixtures::option('Options_Footer', 'fremd');
        $e = $this->refused('blocked_row', [
            ContentFixtures::row('insert', 'postmeta', "219\0_mein_feld", 'absent', ['values' => ['x']]),
            ContentFixtures::row('insert', 'options', 'options_footer', 'absent', ['option_value' => 'x']),
        ]);
        $this->assertSame([['table' => 'postmeta', 'key' => "219\0_mein_feld"], ['table' => 'options', 'key' => 'options_footer']], $e->keys());
    }

    /** Härtung S5: erwartet wird nie, was das Paket über das Ziel behauptet – der Agent rechnet den Abdruck selbst. */
    public function testTheAgentComputesEveryFingerprintItself(): void
    {
        $new = ContentFixtures::postRow('219', ['post_title' => 'Neu']);
        $own = \WpSync\ContentState::desired('posts', '219', $new);
        $e   = $this->refused('conflict', [ContentFixtures::row('update', 'posts', '219', $own, $new)]);
        $this->assertSame([['table' => 'posts', 'key' => '219']], $e->keys(), 'der Abdruck der eigenen Zeile ist nicht der des Ziels');
    }

    /** Was geschrieben würde, muss normalisiert wieder der Wert des Pakets sein (Härtung S1, write_mismatch). */
    public function testAValueThatWouldNotRoundTripIsRefused(): void
    {
        $e = $this->refused('write_mismatch', [$this->updatePost(['post_content' => 'wörtlich: https://kunde.de/kontakt'])]);
        $this->assertSame([['table' => 'posts', 'key' => '219']], $e->keys());
        $this->check([$this->updatePost(['post_content' => 'als Platzhalter: ' . ContentOrigin::PLAIN . '/kontakt'])])->run();
    }

    /** Nr. 5: was die Normalisierung nicht erwischt hat, darf nicht als Link auf die Arbeitskopie live gehen. */
    public function testRestOfTheLocalOrigin(): void
    {
        foreach ([
            'Klartext'            => 'https://kunde.ddev.site/kontakt',
            'gross geschrieben'   => '//KUNDE.DDEV.SITE/x',
            'URL-kodiert'         => 'https%3A%2F%2Fkunde%2Eddev%2Esite%2Fx',
            'doppelt URL-kodiert' => 'u=https%253A%252F%252Fkunde%252Eddev%252Esite',
            'in escaptem JSON'    => '{"u":"https:\/\/kunde.ddev.site\/x"}',
        ] as $what => $value) {
            $e = $this->refused('local_origin_in_package', [$this->updatePost(['post_content' => $value])]);
            $this->assertSame([['table' => 'posts', 'key' => '219']], $e->keys(), $what);
            $this->assertStringNotContainsString('ddev', $e->getMessage());
        }
        $this->refused('local_origin_in_package', [ContentFixtures::row('insert', 'postmeta', "219\0_ser", 'absent', ['values' => [serialize(['u' => 'http://kunde.ddev.site'])]])]);
        $this->check([$this->updatePost(['post_content' => 'kunde.ddev.example ist eine andere Adresse'])])->run();
    }

    /** AC-149: die Ablehnung nennt Schlüssel und Muster, nicht den Wert. */
    public function testPseudonymInPackage(): void
    {
        $e = $this->refused('pseudonym_in_package', [
            $this->updatePost(['post_content' => 'Schreiben Sie an user-0123456789abcdef@example.invalid']),
            ContentFixtures::row('insert', 'postmeta', "219\0_form", 'absent', ['values' => [serialize(['to' => 'Vorname 0a1b2c'])]]),
        ]);
        $this->assertSame([
            ['table' => 'posts', 'key' => '219', 'pattern' => 'email'],
            ['table' => 'postmeta', 'key' => "219\0_form", 'pattern' => 'name'],
        ], $e->keys());
        $this->assertStringNotContainsString('example.invalid', json_encode($e->toArray()));
        $this->assertStringNotContainsString('0a1b2c', json_encode($e->toArray()));
        $this->check([$this->updatePost(['post_content' => 'Musterstadt, 00000'])])->run();
    }

    /** AC-152 */
    public function testCorridorAndTakenIds(): void
    {
        $e = $this->refused('id_outside_corridor', [
            ContentFixtures::row('insert', 'posts', '500', 'absent', ContentFixtures::postRow('500')),
            ContentFixtures::row('insert', 'terms', '2000000', 'absent', ['name' => 'N', 'slug' => 'n', 'term_group' => '0']),
            ContentFixtures::row('insert', 'term_taxonomy', '2000000', 'absent', ['term_id' => '2000000', 'taxonomy' => 'category', 'description' => '', 'parent' => '0']),
        ]);
        $this->assertSame(['500', '2000000', '2000000'], array_column($e->keys(), 'key'));

        $this->store->data['posts']['1000001'] = ContentFixtures::post('1000001');
        $e = $this->refused('id_taken', [ContentFixtures::row('insert', 'posts', '1000001', 'absent', ContentFixtures::postRow('1000001'))]);
        $this->assertSame([['table' => 'posts', 'key' => '1000001']], $e->keys());

        // Ein Paar an einem bestehenden Objekt braucht keinen Korridor (S1) – nur frei muss es sein.
        $this->check([ContentFixtures::row('insert', 'postmeta', "219\0_neu", 'absent', ['values' => ['x']])])->run();
        $e = $this->refused('conflict', [ContentFixtures::row('insert', 'postmeta', "219\0_elementor_data", 'absent', ['values' => ['x']])]);
        $this->assertSame([['table' => 'postmeta', 'key' => "219\0_elementor_data"]], $e->keys());
    }

    /**
     * M1: der Korridor des Pakets allein begrenzt nichts – ein Insert weit über der höchsten ID
     * des Ziels verschöbe dessen AUTO_INCREMENT auf Dauer. Hart: höchstens 2^53−1. Relativ:
     * höchstens ID_HEADROOM über max(MAX(id), AUTO_INCREMENT − 1) des Ziels.
     */
    public function testInsertsStayCloseToTheHighestIdOfTheTarget(): void
    {
        $wide = ['corridor' => ['offset' => 1000000, 'posts' => [1000001, 999999999999999999], 'terms' => [1000001, 999999999999999999], 'term_taxonomy' => [1000001, 999999999999999999]]];
        $e    = $this->refused('id_outside_corridor', [ContentFixtures::row('insert', 'posts', '999999999999999999', 'absent', ContentFixtures::postRow('999999999999999999'))], $wide);
        $this->assertSame([['table' => 'posts', 'key' => '999999999999999999']], $e->keys());
        $this->refused('id_outside_corridor', [ContentFixtures::row('insert', 'posts', '9007199254740992', 'absent', ContentFixtures::postRow('9007199254740992'))], $wide);

        // Höchste ID der Beiträge ist 400: bis 1000400 geht es, darüber nicht.
        $this->assertSame(1000000, ContentCheck::ID_HEADROOM);
        $this->check([ContentFixtures::row('insert', 'posts', '1000400', 'absent', ContentFixtures::postRow('1000400'))], $wide)->run();
        $e = $this->refused('id_outside_corridor', [
            ContentFixtures::row('insert', 'posts', '1000401', 'absent', ContentFixtures::postRow('1000401')),
            ContentFixtures::row('insert', 'terms', '1000010', 'absent', ['name' => 'N', 'slug' => 'n', 'term_group' => '0']),
            ContentFixtures::row('insert', 'term_taxonomy', '1000010', 'absent', ['term_id' => '1000009', 'taxonomy' => 'category', 'description' => '', 'parent' => '0']),
        ], $wide);
        $this->assertSame(['1000401', '1000010', '1000010'], array_column($e->keys(), 'key'), 'Terme und term_taxonomy: höchste ID 9');

        // AUTO_INCREMENT − 1 zählt wie im Manifest (id_max): gelöschte Objekte haben den Zähler schon verschoben.
        $this->store->counters = ['posts' => 5000, 'terms' => 5000, 'term_taxonomy' => 5000];
        $this->check([
            ContentFixtures::row('insert', 'posts', '1005000', 'absent', ContentFixtures::postRow('1005000')),
            ContentFixtures::row('insert', 'terms', '1005000', 'absent', ['name' => 'N', 'slug' => 'n', 'term_group' => '0']),
            ContentFixtures::row('insert', 'term_taxonomy', '1005000', 'absent', ['term_id' => '1005000', 'taxonomy' => 'category', 'description' => '', 'parent' => '0']),
        ], $wide)->run();
        $this->refused('id_outside_corridor', [ContentFixtures::row('insert', 'posts', '1005001', 'absent', ContentFixtures::postRow('1005001'))], $wide);
    }

    /** M1: auf Staging zählen die Tabellen der Kopie – ihr Store, nicht der von Live. */
    public function testTheHeadroomIsThatOfTheTargetsOwnTables(): void
    {
        $copy = new ContentMemory(['posts' => ['7' => ContentFixtures::post('7')], 'options' => ['stylesheet' => ContentFixtures::option('stylesheet', 'hello-child')]]);
        $row  = ContentFixtures::row('insert', 'posts', '1000008', 'absent', ContentFixtures::postRow('1000008'));
        try {
            $this->check([$row], [], ContentFixtures::staging($copy))->run();
            $this->fail('accepted');
        } catch (ContentException $e) {
            $this->assertSame('id_outside_corridor', $e->reason());
        }
        $this->check([ContentFixtures::row('insert', 'posts', '1000007', 'absent', ContentFixtures::postRow('1000007'))], [], ContentFixtures::staging($copy))->run();
    }

    /**
     * M4 (c): eine Zuordnung ist nur pushbar, wenn ihr Objekt ein Beitrag erlaubten Typs ist – die
     * Listen verlangen den post_type des Objekts. Eine per Erweiterung freigegebene Taxonomie, die
     * auf der Site für Benutzer registriert ist, trifft nie etwas: in term_relationships steht für
     * sie die ID eines Benutzers, die sich von der eines Beitrags nicht unterscheiden lässt.
     */
    public function testAnExtensionTaxonomyNeverReachesObjectsThatAreNoPosts(): void
    {
        $ext  = ['extensions' => ['post_types' => [], 'taxonomies' => ['language', 'abteilung'], 'meta_exceptions' => []]];
        $this->store->data['terms']['11']         = ContentFixtures::term('11', 'Vertrieb');
        $this->store->data['term_taxonomy']['11'] = ContentFixtures::taxonomy('11', '11', 'abteilung');
        // Kein Beitrag mit dieser ID (etwa ein Benutzer 7): dangling_reference, nie ein Schreiben.
        $e = $this->refused('dangling_reference', [ContentFixtures::row('insert', 'term_relationships', "7\0language", 'absent', ['values' => ['9:0']])], $ext);
        $this->assertSame([['table' => 'term_relationships', 'key' => "7\0language"]], $e->keys());
        // Ein Beitrag gesperrten Typs: blocked_row.
        $this->refused('blocked_row', [ContentFixtures::row('insert', 'term_relationships', "400\0language", 'absent', ['values' => ['9:0']])], $ext);

        // Auf der Site registriert: language für Seiten, abteilung nur für Benutzer.
        $target              = ContentFixtures::live($this->store);
        $target->objectTypes = static function (string $taxonomy): ?array {
            return ['language' => ['page', 'post'], 'abteilung' => ['user'], 'category' => ['post']][$taxonomy] ?? null;
        };
        $this->check([ContentFixtures::row('insert', 'term_relationships', "219\0language", 'absent', ['values' => ['9:0']])], $ext, $target)->run();
        $rows = [
            ContentFixtures::row('insert', 'term_relationships', "219\0abteilung", 'absent', ['values' => ['11:0']]),
            ContentFixtures::row('insert', 'terms', '1000001', 'absent', ['name' => 'Neu', 'slug' => 'neu', 'term_group' => '0']),
            ContentFixtures::row('insert', 'term_taxonomy', '1000001', 'absent', ['term_id' => '1000001', 'taxonomy' => 'abteilung', 'description' => '', 'parent' => '0']),
            ContentFixtures::row('insert', 'termmeta', "11\0farbe", 'absent', ['values' => ['rot']]),
        ];
        $e = $this->refused('blocked_row', $rows, $ext, $target);
        $this->assertSame(["219\0abteilung", '1000001', '1000001', "11\0farbe"], array_column($e->keys(), 'key'), 'eine Benutzer-Taxonomie: keine Zeile, in keiner Tabelle');

        // Registriert, aber nicht für den Typ dieses Beitrags (Anhang 300): die Zuordnung geht nicht.
        $this->refused('blocked_row', [ContentFixtures::row('insert', 'term_relationships', "300\0language", 'absent', ['values' => ['9:0']])], $ext, $target);
        // Die Whitelist bleibt, wie sie ist – auch wenn die Site category nur für Beiträge registriert.
        $this->check([ContentFixtures::row('insert', 'term_relationships', "219\0category", 'absent', ['values' => ['5:0']])], [], $target)->run();
    }

    /**
     * N1: was die Prüfung vom Ziel liest – die Vorher-Zustände, die später in before.json stehen –,
     * ist in der Summe begrenzt: ein kleines Paket kann sonst Zeilen beliebiger Grösse treffen und
     * den Speicher des Requests füllen. Im Probelauf dieselbe Ablehnung, und nichts wird geschrieben.
     */
    public function testTheStatesReadFromTheTargetAreBounded(): void
    {
        $this->assertSame(67108864, ContentCheck::MAX_STATE_BYTES);
        $this->store->data['postmeta']["219\0_elementor_data"] = ['values' => [str_repeat('x', 3000)]];
        $rows = [
            $this->updatePost(),
            ContentFixtures::row('update', 'postmeta', "219\0_elementor_data", $this->h('postmeta', "219\0_elementor_data"), ['values' => ['[]']]),
        ];
        $this->check($rows)->run();
        ContentCheck::$maxStateBytes = 2000;
        try {
            $e = $this->refused('package_too_large', $rows);
            $this->assertSame(413, $e->status());
            $data = $e->toArray();
            $this->assertSame(2000, $data['limits']['max_state_bytes']);
            $this->assertGreaterThan(3000, $data['state_bytes']);
            $this->assertArrayNotHasKey('keys', $data);
            $this->assertSame(2000, ContentPackage::limits()['max_state_bytes']);
        } finally {
            ContentCheck::$maxStateBytes = ContentCheck::MAX_STATE_BYTES;
        }
        $this->assertSame(ContentCheck::MAX_STATE_BYTES, ContentPackage::limits()['max_state_bytes']);
    }

    /** AC-151: der Konflikt nennt alle abweichenden Schlüssel, nicht nur den ersten. */
    public function testConflictNamesEveryKey(): void
    {
        $rows = [
            $this->updatePost(),
            ContentFixtures::row('update', 'postmeta', "219\0_elementor_data", $this->h('postmeta', "219\0_elementor_data"), ['values' => ['[]']]),
            ContentFixtures::row('update', 'options', 'blogname', $this->h('options', 'blogname'), ['option_value' => 'Neu']),
            ContentFixtures::row('trash', 'posts', '220', $this->h('posts', '220')),
            ContentFixtures::row('update', 'posts', '999', str_repeat('a', 64), ContentFixtures::postRow('999')),
        ];
        // Seit dem Pull auf dem Ziel geändert: Titel der Seite, das Elementor-Meta; Beitrag 999 gibt es nicht (mehr).
        $this->store->data['posts']['219']['post_title']                 = 'auf Live geändert';
        $this->store->data['postmeta']["219\0_elementor_data"]['values'] = ['[{"neu":1}]'];
        $e = $this->refused('conflict', $rows);
        $this->assertSame([
            ['table' => 'posts', 'key' => '219'],
            ['table' => 'postmeta', 'key' => "219\0_elementor_data"],
            ['table' => 'posts', 'key' => '999'],
        ], $e->keys());
    }

    /** Was sich im Abdruck nicht zeigt, ist kein Konflikt: post_modified, post_author, guid, autoload. */
    public function testNoiseIsNoConflict(): void
    {
        $rows = [$this->updatePost(), ContentFixtures::row('update', 'options', 'blogname', $this->h('options', 'blogname'), ['option_value' => 'Neu'])];
        $this->store->data['posts']['219']['post_modified']  = '2027-01-01 00:00:00';
        $this->store->data['posts']['219']['post_author']    = '99';
        $this->store->data['options']['blogname']['autoload'] = 'no';
        $this->check($rows)->run();
        $this->addToAssertionCount(1);
    }

    public function testRowUnfaithful(): void
    {
        $this->store->data['posts']['219']['post_content'] = 'Rohwert mit ' . ContentOrigin::PLAIN;
        $e = $this->refused('row_unfaithful', [ContentFixtures::row('update', 'posts', '219', str_repeat('a', 64), ContentFixtures::postRow('219'))]);
        $this->assertSame([['table' => 'posts', 'key' => '219']], $e->keys());
    }

    public function testDanglingReferences(): void
    {
        $e = $this->refused('dangling_reference', [
            ContentFixtures::row('insert', 'postmeta', "999\0_x", 'absent', ['values' => ['x']]),
            ContentFixtures::row('insert', 'term_relationships', "219\0category", 'absent', ['values' => ['777:0']]),
            ContentFixtures::row('insert', 'terms', '1000001', 'absent', ['name' => 'Ohne Taxonomie', 'slug' => 'ohne', 'term_group' => '0']),
            ContentFixtures::row('insert', 'term_taxonomy', '1000002', 'absent', ['term_id' => '888', 'taxonomy' => 'category', 'description' => '', 'parent' => '0']),
            ContentFixtures::row('insert', 'options', 'page_on_front', 'absent', ['option_value' => '999']),
            ContentFixtures::row('insert', 'options', 'site_icon', 'absent', ['option_value' => '300']),
            ContentFixtures::row('insert', 'options', 'theme_mods_hello-child', 'absent', ['option_value' => serialize(['nav_menu_locations' => ['main' => 5, 'footer' => 666], 'custom_css_post_id' => 219])]),
        ]);
        $this->assertSame([
            ['table' => 'postmeta', 'key' => "999\0_x"],
            ['table' => 'term_relationships', 'key' => "219\0category"],
            ['table' => 'terms', 'key' => '1000001'],
            ['table' => 'term_taxonomy', 'key' => '1000002'],
            ['table' => 'options', 'key' => 'page_on_front'],
            ['table' => 'options', 'key' => 'theme_mods_hello-child'],
        ], $e->keys());
    }

    /**
     * N3: ohne offenes Push-Fenster sagt der Probelauf nicht, welche Objekte es auf dem Ziel gibt.
     * Was ins Leere zeigt, sieht aus wie eine gesperrte Zeile – ein Code, eine Meldung, die Schlüssel
     * in der Reihenfolge des Pakets – und die Dateien von Attachments prüft er gar nicht.
     */
    public function testAPartialRunDoesNotTellWhichObjectsExist(): void
    {
        $dangling = [
            ContentFixtures::row('insert', 'postmeta', "999\0_x", 'absent', ['values' => ['x']]),
            ContentFixtures::row('insert', 'term_relationships', "219\0category", 'absent', ['values' => ['777:0']]),
            ContentFixtures::row('insert', 'term_taxonomy', '1000002', 'absent', ['term_id' => '888', 'taxonomy' => 'category', 'description' => '', 'parent' => '0']),
            ContentFixtures::row('insert', 'options', 'page_on_front', 'absent', ['option_value' => '999']),
        ];
        $blocked = [
            ContentFixtures::row('insert', 'postmeta', "400\0_x", 'absent', ['values' => ['x']]),
            ContentFixtures::row('insert', 'postmeta', "219\0_edit_lock2_token", 'absent', ['values' => ['x']]),
        ];
        $mixed = [$dangling[0], $blocked[0], $dangling[1], $dangling[2], $blocked[1], $dangling[3]];
        $error = function (array $rows): array {
            try {
                $this->check($rows)->run([], false, true);
            } catch (ContentException $e) {
                $this->assertSame([], $this->store->log);
                return $e->toArray();
            }
            $this->fail('accepted');
        };
        $both = $error($mixed);
        $this->assertSame('blocked_row', $both['code']);
        $this->assertSame(array_column($mixed, 'key'), array_column($both['keys'], 'key'), 'in der Reihenfolge des Pakets, ohne Unterschied');
        $this->assertSame(6, $both['total']);
        // Dieselbe Form, ob das Objekt fehlt oder gesperrt ist.
        $missing = $error([$dangling[0]]);
        $locked  = $error([$blocked[0]]);
        $this->assertSame(['code', 'message', 'keys', 'total'], array_keys($missing));
        $this->assertSame(array_keys($locked), array_keys($missing));
        $this->assertSame($locked['message'], $missing['message']);
        $this->assertSame($locked['code'], $missing['code']);
        foreach ($dangling as $row) {
            $this->assertSame('blocked_row', $error([$row])['code'], $row['table']);
        }
        // Mit offenem Fenster bleibt es bei dangling_reference.
        $this->refused('dangling_reference', [$dangling[0]]);

        // Dateien von Attachments: gar nicht geprüft – weder ob sie fehlen noch ob sie da sind.
        $dir = sys_get_temp_dir() . '/wpsync-check-' . bin2hex(random_bytes(4));
        mkdir($dir . '/2026/10', 0777, true);
        file_put_contents($dir . '/2026/10/da.jpg', 'x');
        $target = ContentFixtures::live($this->store, $dir);
        foreach (['2026/10/da.jpg', '2026/10/fehlt.jpg'] as $file) {
            $rows = [ContentFixtures::row('insert', 'postmeta', "300\0_wp_attached_file", 'absent', ['values' => [$file]])];
            $this->check($rows, [], $target)->run([], false, true);
        }
        $this->refused('upload_missing', [ContentFixtures::row('insert', 'postmeta', "300\0_wp_attached_file", 'absent', ['values' => ['2026/10/fehlt.jpg']])], [], $target);
        exec('rm -rf ' . escapeshellarg($dir));
    }

    /** Nr. 9, S10: die Dateimenge eines Attachments ist genau festgelegt. */
    public function testUploadMissing(): void
    {
        $dir = sys_get_temp_dir() . '/wpsync-check-' . bin2hex(random_bytes(4));
        mkdir($dir . '/2026/10', 0777, true);
        file_put_contents($dir . '/2026/10/da.jpg', 'x');
        $meta = serialize(['file' => '2026/10/bild.jpg', 'sizes' => ['thumbnail' => ['file' => 'bild-150x150.jpg'], 'medium' => ['file' => 'bild-300x200.jpg']], 'original_image' => 'bild-original.jpg']);
        $rows = [
            ContentFixtures::row('insert', 'posts', '1000001', 'absent', ContentFixtures::postRow('1000001', ['post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => 'image/jpeg'])),
            ContentFixtures::row('insert', 'postmeta', "1000001\0_wp_attached_file", 'absent', ['values' => ['2026/10/bild.jpg']]),
            ContentFixtures::row('insert', 'postmeta', "1000001\0_wp_attachment_metadata", 'absent', ['values' => [$meta]]),
            ContentFixtures::row('insert', 'postmeta', "300\0_wp_attached_file", 'absent', ['values' => ['2026/10/da.jpg']]),
        ];
        $target = ContentFixtures::live($this->store, $dir);
        $e      = $this->refused('upload_missing', $rows, [], $target, ['2026/10/bild.jpg' => 1, '2026/10/bild-150x150.jpg' => 1]);
        $this->assertSame(['2026/10/bild-300x200.jpg', '2026/10/bild-original.jpg'], $e->toArray()['paths']);
        $this->assertSame([], $e->keys());

        $all = ['2026/10/bild.jpg' => 1, '2026/10/bild-150x150.jpg' => 1, '2026/10/bild-300x200.jpg' => 1, '2026/10/bild-original.jpg' => 1];
        $this->check($rows, [], $target)->run($all);

        // Härtung S3: Pfad, Name und Typ wie bei der Einheit uploads – sonst gesperrt.
        foreach (['../../wp-config.php', '/etc/passwd', '2026\\10\\a.jpg', 'http://boese.example/a.jpg', '2026/10/shell.php', '2026/10/bild.php.jpg', '2026/10/.htaccess', "2026/10/a\0.jpg"] as $path) {
            $evil = [ContentFixtures::row('insert', 'postmeta', "300\0_wp_attached_file", 'absent', ['values' => [$path]])];
            $e    = $this->refused('blocked_row', $evil, [], $target);
            $this->assertSame([['table' => 'postmeta', 'key' => "300\0_wp_attached_file"]], $e->keys(), $path);
        }
        $sized = serialize(['file' => '2026/10/da.jpg', 'sizes' => ['x' => ['file' => '../../../wp-config.php']]]);
        $this->refused('blocked_row', [ContentFixtures::row('insert', 'postmeta', "300\0_wp_attachment_metadata", 'absent', ['values' => [$sized]])], [], $target);

        // Jeder Wert des Paars zählt, nicht nur der erste – und ein Attachment hat genau eine Datei.
        $good = serialize(['file' => '2026/10/da.jpg']);
        $e    = $this->refused('blocked_row', [ContentFixtures::row('insert', 'postmeta', "300\0_wp_attachment_metadata", 'absent', ['values' => [$good, $sized]])], [], $target);
        $this->assertSame([['table' => 'postmeta', 'key' => "300\0_wp_attachment_metadata"]], $e->keys());
        $e = $this->refused('upload_missing', [ContentFixtures::row('insert', 'postmeta', "300\0_wp_attachment_metadata", 'absent', ['values' => [$good, serialize(['file' => '2026/10/fehlt.jpg'])]])], [], $target);
        $this->assertSame(['2026/10/fehlt.jpg'], $e->toArray()['paths']);
        $e = $this->refused('blocked_row', [ContentFixtures::row('insert', 'postmeta', "300\0_wp_attached_file", 'absent', ['values' => ['2026/10/da.jpg', '2026/10/da.jpg']])], [], $target);
        $this->assertSame([['table' => 'postmeta', 'key' => "300\0_wp_attached_file"]], $e->keys(), 'mehr als ein Wert für _wp_attached_file');
        exec('rm -rf ' . escapeshellarg($dir));
    }

    /** AC-147, §7.8: die Abdrücke des Pakets gelten auf der Kopie, obwohl ihre URLs umgeschrieben sind. */
    public function testStagingCopyHasTheSameFingerprints(): void
    {
        $expected = [$this->h('posts', '219'), $this->h('postmeta', "219\0_elementor_data")];
        $path     = '/' . ContentFixtures::STAGING_DIR;
        $this->store->data['posts']['219']['post_content']               = '<a href="https://kunde.de' . $path . '/kontakt">Kontakt</a>';
        $this->store->data['postmeta']["219\0_elementor_data"]['values'] = ['[{"url":"https:\/\/kunde.de\\' . $path . '\/x"}]'];
        $check = $this->check([
            ContentFixtures::row('update', 'posts', '219', $expected[0], ContentFixtures::postRow('219', ['post_content' => 'Neu: ' . ContentOrigin::PLAIN . '/a und ' . ContentOrigin::ESC2 . '\\\\\\/b'])),
            ContentFixtures::row('update', 'postmeta', "219\0_elementor_data", $expected[1], ['values' => ['[{"url":"' . ContentOrigin::ESC1 . '\/neu"}]']]),
        ], [], ContentFixtures::staging($this->store));
        $check->run();
        $this->assertSame('Neu: https://kunde.de' . $path . '/a und https:\\\\\\/\\\\\\/kunde.de\\\\\\' . $path . '\\\\\\/b', $check->value('posts', '219')['post_content']);
        $this->assertSame(['[{"url":"https:\/\/kunde.de\\' . $path . '\/neu"}]'], $check->value('postmeta', "219\0_elementor_data")['values']);
    }

    public function testAFailedReadIsNoVerdict(): void
    {
        $this->store->failRead = true;
        $this->refused('content_failed', [$this->updatePost()]);
    }

    /**
     * Scheitert das Einsetzen oder Normalisieren eines Werts mit einem Fehler statt mit null, ist
     * das die Ablehnung genau dieser Zeile – wie beim Lesen (ContentReader), nie das Ende der Prüfung.
     */
    public function testAValueThatBreaksTheOriginIsThatRowsRefusal(): void
    {
        foreach (['insert', 'normalize'] as $failing) {
            $target         = ContentFixtures::live($this->store);
            $target->origin = new class ($target->origin, $failing) {
                /** @var \WpSync\ContentOrigin */
                private $inner;
                /** @var string */
                private $failing;

                public function __construct(\WpSync\ContentOrigin $inner, string $failing)
                {
                    $this->inner   = $inner;
                    $this->failing = $failing;
                }

                public function insert(string $value): ?string
                {
                    if ($this->failing === 'insert' && $value === 'bricht') {
                        throw new \TypeError('boom');
                    }
                    return $this->inner->insert($value);
                }

                public function normalize(string $value): ?string
                {
                    if ($this->failing === 'normalize' && $value === 'bricht') {
                        throw new \ValueError('boom');
                    }
                    return $this->inner->normalize($value);
                }
            };
            $e = $this->refused('package_invalid', [
                ContentFixtures::row('insert', 'postmeta', "219\0_gut", 'absent', ['values' => ['x']]),
                ContentFixtures::row('insert', 'postmeta', "219\0_kaputt", 'absent', ['values' => ['y', 'bricht']]),
            ], [], $target);
            $this->assertSame([['table' => 'postmeta', 'key' => "219\0_kaputt"]], $e->keys(), $failing);
            $this->assertStringNotContainsString('boom', $e->getMessage());
        }
    }

    /** Härtung S1: auch was nach dem Einsetzen der Adresse geschrieben würde, trägt kein Objekt. */
    public function testTheValueAsWrittenCarriesNoObjectEither(): void
    {
        $target         = ContentFixtures::live($this->store);
        $target->origin = new class ($target->origin) {
            /** @var \WpSync\ContentOrigin */
            private $inner;

            public function __construct(\WpSync\ContentOrigin $inner)
            {
                $this->inner = $inner;
            }

            public function insert(string $value): ?string
            {
                return $value === 'harmlos' ? 'O:8:"stdClass":0:{}' : $this->inner->insert($value);
            }

            public function normalize(string $value): ?string
            {
                return $value === 'O:8:"stdClass":0:{}' ? 'harmlos' : $this->inner->normalize($value);
            }
        };
        $e = $this->refused('unsafe_value', [ContentFixtures::row('insert', 'postmeta', "219\0_x", 'absent', ['values' => ['harmlos']])], [], $target);
        $this->assertSame([['table' => 'postmeta', 'key' => "219\0_x"]], $e->keys());
    }

    /**
     * Eine verwaiste Zuordnung auf dem Ziel – (object_id, term_taxonomy_id) ohne term_taxonomy-Zeile –
     * sieht kein Abdruck. Träfe das Paket dieselbe Stelle, scheiterte das Schreiben am
     * Primärschlüssel: das fällt vorher auf, mit dem Schlüssel.
     */
    public function testAnOrphanedRelationshipInTheWayIsNamed(): void
    {
        $rows = [
            ContentFixtures::row('insert', 'terms', '1000001', 'absent', ['name' => 'Neu', 'slug' => 'neu', 'term_group' => '0']),
            ContentFixtures::row('insert', 'term_taxonomy', '1000002', 'absent', ['term_id' => '1000001', 'taxonomy' => 'category', 'description' => '', 'parent' => '0']),
            ContentFixtures::row('insert', 'term_relationships', "219\0category", 'absent', ['values' => ['5:0', '1000002:0']]),
        ];
        $this->check($rows)->run();

        $this->store->orphans = ['219' => ['1000002']];
        $e                    = $this->refused('blocked_row', $rows);
        $this->assertSame([['table' => 'term_relationships', 'key' => "219\0category"]], $e->keys());
        $this->assertStringContainsString('verwaist', $e->getMessage());

        $this->store->orphans = ['219' => ['77'], '220' => ['1000002']]; // nicht an derselben Stelle
        $this->check($rows)->run();
    }
}
