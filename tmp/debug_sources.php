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

foreach ($mission->getSources() as $source) {
    $id = $source['id'] ?? $source['filename'] ?? 'unknown';
    $offset = isset($source['offset']) ? (float)$source['offset'] : 0.0;
    $strategy = $source['offsetStrategy'] ?? 'none';
    $baseline = !empty($source['baseline']);
    $start = isset($source['startTime']) ? (float)$source['startTime'] : null;
    $duration = isset($source['duration']) ? (float)$source['duration'] : null;
    printf(
        "%s | offset=%+.2f | strategy=%s | baseline=%s | start=%.2f | duration=%.2f\n",
        $id,
        $offset,
        $strategy,
        $baseline ? 'yes' : 'no',
        $start ?? -1,
        $duration ?? -1
    );
}
