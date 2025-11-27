<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$config = require $root . '/config.php';
require $root . '/src/core_path.php';
$corePath = tacview_resolve_core_path($config['core_path'] ?? 'core', $root);
require $corePath . '/tacview.php';
require $root . '/src/EventGraph/autoload.php';

chdir($root);

// CLI: php tmp/inspect_saageli.php [pilot_filter]
$pilotFilter = $argv[1] ?? 'Pygmalion';

$aggregator = new EventGraph\EventGraphAggregator(
    $config['default_language'] ?? 'en',
    $config['aggregator'] ?? []
);

foreach (glob($config['debriefings_path']) as $file) {
    $aggregator->ingestFile($file);
}

$mission = $aggregator->toAggregatedMission();

echo "=== Kills by $pilotFilter ===\n";
foreach ($mission->getEvents() as $event) {
    if (($event['Action'] ?? null) !== 'HasBeenDestroyed') {
        continue;
    }

    $parentPilot = $event['ParentObject']['Pilot'] ?? null;
    $secondaryPilot = $event['SecondaryObject']['Pilot'] ?? null;

    $matches = false;
    $sourceTag = null;

    if ($parentPilot !== null && stripos($parentPilot, $pilotFilter) !== false) {
        $matches = true;
        $sourceTag = 'parent';
    } elseif ($secondaryPilot !== null && stripos($secondaryPilot, $pilotFilter) !== false) {
        $matches = true;
        $sourceTag = 'secondary';
    }

    if (!$matches) {
        continue;
    }

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

    printf(
        "%s | %-25s | tgt=%s | t=%.3f | evidence=%d | sources=%d | via=%s\n",
        $event['ID'] ?? 'evt?',
        $primary,
        $primaryPilot,
        $time,
        count($evidence),
        count($sources),
        $sourceTag ?? 'unknown'
    );
}

// Also show all deaths for the targets
echo "\n=== All Rich/Baron deaths ===\n";
foreach ($mission->getEvents() as $event) {
    if (($event['Action'] ?? null) !== 'HasBeenDestroyed') {
        continue;
    }
    $primaryPilot = $event['PrimaryObject']['Pilot'] ?? '';
    if (stripos($primaryPilot, 'Rich') !== false || stripos($primaryPilot, 'Baron') !== false) {
        $killer = $event['ParentObject']['Pilot'] ?? $event['SecondaryObject']['Pilot'] ?? 'unknown';
        $time = $event['Time'] ?? 0.0;
        $evidence = $event['Evidence'] ?? [];
        printf(
            "tgt=%s | killer=%s | t=%.3f | evidence=%d\n",
            $primaryPilot,
            $killer,
            $time,
            count($evidence)
        );
    }
}

// Show all MiG-23 kills to see who really killed Olympus
echo "\n=== MiG-23 deaths ===\n";
foreach ($mission->getEvents() as $event) {
    if (($event['Action'] ?? null) !== 'HasBeenDestroyed') {
        continue;
    }
    $primaryName = $event['PrimaryObject']['Name'] ?? '';
    if (stripos($primaryName, 'MiG-23') !== false) {
        $primaryPilot = $event['PrimaryObject']['Pilot'] ?? 'unknown';
        $killer = $event['ParentObject']['Pilot'] ?? $event['SecondaryObject']['Pilot'] ?? 'unknown';
        $time = $event['Time'] ?? 0.0;
        $evidence = $event['Evidence'] ?? [];
        printf(
            "tgt=%s | killer=%s | t=%.3f | evidence=%d\n",
            $primaryPilot,
            $killer,
            $time,
            count($evidence)
        );
    }
}

// Detail view for Pygmalion's MiG-23 kills
echo "\n=== Detail: Pygmalion MiG-23 kills ===\n";
foreach ($mission->getEvents() as $event) {
    if (($event['Action'] ?? null) !== 'HasBeenDestroyed') {
        continue;
    }
    $parentPilot = $event['ParentObject']['Pilot'] ?? '';
    $secondaryPilot = $event['SecondaryObject']['Pilot'] ?? '';
    $primaryName = $event['PrimaryObject']['Name'] ?? '';
    
    if (stripos($primaryName, 'MiG-23') !== false && 
        (stripos($parentPilot, 'Pygmalion') !== false || stripos($secondaryPilot, 'Pygmalion') !== false)) {
        print_r($event);
    }
}
