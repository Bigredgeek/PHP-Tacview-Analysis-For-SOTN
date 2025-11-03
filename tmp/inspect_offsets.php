<?php

declare(strict_types=1);

$config = require __DIR__ . '/../config.php';
require $config['core_path'] . '/tacview.php';
require __DIR__ . '/../src/EventGraph/autoload.php';

$aggregator = new \EventGraph\EventGraphAggregator($config['default_language'], $config['aggregator']);

foreach (glob(__DIR__ . '/../debriefings/*.xml') as $file) {
    $aggregator->ingestFile($file);
}

$mission = $aggregator->toAggregatedMission();

echo "Recording offsets:\n";
foreach ($aggregator->getRecordingOffsets() as $id => $offset) {
    printf("- %s: %.3f\n", $id, $offset);
}

echo "\nSources:\n";
foreach ($mission->getSources() as $source) {
    printf("- %s | offset %.3f | start %.3f | duration %.3f | events %d | strategy %s\n",
        $source['id'],
        $source['offset'] ?? 0.0,
        $source['startTime'] ?? 0.0,
        $source['duration'] ?? 0.0,
        $source['events'] ?? 0,
        $source['offsetStrategy'] ?? 'n/a'
    );
}
