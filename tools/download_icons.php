<?php
declare(strict_types=1);

// Reads data/aircraft_icons_manifest.json and downloads any entries with fileUrl set
// Saves to objectIcons/<targetFilename>

$root = dirname(__DIR__);
$manifestPath = $root . '/data/aircraft_icons_manifest.json';
$destDir = $root . '/objectIcons';
if (!file_exists($manifestPath)) {
    fwrite(STDERR, "Manifest not found: $manifestPath\n");
    exit(1);
}
$manifest = json_decode(file_get_contents($manifestPath), true);
if (!is_array($manifest) || !isset($manifest['aircraft'])) {
    fwrite(STDERR, "Invalid manifest format\n");
    exit(1);
}
if (!is_dir($destDir)) {
    mkdir($destDir, 0775, true);
}

$downloaded = 0;
$skipped = 0;
foreach ($manifest['aircraft'] as $entry) {
    $name = $entry['name'] ?? '';
    $target = $entry['targetFilename'] ?? '';
    $url = $entry['fileUrl'] ?? null;
    if (!$url || !$target) {
        $skipped++;
        continue;
    }
    $targetPath = $destDir . '/' . $target;
    echo "Downloading $name -> $target ...\n";
    $ctx = stream_context_create([ 'http' => ['timeout' => 20, 'user_agent' => 'php-tacview/1.0'], 'https' => ['timeout' => 20, 'user_agent' => 'php-tacview/1.0'] ]);
    $data = @file_get_contents($url, false, $ctx);
    if ($data === false) {
        echo "  FAILED: $url\n";
        continue;
    }
    file_put_contents($targetPath, $data);
    $downloaded++;
}

echo "Done. Downloaded: $downloaded, Skipped: $skipped\n";
