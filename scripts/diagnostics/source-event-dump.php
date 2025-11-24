#!/usr/bin/env php
<?php

declare(strict_types=1);

use EventGraph\SourceRecording;

$projectRoot = dirname(__DIR__, 2);
$config = require $projectRoot . '/config.php';

require_once $projectRoot . '/src/core_path.php';
$corePath = tacview_resolve_core_path($config['core_path'] ?? 'core', $projectRoot);
require_once $corePath . '/tacview.php';
require_once $projectRoot . '/src/EventGraph/autoload.php';

$target = isset($argv[1]) ? (float)$argv[1] : 0.0;
$window = isset($argv[2]) ? (float)$argv[2] : 1.0;
$pilotMatch = isset($argv[3]) ? strtolower($argv[3]) : null;
$actionMatch = isset($argv[4]) ? strtolower($argv[4]) : null;

$files = glob($projectRoot . '/debriefings/*.xml') ?: [];
if ($files === []) {
    fwrite(STDERR, "No Tacview XML files found under debriefings/.\n");
    exit(1);
}

foreach ($files as $file) {
    $recording = new SourceRecording($file, $config['default_language']);
    printf("Recording %s start=%.2f duration=%.2f\n", $recording->id, $recording->startTime ?? -1.0, $recording->duration ?? -1.0);
    foreach ($recording->events as $event) {
        $time = $event->getMissionTime();
        if ($time < $target - $window || $time > $target + $window) {
            continue;
        }
        $action = strtolower($event->getType());
        if ($actionMatch !== null && $action !== $actionMatch) {
            continue;
        }
        $primary = $event->getPrimary();
        $pilot = strtolower((string)($primary['Pilot'] ?? ''));
        if ($pilotMatch !== null && $pilot !== $pilotMatch) {
            continue;
        }
        printf(
            "  time=%8.2f | action=%s | name=%s | pilot=%s | group=%s | coalition=%s | id=%s\n",
            $time,
            $event->getType(),
            $primary['Name'] ?? 'unknown',
            $primary['Pilot'] ?? 'none',
            $primary['Group'] ?? 'none',
            $primary['Coalition'] ?? 'none',
            $primary['ID'] ?? 'none'
        );
    }
}
