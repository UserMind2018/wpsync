<?php
namespace WpSync;

defined('ABSPATH') || exit;

/** Zugang zur Staging-Kopie – vollständig in Task 4. */
final class StagingAccess
{
    public const FILE   = 'wp-content/wpsync-staging.json';
    public const COOKIE = 'wpsync_stg';
}
