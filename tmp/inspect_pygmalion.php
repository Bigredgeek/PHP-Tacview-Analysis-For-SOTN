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

echo "=== Pygmalion HasBeenDestroyed events (kills credited) ===\n\n";

$killCount = 0;
foreach ($mission->getEvents() as $event) {
    if (($event['Action'] ?? null) !== 'HasBeenDestroyed') {
        continue;
    }

    $parentPilot = $event['ParentObject']['Pilot'] ?? null;
    $secondaryPilot = $event['SecondaryObject']['Pilot'] ?? null;

    $matches = false;
    $sourceTag = null;

    if ($parentPilot !== null && stripos($parentPilot, 'Pygmalion') !== false) {
        $matches = true;
        $sourceTag = 'parent';
    } elseif ($secondaryPilot !== null && stripos($secondaryPilot, 'Pygmalion') !== false) {
        $matches = true;
        $sourceTag = 'secondary';
    }

    if (!$matches) {
        continue;
    }

    $killCount++;

    $primary = $event['PrimaryObject']['Name'] ?? 'Unknown';
    $primaryPilot = $event['PrimaryObject']['Pilot'] ?? 'Unknown';
    $evidence = $event['Evidence'] ?? [];
    $sources = [];
    foreach ($evidence as $entry) {
        if (!isset($entry['sourceId'])) {
            continue;
        }
        $sources[$entry['sourceId']] = true;
    }

    $time = $event['Time'] ?? 0.0;
    $hours = (int)floor($time / 3600);
    $mins = (int)floor(fmod($time, 3600) / 60);
    $secs = (int)floor(fmod($time, 60));
    $timeFormatted = sprintf("%02d:%02d:%02d", $hours, $mins, $secs);

    printf(
        "KILL #%d | %s | %-25s | tgt=%s | t=%0.3f | evidence=%d | sources=%d | via=%s\n",
        $killCount,
        $timeFormatted,
        $primary,
        $primaryPilot,
        $time,
        count($evidence),
        count($sources),
        $sourceTag ?? 'unknown'
    );

    // Print event details for analysis
    echo "   Target identity: " . json_encode($event['PrimaryObject'] ?? []) . "\n";
    echo "   Attacker (parent): " . json_encode($event['ParentObject'] ?? null) . "\n";
    echo "   Attacker (secondary): " . json_encode($event['SecondaryObject'] ?? null) . "\n";

    // Print evidence details
    foreach ($evidence as $ev) {
        printf("   Evidence: source=%s, missionTime=%0.2f\n", 
            $ev['sourceId'] ?? 'unknown', 
            $ev['missionTime'] ?? 0.0
        );
    }
    echo "\n";
}

echo "=== Total kills credited to Pygmalion: $killCount ===\n";
