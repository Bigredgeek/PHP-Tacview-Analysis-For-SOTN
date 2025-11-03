<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$config = require $root . '/config.php';

require $root . '/' . $config['core_path'] . '/tacview.php';
require $root . '/src/EventGraph/autoload.php';

$aggregator = new \EventGraph\EventGraphAggregator($config['default_language'], $config['aggregator']);
foreach (glob($root . '/debriefings/*.xml') as $file) {
    $aggregator->ingestFile($file);
}

$mission = $aggregator->toAggregatedMission();

$matchType = strtolower($argv[1] ?? 'hasbeenDestroyed');
$matchPilot = strtolower($argv[2] ?? 'menton 2-1 | castor');

foreach ($mission->getEvents() as $event) {
    $type = strtolower((string)($event['Action'] ?? ''));
    if ($type !== $matchType) {
        continue;
    }
    $primary = $event['PrimaryObject'] ?? null;
    if (!is_array($primary) || !isset($primary['Pilot'])) {
        continue;
    }
    if (strtolower((string)$primary['Pilot']) !== $matchPilot) {
        continue;
    }

    $time = (float)($event['Time'] ?? 0.0);
    $confidence = isset($event['Confidence']) ? (float)$event['Confidence'] : -1.0;
    $sources = [];
    foreach ($event['Evidence'] ?? [] as $evidence) {
        if (!is_array($evidence)) {
            continue;
        }
        $sources[] = $evidence['sourceId'] ?? 'unknown';
    }
    printf("time=%8.2f | conf=%5.2f | sources=%s\n", $time, $confidence, implode(',', $sources));
}
