<?php

declare(strict_types=1);

namespace EventGraph;

use RuntimeException;

use function abs;
use function array_key_exists;
use function array_key_first;
use function array_keys;
use function array_slice;
use function count;
use function floor;
use function implode;
use function in_array;
use function intdiv;
use function is_array;
use function is_file;
use function max;
use function method_exists;
use function min;
use function preg_match;
use function round;
use function sort;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function strtolower;
use function strlen;
use function trim;
use function usort;

final class EventGraphAggregator
{
    private const DEFAULT_MISSION_NAME = 'Tacview Combined Debrief';
    private const POST_FILTER_TIME_WINDOW = 1.0;
    private const EVENT_TIME_OVERRIDES = [
        'HasTakenOff' => 30.0,
        'HasLanded' => 45.0,
        'HasEnteredTheArea' => 45.0,
        'HasLeftTheArea' => 45.0,
        'HasBeenHitBy' => 4.0,
        'HasBeenDestroyed' => 5.0,
    ];
    private const MISSING_SECONDARY_MERGE_TYPES = [
        'hasbeenhitby',
        'hasbeendestroyed',
        'hasbeenshotdown',
        'hasbeenkilled',
        'hascrashed',
    ];
    private const COALITION_MISMATCH_EXEMPT_TYPES = [
        'hasbeenhitby',
        'hasbeendestroyed',
        'hasbeenkilled',
        'hasbeenshotdown',
        'hascrashed',
    ];

    private readonly string $language;
    private readonly float $timeTolerance;
    private readonly float $hitBacktrackWindow;
    private readonly float $anchorTolerance;
    private readonly int $anchorMinimumMatches;
    private readonly float $maxFallbackOffset;
    private readonly float $maxAnchorOffset;
    private readonly float $missionTimeCongruenceTolerance;

    /** @var list<SourceRecording> */
    private array $recordings = [];

    /** @var list<NormalizedEvent> */
    private array $events = [];

    /** @var array<string, list<NormalizedEvent>> */
    private array $eventIndex = [];

    /** @var array<string, list<NormalizedEvent>> */
    private array $pendingEvents = [];

    /** @var array<string, float> */
    private array $recordingOffsets = [];

    /** @var array<string, string> */
    private array $offsetStrategies = [];

    /** @var array<string, float> */
    private array $recordingReliability = [];

    /** @var array<string, string|null> */
    private array $recordingCoalitions = [];

    private ?string $baselineRecordingId = null;
    private ?string $missionName = null;
    private ?float $startTime = null;
    private ?float $endTime = null;
    private bool $built = false;
    /** @var list<float> */
    private array $startTimeSamples = [];

    /** @var array<string, mixed> */
    private array $metrics = [
        'raw_event_count' => 0,
        'merged_events' => 0,
        'duplicates_suppressed' => 0,
        'inferred_links' => 0,
        'post_filtered_events' => 0,
        'coalition_mismatch_pruned' => 0,
        'single_source_outliers_pruned' => 0,
        'orphan_hasfired_without_hit' => 0,
        'orphan_kill_without_launch' => 0,
        'mixed_coalition_evidence' => 0,
        'disconnect_destructions_pruned' => 0,
        'disconnect_midair_flagged' => 0,
    ];

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(string $language, array $options = [])
    {
        $this->language = $language;
        $this->timeTolerance = isset($options['time_tolerance']) ? (float)$options['time_tolerance'] : 1.5;
        $this->hitBacktrackWindow = isset($options['hit_backtrack_window']) ? (float)$options['hit_backtrack_window'] : 5.0;
        $this->anchorTolerance = isset($options['anchor_tolerance']) ? (float)$options['anchor_tolerance'] : 120.0;

        $minimumMatches = isset($options['anchor_min_matches']) ? (int)$options['anchor_min_matches'] : 3;
        $this->anchorMinimumMatches = $minimumMatches > 0 ? $minimumMatches : 1;

        $this->maxFallbackOffset = isset($options['max_fallback_offset']) ? (float)$options['max_fallback_offset'] : 600.0;
        $defaultAnchorOffset = max($this->maxFallbackOffset, 7200.0);
        $this->maxAnchorOffset = isset($options['max_anchor_offset']) ? (float)$options['max_anchor_offset'] : $defaultAnchorOffset;
        $this->missionTimeCongruenceTolerance = isset($options['mission_time_congruence_tolerance'])
            ? max(0.0, (float)$options['mission_time_congruence_tolerance'])
            : 1800.0;
    }

    public function ingestFile(string $path): void
    {
        if (!is_file($path)) {
            throw new RuntimeException("Tacview file not found: {$path}");
        }

        $this->ingestRecording(new SourceRecording($path, $this->language));
    }

    public function getBaselineRecordingId(): ?string
    {
        return $this->baselineRecordingId;
    }

