<?php

declare(strict_types=1);

require_once __DIR__ . '/../core/tacview.php';

$tv = new tacview('en');
$tv->parseXML(__DIR__ . '/../debriefings/Tacview-20251025-232536-DCS-Client.xml');

$events = array_slice($tv->events, 0, 5, true);
foreach ($events as $id => $event) {
    echo "Event #{$id}\n";
    var_export($event);
    echo "\n\n";
}
