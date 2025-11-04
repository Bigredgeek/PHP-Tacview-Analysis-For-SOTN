<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$config = require $root . '/config.php';

require $root . '/' . $config['core_path'] . '/tacview.php';
require $root . '/src/EventGraph/autoload.php';

use EventGraph\SourceRecording;

$target = isset($argv[1]) ? (float)$argv[1] : 0.0;
$window = isset($argv[2]) ? (float)$argv[2] : 1.0;
$pilotMatch = isset($argv[3]) ? strtolower($argv[3]) : null;
$actionMatch = isset($argv[4]) ? strtolower($argv[4]) : null;

foreach (glob($root . '/debriefings/*.xml') as $file) {
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
        printf("  time=%8.2f | action=%s | name=%s | pilot=%s | group=%s | coalition=%s | id=%s\n",
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
