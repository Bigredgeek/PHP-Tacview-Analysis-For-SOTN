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

$groups = [];
foreach ($mission->getEvents() as $event) {
    $type = strtolower((string)($event['Action'] ?? ''));
    $primaryKey = object_key($event['PrimaryObject'] ?? null);
    if ($primaryKey === null) {
        continue;
    }
    $groups[$type . '|' . $primaryKey][] = $event;
}

foreach ($groups as $key => $events) {
    if (count($events) <= 1) {
        continue;
    }
    usort($events, static function (array $a, array $b): int {
        return ($a['Time'] ?? 0.0) <=> ($b['Time'] ?? 0.0);
    });
    $times = array_map(static fn (array $event): float => (float)($event['Time'] ?? 0.0), $events);
    $minGap = null;
    for ($i = 1; $i < count($times); $i++) {
        $gap = $times[$i] - $times[$i - 1];
        $minGap = $minGap === null ? $gap : min($minGap, $gap);
    }
    if ($minGap !== null && $minGap <= 60.0) {
        echo $key . "\n";
        foreach ($events as $event) {
            $sources = array_map(static fn (array $sample): string => (string)($sample['sourceId'] ?? 'unknown'), $event['Evidence'] ?? []);
            printf(
                "  time=%8.2f | conf=%5.2f | sources=%s\n",
                (float)($event['Time'] ?? 0.0),
                isset($event['Confidence']) ? (float)$event['Confidence'] : -1.0,
                implode(',', $sources)
            );
        }
    }
}
