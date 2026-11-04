<?php
namespace WpSync;

defined('ABSPATH') || exit;

/** Ziel des Klartexts eines Schnappschusses: SnapshotFile, in Tests ein Puffer. */
interface SnapshotSink
{
    /** @throws \RuntimeException wenn nicht geschrieben werden konnte */
    public function write(string $data): void;
}
