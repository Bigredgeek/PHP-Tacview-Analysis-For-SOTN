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

$legacySource = 'Tacview-20251025-144445-DCS-Client';
$comparisonSources = [];
foreach ($mission->getSources() as $source) {
    $id = $source['id'] ?? ($source['filename'] ?? null);
    if ($id === null || $id === $legacySource) {
        continue;
    }
    $comparisonSources[] = $id;
}

/**
 * @param array<string, mixed>|null $object
 */
function event_object_key(?array $object): ?string
{
    if ($object === null) {
        return null;
    }
    $fields = ['Pilot', 'Name', 'Group', 'Type', 'Coalition', 'Country'];
    $parts = [];
    foreach ($fields as $field) {
        if (!isset($object[$field])) {
            continue;
        }
        $value = strtolower(trim((string)$object[$field]));
        if ($value !== '') {
            $parts[] = $value;
        }
    }
    if ($parts !== []) {
        return implode('|', $parts);
    }
    if (isset($object['ID'])) {
        $id = strtolower(trim((string)$object['ID']));
        return $id !== '' ? $id : null;
    }
    return null;
}

/** @var array<string, list<array{time: float, sources: list<string>}>> $groups */
$groups = [];

foreach ($events as $event) {
    $type = strtolower((string)($event['Action'] ?? 'unknown'));
    $primaryKey = event_object_key($event['PrimaryObject'] ?? null);
    if ($primaryKey === null) {
        continue;
    }
    $key = $type . '|' . $primaryKey;
    $time = (float)($event['Time'] ?? 0.0);
    $sources = [];
    foreach ($event['Evidence'] ?? [] as $sample) {
        $sources[] = $sample['sourceId'];
    }
    $groups[$key][] = ['time' => $time, 'sources' => $sources];
}

$deltas = [];
foreach ($groups as $key => $instances) {
    $legacyTimes = [];
    $otherTimes = [];
    foreach ($instances as $instance) {
        $sources = $instance['sources'];
        $time = $instance['time'];
        if (in_array($legacySource, $sources, true)) {
            $legacyTimes[] = $time;
        }
        $hasComparison = false;
        foreach ($comparisonSources as $candidate) {
            if (in_array($candidate, $sources, true)) {
                $hasComparison = true;
                break;
            }
        }
        if ($hasComparison) {
            $otherTimes[] = $time;
        }
    }
    if ($legacyTimes === [] || $otherTimes === []) {
        continue;
    }
    foreach ($legacyTimes as $legacyTime) {
        $bestDelta = null;
        foreach ($otherTimes as $otherTime) {
            $delta = $otherTime - $legacyTime;
            if ($bestDelta === null || abs($delta) < abs($bestDelta)) {
                $bestDelta = $delta;
            }
        }
        if ($bestDelta !== null) {
            $deltas[] = $bestDelta;
        }
    }
}

sort($deltas);
$count = count($deltas);
if ($count === 0) {
    echo "No overlapping events found.\n";
    exit(0);
}

$median = $deltas[(int)floor($count / 2)];
if ($count % 2 === 0) {
    $median = ($median + $deltas[(int)floor($count / 2) - 1]) / 2;
}

printf("Collected %d deltas. Median offset %.3f seconds.\n", $count, $median);
$start = (int)floor($count * 0.05);
$end = $count - (int)floor($count * 0.05);
$length = max(0, $end - $start);
$trimmed = array_slice($deltas, $start, $length);
if ($trimmed !== [] && $length !== $count) {
    $avg = array_sum($trimmed) / count($trimmed);
    printf("Trimmed mean offset %.3f seconds.\n", $avg);
}

printf("Min delta %.3f, max delta %.3f\n", min($deltas), max($deltas));