    /**
     * @return array<string, float>
     */
    public function getRecordingOffsets(): array
    {
        return $this->recordingOffsets;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetrics(): array
    {
        return $this->metrics;
    }

    public function build(): void
    {
        if ($this->built) {
            return;
        }

        $this->prepareEvents();

        if ($this->events === []) {
            $this->built = true;
            return;
        }

        $this->computeRecordingMetadata();

        usort(
            $this->events,
            static fn (NormalizedEvent $left, NormalizedEvent $right): int => $left->getMissionTime() <=> $right->getMissionTime()
        );

        $this->rebuildEventIndex();
        $this->runInference();
        $this->applyPostMergeFilters();
        $this->pruneDisconnectDestructions();
        $this->annotateEvents();

        $this->metrics['merged_events'] = count($this->events);
        $this->built = true;
    }

    public function toAggregatedMission(): AggregatedMission
    {
        $this->build();

        $mission = new AggregatedMission(
            $this->missionName ?? self::DEFAULT_MISSION_NAME,
            $this->startTime ?? 0.0,
            $this->computeDuration()
        );

        $counter = 1;
        foreach ($this->events as $event) {
            $mission->addEvent($counter++, $event->toArray());
        }

        foreach ($this->recordings as $recording) {
            $mission->addSource([
                'id' => $recording->id,
                'filename' => $recording->filename,
                'missionName' => $recording->missionName,
                'startTime' => $recording->startTime,
                'duration' => $recording->duration,
                'events' => count($recording->events),
                'offset' => $this->recordingOffsets[$recording->id] ?? 0.0,
                'baseline' => $recording->id === $this->baselineRecordingId,
                'offsetStrategy' => $this->offsetStrategies[$recording->id] ?? null,
                'reliability' => $this->recordingReliability[$recording->id] ?? 1.0,
                'dominantCoalition' => $this->recordingCoalitions[$recording->id] ?? null,
            ]);
        }

        $mission->setMetrics($this->metrics);

        return $mission;
    }

    private function ingestRecording(SourceRecording $recording): void
    {
        $this->recordings[] = $recording;
        $this->metrics['raw_event_count'] += $recording->rawEventCount;

        $this->missionName = $this->resolveMissionName($this->missionName, $recording->missionName);
        $this->startTime = $this->resolveStartTime($recording);
        if ($recording->startTime !== null) {
            $this->startTimeSamples[] = $recording->startTime;
        }
        $this->endTime = $this->resolveEndTime($recording);

        $this->pendingEvents[$recording->id] = $recording->events;
        $this->recordingCoalitions[$recording->id] = $recording->dominantCoalition;
        $this->built = false;
    }

    private function prepareEvents(): void
    {
        if ($this->pendingEvents === []) {
            return;
        }

        $this->events = [];
        $this->eventIndex = [];
        $this->recordingOffsets = [];
        $this->offsetStrategies = [];

        $recordingsById = [];
        foreach ($this->recordings as $recording) {
            $recordingsById[$recording->id] = $recording;
        }

        $baselineId = $this->selectBaselineRecordingId();
        if ($baselineId === null) {
            $baselineId = array_key_first($this->pendingEvents);
        }
        $this->baselineRecordingId = $baselineId;

        if ($baselineId !== null) {
            $this->recordingOffsets[$baselineId] = 0.0;
            $this->offsetStrategies[$baselineId] = 'baseline';
        }

        $alignedIds = [];
        if ($baselineId !== null) {
            $alignedIds[$baselineId] = true;
        }

        $pending = [];
        foreach ($recordingsById as $id => $recording) {
            if (!isset($this->pendingEvents[$id]) || $id === $baselineId) {
                continue;
            }
            $pending[$id] = $recording;
        }

        while ($pending !== []) {
            $progress = false;

            foreach ($pending as $recordingId => $recording) {
                $best = null;

                foreach (array_keys($alignedIds) as $referenceId) {
                    $estimate = $this->estimateRecordingOffset($referenceId, $recordingId);
                    $estimate['reference'] = $referenceId;

                    if ($estimate['strategy'] === 'anchor' && $estimate['matches'] >= $this->anchorMinimumMatches) {
                        if ($best === null
                            || $best['strategy'] !== 'anchor'
                            || $estimate['matches'] > $best['matches']
                            || ($estimate['matches'] === $best['matches'] && abs($estimate['offset']) < abs($best['offset']))) {
                            $best = $estimate;
                        }
                    } elseif ($estimate['strategy'] === 'fallback-applied') {
                        if ($best === null
                            || $best['strategy'] !== 'anchor'
                            || ($best['strategy'] === 'fallback-applied' && abs($estimate['offset']) < abs($best['offset']))) {
                            $best = $estimate;
                        }
                    }
                }

                if ($best !== null) {
                    $referenceOffset = $this->recordingOffsets[$best['reference']] ?? 0.0;
                    $globalOffset = $best['offset'] + $referenceOffset;

                    $this->recordingOffsets[$recordingId] = $globalOffset;
                    if ($best['strategy'] === 'anchor') {
                        $label = 'anchor';
                        if ($best['reference'] !== $baselineId) {
                            $label .= ' vs ' . $best['reference'];
                        }
                        $label .= ' (delta=' . round($best['offset'], 3) . ', matches=' . $best['matches'] . ')';
                        $this->offsetStrategies[$recordingId] = $label;
                    } elseif ($best['strategy'] === 'fallback-applied') {
                        $label = 'fallback-applied';
                        if ($best['reference'] !== null) {
                            $label .= ' vs ' . $best['reference'];
                        }
                        $label .= ' (delta=' . round($best['offset'], 3) . ')';
                        $this->offsetStrategies[$recordingId] = $label;
                    } else {
                        $this->offsetStrategies[$recordingId] = $best['strategy'];
                    }

                    unset($pending[$recordingId]);
                    $alignedIds[$recordingId] = true;
                    $progress = true;
                }
            }

            if (!$progress) {
                foreach (array_keys($pending) as $recordingId) {
                    $this->recordingOffsets[$recordingId] = 0.0;
                    $this->offsetStrategies[$recordingId] = 'fallback-skipped';
                }
                break;
            }
        }

        foreach ($this->recordings as $recording) {
            $events = $this->pendingEvents[$recording->id] ?? [];
            if ($events === []) {
                continue;
            }

            $offset = $this->recordingOffsets[$recording->id] ?? 0.0;
            $strategy = $this->offsetStrategies[$recording->id] ?? 'baseline';

            if ($offset !== 0.0 && (str_starts_with($strategy, 'anchor') || str_starts_with($strategy, 'fallback-applied'))) {
                foreach ($events as $event) {
                    $event->shiftMissionTime(-$offset);
                }
            }

            foreach ($events as $event) {
                $this->addOrMerge($event);
            }
        }

        $this->pendingEvents = [];
        $this->applyStartTimeConsensus();
        $this->metrics['merged_events'] = count($this->events);
    }

    private function addOrMerge(NormalizedEvent $candidate): void
    {
        $bucketKey = $candidate->getType();
        $bucket = $this->eventIndex[$bucketKey] ?? [];

        foreach ($bucket as $existing) {
            if ($this->areEventsEquivalent($existing, $candidate)) {
                $existing->mergeWith($candidate);
                $this->metrics['duplicates_suppressed']++;
                return;
            }
        }

        $this->events[] = $candidate;
        $this->eventIndex[$bucketKey][] = $candidate;
    }

    private function areEventsEquivalent(NormalizedEvent $left, NormalizedEvent $right): bool
    {
        $tolerance = max(
            $this->timeToleranceForEvent($left),
            $this->timeToleranceForEvent($right)
        );

        if (abs($left->getMissionTime() - $right->getMissionTime()) > $tolerance) {
            return false;
        }

        if (!$this->objectsComparable($left->getPrimary(), $right->getPrimary())) {
            return false;
        }

        if (!$this->secondaryComparable($left, $right)) {
            return false;
        }

        return true;
    }

    private function objectsComparable(?array $a, ?array $b): bool
    {
        if ($a === null && $b === null) {
            return true;
        }

        if ($a === null || $b === null) {
            return false;
        }

        $keyA = $this->objectKey($a);
        $keyB = $this->objectKey($b);

        return $keyA !== null && $keyA === $keyB;
    }

    private function objectKey(?array $object): ?string
    {
        if ($object === null) {
            return null;
        }

        $fields = ['Pilot', 'Name', 'Group', 'Type', 'Coalition', 'Country'];
        $parts = [];

        foreach ($fields as $field) {
            if (!isset($object[$field])) {
                continue;
            }

            $value = strtolower(trim((string)$object[$field]));
            if ($value !== '') {
                $parts[] = $value;
            }
        }

        if ($parts !== []) {
            return implode('|', $parts);
        }

        if (isset($object['ID'])) {
            $id = strtolower(trim((string)$object['ID']));
            if ($id !== '') {
                return $id;
            }
        }

        return null;
    }

    private function secondaryComparable(NormalizedEvent $left, NormalizedEvent $right): bool
    {
        $secondaryLeft = $left->getSecondary();
        $secondaryRight = $right->getSecondary();

        if ($this->objectsComparable($secondaryLeft, $secondaryRight)) {
            return true;
        }

        if (!$this->allowsMissingSecondary($left->getType())) {
            return false;
        }

        $leftMissing = $this->isObjectEffectivelyMissing($secondaryLeft);
        $rightMissing = $this->isObjectEffectivelyMissing($secondaryRight);

        if ($leftMissing && !$rightMissing) {
            return true;
        }

        if ($rightMissing && !$leftMissing) {
            return true;
        }

        return false;
    }

    private function allowsMissingSecondary(string $eventType): bool
    {
        return in_array(strtolower($eventType), self::MISSING_SECONDARY_MERGE_TYPES, true);
    }

    private function isObjectEffectivelyMissing(?array $object): bool
    {
        if ($object === null) {
            return true;
        }

        foreach ($object as $value) {
            if ($value !== null && $value !== '') {
                return false;
            }
        }

        return true;
    }

    private function timeToleranceForEvent(NormalizedEvent $event): float
    {
        $type = $event->getType();
        if (isset(self::EVENT_TIME_OVERRIDES[$type])) {
            $override = self::EVENT_TIME_OVERRIDES[$type];
            return $override > $this->timeTolerance ? $override : $this->timeTolerance;
        }

        return $this->timeTolerance;
    }

    private function selectBaselineRecordingId(): ?string
    {
        if ($this->recordings === []) {
            return null;
        }

        $eventSets = [];
        foreach ($this->recordings as $recording) {
            $eventSets[$recording->id] = $this->pendingEvents[$recording->id] ?? [];
        }

        $anchorScores = [];
        $indices = [];
        foreach ($this->recordings as $recording) {
            $indices[$recording->id] = $this->buildAnchorIndex($eventSets[$recording->id]);
        }

        foreach ($this->recordings as $candidate) {
            $score = 0;
            $index = $indices[$candidate->id];
            if ($index !== []) {
                foreach ($this->recordings as $other) {
                    if ($candidate->id === $other->id) {
                        continue;
                    }
                    $offset = $this->findAnchorOffset($index, $eventSets[$other->id]);
                    if ($offset !== null) {
                        $score += $offset['matches'];
                    }
                }
            }
            $anchorScores[$candidate->id] = $score;
        }

        $bestId = null;
        $bestScore = -1;
        $bestStart = null;

        foreach ($this->recordings as $recording) {
            $score = $anchorScores[$recording->id] ?? 0;
            $startTime = $recording->startTime;

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestId = $recording->id;
                $bestStart = $startTime;
                continue;
            }

            if ($score === $bestScore) {
                $prefer = false;

                if ($bestStart === null && $startTime !== null) {
                    $prefer = true;
                } elseif ($bestStart !== null && $startTime !== null && $startTime < $bestStart) {
                    $prefer = true;
                } elseif ($bestStart === null && $startTime === null && $bestId === null) {
                    $prefer = true;
                }

                if ($prefer) {
                    $bestId = $recording->id;
                    $bestStart = $startTime;
                }
            }
        }

        if ($bestId !== null && $bestScore > 0) {
            return $bestId;
        }

        return $this->selectEarliestStartRecordingId();
    }

