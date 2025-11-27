<?php
declare(strict_types=1);

require_once __DIR__ . '/../public/config.php';
$config = require __DIR__ . '/../public/config.php';

require_once __DIR__ . '/../src/core_path.php';
$corePath = tacview_resolve_core_path($config['core_path'] ?? 'core', dirname(__DIR__));
require_once $corePath . '/tacview.php';
require_once __DIR__ . '/../src/EventGraph/autoload.php';

use EventGraph\EventGraphAggregator;

$debriefingsGlob = dirname(__DIR__) . '/debriefings/*.xml';
$xmlFiles = glob($debriefingsGlob) ?: [];

echo "Found " . count($xmlFiles) . " XML files\n";

$aggregatorOptions = $config['aggregator'] ?? [];
$aggregator = new EventGraphAggregator($config['default_language'], $aggregatorOptions);

foreach ($xmlFiles as $filexml) {
    $aggregator->ingestFile($filexml);
    echo "Ingested: " . basename($filexml) . "\n";
}

$mission = $aggregator->toAggregatedMission();
$events = $mission->getEvents();
$totalEvents = count($events);
echo "Total events: " . $totalEvents . "\n";
echo "Sources: " . count($mission->getSources()) . "\n";

// Apply event limit
$maxEvents = (int)($config['max_events'] ?? 0);
if ($maxEvents > 0 && count($events) > $maxEvents) {
    $events = array_slice($events, 0, $maxEvents);
    echo "Events after limit ($maxEvents): " . count($events) . "\n";
}

$tv = new tacview($config['default_language']);
$tv->image_path = '/';
$sources = $mission->getSources();
$tv->proceedAggregatedStats(
    $mission->getMissionName(),
    $mission->getStartTime(),
    $mission->getDuration(),
    $events,
    count($sources),
    $sources
);

$output = $tv->getOutput();
echo "Output size: " . strlen($output) . " bytes (" . round(strlen($output) / 1024 / 1024, 2) . " MB)\n";

// Also check what gzip would give us
$compressed = gzencode($output, 9);
echo "Gzipped size: " . strlen($compressed) . " bytes (" . round(strlen($compressed) / 1024 / 1024, 2) . " MB)\n";

// Vercel limits
echo "\n--- Vercel Limits ---\n";
$hobbyLimit = 4.5 * 1024 * 1024;
$proLimit = 6 * 1024 * 1024;
$outputSize = strlen($output);
echo "Hobby (4.5MB): " . ($outputSize < $hobbyLimit ? "✅ OK" : "❌ EXCEEDS") . "\n";
echo "Pro (6MB): " . ($outputSize < $proLimit ? "✅ OK" : "❌ EXCEEDS") . "\n";
