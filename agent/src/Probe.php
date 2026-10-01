<?php
namespace WpSync;

defined('ABSPATH') || exit;

/** Datenquelle des Inventars: in WordPress WpProbe, in Tests ein Fake. */
interface Probe
{
    /** @return array<string, mixed> Umgebung wie /ping */
    public function env(): array;

    public function prefix(): string;

    /** @return list<array{slug: string, name: string, version: string, active: bool}> */
    public function plugins(): array;

    /** @return list<array{slug: string, name: string, version: string, active: bool}> */
    public function themes(): array;

    /** @return list<array{name: string, rows: int, bytes: int}> ohne eigene Tabellen */
    public function tables(): array;

    /** @return array{posts: int, postmeta: int} höchste ID bzw. meta_id */
    public function maxIds(): array;

    /** @return array<string, array{count: int, bytes: int}> Post-Typ → Anzahl, Bytes für ID in (from, to] */
    public function postStats(int $from, int $to): array;

    /** @return array<string, array{count: int, bytes: int}> Post-Typ des Eltern-Posts ('' = verwaist) → Zeilen, Bytes für meta_id in (from, to] */
    public function postmetaStats(int $from, int $to): array;

    /** @return list<string> vorhandene Drop-ins, relativ zu ABSPATH */
    public function dropIns(): array;
}
