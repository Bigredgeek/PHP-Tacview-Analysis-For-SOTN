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

echo "=== All HasBeenDestroyed events where Pygmalion is attacker ===\n\n";

foreach ($mission->getEvents() as $event) {
    if (($event['Action'] ?? null) !== 'HasBeenDestroyed') {
        continue;
    }

    $parentPilot = $event['ParentObject']['Pilot'] ?? '';
    $secondaryPilot = $event['SecondaryObject']['Pilot'] ?? '';
    
    if (stripos($parentPilot, 'Pygmalion') === false && stripos($secondaryPilot, 'Pygmalion') === false) {
        continue;
    }

    $evidence = $event['Evidence'] ?? [];
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
    printf("Parent: %s\n", $parentPilot ?: 'unknown');
    printf("Secondary: %s\n", $secondaryPilot ?: 'unknown');
    printf("Evidence count: %d\n", count($evidence));
    echo "\n";
}

echo "\n=== Looking for raw source file events with Baron + Pygmalion ===\n\n";

// Directly parse the source file to see the raw event
$sourceFile = $root . '/debriefings/Tacview-20251122-190503-DCS-Client-SOTN_GT6_rc4.xml';
if (file_exists($sourceFile)) {
    $xml = simplexml_load_file($sourceFile);
    if ($xml !== false) {
        foreach ($xml->Event as $event) {
            $primary = (string)($event->PrimaryObject->Pilot ?? '');
            $secondary = (string)($event->SecondaryObject->Pilot ?? '');
            $parent = (string)($event->ParentObject->Pilot ?? '');
            $action = (string)($event->Action ?? '');
            
            if (stripos($primary, 'Baron') !== false && 
                (stripos($secondary, 'Pygmalion') !== false || stripos($parent, 'Pygmalion') !== false)) {
                echo "RAW EVENT FOUND:\n";
                printf("  Time: %s\n", (string)$event->Time);
                printf("  Action: %s\n", $action);
                printf("  Primary: %s\n", $primary);
                printf("  Parent: %s\n", $parent);
                printf("  Secondary: %s\n", $secondary);
                echo "\n";
            }
        }
    }
}
