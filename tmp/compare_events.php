<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$config = require $root . '/config.php';

require $root . '/' . $config['core_path'] . '/tacview.php';
require $root . '/src/EventGraph/autoload.php';

use EventGraph\SourceRecording;

$files = [
    'Tacview-20251025-144445-DCS-Client.xml',
    'Tacview-20251025-230708-DCS-Client.xml',
    'Tacview-20251025-232536-DCS-Client.xml',
];

$records = [];
foreach ($files as $file) {
    $path = $root . '/debriefings/' . $file;
    $record = new SourceRecording($path, $config['default_language']);
    $records[$record->id] = $record;
}

$pilot = strtolower('Menton 2-1 | Castor');
$action = 'HasBeenDestroyed';

foreach ($records as $id => $record) {
    printf("%s start=%.2f duration=%.2f events=%d\n", $id, $record->startTime ?? -1, $record->duration ?? -1, count($record->events));
    foreach ($record->events as $event) {
        if (strtolower($event->getType()) !== strtolower($action)) {
            continue;
        }
        $primary = $event->getPrimary();
        if ($primary === null || !isset($primary['Pilot'])) {
            continue;
        }
        if (strtolower((string)$primary['Pilot']) !== $pilot) {
            continue;
        }
        $secondary = $event->getSecondary();
        $parent = $event->getParent();
        $idField = $primary['ID'] ?? 'none';
        printf(
            "  time=%8.2f | mission=%.2f | id=%s | secondary=%s | parent=%s | evidence=%d\n",
            $event->getMissionTime(),
            $record->startTime !== null ? $record->startTime + $event->getMissionTime() : $event->getMissionTime(),
            $idField,
            $secondary['Name'] ?? 'none',
            $parent['Pilot'] ?? 'none',
            count($event->getEvidence())
        );
    }
}
