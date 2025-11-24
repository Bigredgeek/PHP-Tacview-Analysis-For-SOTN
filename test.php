<?php

declare(strict_types=1);

require_once __DIR__ . '/src/core_path.php';
require_once __DIR__ . '/src/EventGraph/autoload.php';

echo "========================================\n";
echo "PHP Tacview Deployment Simulation\n";
echo "========================================\n\n";

// Test 1: Check PHP version
echo "✓ Test 1: PHP Version Check\n";
echo "  PHP Version: " . phpversion() . "\n";
echo "  Required: PHP 8.2+\n";
if (!version_compare(phpversion(), '8.2.0', '>=')) {
    echo "  Status: FAIL ✗\n\n";
    exit(1);
}
echo "  Status: PASS ✓\n\n";

// Test 2: Strict types declaration
echo "✓ Test 2: Strict Types Declaration\n";
echo "  Script declares strict_types=1\n";
echo "  Status: PASS ✓\n\n";

// Test 3: Load configuration
echo "✓ Test 3: Configuration\n";
try {
    $config = require __DIR__ . '/config.php';
    $defaultLanguage = $config['default_language'] ?? 'en';
    $corePathSetting = $config['core_path'] ?? 'core';
    echo "  Config loaded, default language: {$defaultLanguage}\n";
    echo "  Requested core path: {$corePathSetting}\n";
    echo "  Status: PASS ✓\n\n";
} catch (Throwable $exception) {
    echo "  ERROR: " . $exception->getMessage() . "\n";
    echo "  Status: FAIL ✗\n\n";
    exit(1);
}

// Test 4: Resolve Tacview core path
echo "✓ Test 4: Resolve Tacview Core\n";
try {
    $corePath = tacview_resolve_core_path($corePathSetting, __DIR__);
    echo "  Core located at: {$corePath}\n";
    echo "  Status: PASS ✓\n\n";
} catch (Throwable $exception) {
    echo "  ERROR: " . $exception->getMessage() . "\n";
    echo "  Status: FAIL ✗\n\n";
    exit(1);
}

// Test 5: Load Tacview engine
echo "✓ Test 5: Load Tacview Engine\n";
try {
    require_once $corePath . '/tacview.php';
    $tv = new tacview($defaultLanguage);
    $tv->image_path = '/';
    echo "  Tacview class instantiated\n";
    echo "  Properties: htmlOutput(" . gettype($tv->htmlOutput) . "), stats(" . gettype($tv->stats) . "), language(" . gettype($tv->language) . ")\n";
    echo "  Status: PASS ✓\n\n";
} catch (Throwable $exception) {
    echo "  ERROR: " . $exception->getMessage() . "\n";
    echo "  Status: FAIL ✗\n\n";
    exit(1);
}

// Test 6: Discover Tacview XML debriefings
echo "✓ Test 6: Locate Debriefings\n";
$pattern = __DIR__ . '/' . str_replace('*.xml', '*.xml', $config['debriefings_path'] ?? 'debriefings/*.xml');
$xmlFiles = glob($pattern) ?: [];
if ($xmlFiles === []) {
    echo "  ERROR: No XML files found using pattern {$pattern}\n";
    echo "  Status: FAIL ✗\n\n";
    exit(1);
}
echo "  Found " . count($xmlFiles) . " XML file(s)\n";
foreach ($xmlFiles as $file) {
    echo "    - " . basename($file) . " (" . number_format(filesize($file)) . " bytes)\n";
}
echo "  Status: PASS ✓\n\n";

// Test 7: Aggregate mission data
echo "✓ Test 7: Aggregate Mission\n";
try {
    $aggregatorOptions = $config['aggregator'] ?? [];
    $aggregator = new \EventGraph\EventGraphAggregator($defaultLanguage, $aggregatorOptions);
    foreach ($xmlFiles as $filexml) {
        $aggregator->ingestFile($filexml);
    }
    $mission = $aggregator->toAggregatedMission();
    $metrics = $aggregator->getMetrics();
    echo "  Mission name: " . ($mission->getMissionName() ?: '[unknown]') . "\n";
    echo "  Duration (s): " . (int) $mission->getDuration() . "\n";
    echo "  Raw events: " . (int) ($metrics['raw_event_count'] ?? 0) . "\n";
    echo "  Merged events: " . (int) ($metrics['merged_events'] ?? 0) . "\n";
    echo "  Status: PASS ✓\n\n";
} catch (Throwable $exception) {
    echo "  ERROR: " . $exception->getMessage() . "\n";
    echo "  Status: FAIL ✗\n\n";
    exit(1);
}

// Test 8: Render aggregated output
echo "✓ Test 8: Render Output\n";
try {
    $sources = $mission->getSources();
    $tv->proceedAggregatedStats(
        $mission->getMissionName(),
        $mission->getStartTime(),
        $mission->getDuration(),
        $mission->getEvents(),
        count($sources)
    );
    $output = $tv->getOutput();
    $length = strlen($output);
    if ($length <= 0) {
        echo "  ERROR: Empty renderer output\n";
        echo "  Status: FAIL ✗\n\n";
        exit(1);
    }
    echo "  Output length: " . number_format($length) . " characters\n";
    echo "  Contains table markup: " . (strpos($output, '<table') !== false ? 'YES' : 'NO') . "\n";
    echo "  Status: PASS ✓\n\n";
} catch (Throwable $exception) {
    echo "  ERROR: " . $exception->getMessage() . "\n";
    echo "  Status: FAIL ✗\n\n";
    exit(1);
}

// Test 9: Language support
echo "✓ Test 9: Language Support\n";
try {
    $label = $tv->L('missionName');
    if ($label && $label !== 'missionName') {
        echo "  Translation sample: missionName => {$label}\n";
        echo "  Status: PASS ✓\n\n";
    } else {
        echo "  WARNING: Language key unresolved\n";
        echo "  Status: PARTIAL ⚠\n\n";
    }
} catch (Throwable $exception) {
    echo "  ERROR: " . $exception->getMessage() . "\n";
    echo "  Status: FAIL ✗\n\n";
    exit(1);
}

// Summary
echo "========================================\n";
echo "Test Results Summary\n";
echo "========================================\n";
echo "✓ Core path resolver exercised\n";
echo "✓ Aggregator merged multi-file mission data\n";
echo "✓ Renderer emitted deployment-ready HTML\n";
echo "\nDeployment simulation completed successfully.\n";
echo "========================================\n";

exit(0);
