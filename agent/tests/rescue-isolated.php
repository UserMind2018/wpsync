<?php
/**
 * AC-170 (Spec Content-Push P3 §4.2): was rescue.php für die Rücknahme der Inhalte lädt, läuft in
 * einem PHP-Prozess ohne WordPress. Dieses Skript ist dieser Prozess – RescueIsolatedTest startet
 * es. Es definiert nur WPSYNC_RESCUE: kein ABSPATH, kein Autoloader von Composer, keine Attrappe
 * einer WordPress-Funktion. Jeder Aufruf einer WordPress-Funktion ist damit ein Fatal, jede nicht
 * geladene Klasse ein Abbruch (der Autoloader unten), und jede Warnung eine Exception.
 */
declare(strict_types=1);

define('WPSYNC_RESCUE', true);
error_reporting(E_ALL);
ini_set('display_errors', '1');
set_error_handler(static function (int $no, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $no, $file, $line);
});
spl_autoload_register(static function (string $class): void {
    fwrite(STDERR, 'not loaded: ' . $class . "\n");
    exit(3);
});

/** @param mixed $want @param mixed $got */
function same($want, $got, string $what): void
{
    if ($want !== $got) {
        fwrite(STDERR, 'FAILED: ' . $what . "\nwant " . var_export($want, true) . "\ngot  " . var_export($got, true) . "\n");
        exit(1);
    }
}

/** @return list<string> Klassen und Schnittstellen des Agents, die dieser Prozess kennt */
function loaded(): array
{
    $names = [];
    foreach (array_merge(get_declared_classes(), get_declared_interfaces()) as $name) {
        if (strpos($name, 'WpSync\\') === 0 && strpos($name, 'WpSync\\Tests\\') !== 0) {
            $names[] = substr($name, 7);
        }
    }
    sort($names);
    return $names;
}

$src = dirname(__DIR__) . '/src';

// 1. Vor der Schlüsselprüfung: nur PushSwap und PushRescue.
require $src . '/PushSwap.php';
require $src . '/PushRescue.php';
same(['PushRescue', 'PushSwap'], loaded(), 'before the key check only PushSwap and PushRescue are loaded');

// 2. Danach: RescueContent und genau seine Liste – jede Datei lädt mit WPSYNC_RESCUE allein.
require $src . '/RescueContent.php';
\WpSync\RescueContent::load();
$expected = array_merge(['PushRescue', 'PushSwap', 'RescueContent'], \WpSync\RescueContent::CLASSES);
sort($expected);
same($expected, loaded(), 'load() loads exactly the list');

require __DIR__ . '/ContentMemory.php';
require __DIR__ . '/ContentFixtures.php';
require __DIR__ . '/FakeRescueLink.php';

use WpSync\ContentImage;
use WpSync\ContentState;
use WpSync\PushRescue;
use WpSync\RescueContent;
use WpSync\RescueDb;
use WpSync\RescueSeal;
use WpSync\Tests\ContentFixtures;
use WpSync\Tests\ContentMemory;
use WpSync\Tests\FakeRescueLink;

const ID = 'p_20261009_0123456789ab';

$root    = rtrim((string) realpath(sys_get_temp_dir()), '/') . '/wpsync-isolated-' . bin2hex(random_bytes(4));
$content = $root . '/wp-content';
$work    = $content . '/wpsync-push-0123456789abcdef';
$dir     = $work . '/' . ID . '/content';
mkdir($dir, 0777, true);
register_shutdown_function(static function () use ($root): void {
    exec('rm -rf ' . escapeshellarg($root));
});

// Der Stand vor dem Push und der gepushte Stand – mit jeder Art von Schlüssel.
$before = [
    'posts'              => ['219' => ContentFixtures::post('219'), '220' => ContentFixtures::post('220', ['post_status' => 'draft', 'post_date_gmt' => '0000-00-00 00:00:00'])],
    'postmeta'           => ["219\0_elementor_data" => ['values' => ['[{"url":"https:\/\/kunde.de\/x"}]']], "219\0_fremd" => ['values' => ['bleibt']]],
    'terms'              => ['5' => ContentFixtures::term('5', 'News')],
    'term_taxonomy'      => ['5' => ContentFixtures::taxonomy('5', '5', 'category')],
    'term_relationships' => ["219\0category" => ['values' => ['5:0']]],
    'options'            => ['blogname' => ContentFixtures::option('blogname', 'Kunde')],
];
$pushed                                              = $before;
$pushed['posts']['219']['post_title']                = 'Neu';
$pushed['posts']['219']['post_content']              = serialize(['url' => 'https://kunde.de/neu']);
$pushed['posts']['220']['post_status']               = 'trash';
$pushed['posts']['1000001']                          = ContentFixtures::post('1000001');
$pushed['postmeta']["219\0_elementor_data"]          = ['values' => ['[{"url":"https:\/\/kunde.de\/neu"}]']];
$pushed['postmeta']["1000001\0_wp_page_template"]    = ['values' => ['default']];
$pushed['terms']['1000001']                          = ContentFixtures::term('1000001', 'Neu');
$pushed['term_taxonomy']['1000002']                  = ContentFixtures::taxonomy('1000002', '1000001', 'category');
$pushed['term_relationships']["219\0category"]       = ['values' => ['5:0', '1000002:0']];
$pushed['term_relationships']["1000001\0category"]   = ['values' => ['1000002:0']];
$pushed['options']['blogname']['option_value']       = 'Kunde GmbH';
$pushed['options']['page_on_front']                  = ContentFixtures::option('page_on_front', '1000001', '11');

