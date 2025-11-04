<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$config = require $root . '/config.php';

require $root . '/' . $config['core_path'] . '/tacview.php';
require $root . '/src/EventGraph/autoload.php';

use EventGraph\SourceRecording;
use EventGraph\NormalizedEvent;

$baselinePath = $root . '/debriefings/Tacview-20251025-230708-DCS-Client.xml';
$targetPath = $root . '/debriefings/Tacview-20251025-144445-DCS-Client.xml';

$baseline = new SourceRecording($baselinePath, $config['default_language']);
$target = new SourceRecording($targetPath, $config['default_language']);

echo 'Baseline: ' . $baseline->id . ' events=' . count($baseline->events) . PHP_EOL;
echo 'Target: ' . $target->id . ' events=' . count($target->events) . PHP_EOL;

/**
 * @param array<string, mixed>|null $object
 */
function object_key(?array $object): ?string
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

/**
 * @return list<string>
 */
function anchor_keys(NormalizedEvent $event): array
{
    $primary = object_key($event->getPrimary());
    if ($primary === null) {
        return [];
    }
    $keys = [];
    $secondary = object_key($event->getSecondary());
    if ($secondary !== null) {
        $keys[] = $event->getType() . '|' . $primary . '|' . $secondary;
    }
    $keys[] = $event->getType() . '|' . $primary;
    return $keys;
}

$baselineIndex = [];
foreach ($baseline->events as $event) {
    foreach (anchor_keys($event) as $key) {
        $baselineIndex[$key][] = $event->getMissionTime();
    }
}

$deltas = [];
foreach ($target->events as $event) {
    $keys = anchor_keys($event);
    if ($keys === []) {
        continue;
    }
    foreach ($keys as $key) {
        if (!isset($baselineIndex[$key])) {
            continue;
        }
        $targetTime = $event->getMissionTime();
        $bestDelta = null;
        foreach ($baselineIndex[$key] as $baseTime) {
            $delta = $targetTime - $baseTime;
            if ($bestDelta === null || abs($delta) < abs($bestDelta)) {
                $bestDelta = $delta;
            }
        }
        if ($bestDelta !== null) {
            $deltas[] = $bestDelta;
        }
        break;
    }
}

if ($deltas === []) {
    echo "No shared anchor keys found." . PHP_EOL;
    exit(0);
}

sort($deltas);
$count = count($deltas);
$median = $deltas[(int)floor($count / 2)];
if ($count % 2 === 0) {
    $median = ($median + $deltas[(int)floor($count / 2) - 1]) / 2;
}

printf("Collected %d deltas. Median %.2f, min %.2f, max %.2f\n", $count, $median, $deltas[0], $deltas[$count - 1]);

$histogram = [];
foreach ($deltas as $delta) {
    $bucket = (int)round($delta / 10.0) * 10;
    $histogram[$bucket] = ($histogram[$bucket] ?? 0) + 1;
}
ksort($histogram);
foreach ($histogram as $bucket => $freq) {
    printf("%6d => %d\n", $bucket, $freq);
}

echo "\nSample deltas:\n";
foreach (array_slice($deltas, max(0, $count - 20)) as $delta) {
    printf("%.2f\n", $delta);
}
