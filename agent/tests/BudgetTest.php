<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WpSync\Budget;

final class BudgetTest extends TestCase
{
    /** @return array<string, array{int, int}> */
    public static function cases(): array
    {
        return [
            'unlimited'  => [0, 20],
            '30 s host'  => [30, 18],
            '60 s host'  => [60, 20],
            '10 s host'  => [10, 6],
            'tiny limit' => [2, 2],
        ];
    }

    #[DataProvider('cases')]
    public function testSeconds(int $maxExecutionTime, int $expected): void
    {
        $this->assertSame($expected, Budget::seconds($maxExecutionTime));
    }
}
