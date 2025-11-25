#!/usr/bin/env php
<?php

declare(strict_types=1);

use EventGraph\EventGraphAggregator;
use EventGraph\ObjectIdentity;

const DEFAULT_TIME_WINDOW = 5.0;
const DEFAULT_EVENT_WINDOW = 1.0;
const VALID_MODES = ['clusters', 'events', 'sources'];

/**
 * CLI helper that exposes composite-signature clusters so investigators can audit
 * dedupe behaviour without crafting bespoke tmp scripts.
 */

$projectRoot = dirname(__DIR__);
$config = require $projectRoot . '/config.php';

require_once $projectRoot . '/src/core_path.php';
$corePath = tacview_resolve_core_path($config['core_path'] ?? 'core', $projectRoot);
require_once $corePath . '/tacview.php';

$autoloadPath = resolveEventGraphAutoloader($projectRoot);
require_once $autoloadPath;

$options = parseArguments($argv);
if ($options['help'] ?? false) {
    echo buildUsage($argv[0] ?? 'eventgraph-dedupe-audit.php');
    exit(0);
}

$mode = strtolower($options['mode'] ?? 'clusters');
if (!in_array($mode, VALID_MODES, true)) {
    fwrite(STDERR, "[error] Unsupported mode '{$mode}'. Valid modes: " . implode(', ', VALID_MODES) . "\n");
    exit(1);
}

if (($options['json'] ?? false) && $mode !== 'clusters') {
    fwrite(STDERR, "[error] --json output is only available in clusters mode.\n");
    exit(1);
}

$pathHint = $options['path'] ?? ($config['debriefings_path'] ?? 'debriefings/*.xml');
$window = isset($options['window']) ? max(0.1, (float)$options['window']) : DEFAULT_TIME_WINDOW;
$eventWindow = isset($options['timeWindow']) ? max(0.01, (float)$options['timeWindow']) : DEFAULT_EVENT_WINDOW;
$timeFocus = array_key_exists('time', $options) ? (float)$options['time'] : null;
$typesFilter = array_map('strtolower', $options['types'] ?? []);
$files = resolveDebriefingFiles($pathHint, $projectRoot);

if ($files === []) {
    fwrite(STDERR, "[error] No Tacview XML files found for path '{$pathHint}'.\n");
    exit(1);
}

echo "EventGraph Dedupe Audit\n";
echo str_repeat('=', 25) . "\n";
echo 'Using ' . count($files) . " file(s)\n";
foreach ($files as $file) {
    echo '  • ' . basename($file) . "\n";
}
echo 'Mode: ' . ucfirst($mode) . "\n\n";

$aggregatorOptions = $config['aggregator'] ?? [];
$language = (string)($config['default_language'] ?? 'en');
$aggregator = new EventGraphAggregator($language, $aggregatorOptions);

foreach ($files as $file) {
    try {
        $aggregator->ingestFile($file);
    } catch (\Throwable $exception) {
        fwrite(STDERR, "[error] Failed to ingest " . basename($file) . ': ' . $exception->getMessage() . "\n");
        exit(1);
    }
}

$mission = $aggregator->toAggregatedMission();
$events = $mission->getEvents();
$metrics = $aggregator->getMetrics();

$filters = [
    'pilot' => $options['pilot'] ?? null,
    'target' => $options['target'] ?? null,
    'weapon' => $options['weapon'] ?? null,
    'parent' => $options['parent'] ?? null,
    'types' => $typesFilter,
];

if ($mode === 'sources') {
    renderSources($aggregator, $mission->getSources());
    exit(0);
}