    private function selectEarliestStartRecordingId(): ?string
    {
        $selected = null;
        $bestStart = null;

        foreach ($this->recordings as $recording) {
            if ($recording->startTime === null) {
                if ($selected === null) {
                    $selected = $recording->id;
                }
                continue;
            }

            if ($bestStart === null || $recording->startTime < $bestStart) {
                $bestStart = $recording->startTime;
                $selected = $recording->id;
            }
        }

        return $selected;
    }

    /**
     * @param list<NormalizedEvent> $events
     * @return array<string, list<float>>
     */
    private function buildAnchorIndex(array $events): array
    {
        $index = [];
        foreach ($events as $event) {
            foreach ($this->anchorKeysForEvent($event) as $key) {
                $index[$key][] = $event->getMissionTime();
            }
        }

        return $index;
    }

    private function buildAnchorKey(NormalizedEvent $event): ?string
    {
        $keys = $this->anchorKeysForEvent($event);
        return $keys[0] ?? null;
    }

    /**
     * @return list<string>
     */
    private function anchorKeysForEvent(NormalizedEvent $event): array
    {
        $primaryKey = $this->objectKey($event->getPrimary());
        if ($primaryKey === null) {
            return [];
        }

        $keys = [];
        $secondaryKey = $this->objectKey($event->getSecondary());
        if ($secondaryKey !== null) {
            $keys[] = $event->getType() . '|' . $primaryKey . '|' . $secondaryKey;
        }

        $keys[] = $event->getType() . '|' . $primaryKey;

        return $keys;
    }

