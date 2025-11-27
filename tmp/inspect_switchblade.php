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

echo "=== Kills credited to Switchblade06 ===\n";
$count = 0;
foreach ($mission->getEvents() as $event) {
    if (($event['Action'] ?? null) !== 'HasBeenDestroyed') continue;
    
    $parentPilot = $event['ParentObject']['Pilot'] ?? null;
    $secondaryPilot = $event['SecondaryObject']['Pilot'] ?? null;
    
    $matches = false;
    $via = null;
    
    if ($parentPilot && stripos($parentPilot, 'Switchblade') !== false) {
        $matches = true;
        $via = 'parent';
    } elseif ($secondaryPilot && stripos($secondaryPilot, 'Switchblade') !== false) {
        $matches = true;
        $via = 'secondary';
    }
    
    if (!$matches) continue;
    
    $count++;
    $evidence = $event['Evidence'] ?? [];
    
    printf("KILL #%d | %s | tgt=%s | evidence=%d | via=%s\n",
        $count,
        $event['PrimaryObject']['Name'] ?? 'Unknown',
        $event['PrimaryObject']['Pilot'] ?? 'Unknown',
        count($evidence),
        $via
    );
    echo "  Parent: " . json_encode($event['ParentObject'] ?? null) . "\n";
    echo "  Secondary: " . json_encode($event['SecondaryObject'] ?? null) . "\n";
    foreach ($evidence as $ev) {
        printf("  Evidence: source=%s\n", $ev['sourceId'] ?? 'unknown');
    }
    echo "\n";
}

echo "=== Total kills: $count ===\n\n";

// Also show Olympus deaths to see who really killed them
echo "=== All Olympus aircraft deaths ===\n";
foreach ($mission->getEvents() as $event) {
    if (($event['Action'] ?? null) !== 'HasBeenDestroyed') continue;
    $tgtPilot = $event['PrimaryObject']['Pilot'] ?? '';
    if (stripos($tgtPilot, 'Olympus') === false) continue;
    
    $evidence = $event['Evidence'] ?? [];
    printf("%s | tgt=%s | killer_p=%s | killer_s=%s | evidence=%d\n",
        $event['PrimaryObject']['Name'] ?? 'Unknown',
        $tgtPilot,
        $event['ParentObject']['Pilot'] ?? 'unknown',
        $event['SecondaryObject']['Pilot'] ?? 'unknown',
        count($evidence)
    );
}
