<?php
declare(strict_types=1);

// Scans debriefings XML files and lists unique Aircraft names found in Events/PrimaryObject[Type=Aircraft]/Name

$roots = [__DIR__ . '/../debriefings', __DIR__ . '/../public/debriefings'];
$files = [];
foreach ($roots as $root) {
    if (!is_dir($root)) continue;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (strtolower($file->getExtension()) === 'xml') {
            $files[] = $file->getPathname();
        }
    }
}

$aircraft = [];
foreach ($files as $xmlPath) {
    $xml = @simplexml_load_file($xmlPath);
    if (!$xml) continue;
    if (!isset($xml->Events)) continue;
    foreach ($xml->Events->Event as $event) {
        if (isset($event->PrimaryObject) && isset($event->PrimaryObject->Type) && (string)$event->PrimaryObject->Type === 'Aircraft') {
            $name = trim((string)$event->PrimaryObject->Name);
            if ($name !== '') {
                $aircraft[$name] = ($aircraft[$name] ?? 0) + 1;
            }
        }
        if (isset($event->SecondaryObject) && isset($event->SecondaryObject->Type) && (string)$event->SecondaryObject->Type === 'Aircraft') {
            $name = trim((string)$event->SecondaryObject->Name);
            if ($name !== '') {
                $aircraft[$name] = ($aircraft[$name] ?? 0) + 1;
            }
        }
    }
}

ksort($aircraft, SORT_NATURAL | SORT_FLAG_CASE);

echo "Found " . count($aircraft) . " unique aircraft across " . count($files) . " XML files\n\n";
foreach ($aircraft as $name => $count) {
    $fileName = str_replace([' ', '/'], ['_', '_'], $name) . '.jpg';
    $exists = file_exists(__DIR__ . '/../objectIcons/' . $fileName) ? 'local:yes' : 'local:no';
    echo $name . "\t" . $fileName . "\t" . $exists . "\tcount:" . $count . "\n";
}
