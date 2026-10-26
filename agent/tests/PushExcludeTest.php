<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use WpSync\Push;

require_once __DIR__ . '/PushContentFlowCase.php';

/**
 * Spec 0.10.0 E: ausgeschlossene Pfade einer Code-Einheit sind beim Push weder neu noch gelöscht –
 * der Agent übernimmt sie aus dem Ziel (AC-267) und lehnt ein Manifest ab, das welche nennt (E2).
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PushExcludeTest extends PushContentFlowCase
{
    private const MTIME = 1700000000;
    private const CACHE = "\x00binär\xff";

    protected function setUp(): void
    {
        parent::setUp();
        mkdir($this->live . '/plugins/x/cache', 0777, true);
        file_put_contents($this->live . '/plugins/x/cache/x.bin', self::CACHE);
        touch($this->live . '/plugins/x/cache/x.bin', self::MTIME);
        touch($this->live . '/plugins/x/main.php', self::MTIME);
    }

    /**
     * @param array<string, mixed> $extra
     * @return \WP_REST_Response|\WP_Error
     */
    private function beginX(string $code, array $extra = [], array $files = [])
    {
        $unit = [
            'path'  => 'plugins/x',
            'files' => ($files ?: ['main.php' => $this->entry($code)]),
            'base'  => ['main.php' => ['size' => 3, 'mtime' => self::MTIME]],
        ];
        return Push::begin($extra + ['force' => false, 'units' => [$unit], 'exclude_paths' => ['plugins/x/cache/']], self::KEY);
    }

    public function testExcludedFilesOfTheTargetAreNoConflict(): void
    {
        $dry = $this->beginX('new', ['dry' => true]);
        $this->assertInstanceOf(\WP_REST_Response::class, $dry, $dry instanceof \WP_Error ? $dry->code . ' ' . $dry->message : '');
        $this->assertSame([], $dry->data['units'][0]['conflicts']);

        // Ohne Ausschluss (Altverhalten) ist die Datei, die die Baseline nicht kennt, ein Konflikt.
        $old = $this->beginX('new', ['dry' => true, 'exclude_paths' => []]);
        $this->assertInstanceOf(\WP_REST_Response::class, $old);
        $this->assertSame(['cache/x.bin'], $old->data['units'][0]['conflicts']);
        $this->assertRefused('wpsync_push_conflict', 409, $this->beginX('new', ['exclude_paths' => []]));
    }

    /** AC-267, E4: Commit und Rücknahme lassen die ausgeschlossene Datei byte-gleich liegen. */
    public function testPushAndRollbackKeepExcludedFiles(): void
    {
        $cache = $this->live . '/plugins/x/cache/x.bin';
        $begin = $this->beginX('new');
        $this->assertInstanceOf(\WP_REST_Response::class, $begin, $begin instanceof \WP_Error ? $begin->code . ' ' . $begin->message : '');
        $id = (string) $begin->data['push_id'];
        $this->assertInstanceOf(\WP_REST_Response::class, Push::upload(['push_id' => $id, 'unit' => 0, 'files' => [['path' => 'main.php', 'data' => base64_encode('new'), 'offset' => 0]]], self::KEY));
        $commit = Push::commit(['push_id' => $id], self::KEY);
        $this->assertInstanceOf(\WP_REST_Response::class, $commit, $commit instanceof \WP_Error ? $commit->code . ' ' . $commit->message : '');

        clearstatcache();
        $this->assertSame('new', file_get_contents($this->live . '/plugins/x/main.php'));
        $this->assertSame(hash('sha256', self::CACHE), hash_file('sha256', $cache));
        $this->assertSame(self::MTIME, filemtime($cache));
        $stamps = json_decode((string) json_encode($commit->data['stamps']), true)['plugins/x'];
        $this->assertArrayHasKey('main.php', $stamps);
        $this->assertArrayNotHasKey('cache/x.bin', $stamps, 'E3: nicht in der neuen Baseline');

        $back = Push::rollback(['push_id' => $id], self::KEY);
        $this->assertInstanceOf(\WP_REST_Response::class, $back, $back instanceof \WP_Error ? $back->code . ' ' . $back->message : '');
        clearstatcache();
        $this->assertSame('old', file_get_contents($this->live . '/plugins/x/main.php'));
        $this->assertSame(hash('sha256', self::CACHE), hash_file('sha256', $cache));
        $this->assertSame(self::MTIME, filemtime($cache));
    }

    /** E2: eine Manifest-Datei unter einem Ausschluss wird abgelehnt – schon im Probelauf. */
    public function testManifestWithExcludedFileIsRefused(): void
    {
        foreach ([true, false] as $dry) {
            $result = $this->beginX('new', ['dry' => $dry], ['main.php' => $this->entry('new'), 'cache/y.bin' => $this->entry('y')]);
            $error  = $this->assertRefused('wpsync_push_excluded', 400, $result);
            $this->assertStringContainsString('wp-content/plugins/x/cache/y.bin', $error->message);
        }
        $this->assertFileDoesNotExist($this->live . '/plugins/x/cache/y.bin');
    }

    public function testInvalidPatternsAreRefused(): void
    {
        foreach ([['plugins/x/'], ['*'], 'uploads/', [42]] as $bad) {
            $this->assertRefused('wpsync_push_excluded', 400, $this->beginX('new', ['dry' => true, 'exclude_paths' => $bad]));
        }
    }
}
