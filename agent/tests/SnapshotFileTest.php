<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\TestCase;
use WpSync\SnapshotFile;

final class SnapshotFileTest extends TestCase
{
    private const ID = '0123456789abcdef0123456789abcdef';

    private string $path;
    private string $key;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/wpsync-snap-test-' . bin2hex(random_bytes(6)) . '.bin';
        $this->key  = random_bytes(32);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    /** @return array{0: string, 1: array{blocks: int, size: int, sha256: string, plain: int}} */
    private function write(string $sql): array
    {
        $file = SnapshotFile::create($this->path, $this->key, self::ID);
        foreach (str_split($sql, 100000) as $part) {
            $file->write($part);
        }
        return [$sql, $file->finish()];
    }

    private function readAll(int $blocks, string $id = self::ID): ?string
    {
        $out = '';
        for ($i = 0; $i < $blocks; $i++) {
            $block = SnapshotFile::block($this->path, $this->key, $id, $i, $blocks);
            if ($block === null) {
                return null;
            }
            $out .= $block;
        }
        return $out;
    }

    public function testRoundTripAcrossSeveralBlocks(): void
    {
        // Zufalls-Hex komprimiert schlecht: > 1 MiB gzip, also mehrere Blöcke.
        [$sql, $result] = $this->write(bin2hex(random_bytes(1500000)));
        $this->assertGreaterThanOrEqual(2, $result['blocks']);
        $this->assertSame(strlen($sql), $result['plain']);
        $gz = $this->readAll($result['blocks']);
        $this->assertNotNull($gz);
        $this->assertSame($result['size'], strlen($gz));
        $this->assertSame(hash('sha256', $gz), $result['sha256']);
        $this->assertSame($sql, gzdecode($gz));
        for ($i = 0; $i < $result['blocks'] - 1; $i++) {
            $this->assertSame(SnapshotFile::BLOCK, strlen((string) SnapshotFile::block($this->path, $this->key, self::ID, $i, $result['blocks'])));
        }
    }

    public function testSmallDumpIsOneBlockAndNoPlainText(): void
    {
        [, $result] = $this->write("INSERT INTO `wp_options` VALUES (1,'geheim-wert');\n");
        $this->assertSame(1, $result['blocks']);
        $this->assertStringNotContainsString('geheim-wert', (string) file_get_contents($this->path));
        $this->assertSame('0600', substr(sprintf('%o', fileperms($this->path)), -4));
    }

    public function testTamperedByteDoesNotOpen(): void
    {
        [, $result] = $this->write(str_repeat('x', 5000));
        $raw      = (string) file_get_contents($this->path);
        $raw[40]  = $raw[40] === 'a' ? 'b' : 'a';
        file_put_contents($this->path, $raw);
        $this->assertNull(SnapshotFile::block($this->path, $this->key, self::ID, 0, $result['blocks']));
    }

    public function testWrongJobIdOrKeyDoesNotOpen(): void
    {
        [, $result] = $this->write(str_repeat('x', 5000));
        $this->assertNull($this->readAll($result['blocks'], 'ffffffffffffffffffffffffffffffff'));
        $this->assertNull(SnapshotFile::block($this->path, random_bytes(32), self::ID, 0, $result['blocks']));
    }

    public function testCutFileDoesNotPassAsComplete(): void
    {
        [, $result] = $this->write(bin2hex(random_bytes(1500000)));
        // Wer nur die ersten Blöcke als „alle“ ausgibt, scheitert am Merkmal „last“.
        $this->assertNull(SnapshotFile::block($this->path, $this->key, self::ID, $result['blocks'] - 2, $result['blocks'] - 1));
        $this->assertNull(SnapshotFile::block($this->path, $this->key, self::ID, $result['blocks'], $result['blocks']));
    }

    public function testCreateRefusesAnExistingFileAndShortKeys(): void
    {
        touch($this->path);
        try {
            SnapshotFile::create($this->path, $this->key, self::ID);
            $this->fail('existing file must be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not creatable', $e->getMessage());
        }
        $this->expectException(\InvalidArgumentException::class);
        SnapshotFile::create($this->path . '.x', 'short', self::ID);
    }
}
