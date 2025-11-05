#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Pre-Process Debriefings Script
 * 
 * This script processes all Tacview XML files at build time using the EventGraphAggregator,
 * generating static HTML output that can be served directly without runtime processing.
 * 
 * This dramatically improves performance for mobile and weaker PCs by:
 * - Eliminating ~1.3s of server-side XML parsing and aggregation per page load
 * - Reducing server CPU load
 * - Enabling better caching strategies
 * 
 * Usage:
 *   php scripts/preprocess-debriefings.php
 * 
 * Output:
 *   - public/debriefings/aggregated.html (pre-processed debriefing HTML)
 *   - public/debriefings/aggregated.json (metadata for cache invalidation)
 */

// Load configuration
$config = require_once __DIR__ . "/../config.php";

require_once __DIR__ . '/../src/core_path.php';
$corePath = tacview_resolve_core_path($config['core_path'] ?? 'core', dirname(__DIR__));

// Load core tacview library
require_once $corePath . "/tacview.php";
require_once __DIR__ . "/../src/EventGraph/autoload.php";

use EventGraph\EventGraphAggregator;

echo "=== Tacview Debriefing Pre-Processor ===\n\n";

// Create output directory if it doesn't exist
$outputDir = __DIR__ . "/../public/debriefings";
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
    echo "Created output directory: $outputDir\n";
}

// Find all XML files
$debriefingsBase = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('*.xml', '', $config['debriefings_path']);
$debriefingsPath = rtrim($debriefingsBase, DIRECTORY_SEPARATOR . '/') . DIRECTORY_SEPARATOR . "*.xml";
$xmlFiles = glob($debriefingsPath) ?: [];

echo "Found " . count($xmlFiles) . " XML file(s) to process:\n";
foreach ($xmlFiles as $file) {
    echo "  - " . basename($file) . " (" . round(filesize($file) / 1024, 2) . " KB)\n";
}
echo "\n";

if ($xmlFiles === []) {
    echo "ERROR: No XML files found in $debriefingsBase\n";
    echo "Please ensure Tacview XML exports are placed in the debriefings/ directory.\n";
    exit(1);
}

// Start processing
$startTime = microtime(true);
echo "Processing...\n";

// Initialize aggregator
$aggregatorOptions = $config['aggregator'] ?? [];
$aggregator = new EventGraphAggregator($config['default_language'], $aggregatorOptions);

// Ingest all files
foreach ($xmlFiles as $filexml) {
    echo "  Ingesting " . basename($filexml) . "...\n";
    try {
        $aggregator->ingestFile($filexml);
    } catch (\Throwable $exception) {
        echo "  ERROR: Failed to ingest " . basename($filexml) . ": " . $exception->getMessage() . "\n";
        exit(1);
    }
}

// Generate aggregated mission data
echo "  Building aggregated mission...\n";
$mission = $aggregator->toAggregatedMission();

// Initialize tacview renderer
$tv = new tacview($config['default_language']);
$tv->image_path = '/'; // Use root-relative paths for icons

// Process aggregated statistics
echo "  Generating HTML output...\n";
$tv->proceedAggregatedStats(
    $mission->getMissionName(),
    $mission->getStartTime(),
    $mission->getDuration(),
    $mission->getEvents()
);

// Get the rendered output
$htmlBody = $tv->getOutput();

// Generate metadata
$metrics = $aggregator->getMetrics();
$sources = $mission->getSources();

$metadata = [
    'generated_at' => date('c'),
    'processing_time_seconds' => round(microtime(true) - $startTime, 3),
    'mission_name' => $mission->getMissionName(),
    'mission_start' => $mission->getStartTime(),
    'mission_duration' => $mission->getDuration(),
    'source_files' => array_map(function($file) {
        return [
            'filename' => basename($file),
            'size' => filesize($file),
            'modified' => date('c', filemtime($file)),
            'hash' => md5_file($file),
        ];
    }, $xmlFiles),
    'metrics' => $metrics,
    'sources' => $sources,
    'html_size_bytes' => strlen($htmlBody),
];

// Save HTML output
$htmlOutputPath = $outputDir . "/aggregated.html";
file_put_contents($htmlOutputPath, $htmlBody);
echo "\nSaved HTML to: $htmlOutputPath (" . round(strlen($htmlBody) / 1024, 2) . " KB)\n";

// Save metadata as JSON
$jsonOutputPath = $outputDir . "/aggregated.json";
file_put_contents($jsonOutputPath, json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo "Saved metadata to: $jsonOutputPath\n";

// Display summary
$processingTime = round(microtime(true) - $startTime, 3);
echo "\n=== Processing Complete ===\n";
echo "Total time: {$processingTime}s\n";
echo "Events processed: " . ($metrics['merged_events'] ?? 0) . "\n";
echo "Sources: " . count($sources) . "\n";
echo "Output size: " . round(strlen($htmlBody) / 1024 / 1024, 2) . " MB\n";
echo "\nTo use this pre-processed data, update debriefing.php to load from:\n";
echo "  $htmlOutputPath\n";
echo "\n";

exit(0);
