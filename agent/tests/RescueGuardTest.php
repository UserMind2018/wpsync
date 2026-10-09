<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Welche Quelldateien ohne WordPress laufen dürfen (Spec Content-Push P3 R4, §4.2): genau die, die
 * rescue.php lädt. Jede andere bricht ohne ABSPATH sofort ab (SEC-13) – ein Direktaufruf verriete
 * sonst Serverpfade, und eine Datei, die versehentlich den weiten Guard bekäme, liefe ohne die
 * Annahmen, für die sie geschrieben ist.
 */
final class RescueGuardTest extends TestCase
{
    /** Vor der Schlüsselprüfung, der Einstieg in die Rücknahme der Inhalte und dessen feste Liste (RescueContent::CLASSES). */
    private const RESCUE = [
        'PushSwap', 'PushRescue', 'RescueContent',
        'Canon', 'SerializedWalker', 'StagingReplace', 'ContentOrigin', 'ContentException', 'ContentStore', 'ContentState', 'ContentLists',
        'ContentReader', 'ContentImage', 'ContentTarget', 'ContentSql', 'ContentRepair', 'ContentRollback', 'RescueSeal', 'RescueLink',
        'MysqliLink', 'RescueDb',
    ];

    private const STRICT = "defined('ABSPATH') || exit;";
    private const WIDE   = "defined('ABSPATH') || defined('WPSYNC_RESCUE') || exit;";

    public function testOnlyWhatRescueLoadsRunsWithoutWordPress(): void
    {
        $wide = [];
        foreach (glob(__DIR__ . '/../src/*.php') ?: [] as $file) {
            $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];
            $guard = array_values(array_filter($lines, static function (string $line): bool {
                return strpos($line, 'defined(') === 0;
            }))[0] ?? '';
            $this->assertContains($guard, [self::STRICT, self::WIDE], basename($file));
            if ($guard === self::WIDE) {
                $wide[] = basename($file, '.php');
            }
        }
        $expected = array_values(array_filter(self::RESCUE, static function (string $name): bool {
            return is_file(__DIR__ . '/../src/' . $name . '.php');
        }));
        sort($wide);
        sort($expected);
        $this->assertSame($expected, $wide);
    }
}
