<?php
// Die Quelldateien brechen ohne ABSPATH ab (SEC-13) – für Unit-Tests genügt ein Platzhalter.
define('ABSPATH', sys_get_temp_dir() . '/wpsync-tests/');

require __DIR__ . '/../vendor/autoload.php';