    /**
     * @param array<string, list<float>> $baselineIndex
     * @param list<NormalizedEvent> $targetEvents
     * @return array{offset: float, matches: int}|null
     */
    private function findAnchorOffset(array $baselineIndex, array $targetEvents): ?array
    {
        $deltas = [];
        foreach ($targetEvents as $event) {
            $keys = $this->anchorKeysForEvent($event);
            if ($keys === []) {
                continue;
            }

            $targetTime = $event->getMissionTime();
            foreach ($keys as $anchorKey) {
                if (!array_key_exists($anchorKey, $baselineIndex)) {
                    continue;
                }

                $deltas[] = $this->nearestDelta($targetTime, $baselineIndex[$anchorKey]);
                break;
            }
        }

        if ($deltas === []) {
            return null;
        }

        sort($deltas);
        $count = count($deltas);
        $start = 0;
        $bestPrimaryMedian = null;
        $bestPrimaryCount = 0;
        $bestFallbackMedian = null;
        $bestFallbackCount = 0;

        for ($end = 0; $end < $count; $end++) {
            while ($start <= $end && ($deltas[$end] - $deltas[$start]) > $this->anchorTolerance) {
                $start++;
            }

            $windowSize = $end - $start + 1;
            if ($windowSize < $this->anchorMinimumMatches) {
                continue;
            }

            $window = array_slice($deltas, $start, $windowSize);
            $median = $this->calculateMedian($window);
            $absoluteMedian = abs($median);

            if ($this->maxAnchorOffset > 0.0 && $absoluteMedian > $this->maxAnchorOffset) {
                continue;
            }

            if ($absoluteMedian <= $this->anchorTolerance) {
                if ($windowSize > $bestPrimaryCount
                    || ($windowSize === $bestPrimaryCount && ($bestPrimaryMedian === null || $absoluteMedian < abs($bestPrimaryMedian)))) {
                    $bestPrimaryCount = $windowSize;
                    $bestPrimaryMedian = $median;
                }
            } else {
                if ($windowSize > $bestFallbackCount
                    || ($windowSize === $bestFallbackCount && ($bestFallbackMedian === null || $absoluteMedian < abs($bestFallbackMedian)))) {
                    $bestFallbackCount = $windowSize;
                    $bestFallbackMedian = $median;
                }
            }
        }

        if ($bestPrimaryMedian !== null && $bestFallbackMedian !== null) {
            if ($bestFallbackCount > $bestPrimaryCount) {
                return ['offset' => $bestFallbackMedian, 'matches' => $bestFallbackCount];
            }

            if ($bestPrimaryCount > $bestFallbackCount) {
                return ['offset' => $bestPrimaryMedian, 'matches' => $bestPrimaryCount];
            }

            if (abs($bestFallbackMedian) < abs($bestPrimaryMedian)) {
                return ['offset' => $bestFallbackMedian, 'matches' => $bestFallbackCount];
            }

            return ['offset' => $bestPrimaryMedian, 'matches' => $bestPrimaryCount];
        }

        if ($bestPrimaryMedian !== null) {
            return ['offset' => $bestPrimaryMedian, 'matches' => $bestPrimaryCount];
        }

        if ($bestFallbackMedian !== null) {
            return ['offset' => $bestFallbackMedian, 'matches' => $bestFallbackCount];
        }

        return null;
    }

    private function nearestDelta(float $targetTime, array $baseTimes): float
    {
        $closestDelta = null;
        foreach ($baseTimes as $baseTime) {
            $delta = $targetTime - $baseTime;
            if ($closestDelta === null || abs($delta) < abs($closestDelta)) {
                $closestDelta = $delta;
            }
        }

        return $closestDelta ?? 0.0;
    }

    /**
     * @param list<float> $values
     */
    private function calculateMedian(array $values): float
    {
        $count = count($values);
        if ($count === 0) {
            return 0.0;
        }

        $middle = intdiv($count, 2);
        if ($count % 2 === 1) {
            return $values[$middle];
        }

        return ($values[$middle - 1] + $values[$middle]) / 2;
    }

    private function estimateRecordingOffset(string $baselineId, string $targetId): array
    {
        $baselineEvents = $this->pendingEvents[$baselineId] ?? [];
        $targetEvents = $this->pendingEvents[$targetId] ?? [];

        if ($baselineEvents === [] || $targetEvents === []) {
            $fallback = $this->fallbackOffset($baselineId, $targetId);
            if ($fallback !== null) {
                return ['offset' => $fallback, 'strategy' => 'fallback-applied', 'matches' => 0];
            }

            return ['offset' => 0.0, 'strategy' => 'fallback-skipped', 'matches' => 0];
        }

        $baselineIndex = $this->buildAnchorIndex($baselineEvents);
        $anchorOffset = $this->findAnchorOffset($baselineIndex, $targetEvents);
        if ($anchorOffset !== null) {
            return ['offset' => $anchorOffset['offset'], 'strategy' => 'anchor', 'matches' => $anchorOffset['matches']];
        }

        $fallback = $this->fallbackOffset($baselineId, $targetId);
        if ($fallback !== null) {
            return ['offset' => $fallback, 'strategy' => 'fallback-applied', 'matches' => 0];
        }

        return ['offset' => 0.0, 'strategy' => 'fallback-skipped', 'matches' => 0];
    }

    private function fallbackOffset(string $baselineId, string $targetId): ?float
    {
        $baseline = $this->findRecording($baselineId);
        $target = $this->findRecording($targetId);

        if ($baseline !== null && $target !== null && $baseline->startTime !== null && $target->startTime !== null) {
            $difference = $target->startTime - $baseline->startTime;
            if (abs($difference) <= $this->maxFallbackOffset) {
                return $difference;
            }
        }

        return null;
    }

    private function findRecording(string $id): ?SourceRecording
    {
        foreach ($this->recordings as $recording) {
            if ($recording->id === $id) {
                return $recording;
            }
        }

        return null;
    }

