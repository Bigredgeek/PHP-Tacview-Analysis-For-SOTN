<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$config = require $root . '/config.php';

require $root . '/' . $config['core_path'] . '/tacview.php';
require $root . '/src/EventGraph/autoload.php';

$targetTime = isset($argv[1]) ? (float)$argv[1] : 0.0;
$window = isset($argv[2]) ? (float)$argv[2] : 0.5;

$aggregator = new \EventGraph\EventGraphAggregator($config['default_language'], $config['aggregator']);
foreach (glob($root . '/debriefings/*.xml') as $file) {
    $aggregator->ingestFile($file);
}

$mission = $aggregator->toAggregatedMission();

foreach ($mission->getEvents() as $event) {
    $time = (float)($event['Time'] ?? 0.0);
    if ($time < $targetTime - $window || $time > $targetTime + $window) {
        continue;
    }
    print_r($event);
}
