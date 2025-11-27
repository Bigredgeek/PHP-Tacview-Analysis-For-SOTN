<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$config = require $root . '/config.php';
require $root . '/src/core_path.php';
$corePath = tacview_resolve_core_path($config['core_path'] ?? 'core', $root);
require $corePath . '/tacview.php';
require $root . '/src/EventGraph/autoload.php';

chdir($root);

$aggregator = new EventGraph\EventGraphAggregator(
    $config['default_language'] ?? 'en',
    $config['aggregator'] ?? []
);

foreach (glob($config['debriefings_path']) as $file) {
    $aggregator->ingestFile($file);
}

$mission = $aggregator->toAggregatedMission();
$metrics = $aggregator->getMetrics();

echo "=== Aggregator Metrics ===\n";
foreach ($metrics as $key => $value) {
    printf("%s: %s\n", $key, is_array($value) ? json_encode($value) : $value);
}
