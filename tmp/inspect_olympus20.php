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

echo "=== All Olympus-20-2 destruction events ===\n\n";

foreach ($mission->getEvents() as $event) {
    if (($event['Action'] ?? null) !== 'HasBeenDestroyed') {
        continue;
    }

    $tgtPilot = $event['PrimaryObject']['Pilot'] ?? '';
    if (stripos($tgtPilot, 'Olympus-20-2') === false) {
        continue;
    }

    $evidence = $event['Evidence'] ?? [];
    $sources = [];
    foreach ($evidence as $ev) {
        $sources[$ev['sourceId'] ?? 'unknown'] = true;
    }

    $time = $event['Time'] ?? 0.0;
    $hours = (int)floor($time / 3600);
    $mins = (int)floor(fmod($time, 3600) / 60);
    $secs = (int)floor(fmod($time, 60));
    $timeFormatted = sprintf("%02d:%02d:%02d", $hours, $mins, $secs);

    printf("Time: %s (t=%0.3f)\n", $timeFormatted, $time);
    printf("Target: %s | %s\n", 
        $event['PrimaryObject']['Name'] ?? 'Unknown',
        $event['PrimaryObject']['Pilot'] ?? 'Unknown'
    );
    printf("Parent: %s\n", $event['ParentObject']['Pilot'] ?? 'unknown');
    printf("Secondary: %s\n", $event['SecondaryObject']['Pilot'] ?? 'unknown');
    printf("Evidence count: %d from %d sources\n", count($evidence), count($sources));
    
    // Print evidence details
    foreach ($evidence as $ev) {
        printf("  - source=%s, missionTime=%0.2f\n", 
            $ev['sourceId'] ?? 'unknown',
            $ev['missionTime'] ?? 0.0
        );
    }
    echo "\n";
}
