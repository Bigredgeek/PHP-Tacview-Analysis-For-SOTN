<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$config = require $root . '/config.php';
require $root . '/src/core_path.php';
$corePath = tacview_resolve_core_path($config['core_path'] ?? 'core', $root);
require $corePath . '/tacview.php';
require $root . '/src/EventGraph/autoload.php';

$needle = $argv[1] ?? null;
if ($needle === null || $needle === '') {
    fwrite(STDERR, "Usage: php tmp/inspect_pilot_events.php <pilot-substring>\n");
    exit(1);
}

$needleLower = strtolower($needle);

chdir($root);

$aggregator = new EventGraph\EventGraphAggregator(
    $config['default_language'] ?? 'en',
    $config['aggregator'] ?? []
);

foreach (glob($config['debriefings_path']) as $file) {
    $aggregator->ingestFile($file);
}

$mission = $aggregator->toAggregatedMission();

printf("=== Destruction events involving '%s' ===\n\n", $needle);

foreach ($mission->getEvents() as $event) {
    if (($event['Action'] ?? null) !== 'HasBeenDestroyed') {
        continue;
    }

    $primaryPilot = strtolower($event['PrimaryObject']['Pilot'] ?? '');
    $secondaryPilot = strtolower($event['SecondaryObject']['Pilot'] ?? '');
    $parentPilot = strtolower($event['ParentObject']['Pilot'] ?? '');

    if (
        !str_contains($primaryPilot, $needleLower) &&
        !str_contains($secondaryPilot, $needleLower) &&
        !str_contains($parentPilot, $needleLower)
    ) {
        continue;
    }

    $time = $event['Time'] ?? 0.0;
    $hours = (int)floor($time / 3600);
    $mins = (int)floor(fmod($time, 3600) / 60);
    $secs = (int)floor(fmod($time, 60));
    $timeFormatted = sprintf('%02d:%02d:%02d', $hours, $mins, $secs);

    $evidence = $event['Evidence'] ?? [];
    $sources = [];
    foreach ($evidence as $ev) {
        $sources[$ev['sourceId'] ?? 'unknown'] = true;
    }

    echo "Time: {$timeFormatted} (t=" . number_format((float)$time, 3) . ")\n";
    printf(
        "Target: %s | %s\n",
        $event['PrimaryObject']['Name'] ?? 'Unknown',
        $event['PrimaryObject']['Pilot'] ?? 'Unknown'
    );
    printf(
        "Parent: %s | %s\n",
        $event['ParentObject']['Name'] ?? 'unknown',
        $event['ParentObject']['Pilot'] ?? 'unknown'
    );
    printf(
        "Secondary: %s | %s\n",
        $event['SecondaryObject']['Name'] ?? 'unknown',
        $event['SecondaryObject']['Pilot'] ?? 'unknown'
    );
    printf("Evidence count: %d from %d sources\n", count($evidence), count($sources));
    echo "Sources:" . PHP_EOL;
    foreach ($evidence as $ev) {
        printf(
            "  - source=%s, missionTime=%0.2f\n",
            $ev['sourceId'] ?? 'unknown',
            $ev['missionTime'] ?? 0.0
        );
    }
    echo str_repeat('-', 60) . PHP_EOL;
}
