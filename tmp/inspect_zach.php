<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$config = require $root . '/config.php';

require $root . '/' . $config['core_path'] . '/tacview.php';
require $root . '/src/EventGraph/autoload.php';

$targetPilot = strtolower($argv[1] ?? 'skunk 1-2 | zach');
$targetType = strtolower($argv[2] ?? 'hasfired');

$aggregator = new \EventGraph\EventGraphAggregator($config['default_language'], $config['aggregator']);
foreach (glob($root . '/debriefings/*.xml') as $file) {
    $aggregator->ingestFile($file);
}

$mission = $aggregator->toAggregatedMission();

$matches = [];
foreach ($mission->getEvents() as $event) {
    $type = strtolower((string)($event['Action'] ?? ''));
    if ($type !== $targetType) {
        continue;
    }

    $primary = $event['PrimaryObject'] ?? null;
    if (!is_array($primary)) {
        continue;
    }

    $pilot = strtolower(trim((string)($primary['Pilot'] ?? '')));
    if ($pilot === '') {
        continue;
    }

    if ($pilot !== $targetPilot) {
        continue;
    }

    $matches[] = $event;
}

usort($matches, static fn (array $a, array $b): int => (int)($a['Time'] <=> $b['Time']));

foreach ($matches as $event) {
    $weapon = $event['SecondaryObject'] ?? [];
    $parent = $event['ParentObject'] ?? [];
    $primary = $event['PrimaryObject'] ?? [];

    $describe = static function (array $object): array {
        $fields = ['Pilot', 'Name', 'Group', 'Type', 'ID', 'Parent'];
        $out = [];
        foreach ($fields as $field) {
            if (!isset($object[$field])) {
                continue;
            }
            $value = trim((string)$object[$field]);
            if ($value === '') {
                continue;
            }
            $out[] = $field . '=' . $value;
        }
        return $out;
    };

    printf("time=%8.2f | conf=%5.2f\n", (float)($event['Time'] ?? 0.0), (float)($event['Confidence'] ?? 0.0));
    echo "  Primary: " . implode(', ', $describe($primary)) . "\n";
    echo "  Weapon : " . implode(', ', $describe($weapon)) . "\n";
    echo "  Parent : " . implode(', ', $describe($parent)) . "\n";
    $sources = array_map(static fn (array $sample): string => (string)($sample['sourceId'] ?? 'unknown'), $event['Evidence'] ?? []);
    echo "  Sources: " . implode(', ', $sources) . "\n";
    echo str_repeat('-', 60) . "\n";
}
