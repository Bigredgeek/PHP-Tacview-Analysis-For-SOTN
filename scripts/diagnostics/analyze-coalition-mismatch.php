#!/usr/bin/env php
<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);

$pattern = $argv[1] ?? 'HasBeenDestroyed';
$patternLower = strtolower($pattern);

$files = glob($projectRoot . '/debriefings/*.xml');
if ($files === false) {
    fwrite(STDERR, "No debriefing files found\n");
    exit(1);
}

foreach ($files as $file) {
    $raw = file_get_contents($file);
    if ($raw === false) {
        fwrite(STDERR, "Failed to read " . basename($file) . "\n");
        continue;
    }

    $xml = new SimpleXMLElement($raw);
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

        echo basename($file) . '|' . implode('|', array_map(static fn ($value) => str_replace(["\r", "\n"], ' ', trim((string)$value)), $fields)) . PHP_EOL;
    }
}
