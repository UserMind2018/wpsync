<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use WpSync\ContentException;
use WpSync\ContentImage;
use WpSync\ContentTarget;
use WpSync\PushContent;

/**
 * PushContent reicht den Plugin-Zustand an den DB-Schritt durch (Spec Content-Push P4 §8.1 Nr. 5,
 * A7): auch ohne Paket, und bei der Rücknahme mit dem Wissen, ob der COMMIT quittiert ist (V1).
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PushContentPluginsTest extends TestCase
{
    private string $dir;
    private ContentMemory $store;

    protected function setUp(): void
    {
        require_once __DIR__ . '/ContentFixtures.php';
        require_once __DIR__ . '/ContentMemory.php';
        $this->dir   = (string) realpath(sys_get_temp_dir()) . '/wpsync-pcp-' . bin2hex(random_bytes(4)) . '/p_20261009_0123456789ab';
        $this->store = new ContentMemory(['options' => ['active_plugins' => ContentFixtures::option('active_plugins', serialize(['akismet/akismet.php', 'old/old.php']), '33')]]);
        PushContent::$resolve = function (string $name, string $content): ContentTarget {
            return ContentFixtures::live($this->store);
        };
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg(dirname($this->dir)));
    }

    public function testApplyWithoutAPackageAndRollback(): void
    {
        $applied = PushContent::apply(null, 'live', '/nonexistent/wp-content', $this->dir, 7, null, ['add' => ['kunde/kunde.php'], 'drop' => ['old']]);
        $this->assertSame(0, $applied['rows']);
        $this->assertSame([], $applied['after']);
        $this->assertSame(['added' => ['kunde/kunde.php'], 'removed' => ['old/old.php']], $applied['plugins']);
        $this->assertIsFloat($applied['seconds']);
        $this->assertFileExists($this->dir . '/' . PushContent::UNIT . '/' . ContentImage::BEFORE, 'die Abbilder liegen im Ordner content des Pushs');
        $this->assertSame(serialize(['akismet/akismet.php', 'kunde/kunde.php']), $this->store->data['options']['active_plugins']['option_value']);

        $back = PushContent::rollback('live', '/nonexistent/wp-content', $this->dir);
        $this->assertSame(['deactivated' => ['kunde/kunde.php'], 'reactivated' => ['old/old.php']], $back['plugins']);
        $this->assertSame(serialize(['akismet/akismet.php', 'old/old.php']), $this->store->data['options']['active_plugins']['option_value']);
    }

    public function testRollbackPassesOnWhetherTheCommitIsAcknowledged(): void
    {
        PushContent::apply(null, 'live', '/nonexistent/wp-content', $this->dir, 7, null, ['add' => ['kunde/kunde.php'], 'drop' => []]);
        $this->store->data['options']['active_plugins']['option_value'] = serialize(['akismet/akismet.php', 'fremd/fremd.php', 'kunde/kunde.php', 'old/old.php']);
        try {
            PushContent::rollback('live', '/nonexistent/wp-content', $this->dir, false);
            $this->fail('ohne Quittung zählt der Abdruck');
        } catch (ContentException $e) {
            $this->assertSame(ContentException::CHANGED, $e->reason());
        }
        $this->assertSame(['deactivated' => ['kunde/kunde.php'], 'reactivated' => []], PushContent::rollback('live', '/nonexistent/wp-content', $this->dir, true)['plugins']);
    }
}
