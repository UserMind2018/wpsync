<?php
namespace WpSync;

defined('ABSPATH') || exit;

/**
 * Zeit, die ein Request höchstens arbeitet, bevor er mit Cursor endet (AC-10).
 * 60 % von max_execution_time, höchstens 20 s (Proxy-Timeouts), mindestens 2 s.
 */
final class Budget
{
    public static function seconds(int $maxExecutionTime): int
    {
        if ($maxExecutionTime <= 0) {
            return 20;
        }
        return (int) min(20, max(2, (int) floor($maxExecutionTime * 0.6)));
    }
}