    private function runInference(): void
    {
        $weaponFires = [];
        foreach ($this->events as $event) {
            if ($event->getType() === 'HasFired') {
                $secondary = $event->getSecondary();
                if ($secondary !== null && isset($secondary['ID'])) {
                    $weaponId = (string)$secondary['ID'];
                    $weaponFires[$weaponId][] = $event;
                }
            }
        }

        foreach ($this->events as $event) {
            if (!$this->isDestructiveEvent($event)) {
                continue;
            }

            if ($event->getParent() !== null) {
                continue;
            }

            $linked = false;
            $weapon = $event->getSecondary();
            if ($weapon !== null && isset($weapon['ID'])) {
                $weaponId = (string)$weapon['ID'];
                if (isset($weaponFires[$weaponId])) {
                    $candidate = $this->selectBestFire($weaponFires[$weaponId], $event->getMissionTime());
                    if ($candidate !== null && $candidate->getPrimary() !== null) {
                        $event->setParent($candidate->getPrimary(), 'weapon-owner-inference');
                        $this->metrics['inferred_links']++;
                        $linked = true;
                    }
                }
            }

            if (!$linked) {
                $hit = $this->findRecentHit($event);
                if ($hit !== null && $hit->getParent() !== null) {
                    $event->setParent($hit->getParent(), 'prior-hit-inference', 0.05);
                    $this->metrics['inferred_links']++;
                }
            }
        }
    }

    private function isDestructiveEvent(NormalizedEvent $event): bool
    {
        $action = strtolower($event->getType());

        return str_contains($action, 'destroyed')
            || str_contains($action, 'shotdown')
            || str_contains($action, 'crashed');
    }

    /**
     * @param list<NormalizedEvent> $candidates
     */
    private function selectBestFire(array $candidates, float $referenceTime): ?NormalizedEvent
    {
        $best = null;
        $bestDelta = null;

        foreach ($candidates as $candidate) {
            $delta = $referenceTime - $candidate->getMissionTime();
            if ($delta < 0.0) {
                continue;
            }

            if ($best === null || $delta < $bestDelta) {
                $best = $candidate;
                $bestDelta = $delta;
            }
        }

        return $best;
    }

    private function applyPostMergeFilters(): void
    {
        if ($this->events === []) {
            $this->rebuildEventIndex();
            return;
        }

        $remove = [];
        $groups = [];

        foreach ($this->events as $index => $event) {
            if ($this->shouldPruneDueToCoalitionMismatch($event)) {
                $remove[$index] = true;
                $this->metrics['coalition_mismatch_pruned']++;
                continue;
            }

            if ($event->getType() !== 'HasFired') {
                continue;
            }

            $groupKey = $this->buildPostFilterGroupKey($event);
            if ($groupKey !== null) {
                $groups[$groupKey][] = $index;
            }
        }

        foreach ($groups as $indices) {
            if (count($indices) <= 1) {
                continue;
            }

            $maxEvidence = 0;
            foreach ($indices as $idx) {
                if (isset($remove[$idx])) {
                    continue;
                }
                $evidenceCount = count($this->events[$idx]->getEvidence());
                if ($evidenceCount > $maxEvidence) {
                    $maxEvidence = $evidenceCount;
                }
            }

            if ($maxEvidence < 2) {
                continue;
            }

            foreach ($indices as $idx) {
                if (isset($remove[$idx])) {
                    continue;
                }

                if (count($this->events[$idx]->getEvidence()) === 1) {
                    $remove[$idx] = true;
                    $this->metrics['single_source_outliers_pruned']++;
                }
            }
        }

        if ($remove === []) {
            $this->rebuildEventIndex();
            return;
        }

        $filtered = [];
        foreach ($this->events as $idx => $event) {
            if (isset($remove[$idx])) {
                continue;
            }
            $filtered[] = $event;
        }

        $this->metrics['post_filtered_events'] += count($remove);
        $this->events = $filtered;
        $this->rebuildEventIndex();
    }

    private function buildPostFilterGroupKey(NormalizedEvent $event): ?string
    {
        $secondary = $event->getSecondary();
        if ($secondary === null) {
            return null;
        }

        $weaponName = strtolower(trim((string)($secondary['Name'] ?? '')));
        if ($weaponName === '') {
            return null;
        }

        $weaponType = strtolower(trim((string)($secondary['Type'] ?? '')));
        $bucket = (int)floor($event->getMissionTime() / self::POST_FILTER_TIME_WINDOW);

        return strtolower($event->getType()) . '|' . $weaponType . '|' . $weaponName . '|' . $bucket;
    }

    private function pruneDisconnectDestructions(): void
    {
        if ($this->events === []) {
            return;
        }

        $remove = [];

        foreach ($this->events as $index => $event) {
            if ($event->getType() !== 'HasBeenDestroyed') {
                continue;
            }

            $classification = $this->classifyDestructionDisconnect($event);
            if ($classification === null) {
                continue;
            }

            $status = $classification['status'];

            if ($status === 'landed_disconnect') {
                $remove[$index] = true;
                $this->metrics['disconnect_destructions_pruned']++;

                if (isset($classification['landing'])) {
                    $landingEvent = $classification['landing'];
                    $delay = isset($classification['sinceLanding']) ? (float)$classification['sinceLanding'] : 0.0;
                    $this->annotateLandingDisconnect($landingEvent, $event, $delay);
                }

                $this->annotateRelatedDeparture($event, 'landed');
            } elseif ($status === 'midair_disconnect') {
                $event->addGraphLink('disconnectStatus', [
                    'status' => 'midair',
                    'reference' => $event->getId(),
                    'time' => $classification['destroyTime'] ?? $event->getMissionTime(),
                    'windowStart' => $classification['windowStart'] ?? null,
                    'role' => 'destruction',
                ]);
                $this->metrics['disconnect_midair_flagged']++;
                $this->annotateRelatedDeparture($event, 'midair');
            }
        }

        if ($remove === []) {
            return;
        }

        $filtered = [];
        foreach ($this->events as $idx => $event) {
            if (isset($remove[$idx])) {
                continue;
            }
            $filtered[] = $event;
        }

        $this->events = $filtered;
        $this->rebuildEventIndex();
    }

