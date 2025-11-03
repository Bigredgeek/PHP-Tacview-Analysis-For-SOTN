<?php

declare(strict_types=1);

$root = dirname(__DIR__);

$pattern = $argv[1] ?? 'HasBeenDestroyed';
$patternLower = strtolower($pattern);

$files = glob($root . '/debriefings/*.xml');
if ($files === false) {
    fwrite(STDERR, "No debriefing files found\n");
    exit(1);
}

foreach ($files as $file) {
    $xml = new SimpleXMLElement((string)file_get_contents($file));
    if (!isset($xml->Events->Event)) {
        continue;
    }

    foreach ($xml->Events->Event as $event) {
        $action = (string)$event->Action;
        if ($patternLower !== 'all' && strtolower($action) !== $patternLower) {
            continue;
        }

        $time = (float)$event->Time;
        $primary = $event->PrimaryObject ?? null;
        $secondary = $event->SecondaryObject ?? null;
        $parent = $event->ParentObject ?? null;

        $fields = [
            'time' => $time,
            'action' => $action,
            'primaryType' => $primary?->Type ?? '',
            'primaryCoalition' => $primary?->Coalition ?? '',
            'primaryPilot' => $primary?->Pilot ?? '',
            'secondaryType' => $secondary?->Type ?? '',
            'secondaryCoalition' => $secondary?->Coalition ?? '',
            'secondaryPilot' => $secondary?->Pilot ?? '',
            'parentType' => $parent?->Type ?? '',
            'parentCoalition' => $parent?->Coalition ?? '',
            'parentPilot' => $parent?->Pilot ?? '',
        ];

        echo basename($file) . '|' . implode('|', array_map(static fn ($value) => str_replace('\n', ' ', trim((string)$value)), $fields)) . PHP_EOL;
    }
}
