<?php

declare(strict_types=1);

/**
 * EventGraph now lives in the shared Tacview core (php-tacview-core/src/EventGraph).
 *
 * This shim resolves the core path and delegates class autoloading to the core's EventGraph
 * autoloader, so every entry point that historically required src/EventGraph/autoload.php keeps
 * working unchanged. The class files are no longer duplicated in this branch — they are fetched
 * with the core bundle (or served from the local ./core copy during development).
 *
 * The resolution runs inside an isolated closure so it never leaks or clobbers variables in the
 * scope of whatever entry point requires this file.
 */

require_once __DIR__ . '/../core_path.php';

(static function (): void {
    $projectRoot = dirname(__DIR__, 2);
    $config = is_file($projectRoot . '/config.php') ? include $projectRoot . '/config.php' : [];
    $configuredCore = is_array($config) ? ($config['core_path'] ?? 'core') : 'core';

    $corePath = tacview_resolve_core_path($configuredCore, $projectRoot);
    $coreAutoload = $corePath . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'EventGraph'
        . DIRECTORY_SEPARATOR . 'autoload.php';

    if (!is_file($coreAutoload)) {
        throw new \RuntimeException(
            'EventGraph autoloader not found in the shared core at ' . $coreAutoload
            . '. Ensure php-tacview-core (including src/EventGraph) is present or fetched via scripts/fetch-core.php.'
        );
    }

    require_once $coreAutoload;
})();
