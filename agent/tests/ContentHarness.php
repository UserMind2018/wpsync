<?php
/**
 * Umgebung für ContentManifestTest: AgentHarness plus is_multisite(). Darf nur in einem eigenen
 * Prozess geladen werden (RunTestsInSeparateProcesses).
 */
namespace {
    require_once __DIR__ . '/AgentHarness.php';

    function is_multisite(): bool
    {
        return !empty($GLOBALS['wpsync_test_multisite']);
    }
}
