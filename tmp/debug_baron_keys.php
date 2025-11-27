<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$config = require $root . '/config.php';
require $root . '/src/core_path.php';
$corePath = tacview_resolve_core_path($config['core_path'] ?? 'core', $root);
require $corePath . '/tacview.php';
require $root . '/src/EventGraph/autoload.php';

chdir($root);

// Test the ObjectIdentity key generation for Baron's different formats
$baronFormats = [
    ['Pilot' => '♦] Baron', 'Type' => 'Aircraft', 'Name' => 'F-15C Eagle'],
    ['Pilot' => 'Gorilla 22 | [♦] Baron', 'Type' => 'Aircraft', 'Name' => 'F-15C Eagle'],
    ['Pilot' => '[♦] Baron', 'Type' => 'Aircraft', 'Name' => 'F-15C Eagle'],
];

echo "=== ObjectIdentity keys for Baron name formats ===\n\n";
foreach ($baronFormats as $obj) {
    $identity = EventGraph\ObjectIdentity::forObject($obj);
    printf("Pilot: %-30s => Key: %s\n", $obj['Pilot'], $identity?->getKey() ?? 'null');
}

echo "\n=== Looking for ALL Baron destruction events (raw, pre-merge) ===\n\n";

$aggregator = new EventGraph\EventGraphAggregator(
    $config['default_language'] ?? 'en',
    $config['aggregator'] ?? []
);

// Load files but don't build yet
foreach (glob($config['debriefings_path']) as $file) {
    $aggregator->ingestFile($file);
}

// Build the aggregation
$mission = $aggregator->toAggregatedMission();

// Now look for all Baron-related events
echo "=== All events mentioning Baron ===\n";
foreach ($mission->getEvents() as $event) {
    $primaryPilot = $event['PrimaryObject']['Pilot'] ?? '';
    $parentPilot = $event['ParentObject']['Pilot'] ?? '';
    $secondaryPilot = $event['SecondaryObject']['Pilot'] ?? '';
    
    $action = $event['Action'] ?? '';
    
    if (stripos($primaryPilot, 'Baron') !== false || 
        stripos($parentPilot, 'Baron') !== false || 
        stripos($secondaryPilot, 'Baron') !== false) {
        
        if ($action === 'HasBeenDestroyed') {
            $evidence = $event['Evidence'] ?? [];
            printf("ACTION: %s\n", $action);
            printf("  Primary: %s | %s\n", $event['PrimaryObject']['Name'] ?? 'Unknown', $primaryPilot);
            printf("  Parent: %s\n", $parentPilot ?: 'unknown');
            printf("  Secondary: %s\n", $secondaryPilot ?: 'unknown');
            printf("  Evidence: %d entries\n", count($evidence));
            printf("  Time: %0.2f\n", $event['Time'] ?? 0);
            echo "\n";
        }
    }
}