    /**
     * @return array{
     *     status: 'landed_disconnect'|'midair_disconnect',
     *     landing?: NormalizedEvent,
     *     sinceLanding?: float,
     *     destroyTime: float,
     *     windowStart?: float
     * }|null
     */
    private function classifyDestructionDisconnect(NormalizedEvent $event): ?array
    {
        $primary = $event->getPrimary();
        if ($primary === null || $event->getParent() !== null) {
            return null;
        }

        $destroyTime = $event->getMissionTime();
        $windowStart = $destroyTime - 180.0;

        if ($this->hasCombatAgainstTarget($primary, $windowStart, $destroyTime + 5.0)) {
            return null;
        }

        $landing = $this->findMostRecentEventForPrimary('HasLanded', $primary, $destroyTime);
        if ($landing !== null) {
            $landTime = $landing->getMissionTime();
            $sinceLanding = $destroyTime - $landTime;
            if ($sinceLanding <= 1500.0 && !$this->hasCombatAgainstTarget($primary, $landTime, $destroyTime + 5.0)) {
                return [
                    'status' => 'landed_disconnect',
                    'landing' => $landing,
                    'sinceLanding' => $sinceLanding,
                    'destroyTime' => $destroyTime,
                    'windowStart' => $landTime,
                ];
            }
        }

        return [
            'status' => 'midair_disconnect',
            'destroyTime' => $destroyTime,
            'windowStart' => $windowStart,
        ];
    }

    private function hasCombatAgainstTarget(array $primary, float $windowStart, float $windowEnd): bool
    {
        foreach ($this->eventIndex['HasBeenHitBy'] ?? [] as $hit) {
            $time = $hit->getMissionTime();
            if ($time < $windowStart || $time > $windowEnd) {
                continue;
            }

            if ($this->objectsComparable($hit->getPrimary(), $primary)) {
                return true;
            }
        }

        return false;
    }

    private function findMostRecentEventForPrimary(string $type, array $primary, float $beforeTime): ?NormalizedEvent
    {
        $candidates = $this->eventIndex[$type] ?? [];
        $best = null;
        $bestDelta = null;

        foreach ($candidates as $candidate) {
            $time = $candidate->getMissionTime();
            if ($time > $beforeTime) {
                continue;
            }

            if (!$this->objectsComparable($candidate->getPrimary(), $primary)) {
                continue;
            }

            $delta = $beforeTime - $time;
            if ($best === null || $delta < $bestDelta) {
                $best = $candidate;
                $bestDelta = $delta;
            }
        }

        return $best;
    }

    private function findNearestFutureEventForPrimary(string $type, array $primary, float $afterTime, float $window): ?NormalizedEvent
    {
        $candidates = $this->eventIndex[$type] ?? [];
        $best = null;
        $bestDelta = null;

        foreach ($candidates as $candidate) {
            $time = $candidate->getMissionTime();
            if ($time < $afterTime) {
                continue;
            }

            if (!$this->objectsComparable($candidate->getPrimary(), $primary)) {
                continue;
            }

            $delta = $time - $afterTime;
            if ($delta > $window) {
                continue;
            }

            if ($best === null || $delta < $bestDelta) {
                $best = $candidate;
                $bestDelta = $delta;
            }
        }

        return $best;
    }

    private function annotateRelatedDeparture(NormalizedEvent $destroyEvent, string $status): void
    {
        $primary = $destroyEvent->getPrimary();
        if ($primary === null) {
            return;
        }

        $departure = $this->findNearestFutureEventForPrimary('HasLeftTheArea', $primary, $destroyEvent->getMissionTime(), 60.0);
        if ($departure === null) {
            return;
        }

        $departure->addGraphLink('disconnectStatus', [
            'status' => $status,
            'reference' => $destroyEvent->getId(),
            'time' => $destroyEvent->getMissionTime(),
            'role' => 'departure',
        ]);
    }

    private function annotateLandingDisconnect(NormalizedEvent $landing, NormalizedEvent $destroyEvent, float $delaySinceLanding): void
    {
        $landing->addGraphLink('disconnectStatus', [
            'status' => 'landed',
            'reference' => $destroyEvent->getId(),
            'time' => $destroyEvent->getMissionTime(),
            'delay' => $delaySinceLanding,
            'role' => 'landing',
        ]);
    }

    private function annotateEvents(): void
    {
        if ($this->events === []) {
            return;
        }

        $this->flagOrphanEvents();
        $this->recomputeEventConfidence();
    }

    private function flagOrphanEvents(): void
    {
        $firingOrphans = 0;
        $killOrphans = 0;

        foreach ($this->events as $event) {
            $type = $event->getType();
            if ($type === 'HasFired') {
                if ($this->evaluateFiringOrphan($event)) {
                    $firingOrphans++;
                }
            } elseif ($type === 'HasBeenDestroyed') {
                if ($this->evaluateDestructionOrphan($event)) {
                    $killOrphans++;
                }
            }
        }

        $this->metrics['orphan_hasfired_without_hit'] = $firingOrphans;
        $this->metrics['orphan_kill_without_launch'] = $killOrphans;
    }

    private function evaluateFiringOrphan(NormalizedEvent $event): bool
    {
        $secondary = $event->getSecondary();
        $window = $this->resolveWeaponWindow($secondary);
        $launchTime = $event->getMissionTime();
        $found = false;

        foreach ($this->events as $candidate) {
            $candidateType = $candidate->getType();
            if ($candidateType !== 'HasBeenHitBy' && $candidateType !== 'HasBeenDestroyed') {
                continue;
            }

            $delta = $candidate->getMissionTime() - $launchTime;
            if ($delta < 0.0 || $delta > $window) {
                continue;
            }

            if ($this->firingMatchesImpact($event, $candidate)) {
                $found = true;
                break;
            }
        }

        if (!$found) {
            $event->addGraphLink('orphanStatus', [
                'type' => 'HasFired',
                'reason' => 'no-impact-within-window',
                'window' => $window,
            ]);
            return true;
        }

        return false;
    }

    private function evaluateDestructionOrphan(NormalizedEvent $event): bool
    {
        $lookback = 180.0;
        $missionTime = $event->getMissionTime();
        $target = $event->getPrimary();
        $found = false;

        foreach ($this->events as $candidate) {
            $delta = $missionTime - $candidate->getMissionTime();
            if ($delta <= 0.0 || $delta > $lookback) {
                continue;
            }

            $candidateType = $candidate->getType();
            if ($candidateType === 'HasBeenHitBy' && $this->objectsComparable($target, $candidate->getPrimary())) {
                $found = true;
                break;
            }

            if ($candidateType === 'HasFired' && $this->firingMatchesImpact($candidate, $event)) {
                $found = true;
                break;
            }
        }

        if (!$found) {
            $event->addGraphLink('orphanStatus', [
                'type' => 'HasBeenDestroyed',
                'reason' => 'no-launch-context',
                'lookback' => $lookback,
            ]);
            return true;
        }

        return false;
    }

