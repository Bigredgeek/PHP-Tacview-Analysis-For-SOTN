#!/usr/bin/env php
<?php

declare(strict_types=1);

use EventGraph\EventGraphAggregator;
use EventGraph\ObjectIdentity;

$projectRoot = dirname(__DIR__);
$config = require $projectRoot . '/config.php';

require_once $projectRoot . '/src/core_path.php';
$corePath = tacview_resolve_core_path($config['core_path'] ?? 'core', $projectRoot);
require_once $corePath . '/tacview.php';
require_once $projectRoot . '/src/EventGraph/autoload.php';

$options = parseArguments($argv);
$sets = regressionSets();
if ($options['set'] !== null) {
    $sets = array_values(array_filter($sets, static fn (array $set): bool => $set['name'] === $options['set']));
    if ($sets === []) {
        fwrite(STDERR, "[error] Unknown regression set '{$options['set']}'.\n");
        exit(1);
    }
}

if ($sets === []) {
    fwrite(STDERR, "[error] No regression sets defined.\n");
    exit(1);
}

$timestamp = date('Ymd-His');
$outputDir = $options['output'] ?? ($projectRoot . '/tmp/regressions/' . $timestamp);
if (!is_dir($outputDir) && !mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
    fwrite(STDERR, "[error] Unable to create output directory: {$outputDir}\n");
    exit(1);
}

$results = [
    'timestamp' => $timestamp,
    'phpunit' => null,
    'sets' => [],
];

if (!$options['skipTests']) {
    $results['phpunit'] = runCommand('php vendor/bin/phpunit --testsuite event-graph', $projectRoot);
}

echo "EventGraph Regression Harness\n";
echo str_repeat('=', 32) . "\n";
if ($results['phpunit'] !== null) {
    echo formatCommandResult('PHPUnit', $results['phpunit']);
    echo "\n";
}