$keysBefore = [];
$keysAfter  = [];
foreach (ContentState::ORDER as $table) {
    foreach ($pushed[$table] ?? [] as $key => $state) {
        $key = (string) $key;
        if (($before[$table][$key] ?? null) === $state) {
            continue;
        }
        $keysBefore[] = ['t' => $table, 'k' => $key, 'state' => ContentState::encode($table, $before[$table][$key] ?? null)];
        $keysAfter[]  = ['t' => $table, 'k' => $key, 'h' => ContentFixtures::hash($table, $key, $state)];
    }
}
same(11, count($keysBefore), 'the push wrote eleven keys');

// Die Abbilder liegen geschützt, wie der Agent sie ablegt; der Umschlag trägt ihre Dateischlüssel.
ContentImage::$keys    = [hash('sha256', 'key of the installation', true)];
ContentImage::$encrypt = extension_loaded('sodium');
ContentImage::put($dir, ContentImage::BEFORE, ['keys' => $keysBefore]);
ContentImage::put($dir, ContentImage::AFTER, ['keys' => $keysAfter, 'changes' => ['posts' => [219]]]);
$envelope = [
    'v' => 1, 'created' => time(), 'target' => 'live',
    'db' => ['host' => 'localhost', 'port' => null, 'socket' => null, 'user' => 'u', 'password' => 'p', 'name' => 'wordpress', 'flags' => 0, 'charset' => 'utf8mb4', 'collate' => '', 'sql_mode' => ''],
    'prefix' => 'wp_', 'home' => ContentFixtures::HOME, 'siteurl' => ContentFixtures::HOME, 'staging' => null,
    'image_keys' => [
        ContentImage::BEFORE => array_map('base64_encode', ContentImage::fileKeys(ID, ContentImage::BEFORE)),
        ContentImage::AFTER  => array_map('base64_encode', ContentImage::fileKeys(ID, ContentImage::AFTER)),
    ],
];
ContentImage::$keys = []; // ab hier: kein Schlüssel der Installation, wie in rescue.php
$key                = PushRescue::key(str_repeat('ab', 32), ID, 'salt');
foreach (['sodium', 'gcm'] as $method) {
    $sealed = RescueSeal::seal($envelope, $key, ID, $method);
    same(true, is_string($sealed) && RescueSeal::open($sealed, $key, ID) !== null, 'seal and open with ' . $method);
}
same(true, RescueSeal::put($work, ID, (string) RescueSeal::seal($envelope, $key, ID)), 'the envelope is written');

/** @param array<string, array<string, mixed>> $data @return array<string, array<string, mixed>> */
$sorted = static function (array $data): array {
    foreach ($data as $table => $rows) {
        ksort($rows, SORT_STRING);
        $data[$table] = $rows;
    }
    return $data;
};

// 3. Seit dem Push geändert: nichts wird angefasst, die Antwort nennt den Schlüssel.
$store                                   = new ContentMemory($pushed);
$store->data['options']['blogname']['option_value'] = 'seither geändert';
RescueContent::$resolve                  = static function () use (&$store): \WpSync\ContentTarget {
    return ContentFixtures::live($store);
};
$changed = $store->data;
same(
    ['state' => 'kept', 'wrote' => false, 'error' => ['code' => 'changed_since_push', 'keys' => [['table' => 'options', 'key' => 'blogname']], 'total' => 1]],
    RescueContent::run($content, $work, ID, $key),
    'a changed row keeps the content'
);
same($changed, $store->data, 'nothing was touched');