if ($mode === 'events') {
    echo 'Mission: ' . $mission->getMissionName() . "\n";
    if ($filters['pilot']) {
        echo 'Pilot filter: ' . $filters['pilot'] . "\n";
    }
    if ($filters['types'] !== []) {
        echo 'Type filter: ' . implode(', ', $options['types']) . "\n";
    }
    if ($filters['target']) {
        echo 'Target filter: ' . $filters['target'] . "\n";
    }
    if ($filters['weapon']) {
        echo 'Weapon filter: ' . $filters['weapon'] . "\n";
    }
    if ($filters['parent']) {
        echo 'Parent filter: ' . $filters['parent'] . "\n";
    }
    if ($timeFocus !== null) {
        echo 'Time focus: ' . $timeFocus . ' ± ' . $eventWindow . " seconds\n";
    }
    echo "\n";
    renderEvents($events, $filters, $timeFocus, $eventWindow, $mission->getStartTime());
    exit(0);
}

$clusters = buildClusters($events, $window, $filters, (bool)($options['duplicatesOnly'] ?? false));

if ($options['json'] ?? false) {
    echo json_encode([
        'mission' => $mission->getMissionName(),
        'window' => $window,
        'filters' => array_filter($filters, static fn ($value) => $value !== null && $value !== []),
        'clusters' => $clusters,
        'metrics' => $metrics,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

if ($clusters === []) {
    echo "No clusters matched the supplied filters.\n";
    exit(0);
}

if (isset($options['limit'])) {
    $clusters = array_slice($clusters, 0, (int)$options['limit']);
}

echo 'Mission: ' . $mission->getMissionName() . "\n";
echo 'Window: ' . $window . "s buckets\n";
if ($filters['pilot']) {
    echo 'Pilot filter: ' . $filters['pilot'] . "\n";
}
if ($filters['types'] !== []) {
    echo 'Type filter: ' . implode(', ', $options['types']) . "\n";
}
if ($filters['target']) {
    echo 'Target filter: ' . $filters['target'] . "\n";
}
if ($filters['weapon']) {
    echo 'Weapon filter: ' . $filters['weapon'] . "\n";
}
echo "\n";

foreach ($clusters as $index => $cluster) {
    $label = sprintf('[%02d] %s | target=%s | weapon=%s', $index + 1, $cluster['type'], $cluster['targetKey'], $cluster['weaponKey']);
    echo $label . "\n";
    echo '  Bucket: ' . $cluster['bucketLabel'] . ' | Events: ' . $cluster['eventCount'] . ' | Evidence: ' . $cluster['evidenceTotal'] . "\n";
    foreach ($cluster['events'] as $eventSummary) {
        echo sprintf(
            "    - t=%s s | evidence=%d | pilot=%s | sources=%s\n",
            $eventSummary['time'],
            $eventSummary['evidence'],
            $eventSummary['pilot'] ?? 'n/a',
            implode(',', $eventSummary['sources'])
        );
    }
    echo "\n";
}

echo 'Composite signature merges: ' . ($metrics['composite_signature_merges'] ?? 0) . "\n";
echo 'Post inference merges: ' . ($metrics['post_inference_merges'] ?? 0) . "\n";

echo "Done.\n";

function resolveEventGraphAutoloader(string $projectRoot): string
{
    $candidates = [
        $projectRoot . '/src/EventGraph/autoload.php',
        $projectRoot . '/public/src/EventGraph/autoload.php',
    ];

    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    throw new \RuntimeException('Unable to locate EventGraph autoloader.');
}

/**
 * @return array<string, mixed>
 */
function parseArguments(array $argv): array
{
    $options = [
        'types' => [],
        'duplicatesOnly' => false,
        'mode' => 'clusters',
    ];

    $count = count($argv);
    for ($i = 1; $i < $count; $i++) {
        $arg = $argv[$i];

        if ($arg === '--help' || $arg === '-h') {
            $options['help'] = true;
            continue;
        }

        if ($arg === '--json') {
            $options['json'] = true;
            continue;
        }

        if ($arg === '--duplicates-only') {
            $options['duplicatesOnly'] = true;
            continue;
        }

        if ($arg === '--all') {
            $options['duplicatesOnly'] = false;
            continue;
        }

        if ($arg === '--mode' && isset($argv[$i + 1])) {
            $options['mode'] = strtolower($argv[++$i]);
            continue;
        }

        if (str_starts_with($arg, '--mode=')) {
            $options['mode'] = strtolower(substr($arg, 7));
            continue;
        }

        if ($arg === '--pilot' && isset($argv[$i + 1])) {
            $options['pilot'] = $argv[++$i];
            continue;
        }

        if (str_starts_with($arg, '--pilot=')) {
            $options['pilot'] = substr($arg, 8);
            continue;
        }

        if ($arg === '--type' && isset($argv[$i + 1])) {
            $options['types'][] = $argv[++$i];
            continue;
        }

        if (str_starts_with($arg, '--type=')) {
            $options['types'][] = substr($arg, 7);
            continue;
        }

        if ($arg === '--target' && isset($argv[$i + 1])) {
            $options['target'] = $argv[++$i];
            continue;
        }

        if (str_starts_with($arg, '--target=')) {
            $options['target'] = substr($arg, 9);
            continue;
        }

        if ($arg === '--weapon' && isset($argv[$i + 1])) {
            $options['weapon'] = $argv[++$i];
            continue;
        }

        if (str_starts_with($arg, '--weapon=')) {
            $options['weapon'] = substr($arg, 9);
            continue;
        }

        if ($arg === '--parent' && isset($argv[$i + 1])) {
            $options['parent'] = $argv[++$i];
            continue;
        }

        if (str_starts_with($arg, '--parent=')) {
            $options['parent'] = substr($arg, 9);
            continue;
        }

        if ($arg === '--window' && isset($argv[$i + 1])) {
            $options['window'] = (float)$argv[++$i];
            continue;
        }

        if (str_starts_with($arg, '--window=')) {
            $options['window'] = (float)substr($arg, 9);
            continue;
        }

        if ($arg === '--path' && isset($argv[$i + 1])) {
            $options['path'] = $argv[++$i];
            continue;
        }

        if (str_starts_with($arg, '--path=')) {
            $options['path'] = substr($arg, 7);
            continue;
        }

        if ($arg === '--time' && isset($argv[$i + 1])) {
            $options['time'] = (float)$argv[++$i];
            continue;
        }

        if (str_starts_with($arg, '--time=')) {
            $options['time'] = (float)substr($arg, 7);
            continue;
        }

        if ($arg === '--time-window' && isset($argv[$i + 1])) {
            $options['timeWindow'] = (float)$argv[++$i];
            continue;
        }

        if (str_starts_with($arg, '--time-window=')) {
            $options['timeWindow'] = (float)substr($arg, 14);
            continue;
        }

        if ($arg === '--limit' && isset($argv[$i + 1])) {
            $options['limit'] = (int)$argv[++$i];
            continue;
        }

        if (str_starts_with($arg, '--limit=')) {
            $options['limit'] = (int)substr($arg, 8);
            continue;
        }
    }

    return $options;
}

/**
 * @return list<string>
 */
function resolveDebriefingFiles(string $pathHint, string $projectRoot): array
{
    $pattern = $pathHint;
    if (!str_contains($pattern, '*') && !str_contains($pattern, '?')) {
        $pattern = rtrim($pattern, DIRECTORY_SEPARATOR);
        if (!isAbsolutePath($pattern)) {
            $pattern = $projectRoot . DIRECTORY_SEPARATOR . ltrim($pattern, DIRECTORY_SEPARATOR);
        }

        if (is_dir($pattern)) {
            $pattern = rtrim($pattern, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.xml';
        } elseif (is_file($pattern)) {
            return [$pattern];
        }
    }

    $pattern = isAbsolutePath($pattern)
        ? $pattern
        : $projectRoot . DIRECTORY_SEPARATOR . ltrim($pattern, DIRECTORY_SEPARATOR);

    $files = glob($pattern) ?: [];
    sort($files);

    return $files;
}

/**
 * @param array<int, array<string, mixed>> $events
 * @param array<string, mixed> $filters
 * @return list<array<string, mixed>>
 */
function buildClusters(array $events, float $window, array $filters, bool $duplicatesOnly): array
{
    $clusters = [];
    $bucketSize = max(0.1, $window);

    foreach ($events as $event) {
        $type = (string)($event['Action'] ?? 'Unknown');
        if ($filters['types'] !== [] && !in_array(strtolower($type), $filters['types'], true)) {
            continue;
        }

        $primary = $event['PrimaryObject'] ?? null;
        $secondary = $event['SecondaryObject'] ?? null;
        $parent = $event['ParentObject'] ?? null;

        if (($filters['pilot'] ?? null) !== null && !objectMatchesPilot($primary, $filters['pilot'])) {
            continue;
        }

        if (($filters['target'] ?? null) !== null && !objectMatchesNeedle($primary, $filters['target'])) {
            continue;
        }

        if (($filters['weapon'] ?? null) !== null && !objectMatchesNeedle($secondary, $filters['weapon'])) {
            continue;
        }

        $time = isset($event['Time']) ? (float)$event['Time'] : 0.0;
        $bucket = (int)floor($time / $bucketSize);
        $targetKey = canonicalKey($primary) ?? 'unknown-target';
        $weaponKey = weaponKey($secondary, $parent) ?? 'unknown-weapon';
        $signature = strtolower($type) . '|' . $targetKey . '|' . $weaponKey . '|tb:' . $bucket;

        if (!isset($clusters[$signature])) {
            $clusters[$signature] = [
                'type' => $type,
                'targetKey' => $targetKey,
                'weaponKey' => $weaponKey,
                'bucket' => $bucket,
                'bucketLabel' => formatBucketLabel($bucket, $bucketSize),
                'bucketStart' => $bucket * $bucketSize,
                'events' => [],
                'eventCount' => 0,
                'evidenceTotal' => 0,
            ];
        }

        $evidence = isset($event['Evidence']) && is_array($event['Evidence']) ? count($event['Evidence']) : 0;
        $clusters[$signature]['events'][] = [
            'time' => round($time, 3),
            'evidence' => $evidence,
            'pilot' => extractPilot($primary),
            'sources' => extractSources($event),
        ];
        $clusters[$signature]['eventCount']++;
        $clusters[$signature]['evidenceTotal'] += $evidence;
    }

    if ($duplicatesOnly) {
        $clusters = array_filter($clusters, static fn (array $cluster): bool => $cluster['eventCount'] > 1);
    }

    usort($clusters, static function (array $left, array $right): int {
        return ($right['eventCount'] <=> $left['eventCount'])
            ?: ($right['evidenceTotal'] <=> $left['evidenceTotal'])
            ?: ($left['bucketStart'] <=> $right['bucketStart']);
    });

    return array_values($clusters);
}

/**
 * @param list<array<string, mixed>> $events
 * @param array<string, mixed> $filters
 */
function renderEvents(array $events, array $filters, ?float $timeFocus, float $timeWindow, ?float $missionStart): void
{
    $matches = [];
    $typesFilter = $filters['types'] ?? [];

    foreach ($events as $event) {
        $type = strtolower((string)($event['Action'] ?? 'unknown'));
        if ($typesFilter !== [] && !in_array($type, $typesFilter, true)) {
            continue;
        }

        $primary = $event['PrimaryObject'] ?? null;
        $secondary = $event['SecondaryObject'] ?? null;
        $parent = $event['ParentObject'] ?? null;

        if (!objectMatchesPilot($primary, $filters['pilot'] ?? null)) {
            continue;
        }

        if (($filters['target'] ?? null) !== null && !objectMatchesNeedle($primary, $filters['target'])) {
            continue;
        }

        if (($filters['weapon'] ?? null) !== null && !objectMatchesNeedle($secondary, $filters['weapon'])) {
            continue;
        }

        if (($filters['parent'] ?? null) !== null && !objectMatchesNeedle($parent, $filters['parent'])) {
            continue;
        }

        $time = isset($event['Time']) ? (float)$event['Time'] : 0.0;
        if ($timeFocus !== null && ($time < $timeFocus - $timeWindow || $time > $timeFocus + $timeWindow)) {
            continue;
        }

        $matches[] = $event;
    }

    if ($matches === []) {
        echo "No events matched the supplied filters.\n";
        return;
    }

    usort($matches, static fn (array $left, array $right): int => (int)(((float)($left['Time'] ?? 0.0)) <=> ((float)($right['Time'] ?? 0.0))));

    printf("Matched %d event(s).\n", count($matches));
    if ($timeFocus !== null) {
        printf("Time focus %.3f ± %.3f seconds.\n", $timeFocus, $timeWindow);
    }

    foreach ($matches as $index => $event) {
        $time = isset($event['Time']) ? (float)$event['Time'] : 0.0;
        $confidence = isset($event['Confidence']) ? (float)$event['Confidence'] : -1.0;
        $sources = extractSources($event);
        $primary = $event['PrimaryObject'] ?? [];
        $secondary = $event['SecondaryObject'] ?? [];
        $parent = $event['ParentObject'] ?? [];
        $evidenceCount = isset($event['Evidence']) && is_array($event['Evidence']) ? count($event['Evidence']) : 0;

        $pilot = $primary['Pilot'] ?? 'n/a';
        $target = $primary['Name'] ?? ($primary['Type'] ?? 'unknown');
        $weapon = $secondary['Name'] ?? ($secondary['Type'] ?? ($secondary['ID'] ?? 'none'));
        $parentLabel = $parent['Pilot'] ?? ($parent['Name'] ?? 'n/a');
        $clock = $missionStart !== null ? gmdate('H:i:s', (int)round($missionStart + $time)) : 'n/a';

        printf(
            "[%02d] t=%8.3f (%s) | action=%s | pilot=%s | target=%s | weapon=%s | parent=%s | conf=%5.2f | evidence=%d | sources=%s\n",
            $index + 1,
            $time,
            $clock,
            (string)($event['Action'] ?? 'unknown'),
            $pilot,
            $target,
            $weapon,
            $parentLabel,
            $confidence,
            $evidenceCount,
            $sources === [] ? 'n/a' : implode(',', $sources)
        );
    }
}

/**
 * @param array<int, array<string, mixed>> $sources
 */
function renderSources(EventGraphAggregator $aggregator, array $sources): void
{
    echo "Recording offsets\n";
    $offsets = $aggregator->getRecordingOffsets();
    if ($offsets === []) {
        echo "  (none)\n";
    } else {
        foreach ($offsets as $id => $offset) {
            printf("  - %s: %+.3f\n", $id, $offset);
        }
    }

    echo "\nSource metadata\n";
    if ($sources === []) {
        echo "  (no sources ingested)\n";
        return;
    }

    foreach ($sources as $source) {
        $id = (string)($source['id'] ?? ($source['filename'] ?? 'unknown'));
        $offset = isset($source['offset']) ? (float)$source['offset'] : 0.0;
        $strategy = (string)($source['offsetStrategy'] ?? 'n/a');
        $start = isset($source['startTime']) ? (float)$source['startTime'] : 0.0;
        $duration = isset($source['duration']) ? (float)$source['duration'] : 0.0;
        $events = (int)($source['events'] ?? 0);
        $baseline = !empty($source['baseline']);
        $alignedStart = isset($source['alignedStartTime']) && is_numeric($source['alignedStartTime'])
            ? (float)$source['alignedStartTime']
            : null;
        $alignedDuration = isset($source['alignedDuration']) && is_numeric($source['alignedDuration'])
            ? (float)$source['alignedDuration']
            : null;
        $alignedStartLabel = $alignedStart !== null ? sprintf('%8.2f', $alignedStart) : '   n/a ';
        $alignedDurationLabel = $alignedDuration !== null ? sprintf('%8.2f', $alignedDuration) : '   n/a ';

        printf(
            "  - %s | offset=%+.3f | strategy=%s | baseline=%s | start=%8.2f | duration=%8.2f | alignedStart=%s | alignedDuration=%s | events=%d\n",
            $id,
            $offset,
            $strategy,
            $baseline ? 'yes' : 'no',
            $start,
            $duration,
            $alignedStartLabel,
            $alignedDurationLabel,
            $events
        );
    }
}

function buildUsage(string $script): string
{
    return <<<USAGE
Usage: php $script [options]

Options:
  --mode <clusters|events|sources>
                         Switch between cluster summaries (default), raw event dumps, or source diagnostics
  --path <glob>           Override debriefings glob (default config value)
  --type <Action>         Filter by Tacview action type (repeatable)
  --pilot <name>          Filter by substring match in PrimaryObject pilot
  --parent <needle>       Filter ParentObject metadata (events mode)
  --target <needle>       Filter by substring match in primary object metadata
  --weapon <needle>       Filter by substring match in secondary object metadata
  --time <seconds>        Focus on a mission time (events mode)
  --time-window <seconds> Time delta when --time is supplied (default 1)
  --window <seconds>      Time bucket size for cluster grouping (default 5)
  --limit <n>             Limit number of clusters shown
  --duplicates-only       Show only clusters with more than one merged event
  --json                  Emit JSON instead of human-readable output (clusters mode)
  --help                  Show this message

Examples:
  php $script --type HasBeenDestroyed --pilot "Skunk"
  php $script --target Olympus --window 10 --limit 5
  php $script --mode events --type HasFired --pilot "Zach" --time 2500 --time-window 5
  php $script --mode sources
  php $script --json --type HasBeenDestroyed > audit.json
USAGE;
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

function formatBucketLabel(int $bucket, float $bucketSize): string
{
    $start = $bucket * $bucketSize;
    $end = $start + $bucketSize;
    return sprintf('[%.2f, %.2f)', $start, $end);
}

function objectMatchesPilot(?array $object, ?string $needle): bool
{
    if ($needle === null || $needle === '') {
        return true;
    }

    $pilot = extractPilot($object);
    if ($pilot === null) {
        return false;
    }

    return str_contains(strtolower($pilot), strtolower($needle));
}

function objectMatchesNeedle(?array $object, ?string $needle): bool
{
    if ($needle === null || $needle === '') {
        return true;
    }

    if ($object === null) {
        return false;
    }

    $encoded = json_encode($object, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        return false;
    }

    $haystack = strtolower($encoded);
    return str_contains($haystack, strtolower($needle));
}

function extractPilot(?array $object): ?string
{
    if ($object === null) {
        return null;
    }

    $pilot = $object['Pilot'] ?? null;
    if (is_string($pilot)) {
        $pilot = trim($pilot);
        return $pilot === '' ? null : $pilot;
    }

    return null;
}

/**
 * @param array<string, mixed> $event
 * @return list<string>
 */
function extractSources(array $event): array
{
    $sources = [];
    if (!isset($event['Evidence']) || !is_array($event['Evidence'])) {
        return $sources;
    }

    foreach ($event['Evidence'] as $evidence) {
        if (!is_array($evidence) || !isset($evidence['sourceId'])) {
            continue;
        }
        $sources[$evidence['sourceId']] = true;
    }

    $list = array_keys($sources);
    sort($list);

    return $list;
}

function isAbsolutePath(string $path): bool
{
    if ($path === '') {
        return false;
    }

    if ($path[0] === DIRECTORY_SEPARATOR || $path[0] === '/') {
        return true;
    }

    return preg_match('#^[A-Za-z]:[\\/]#', $path) === 1;
}
