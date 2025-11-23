<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);

if (!defined('PROJECT_ROOT')) {
    define('PROJECT_ROOT', $projectRoot);
}

$vendorAutoload = $projectRoot . '/vendor/autoload.php';
if (is_file($vendorAutoload)) {
    require $vendorAutoload;
} else {
    require $projectRoot . '/src/EventGraph/autoload.php';
    require_once $projectRoot . '/tests/EventGraph/Fixture/TacviewFixtureLoader.php';
}
require_once $projectRoot . '/src/core_path.php';

$config = require $projectRoot . '/config.php';
$corePath = tacview_resolve_core_path($config['core_path'] ?? 'core', $projectRoot);
require_once $corePath . '/tacview.php';

if (!defined('TEST_FIXTURE_DIR')) {
    define('TEST_FIXTURE_DIR', $projectRoot . '/tests/EventGraph/fixtures');
}