    private function firingMatchesImpact(NormalizedEvent $firing, NormalizedEvent $impact): bool
    {
        $weapon = $firing->getSecondary();
        if ($weapon === null) {
            return false;
        }

        $impactWeapon = $impact->getSecondary();
        $fireKey = $this->weaponKey($weapon);
        $impactKey = $this->weaponKey($impactWeapon);

        if ($fireKey !== null && $impactKey !== null && $fireKey === $impactKey) {
            return true;
        }

        $impactParent = $impact->getParent();
        if ($impactParent !== null && $this->objectsComparable($firing->getPrimary(), $impactParent)) {
            return true;
        }

        if ($impactWeapon !== null && isset($impactWeapon['Parent'])) {
            $weaponParent = ['ID' => trim((string)$impactWeapon['Parent'])];
            if ($this->objectsComparable($firing->getPrimary(), $weaponParent)) {
                return true;
            }
        }

        return false;
    }

    private function weaponKey(?array $weapon): ?string
    {
        if ($weapon === null) {
            return null;
        }

        $name = strtolower(trim((string)($weapon['Name'] ?? '')));
        $type = strtolower(trim((string)($weapon['Type'] ?? '')));
        $id = strtolower(trim((string)($weapon['ID'] ?? '')));

        if ($name !== '') {
            return $name;
        }

        if ($type !== '') {
            return $type;
        }

        if ($id !== '') {
            return $id;
        }

        return null;
    }

    private function resolveWeaponWindow(?array $weapon): float
    {
        if ($weapon === null) {
            return 60.0;
        }

        $type = strtolower((string)($weapon['Type'] ?? ''));
        $name = strtolower((string)($weapon['Name'] ?? ''));

        if (str_contains($type, 'missile') || str_contains($name, 'missile') || str_contains($name, 'harm') || str_contains($name, '530')) {
            return 120.0;
        }

        if (str_contains($type, 'rocket') || str_contains($name, 'rocket')) {
            return 45.0;
        }

        if (str_contains($type, 'bomb') || str_contains($name, 'bomb')) {
            return 90.0;
        }

        if (str_contains($type, 'shell') || str_contains($name, 'shell') || str_contains($type, 'gun')) {
            return 8.0;
        }

        return 60.0;
    }

    private function recomputeEventConfidence(): void
    {
        $mixedCoalitions = 0;

        foreach ($this->events as $event) {
            $event->recomputeConfidence($this->recordingReliability, $this->recordingCoalitions);

            $coalitions = [];
            if (method_exists($event, 'getCoalitionEvidence')) {
                $coalitions = $event->getCoalitionEvidence();
            } else {
                $snapshot = $event->toArray();
                if (isset($snapshot['CoalitionEvidence']) && is_array($snapshot['CoalitionEvidence'])) {
                    $coalitions = $snapshot['CoalitionEvidence'];
                }
            }

            if (isset($coalitions['allies']) && isset($coalitions['enemies'])) {
                $mixedCoalitions++;
            }
        }

        $this->metrics['mixed_coalition_evidence'] = $mixedCoalitions;
    }

    private function findRecentHit(NormalizedEvent $event): ?NormalizedEvent
    {
        $primary = $event->getPrimary();
        if ($primary === null) {
            return null;
        }

        $targetKey = $this->objectKey($primary);
        if ($targetKey === null) {
            return null;
        }

        $best = null;
        $bestDelta = null;
        foreach ($this->eventIndex['HasBeenHitBy'] ?? [] as $candidate) {
            if ($candidate->getParent() === null) {
                continue;
            }

            $candidateKey = $this->objectKey($candidate->getPrimary());
            if ($candidateKey !== $targetKey) {
                continue;
            }

            $delta = $event->getMissionTime() - $candidate->getMissionTime();
            if ($delta < 0.0 || $delta > $this->hitBacktrackWindow) {
                continue;
            }

            if ($best === null || $delta < $bestDelta) {
                $best = $candidate;
                $bestDelta = $delta;
            }
        }

        return $best;
    }

