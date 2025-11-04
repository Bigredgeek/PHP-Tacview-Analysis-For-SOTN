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

printf("Mission start: %.3f (%s)\n", $missionStart, gmdate('H:i:s', (int)round($missionStart)));

$targetSource = 'Tacview-20251025-232536-DCS-Client';
echo "=== 232536 Menton events 2000-3200 ===\n";
foreach ($events as $event) {
    $type = (string)($event['Action'] ?? '');
    if (stripos($type, 'HasFired') === false && stripos($type, 'HasEntered') === false && stripos($type, 'HasLeft') === false && stripos($type, 'HasBeen') === false) {
        continue;
    }

    $primary = $event['PrimaryObject']['Pilot'] ?? ($event['PrimaryObject']['Name'] ?? '');
    if ($primary === '' || stripos((string)$primary, 'menton 2-1') === false) {
        continue;
    }

    $missionTime = (float)($event['Time'] ?? 0.0);
    if ($missionTime < 2000.0 || $missionTime > 3200.0) {
        continue;
    }

    $evidence = $event['Evidence'] ?? [];
    $hasTarget = false;
    foreach ($evidence as $sample) {
        if ($sample['sourceId'] === $targetSource) {
            $hasTarget = true;
            break;
        }
    }

    if (!$hasTarget) {
        continue;
    }

    $absolute = $missionStart + $missionTime;
    $clock = gmdate('H:i:s', (int)round($absolute));
    $sourceList = [];
    foreach ($evidence as $sample) {
        $sourceList[] = sprintf('%s#%d@%0.3f', $sample['sourceId'], $sample['sourceEventId'], $sample['missionTime']);
    }

    printf(
        "%s | %8.3f | %-16s | evidence=%d [%s]\n",
        $clock,
        $missionTime,
        $type,
        count($evidence),
        implode(', ', $sourceList)
    );
}

$legacySource = 'Tacview-20251025-144445-DCS-Client';
echo "=== 144445 Menton events 2000-5200 ===\n";
foreach ($events as $event) {
    $type = (string)($event['Action'] ?? '');
    if (stripos($type, 'HasFired') === false && stripos($type, 'HasEntered') === false && stripos($type, 'HasLeft') === false && stripos($type, 'HasBeen') === false) {
        continue;
    }

    $primary = $event['PrimaryObject']['Pilot'] ?? ($event['PrimaryObject']['Name'] ?? '');
    if ($primary === '' || stripos((string)$primary, 'menton 2-1') === false) {
        continue;
    }

    $missionTime = (float)($event['Time'] ?? 0.0);
    if ($missionTime < 2000.0 || $missionTime > 5200.0) {
        continue;
    }

    $evidence = $event['Evidence'] ?? [];
    $hasTarget = false;
    foreach ($evidence as $sample) {
        if ($sample['sourceId'] === $legacySource) {
            $hasTarget = true;
            break;
        }
    }

    if (!$hasTarget) {
        continue;
    }

    $absolute = $missionStart + $missionTime;
    $clock = gmdate('H:i:s', (int)round($absolute));
    $sourceList = [];
    foreach ($evidence as $sample) {
        $sourceList[] = sprintf('%s#%d@%0.3f', $sample['sourceId'], $sample['sourceEventId'], $sample['missionTime']);
    }

    printf(
        "%s | %8.3f | %-16s | evidence=%d [%s]\n",
        $clock,
        $missionTime,
        $type,
        count($evidence),
        implode(', ', $sourceList)
    );
}

$pilotNeedle = 'menton 2-1';
$windows = [
    ['label' => 'Menton timeline', 'range' => [0, 50000]],
];

foreach ($windows as $window) {
    [$startRange, $endRange] = $window['range'];
    printf("=== %s %.0f-%.0f ===\n", $window['label'], $startRange, $endRange);

    foreach ($events as $event) {
        $type = (string)($event['Action'] ?? '');
        $primary = $event['PrimaryObject']['Pilot'] ?? ($event['PrimaryObject']['Name'] ?? '');
        if ($primary === '' || stripos((string)$primary, $pilotNeedle) === false) {
            continue;
        }

        $missionTime = (float)($event['Time'] ?? 0.0);
        if ($missionTime < $startRange || $missionTime > $endRange) {
            continue;
        }

        $absolute = $missionStart + $missionTime;
        $clock = gmdate('H:i:s', (int)round($absolute));
        $weapon = $event['SecondaryObject']['Name'] ?? ($event['SecondaryObject']['Type'] ?? '');
        $weaponId = $event['SecondaryObject']['ID'] ?? '';

        $evidence = $event['Evidence'] ?? [];
        $sourceList = [];
        foreach ($evidence as $sample) {
            $sourceList[] = sprintf('%s#%d@%0.3f', $sample['sourceId'], $sample['sourceEventId'], $sample['missionTime']);
        }

        $links = $event['GraphLinks'] ?? [];
        $linkSummary = [];
        foreach ($links as $kind => $targets) {
            $linkSummary[] = $kind . ':' . json_encode($targets);
        }

        printf(
            "%s | %8.3f | %-16s | %-12s | evidence=%d [%s] %s\n",
            $clock,
            $missionTime,
            $type,
            $weapon !== '' ? $weapon : $weaponId,
            count($evidence),
            implode(', ', $sourceList),
            $linkSummary === [] ? '' : 'links=' . implode('; ', $linkSummary)
        );
    }

    echo PHP_EOL;
}
