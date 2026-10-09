<?php
declare(strict_types=1);

namespace WpSync\Tests;

use WpSync\ContentApply;
use WpSync\ContentException;
use WpSync\ContentPackage;

require_once __DIR__ . '/ContentApplyCase.php';

/**
 * Die Naht $gate von ContentApply (Spec Content-Push P3 R10, §7.1): unmittelbar vor COMMIT fragt
 * der Commit, ob der Push noch gilt. Sagt die Naht nein – rescue.php hat ihn inzwischen
 * zurückgenommen –, nimmt die Datenbank alles zurück.
 */
final class ContentApplyGateTest extends ContentApplyCase
{
    /** @return array<string, mixed> */
    private function applyWith(?callable $gate): array
    {
        $this->files[] = $file = ContentFixtures::file($this->rows());
        return ContentApply::run(ContentPackage::read($file), ContentFixtures::live($this->store), $this->dir, 7, self::NOW, '2026-10-09 14:13:20', $gate);
    }

    public function testTheGateIsAskedOnceRightBeforeTheCommit(): void
    {
        $seen   = [];
        $result = $this->applyWith(function () use (&$seen): bool {
            $seen[] = [$this->store->log[count($this->store->log) - 1], file_exists($this->dir . '/before.json'), file_exists($this->dir . '/after.json'), $this->store->alive()];
            return true;
        });
        $this->assertCount(1, $seen);
        $this->assertNotContains('commit', $this->store->log === [] ? [] : array_slice($this->store->log, 0, -1));
        $this->assertStringStartsWith('write ', $seen[0][0], 'alles ist geschrieben');
        $this->assertSame([true, true, true], array_slice($seen[0], 1), 'beide Abbilder liegen, die Transaktion läuft noch');
        $this->assertSame('commit', $this->store->log[count($this->store->log) - 1]);
        $this->assertSame(12, $result['rows']);
        $this->assertSame('Neu', $this->store->data['posts']['219']['post_title']);
    }

    /** AC-167: sagt die Naht nein, bleibt keine Zeile – ROLLBACK, content_failed. */
    public function testARefusingGateRollsEverythingBack(): void
    {
        $old = $this->store->data;
        try {
            $this->applyWith(static function (): bool {
                return false;
            });
            $this->fail('no exception');
        } catch (ContentException $e) {
            $this->assertSame(ContentException::FAILED, $e->reason());
            $this->assertStringContainsString('zurückgenommen', $e->getMessage());
            $this->assertSame([], $e->keys());
            $this->assertArrayNotHasKey('unrestored', $e->toArray());
        }
        $this->assertSame($old, $this->store->data);
        $this->assertSame('rollback', $this->store->log[count($this->store->log) - 1]);
        $this->assertNotContains('commit', $this->store->log);
    }

    public function testAThrowingGateRollsBackToo(): void
    {
        $old = $this->store->data;
        try {
            $this->applyWith(static function (): bool {
                throw new \RuntimeException('boom');
            });
            $this->fail('no exception');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }
        $this->assertSame($old, $this->store->data);
        $this->assertNotContains('commit', $this->store->log);
    }

    /** Ohne Naht – jeder Aufrufer vor P3 – ändert sich nichts. */
    public function testWithoutAGateNothingChanges(): void
    {
        $result = $this->applyWith(null);
        $this->assertSame(12, $result['rows']);
        $this->assertSame('commit', $this->store->log[count($this->store->log) - 1]);
    }
}
