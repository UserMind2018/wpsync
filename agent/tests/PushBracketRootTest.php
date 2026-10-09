<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use WpSync\ContentImage;
use WpSync\Push;
use WpSync\PushRescue;
use WpSync\Store;

require_once __DIR__ . '/PushRescueFlowCase.php';

/**
 * Der Agent unter einem Webroot, dessen Pfad Zeichen trägt, die glob() als Muster läse
 * (Security-Review P3, H1): Marker und Arbeitsordner werden trotzdem gefunden.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PushBracketRootTest extends PushRescueFlowCase
{
    protected function rootSuffix(): string
    {
        return '-[kunde]';
    }

    public function testTheMarkerOfARescueIsFoundOnInit(): void
    {
        list($id, $commit) = $this->push($this->stage($this->rows()), 'new');
        $this->assertInstanceOf(\WP_REST_Response::class, $commit);
        $keys               = ContentImage::$keys;
        ContentImage::$keys = [];
        $answer             = $this->rescueDb($id);
        ContentImage::$keys = $keys;
        $this->assertSame(200, $answer[0], (string) json_encode($answer[1]));
        $this->assertFileExists(PushRescue::pendingFile($this->work($this->live)));

        Push::catchUp();

        $this->assertSame('rolled_back', Store::getPush($id)['status']);
        $this->assertFileDoesNotExist(PushRescue::pendingFile($this->work($this->live)));
    }

    public function testUninstallRemovesTheWorkDirectory(): void
    {
        list(, $commit) = $this->push($this->stage($this->rows()), 'new');
        $this->assertInstanceOf(\WP_REST_Response::class, $commit);
        $work = $this->work($this->live);
        $this->assertDirectoryExists($work);
        Push::uninstall();
        $this->assertDirectoryDoesNotExist($work);
    }
}
