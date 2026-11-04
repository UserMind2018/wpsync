<?php
namespace WpSync;

defined('ABSPATH') || exit;

/** Was der Nachtbericht von der Site liest. */
interface ActivityProbe
{
    /** @return array<string, mixed> wie ActivityDiff::snapshot() */
    public function snapshot(): array;

    public function userLogin(int $id): ?string;

    /** @return array{plugins: list<string>, themes: list<string>} */
    public function autoUpdates(): array;
}
