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
$events = $mission->getEvents();
$missionStart = $mission->getStartTime();

/** @var array<string, array<int, array<string, mixed>>> $byKey */
$byKey = [];

foreach ($events as $index => $event) {
    $primary = $event['PrimaryObject'] ?? null;
    if (!is_array($primary)) {
        continue;
    }
    $keyParts = [];
    foreach (['Pilot', 'Name', 'Group', 'Type'] as $field) {
        if (isset($primary[$field]) && trim((string)$primary[$field]) !== '') {
            $keyParts[] = strtolower(trim((string)$primary[$field]));
        }
    }
    if ($keyParts === []) {
        continue;
    }
    $type = strtolower((string)($event['Action'] ?? 'unknown'));
    $key = $type . '|' . implode('|', $keyParts);
    $byKey[$key][$index] = $event;
}

foreach ($byKey as $key => $group) {
    if (count($group) < 2) {
        continue;
    }

    $sourceCoverage = [];
    foreach ($group as $event) {
        $sources = [];
        foreach ($event['Evidence'] ?? [] as $sample) {
            $sources[] = $sample['sourceId'];
        }
        sort($sources);
        $sourceCoverage[] = $sources;
    }

    // skip if any event already aggregates all sources
    $allSources = array_unique(array_merge(...$sourceCoverage));
    $coverageMatrix = array_map('implode', array_map(static fn (array $list) => $list, $sourceCoverage));
    if (count(array_unique($coverageMatrix)) <= 1) {
        continue;
    }

    echo "==== Potential duplicate for {$key} ====" . PHP_EOL;
    $i = 1;
    foreach ($group as $event) {
        $missionTime = (float)($event['Time'] ?? 0.0);
        $clock = gmdate('H:i:s', (int)round($missionStart + $missionTime));
        $sources = [];
        foreach ($event['Evidence'] ?? [] as $sample) {
            $sources[] = $sample['sourceId'];
        }
        sort($sources);
        printf("  #%d %s t=%.3f [%s]\n", $i++, $clock, $missionTime, implode(', ', $sources));
    }
    echo PHP_EOL;
}