foreach ($sets as $set) {
    $files = resolveFiles($set['glob'], $projectRoot);
    if ($files === []) {
        $results['sets'][] = [
            'name' => $set['name'],
            'label' => $set['label'],
            'status' => 'skipped',
            'reason' => 'no files matched glob',
        ];
        echo "[warn] {$set['name']} - no files matched {$set['glob']}\n";
        continue;
    }

    $summary = runAggregator($files, $config, $set['name']);
    $summary['name'] = $set['name'];
    $summary['label'] = $set['label'];
    $summary['files'] = array_map('basename', $files);
    $summary['glob'] = $set['glob'];
    $summary['status'] = 'ok';

    $results['sets'][] = $summary;

    $logPath = $outputDir . '/' . $set['name'] . '.json';
    file_put_contents($logPath, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    echo formatSetSummary($summary) . "\n";
}

file_put_contents($outputDir . '/summary.json', json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo "Logs written to {$outputDir}" . PHP_EOL;

function regressionSets(): array
{
    return [
        [
            'name' => 'gt6_rc4',
            'label' => 'SOTN GT6 rc4 sanitized bundle',
            'glob' => 'debriefings/*SOTN_GT6_rc4.xml',
        ],
        [
            'name' => 'franz_strike',
            'label' => 'Franz STRIKE3002 archived Tacview',
            'glob' => 'debriefings/Archived logs/*FRANZ*.xml',
        ],
        [
            'name' => 'nov8_evening',
            'label' => 'Tacview 2025-11-08 evening stack',
            'glob' => 'debriefings/Archived logs/Tacview-20251108-21*.xml',
        ],
    ];
}

function parseArguments(array $argv): array
{
    $options = [
        'set' => null,
        'output' => null,
        'skipTests' => false,
    ];

    foreach ($argv as $index => $arg) {
        if ($index === 0) {
            continue;
        }

        if ($arg === '--skip-tests') {
            $options['skipTests'] = true;
            continue;
        }

        if ($arg === '--set' && isset($argv[$index + 1])) {
            $options['set'] = $argv[$index + 1];
            continue;
        }

        if (str_starts_with($arg, '--set=')) {
            $options['set'] = substr($arg, 6);
            continue;
        }

        if ($arg === '--output' && isset($argv[$index + 1])) {
            $options['output'] = $argv[$index + 1];
            continue;
        }

        if (str_starts_with($arg, '--output=')) {
            $options['output'] = substr($arg, 9);
            continue;
        }
    }

    return $options;
}

function runCommand(string $command, string $cwd): array
{
    $descriptorSpec = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($command, $descriptorSpec, $pipes, $cwd);
    if ($process === false) {
        return [
            'command' => $command,
            'exitCode' => -1,
            'stdout' => '',
            'stderr' => 'failed to start process',
        ];
    }

    $stdout = stream_get_contents($pipes[1]) ?: '';
    $stderr = stream_get_contents($pipes[2]) ?: '';

    foreach ($pipes as $pipe) {
        fclose($pipe);
    }

    $exitCode = proc_close($process);

    return [
        'command' => $command,
        'exitCode' => $exitCode,
        'stdout' => $stdout,
        'stderr' => $stderr,
    ];
}

function resolveFiles(string $globPattern, string $projectRoot): array
{
    $pattern = $globPattern;
    if (!preg_match('#^[A-Za-z]:[\\/]#', $pattern) && !str_starts_with($pattern, DIRECTORY_SEPARATOR)) {
        $pattern = $projectRoot . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $pattern);
    }

    $files = glob($pattern) ?: [];
    sort($files);
    return $files;
}

function runAggregator(array $files, array $config, string $setName): array
{
    $language = (string)($config['default_language'] ?? 'en');
    $aggregatorOptions = $config['aggregator'] ?? [];
    $aggregator = new EventGraphAggregator($language, $aggregatorOptions);

    foreach ($files as $file) {
        $aggregator->ingestFile($file);
    }

    $mission = $aggregator->toAggregatedMission();
    $metrics = $aggregator->getMetrics();
    $events = $mission->getEvents();
    $clusterStats = analyzeClusters($events, 5.0);

    return [
        'missionName' => $mission->getMissionName(),
        'fileCount' => count($files),
        'missionDuration' => $mission->getDuration(),
        'missionStart' => $mission->getStartTime(),
        'rawEvents' => (int)($metrics['raw_event_count'] ?? 0),
        'mergedEvents' => (int)($metrics['merged_events'] ?? count($events)),
        'duplicatesSuppressed' => (int)($metrics['duplicates_suppressed'] ?? 0),
        'compositeMerges' => (int)($metrics['composite_signature_merges'] ?? 0),
        'postInferenceMerges' => (int)($metrics['post_inference_merges'] ?? 0),
        'duplicateClusters' => $clusterStats['duplicate_clusters'],
        'heaviestCluster' => $clusterStats['heaviest_cluster'],
    ];
}

function analyzeClusters(array $events, float $bucketSize): array
{
    $clusters = [];
    foreach ($events as $event) {
        $type = strtolower((string)($event['Action'] ?? ''));
        if (!in_array($type, ['hasbeenhitby', 'hasbeendestroyed', 'hasbeenshotdown', 'hasbeenkilled'], true)) {
            continue;
        }

        $primary = $event['PrimaryObject'] ?? null;
        $secondary = $event['SecondaryObject'] ?? null;
        $parent = $event['ParentObject'] ?? null;

        $targetKey = canonicalKey($primary) ?? 'unknown';
        $weaponKey = weaponKey($secondary, $parent) ?? 'unknown';
        $bucket = (int)floor(((float)($event['Time'] ?? 0.0)) / $bucketSize);
        $signature = $type . '|' . $targetKey . '|' . $weaponKey . '|tb:' . $bucket;

        if (!isset($clusters[$signature])) {
            $clusters[$signature] = [
                'type' => $type,
                'targetKey' => $targetKey,
                'weaponKey' => $weaponKey,
                'bucketStart' => $bucket * $bucketSize,
                'eventCount' => 0,
                'evidenceTotal' => 0,
            ];
        }

        $clusters[$signature]['eventCount']++;
        $evidenceCount = isset($event['Evidence']) && is_array($event['Evidence']) ? count($event['Evidence']) : 0;
        $clusters[$signature]['evidenceTotal'] += $evidenceCount;
    }

    $duplicates = array_filter($clusters, static fn (array $cluster): bool => $cluster['eventCount'] > 1);
    usort($duplicates, static function (array $left, array $right): int {
        return ($right['eventCount'] <=> $left['eventCount'])
            ?: ($right['evidenceTotal'] <=> $left['evidenceTotal']);
    });

    return [
        'duplicate_clusters' => count($duplicates),
        'heaviest_cluster' => $duplicates[0] ?? null,
    ];
}

function canonicalKey(?array $object): ?string
{
    $identity = ObjectIdentity::forObject($object);
    return $identity?->getKey();
}

function weaponKey(?array $weapon, ?array $parent): ?string
{
    if ($weapon !== null) {
        $id = strtolower(trim((string)($weapon['ID'] ?? '')));
        if ($id !== '') {
            return $id;
        }

        $directParent = strtolower(trim((string)($weapon['Parent'] ?? '')));
        if ($directParent !== '') {
            return 'parent:' . $directParent;
        }
    }

    if ($parent !== null) {
        $identity = ObjectIdentity::forObject($parent);
        if ($identity !== null) {
            return 'parent:' . $identity->getKey();
        }
    }

    return null;
}

function formatCommandResult(string $label, ?array $result): string
{
    if ($result === null) {
        return sprintf('%s: SKIPPED', $label);
    }

    $status = $result['exitCode'] === 0 ? 'PASS' : 'FAIL';
    $lines = [sprintf('%s: %s (exit %d)', $label, $status, $result['exitCode'])];
    if ($result['stdout'] !== '') {
        $lines[] = trim($result['stdout']);
    }
    if ($result['stderr'] !== '') {
        $lines[] = trim($result['stderr']);
    }
    return implode(PHP_EOL, $lines);
}

function formatSetSummary(array $summary): string
{
    $lines = [];
    $lines[] = sprintf('[%s] %s', $summary['name'], $summary['label']);
    $lines[] = sprintf('  Files: %d (%s)', $summary['fileCount'], $summary['glob']);
    $lines[] = sprintf('  Mission: %s | Duration: %.0fs', $summary['missionName'], $summary['missionDuration']);
    $lines[] = sprintf('  Raw → Merged: %d → %d | Duplicates suppressed: %d', $summary['rawEvents'], $summary['mergedEvents'], $summary['duplicatesSuppressed']);
    $lines[] = sprintf('  Composite merges: %d | Post inference merges: %d', $summary['compositeMerges'], $summary['postInferenceMerges']);

    if ($summary['duplicateClusters'] > 0 && $summary['heaviestCluster'] !== null) {
        $cluster = $summary['heaviestCluster'];
        $lines[] = sprintf('  Duplicate clusters: %d (worst: %s targeting %s w/ %d events)',
            $summary['duplicateClusters'],
            $cluster['type'],
            $cluster['targetKey'],
            $cluster['eventCount']
        );
    } else {
        $lines[] = '  Duplicate clusters: none detected';
    }

    return implode(PHP_EOL, $lines);
}
