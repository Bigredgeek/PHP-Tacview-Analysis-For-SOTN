<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$config = require $root . '/config.php';

require $root . '/' . $config['core_path'] . '/tacview.php';
require $root . '/src/EventGraph/autoload.php';

$targetParent = strtolower($argv[1] ?? 'skunk 1-2 | zach');
$targetType = strtolower($argv[2] ?? 'hasbeendestroyed');

$aggregator = new \EventGraph\EventGraphAggregator($config['default_language'], $config['aggregator']);
foreach (glob($root . '/debriefings/*.xml') as $file) {
    $aggregator->ingestFile($file);
}

$mission = $aggregator->toAggregatedMission();
$matches = [];
foreach ($mission->getEvents() as $event) {
    $type = strtolower((string)($event['Action'] ?? ''));
    if ($targetType !== '' && $type !== $targetType) {
        continue;
    }

    $parent = $event['ParentObject'] ?? null;
    if (!is_array($parent)) {
        continue;
    }

    $pilot = strtolower(trim((string)($parent['Pilot'] ?? '')));
    if ($pilot === '') {
        continue;
    }

    if ($pilot !== $targetParent) {
        continue;
    }

    $matches[] = $event;
}

usort($matches, static fn (array $a, array $b): int => (int)($a['Time'] <=> $b['Time']));

foreach ($matches as $index => $event) {
    $primary = $event['PrimaryObject'] ?? [];
    $weapon = $event['SecondaryObject'] ?? [];
    $time = (float)($event['Time'] ?? 0.0);
    $confidence = isset($event['Confidence']) ? (float)$event['Confidence'] : -1.0;

    $targetName = trim((string)($primary['Name'] ?? $primary['Type'] ?? 'unknown'));
    $weaponName = trim((string)($weapon['Name'] ?? $weapon['Type'] ?? 'unknown'));

    $sources = array_map(
        static fn (array $sample): string => (string)($sample['sourceId'] ?? 'unknown'),
        $event['Evidence'] ?? []
    );

    printf(
        "%02d time=%8.2f | conf=%5.2f | target=%s | weapon=%s | sources=%s\n",
        $index + 1,
        $time,
        $confidence,
        $targetName,
        $weaponName,
        implode(',', $sources)
    );
}

printf("Total: %d\n", count($matches));