    private function shouldPruneDueToCoalitionMismatch(NormalizedEvent $event): bool
    {
        if ($this->isCoalitionMismatchExemptEvent($event)) {
            return false;
        }

        $objects = [
            'primary' => $event->getPrimary(),
            'secondary' => $event->getSecondary(),
            'parent' => $event->getParent(),
        ];

        $signalsByRole = [];
        foreach ($objects as $role => $object) {
            if ($object === null) {
                continue;
            }

            $signals = $this->deriveCoalitionSignals($object);
            if ($this->hasFactionalIconMismatch($signals)) {
                return true;
            }

            $signalsByRole[$role] = $signals;
        }

        if (count($signalsByRole) <= 1) {
            return false;
        }

        $hasAllies = false;
        $hasEnemies = false;

        foreach ($signalsByRole as $signals) {
            $coalition = $signals['effective'];
            if ($coalition === null) {
                continue;
            }

            if ($coalition === 'allies') {
                $hasAllies = true;
            } elseif ($coalition === 'enemies') {
                $hasEnemies = true;
            }

            if ($hasAllies && $hasEnemies) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{declared: ?string, icon: ?string, effective: ?string} $signals
     */
    private function hasFactionalIconMismatch(array $signals): bool
    {
        if ($signals['icon'] === null || $signals['declared'] === null) {
            return false;
        }

        if ($signals['icon'] !== 'allies' && $signals['icon'] !== 'enemies') {
            return false;
        }

        return $signals['declared'] !== $signals['icon'];
    }

    private function isCoalitionMismatchExemptEvent(NormalizedEvent $event): bool
    {
        $type = strtolower($event->getType());

        return in_array($type, self::COALITION_MISMATCH_EXEMPT_TYPES, true);
    }

    /**
     * @return array{declared: ?string, icon: ?string, effective: ?string}
     */
    private function deriveCoalitionSignals(array $object): array
    {
        $declared = null;
        if (isset($object['Coalition']) && $object['Coalition'] !== '') {
            $declared = $this->normalizeCoalitionString((string)$object['Coalition']);
        }

        $iconKey = $this->buildIconKey($object);
        $iconCoalition = null;
        if ($iconKey !== null && preg_match('/_(allies|enemies|neutral)\.gif$/', $iconKey, $matches)) {
            $iconCoalition = strtolower($matches[1]);
        }

        $effective = $declared;
        if ($effective === null && $iconCoalition !== null && $iconCoalition !== 'neutral') {
            $effective = $iconCoalition;
        }

        return [
            'declared' => $declared,
            'icon' => $iconCoalition,
            'effective' => $effective,
        ];
    }

    private function normalizeCoalitionString(string $coalition): ?string
    {
        $value = strtolower(trim($coalition));
        if ($value === '') {
            return null;
        }

        return match ($value) {
            'blue', 'blu', 'ally', 'allies', 'friendly', 'friendlies' => 'allies',
            'red', 'enemy', 'enemies', 'hostile', 'hostiles' => 'enemies',
            'neutral', 'neutrals' => 'neutral',
            default => $value,
        };
    }

    private function buildIconKey(array $object): ?string
    {
        if (!isset($object['Type'])) {
            return null;
        }

        $typeKey = str_replace(['/', ' '], ['-', '_'], (string)$object['Type']);
        $coalition = isset($object['Coalition']) && $object['Coalition'] !== ''
            ? (string)$object['Coalition']
            : 'Neutral';

        return strtolower($typeKey . '_' . $coalition . '.gif');
    }

    private function computeRecordingMetadata(): void
    {
        if ($this->recordings === []) {
            $this->recordingReliability = [];
            return;
        }

        $maxEvents = 0;
        $maxDuration = 0.0;

        foreach ($this->recordings as $recording) {
            $this->recordingCoalitions[$recording->id] = $recording->dominantCoalition;
            if ($recording->rawEventCount > $maxEvents) {
                $maxEvents = $recording->rawEventCount;
            }
            if ($recording->duration !== null && $recording->duration > $maxDuration) {
                $maxDuration = $recording->duration;
            }
        }

        $maxEvents = $maxEvents > 0 ? $maxEvents : 1;
        $maxDuration = $maxDuration > 0.0 ? $maxDuration : 1.0;

        foreach ($this->recordings as $recording) {
            $eventScore = $recording->rawEventCount / $maxEvents;
            $durationScore = $recording->duration !== null ? min(1.0, $recording->duration / $maxDuration) : 0.6;
            $coverageScore = $this->computeCoverageScore($recording);

            $weight = (0.5 * $eventScore) + (0.3 * $durationScore) + (0.2 * $coverageScore);
            if ($recording->id === $this->baselineRecordingId) {
                $weight = max($weight, 0.9);
            }

            $this->recordingReliability[$recording->id] = max(0.35, min(1.0, round($weight, 3)));
        }
    }

    private function computeCoverageScore(SourceRecording $recording): float
    {
        if ($recording->startTime === null || $recording->endTime === null || $this->startTime === null || $this->endTime === null) {
            return 0.6;
        }

        $missionDuration = max(1.0, $this->endTime - $this->startTime);
        $coverage = ($recording->endTime - $recording->startTime) / $missionDuration;

        return max(0.0, min(1.0, $coverage));
    }

    private function rebuildEventIndex(): void
    {
        $this->eventIndex = [];
        foreach ($this->events as $event) {
            $this->eventIndex[$event->getType()][] = $event;
        }

        $this->metrics['merged_events'] = count($this->events);
    }

    private function applyStartTimeConsensus(): void
    {
        if ($this->startTimeSamples === []) {
            return;
        }

        $consensus = $this->computeCongruentStartTime();
        if ($consensus !== null) {
            $this->startTime = $consensus;
        } elseif ($this->startTime === null) {
            $this->startTime = min($this->startTimeSamples);
        }

        $minimumEventTime = $this->getMinimumEventMissionTime();
        if ($minimumEventTime < 0.0) {
            $this->shiftAllEvents(-$minimumEventTime);
        }
    }

    private function computeCongruentStartTime(): ?float
    {
        $samples = array_filter(
            $this->startTimeSamples,
            static fn (float $value): bool => $value >= 0.0
        );

        if ($samples === []) {
            return null;
        }

        sort($samples);

        $count = count($samples);
        if ($count === 1) {
            return $samples[0];
        }

        $bestSize = 0;
        $bestIndex = 0;
        $left = 0;
        $tolerance = $this->missionTimeCongruenceTolerance;

        for ($right = 0; $right < $count; $right++) {
            while ($samples[$right] - $samples[$left] > $tolerance && $left < $right) {
                $left++;
            }

            $windowSize = $right - $left + 1;
            if (
                $windowSize > $bestSize
                || (
                    $windowSize === $bestSize
                    && $samples[$left] < $samples[$bestIndex]
                )
            ) {
                $bestSize = $windowSize;
                $bestIndex = $left;
            }
        }

        if ($bestSize <= 1) {
            return $samples[0];
        }

        return $samples[$bestIndex];
    }

    private function getMinimumEventMissionTime(): float
    {
        $minimum = 0.0;

        foreach ($this->events as $event) {
            $minimum = min($minimum, $event->getMissionTime());
        }

        return $minimum;
    }

    private function shiftAllEvents(float $delta): void
    {
        if ($delta === 0.0) {
            return;
        }

        foreach ($this->events as $event) {
            $event->shiftMissionTime($delta);
        }
    }

    private function resolveMissionName(?string $current, string $candidate): string
    {
        if ($current === null || trim($current) === '') {
            return $candidate;
        }

        if (trim($candidate) === '') {
            return $current;
        }

        return strlen($candidate) > strlen($current) ? $candidate : $current;
    }

    private function resolveStartTime(SourceRecording $recording): ?float
    {
        if ($recording->startTime === null) {
            return $this->startTime;
        }

        return $this->startTime === null ? $recording->startTime : min($this->startTime, $recording->startTime);
    }

    private function resolveEndTime(SourceRecording $recording): ?float
    {
        if ($recording->endTime === null) {
            return $this->endTime;
        }

        return $this->endTime === null ? $recording->endTime : max($this->endTime, $recording->endTime);
    }

    private function computeDuration(): float
    {
        if ($this->startTime === null || $this->endTime === null) {
            if ($this->events === []) {
                return 0.0;
            }

            $first = $this->events[0]->getMissionTime();
            $last = $this->events[count($this->events) - 1]->getMissionTime();

            return max(0.0, $last - $first);
        }

        return max(0.0, $this->endTime - $this->startTime);
    }
}
