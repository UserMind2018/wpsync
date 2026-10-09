<?php
declare(strict_types=1);

namespace WpSync\Tests;

use WpSync\ContentImage;
use WpSync\ContentTarget;
use WpSync\Push;
use WpSync\PushContent;
use WpSync\PushRescue;
use WpSync\RescueContent;
use WpSync\RescueSeal;
use WpSync\Staging;
use WpSync\Store;

require_once __DIR__ . '/PushContentFlowCase.php';

/**
 * Aufbau der Tests, die rescue.php mit DB-Anteil im echten Push-Ablauf prüfen (PushRescueFlowTest,
 * PushSyncRescueTest): die Installation hat einen Schlüssel, der Begin kann einen Umschlag
 * anlegen (Naht PushContent::$rescueData), und rescue.php erreicht die Tabellen des Ziels über
 * die Naht RescueContent::$resolve – dieselben Stores im Speicher wie der Agent.
 */
abstract class PushRescueFlowCase extends PushContentFlowCase
{
    /** @var int wie oft rescue.php bzw. die Probe des Begin „verbunden“ hat */
    protected int $connected = 0;

    protected function setUp(): void
    {
        parent::setUp();
        // Die Installation hat einen Schlüssel, die Datenbank ist mysqli: der Begin kann einen Umschlag anlegen.
        ContentImage::$keys      = [hash('sha256', 'schlüssel der installation', true)];
        ContentImage::$encrypt   = false;
        PushContent::$rescueData = static function (string $name, string $id): array {
            return [
                'target'     => $name,
                'db'         => ['host' => 'db.internal', 'port' => null, 'socket' => null, 'user' => 'wp_user', 'password' => 'geh3im!', 'name' => 'wordpress_db', 'flags' => 0, 'charset' => 'utf8mb4', 'collate' => '', 'sql_mode' => ''],
                'prefix'     => $name === 'staging' ? 'stgabcdef_' : 'wp_',
                'home'       => ContentFixtures::HOME,
                'siteurl'    => ContentFixtures::HOME,
                'staging'    => $name === 'staging' ? ['dir' => Staging::DIR, 'live_home' => ContentFixtures::HOME, 'live_prefix' => 'wp_'] : null,
                'image_keys' => [
                    ContentImage::BEFORE => array_map('base64_encode', ContentImage::fileKeys($id, ContentImage::BEFORE)),
                    ContentImage::AFTER  => array_map('base64_encode', ContentImage::fileKeys($id, ContentImage::AFTER)),
                ],
            ];
        };
        RescueContent::$resolve = function (array $data, string $contentDir): ?ContentTarget {
            $this->connected++;
            return $data['target'] === 'staging'
                ? ContentFixtures::staging($this->stagingDb, $contentDir . '/uploads')
                : ContentFixtures::live($this->liveDb, $contentDir . '/uploads');
        };
    }

    protected function sealed(string $content, string $id): string
    {
        return RescueSeal::file($this->work($content), $id);
    }

    /**
     * rescue.php, wie eine CLI 0.8.0 es aufruft: mit content=1.
     *
     * @param array<string, mixed> $over
     * @return array{0: int, 1: array<string, mixed>}
     */
    protected function rescueDb(string $id, array $over = []): array
    {
        $key = PushRescue::key((string) Store::secretFor(self::KEY), $id, $this->salt);
        $answer = PushRescue::handle(PushRescue::contentDirs($this->live), $over + ['action' => 'rollback', 'push_id' => $id, 'key' => $key, 'content' => '1'], time());
        // Jede Antwort einer Rücknahme nennt ihren Push (die CLI vergleicht ihn); geprüft hier, einmal für alle.
        if ($answer[0] === 200 && ($answer[1]['status'] ?? '') === 'rolled_back') {
            $this->assertSame($id, $answer[1]['push_id'] ?? null);
            unset($answer[1]['push_id']);
        }
        return $answer;
    }

    /** Begin und Upload des Codes; der Commit steht noch aus. */
    protected function uploaded(string $sha): string
    {
        $begin = $this->begin($sha, [], 'new');
        $this->assertInstanceOf(\WP_REST_Response::class, $begin, $begin instanceof \WP_Error ? $begin->code . ' ' . $begin->message : '');
        $id         = (string) $begin->data['push_id'];
        $this->salt = (string) $begin->data['rescue']['salt'];
        $this->assertInstanceOf(\WP_REST_Response::class, Push::upload(['push_id' => $id, 'unit' => 0, 'files' => [['path' => 'main.php', 'data' => base64_encode('new'), 'offset' => 0]]], self::KEY));
        return $id;
    }
}