// 4. Die Rücknahme selbst – durch den Einstieg von rescue.php (PushRescue::handle) –, mit Fremdem
//    an einem eingefügten Beitrag (R15).
PushRescue::write($work, ID, hash('sha256', $key), [], PushRescue::COMMITTED, [], str_repeat('ab', 32));
PushRescue::setContent($work, ID, PushRescue::CONTENT_APPLIED);
$store                                          = new ContentMemory($pushed);
$store->data['postmeta']["1000001\0farbe"]      = ['values' => ['rot']];
$store->data['postmeta']["1000001\0_edit_lock"] = ['values' => ['1:1']];
$request = ['action' => 'rollback', 'push_id' => ID, 'key' => $key, 'content' => '1'];
same([403, ['ok' => false, 'error' => 'wrong key']], PushRescue::handle([$content], ['key' => 'wrong'] + $request, time()), 'a wrong key is refused');
same($pushed['options'], array_intersect_key($store->data['options'], $pushed['options']), 'and touches nothing');
$left = [['table' => 'postmeta', 'key' => "1000001\0farbe"]];
same(
    [200, ['ok' => true, 'status' => 'rolled_back', 'content' => ['state' => 'rolled_back', 'cache' => 'none', 'left' => $left, 'left_total' => 1], 'warnings' => ['content_left_extra']]],
    PushRescue::handle([$content], $request, time()),
    'the content goes back, what grew stays'
);
$expected                                     = $before;
$expected['postmeta']["1000001\0farbe"]       = ['values' => ['rot']];
$expected['postmeta']["1000001\0_edit_lock"]  = ['values' => ['1:1']];
same($sorted($expected), $sorted($store->data), 'the state before the push, plus what the push never wrote');
same(null, ContentImage::$fileKeys, 'no file key stays in the process');
same(false, file_exists(RescueSeal::file($work, ID)), 'the envelope is gone');
same(true, file_exists(PushRescue::pendingFile($work)), 'the marker for the agent lies in the work folder');
same(true, RescueSeal::put($work, ID, (string) RescueSeal::seal($envelope, $key, ID)), 'a new envelope for the next case');
same(['state' => 'nothing', 'wrote' => false], RescueContent::run($content, $work, ID, $key), 'a second run finds nothing to do');

// 5. Dasselbe über ContentSql und RescueDb, mit einer Verbindung, die beim ersten Schreibzugriff
//    verloren geht (R5): ContentRepair sieht nach, content_failed ohne unrestored.
$links                  = [];
RescueContent::$resolve = static function (array $data) use (&$links, $pushed): ?\WpSync\ContentTarget {
    $db = RescueDb::connect($data['db'], static function () use (&$links, $pushed): FakeRescueLink {
        $link = new FakeRescueLink();
        $mark = null;
        $link->answer('/^SET @wpsync_tx = \'/', static function (string $sql) use (&$mark) {
            $mark = substr($sql, 18, 16);
            return null;
        });
        $link->answer('/^SELECT @wpsync_tx/', static function () use (&$mark) {
            return $mark === null ? [['@wpsync_tx' => null]] : $mark;
        });
        $link->answer('/^SHOW TABLE STATUS/', [['Engine' => 'InnoDB']]);
        $link->answer('/^SELECT \* FROM `wp_options`/', [$pushed['options']['blogname']]);
        $link->answer('/^SELECT `option_name` FROM `wp_options`/', [['option_name' => 'blogname']]);
        if ($links === []) {
            $link->fail('/^UPDATE `wp_options`/', 2006);
        }
        return $links[] = $link;
    });
    return $db === null ? null : RescueContent::target($data, $db);
};
ContentImage::$keys = [hash('sha256', 'key of the installation', true)];
$one                = ['t' => 'options', 'k' => 'blogname'];
ContentImage::put($dir, ContentImage::BEFORE, ['keys' => [$one + ['state' => ContentState::encode('options', $before['options']['blogname'])]]]);
ContentImage::put($dir, ContentImage::AFTER, ['keys' => [$one + ['h' => ContentFixtures::hash('options', 'blogname', $pushed['options']['blogname'])]], 'changes' => []]);
ContentImage::$keys = [];
same(['state' => 'kept', 'wrote' => false, 'error' => ['code' => 'content_failed']], RescueContent::run($content, $work, ID, $key), 'a lost connection fails without an unrestored row');
same(2, count($links), 'reconnected once');
same(false, in_array('COMMIT', array_merge($links[0]->queries, $links[1]->queries), true), 'no commit');

$final = array_merge(['PushRescue', 'PushSwap', 'RescueContent'], RescueContent::CLASSES);
sort($final);
same($final, loaded(), 'nothing else was loaded on the way');
echo 'OK ', count($final), " classes\n";
