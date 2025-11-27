<?php

declare(strict_types=1);

namespace EventGraph;

use RuntimeException;

use function abs;
use function array_key_exists;
use function array_key_first;
use function array_keys;
use function array_shift;
use function array_slice;
use function atan2;
use function count;
use function cos;
use function deg2rad;
use function explode;
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
use function sin;
use function sort;
use function sqrt;
use function spl_object_id;
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
        'hastakenoff',
        'haslanded',
        'hasenteredthearea',
        'hasleftthearea',
        'hasenteredparkingarea',
        'hasleftparkingarea',
        'hasenteredthezone',
        'hasleftthezone',
        'hasstarted',
        'hasstopped',
    ];
    private const COALITION_MISMATCH_EXEMPT_TYPES = [
        'hasbeenhitby',
        'hasbeendestroyed',
        'hasbeenkilled',
        'hasbeenshotdown',
        'hascrashed',
    ];
    private const WEAPON_INSTANCE_TYPES = [
        'hasbeenhitby',
        'hasbeendestroyed',
        'hasbeenshotdown',
    ];
    private const IDENTITY_HINT_TIME_WINDOW = 5.0;
    private const IDENTITY_HINT_DISTANCE_METERS = 2000.0;
    private const DUPLICATE_DEFAULT_WINDOW = 1.0;
    private const DUPLICATE_TIME_WINDOWS = [
        'hasfired' => 0.6,
        'hasbeenhitby' => 2.5,
        'hasbeendestroyed' => 1.2,
        'hasbeenshotdown' => 1.2,
        'hasbeenkilled' => 1.2,
        'hascrashed' => 2.0,
        'hastakenoff' => 15.0,
        'haslanded' => 20.0,
        'hasenteredthearea' => 45.0,
        'hasleftthearea' => 45.0,
        'hasenteredparkingarea' => 30.0,
        'hasleftparkingarea' => 30.0,
        'hasenteredthezone' => 45.0,
        'hasleftthezone' => 45.0,
        'hasbeenlockedby' => 2.0,
        'hasbeenlaunchedat' => 2.0,
        'hasbeenfiredat' => 2.0,
        'hasstopped' => 5.0,
        'hasstarted' => 5.0,
        'hasrepaired' => 10.0,
    ];
    private const TERMINAL_EVENT_WINDOW = 30.0;
    private const POST_RECONCILIATION_WINDOW = 5.0;
    private const TERMINAL_EVENT_TYPES = [
        'hasbeendestroyed' => true,
        'hasbeenshotdown' => true,
        'hasbeenkilled' => true,
        'hascrashed' => true,
    ];
    private const TERMINAL_CATEGORY_ACTIONS = [
        'missile' => ['hasenteredthearea', 'hasbeenhitby', 'hasbeendestroyed'],
        'bomb' => ['hasenteredthearea', 'hasbeenhitby', 'hasbeendestroyed'],
        'parachutist' => ['hasenteredthearea', 'hasbeendestroyed'],
        'ground' => ['hasbeenhitby', 'hasbeendestroyed'],
    ];
    private const OBJECT_CATEGORY_KEYWORDS = [
        'parachutist' => ['parachut'],
        'bomb' => ['bomb', 'mk-', 'gbu', 'rockeye', 'fab-', 'betab', 'cbu-', 'cluster'],
        'missile' => ['missile', 'aim-', 'agm-', 'r-', 'phoenix', 'sparrow', 'sidewinder', 'maverick', 'harm', 'super 530', 'rsam', 'lsam'],
        'ground' => ['tank', 'btr', 'bmp', 'sam', 'aaa', 'vehicle', 'truck', 'ural', 'gepard', 'shilka', 'sa-', 'moto', 'car', 'zsu', 'zu-', 'tor', 'buk', 'strela', 'patriot', 'howitzer'],
    ];
    private const HAS_FIRED_SUPPRESSION_WINDOW = 90.0;
    private const HAS_FIRED_BUCKET_SIZE = 1.0;
    private const COMPOSITE_SIGNATURE_BUCKET_SIZE = 5.0;
    private const COMPOSITE_SIGNATURE_TYPES = [
        'hasbeenhitby',
        'hasbeendestroyed',
        'hasbeenshotdown',
        'hasbeenkilled',
    ];

    private readonly string $language;
    private readonly float $timeTolerance;
    private readonly float $hitBacktrackWindow;
    private readonly float $anchorTolerance;
    private readonly int $anchorMinimumMatches;
    private readonly float $maxFallbackOffset;
    private readonly float $maxAnchorOffset;
    private readonly float $missionTimeCongruenceTolerance;
    private readonly float $anchorDecay;
    private readonly float $driftSampleWindow;
    private readonly float $coalitionAlignmentWeight;

    /** @var list<SourceRecording> */
    private array $recordings = [];

    /** @var list<NormalizedEvent> */
    private array $events = [];

    /** @var array<string, list<NormalizedEvent>> */
    private array $eventIndex = [];

    /** @var array<string, NormalizedEvent> */
    private array $compositeSignatureIndex = [];

    /** @var array<string, list<NormalizedEvent>> */
    private array $pendingEvents = [];

    /** @var array<string, float> */
    private array $recordingOffsets = [];

    /** @var array<string, string> */
    private array $offsetStrategies = [];

    /** @var array<string, float> */
    private array $recordingReliability = [];

    /** @var array<string, float> */
    private array $alignmentConfidence = [];

    /** @var array<string, string|null> */
    private array $recordingCoalitions = [];
    /** @var array<string, bool> */
    private array $excludedRecordings = [];

    private ?string $baselineRecordingId = null;
    private ?string $missionName = null;
    private ?float $startTime = null;
    private ?float $endTime = null;
    private bool $built = false;
    /** @var list<float> */
    private array $startTimeSamples = [];
    /** @var array<string, list<array{primary:?array, identity:ObjectIdentity, position:array|null, time:float}>> */
    private array $identityHints = [];

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
        'ambiguous_events_quarantined' => 0,
        'identity_salvaged' => 0,
        'composite_signatures_emitted' => 0,
        'composite_signature_merges' => 0,
        'composite_signature_missing_target' => 0,
        'composite_signature_missing_weapon' => 0,
        'post_inference_merges' => 0,
        'target_signature_merges' => 0,
        // Alignment diagnostics
        'alignment_confidence_avg' => 0.0,
        'alignment_conflicts' => 0,
        'coalition_alignment_matches' => 0,
        'coalition_alignment_mismatches' => 0,
        'anchor_match_total' => 0,
        // Coverage statistics
        'coverage_gap_percent' => 0.0,
        'coverage_overlap_percent' => 0.0,
        'incongruent_recordings' => 0,
        'incongruent_events_pruned' => 0,
        'incongruent_prune_candidates' => 0,
        'incongruent_rescued' => 0,
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

        $this->maxFallbackOffset = isset($options['max_fallback_offset']) ? (float)$options['max_fallback_offset'] : 7200.0;
        $defaultAnchorOffset = max($this->maxFallbackOffset, 28800.0);
        $this->maxAnchorOffset = isset($options['max_anchor_offset']) ? (float)$options['max_anchor_offset'] : $defaultAnchorOffset;
        $this->missionTimeCongruenceTolerance = isset($options['mission_time_congruence_tolerance'])
            ? max(0.0, (float)$options['mission_time_congruence_tolerance'])
            : 1800.0;

        // Phase 1 alignment tuning options
        $this->anchorDecay = isset($options['anchor_decay'])
            ? max(0.0, min(1.0, (float)$options['anchor_decay']))
            : 0.95;
        $this->driftSampleWindow = isset($options['drift_sample_window'])
            ? max(1.0, (float)$options['drift_sample_window'])
            : 60.0;
        $this->coalitionAlignmentWeight = isset($options['coalition_alignment_weight'])
            ? max(0.0, min(1.0, (float)$options['coalition_alignment_weight']))
            : 0.15;
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
        $this->reconcileDestructionEvents();
        $this->collapseTargetSignatureDuplicates();
        $this->collapseAITargetDuplicates();
        $this->collapseHumanTargetDuplicates();
        $this->enrichHumanDestructionsFromHits();
        $this->applyPostMergeFilters();
        $this->pruneDisconnectDestructions();
        $this->annotateEvents();

        $this->metrics['merged_events'] = count($this->events);
        $this->built = true;
    }

    public function toAggregatedMission(): AggregatedMission
    {
        $this->build();
        $coverageWindows = $this->buildRecordingCoverageWindows();
        $alignedWindows = [];
        foreach ($this->recordings as $recording) {
            $alignedWindows[$recording->id] = $this->computeAlignedWindow($recording, $coverageWindows);
        }
        $this->expandMissionBoundsWithAlignedWindows($alignedWindows);

        // Compute coverage statistics after windows are finalized
        $coverageStats = $this->computeCoverageStatistics($alignedWindows);

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
            $offset = $this->recordingOffsets[$recording->id] ?? 0.0;
            $window = $alignedWindows[$recording->id] ?? ['start' => null, 'end' => null, 'duration' => null];
            $sourceCoverage = $coverageStats['perSource'][$recording->id] ?? null;

            $mission->addSource([
                'id' => $recording->id,
                'filename' => $recording->filename,
                'missionName' => $recording->missionName,
                'author' => $recording->author,
                'startTime' => $recording->startTime,
                'duration' => $recording->duration,
                'alignedStartTime' => $window['start'],
                'alignedEndTime' => $window['end'],
                'alignedDuration' => $window['duration'],
                'events' => count($recording->events),
                'offset' => $offset,
                'baseline' => $recording->id === $this->baselineRecordingId,
                'offsetStrategy' => $this->offsetStrategies[$recording->id] ?? null,
                'reliability' => $this->recordingReliability[$recording->id] ?? 1.0,
                'alignmentConfidence' => $this->alignmentConfidence[$recording->id] ?? 0.5,
                'dominantCoalition' => $this->recordingCoalitions[$recording->id] ?? null,
                'coveragePercent' => $sourceCoverage['coveragePercent'] ?? null,
            ]);
        }

        // Add coverage statistics to metrics
        $this->metrics['coverage_stats'] = [
            'totalCoverage' => $coverageStats['totalCoverage'],
            'gapPercent' => $coverageStats['gapPercentage'],
            'overlapPercent' => $coverageStats['overlapPercentage'],
            'sourceCount' => $coverageStats['sourceCount'],
        ];

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
        $this->alignmentConfidence = [];

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
            $this->alignmentConfidence[$baselineId] = 1.0; // Baseline has full confidence
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
                    $this->alignmentConfidence[$recordingId] = $best['confidence'] ?? 0.5;

                    if ($best['strategy'] === 'anchor') {
                        $label = 'anchor';
                        if ($best['reference'] !== $baselineId) {
                            $label .= ' vs ' . $best['reference'];
                        }
                        $label .= ' (delta=' . round($best['offset'], 3) . ', matches=' . $best['matches'];
                        $label .= ', conf=' . round($best['confidence'] ?? 0.5, 2) . ')';
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
                    $this->alignmentConfidence[$recordingId] = 0.1; // Low confidence for unaligned
                    $this->metrics['alignment_conflicts']++;
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
        $this->coalesceDuplicateEvents();
        $this->coalesceTerminalEvents();
        $this->metrics['merged_events'] = count($this->events);
        $this->pruneIncongruentSources();
    }

    private function coalesceDuplicateEvents(): void
    {
        if ($this->events === []) {
            return;
        }

        $groups = [];
        $deduped = [];
        $hasFiredBuckets = [];

        foreach ($this->events as $event) {
            $typeKey = strtolower($event->getType());
            if ($typeKey === 'hasfired') {
                $primaryKey = $this->buildLooseObjectKey($event->getPrimary())
                    ?? $this->buildObjectKey($event->getPrimary());
                $weaponKey = $this->buildWeaponGuidKey($event->getSecondary())
                    ?? $this->buildLooseObjectKey($event->getSecondary());
                if ($primaryKey !== null && $weaponKey !== null) {
                    $timeBucket = $this->getHasFiredTimeBucket($event->getMissionTime());
                    $bucketKey = $primaryKey . '|' . $weaponKey . '|tb:' . $timeBucket;
                    $bucket = $hasFiredBuckets[$bucketKey] ?? [];

                    $merged = false;
                    foreach ($bucket as $existing) {
                        if (abs($existing->getMissionTime() - $event->getMissionTime()) <= self::HAS_FIRED_SUPPRESSION_WINDOW) {
                            // Check coalition compatibility before merging
                            if ($this->areEventsCoalitionCompatible($existing, $event)) {
                                $existing->mergeWith($event);
                                $this->metrics['duplicates_suppressed']++;
                                $merged = true;
                                break;
                            }
                        }
                    }

                    if ($merged) {
                        continue;
                    }

                    $hasFiredBuckets[$bucketKey][] = $event;
                }
            }

            $window = self::DUPLICATE_TIME_WINDOWS[$typeKey] ?? self::DUPLICATE_DEFAULT_WINDOW;
            if ($window <= 0.0) {
                $deduped[] = $event;
                continue;
            }

            // Special handling for weapon logistics events (enter/leave area)
            // Weapons often have different IDs across recordings, so we use loose keys
            // but enforce a tight time window to avoid merging distinct shots
            $isWeaponLogistics = ($typeKey === 'hasenteredthearea' || $typeKey === 'hasleftthearea')
                && $this->isWeaponObject($event->getPrimary());

            if ($typeKey === 'hasfired') {
                $primaryKey = $this->buildLooseObjectKey($event->getPrimary())
                    ?? $this->buildObjectKey($event->getPrimary());
            } elseif ($isWeaponLogistics) {
                $primaryKey = $this->buildLooseObjectKey($event->getPrimary())
                    ?? $this->buildObjectKey($event->getPrimary());
                $window = 2.0; // Tight window for weapon spawns/despawns
            } else {
                $primaryKey = $this->buildObjectKey($event->getPrimary());
            }
            if ($primaryKey === null) {
                $deduped[] = $event;
                continue;
            }

            // For weapon-related events, try to use weapon GUID for better matching
            if ($typeKey === 'hasfired' || in_array($typeKey, self::WEAPON_INSTANCE_TYPES, true)) {
                $secondaryKey = $this->buildWeaponGuidKey($event->getSecondary())
                    ?? $this->buildLooseObjectKey($event->getSecondary())
                    ?? $this->buildObjectKey($event->getSecondary(), true);
            } elseif ($typeKey === 'hasfired') {
                $secondaryKey = $this->buildLooseObjectKey($event->getSecondary())
                    ?? $this->buildObjectKey($event->getSecondary(), true);
            } else {
                $secondaryKey = $this->buildObjectKey($event->getSecondary(), true);
            }
            if ($secondaryKey === null && !$this->allowsMissingSecondary($event->getType())) {
                $deduped[] = $event;
                continue;
            }

            $parentKey = $this->buildObjectKey($event->getParent());
            
            // Include coalition in index key for combat events to prevent cross-coalition merging
            $coalitionKey = '';
            if ($this->isCombatEvent($typeKey)) {
                $coalitionKey = $this->extractEventCoalition($event) ?? 'unknown';
            }
            
            $indexKey = $typeKey . '|' . $primaryKey . '|' . ($secondaryKey ?? 'none') . '|' . ($parentKey ?? 'noparent');
            if ($coalitionKey !== '') {
                $indexKey .= '|' . $coalitionKey;
            }

            if (!isset($groups[$indexKey])) {
                $groups[$indexKey] = [];
            }

            $merged = false;
            foreach ($groups[$indexKey] as $existing) {
                if (abs($existing->getMissionTime() - $event->getMissionTime()) <= $window) {
                    // Additional coalition compatibility check for combat events
                    if (!$this->isCombatEvent($typeKey) || $this->areEventsCoalitionCompatible($existing, $event)) {
                        $existing->mergeWith($event);
                        $this->metrics['duplicates_suppressed']++;
                        $merged = true;
                        break;
                    }
                }
            }

            if (!$merged) {
                $groups[$indexKey][] = $event;
                $deduped[] = $event;
            }
        }

        if (count($deduped) === count($this->events)) {
            $this->rebuildEventIndex();
            return;
        }

        $this->events = $deduped;
        $this->rebuildEventIndex();
    }

    /**
     * Build a key from weapon GUID (ID field) for precise duplicate matching.
     */
    private function buildWeaponGuidKey(?array $object): ?string
    {
        if ($object === null) {
            return null;
        }

        // Weapons typically have ID fields as GUIDs
        if (isset($object['ID'])) {
            $id = strtolower(trim((string)$object['ID']));
            if ($id !== '') {
                return 'guid:' . $id;
            }
        }

        return null;
    }

    /**
     * Check if two events have compatible coalitions for merging.
     * Returns true if coalitions match, are both unknown, or one is unknown.
     */
    private function areEventsCoalitionCompatible(NormalizedEvent $eventA, NormalizedEvent $eventB): bool
    {
        $coalitionA = $this->extractEventCoalition($eventA);
        $coalitionB = $this->extractEventCoalition($eventB);

        // If either is unknown, allow merge (benefit of doubt)
        if ($coalitionA === null || $coalitionB === null) {
            return true;
        }

        return strtolower($coalitionA) === strtolower($coalitionB);
    }

    /**
     * Extract coalition from event's primary object.
     */
    private function extractEventCoalition(NormalizedEvent $event): ?string
    {
        $primary = $event->getPrimary();
        if ($primary === null || !isset($primary['Coalition'])) {
            return null;
        }

        $coalition = trim((string)$primary['Coalition']);
        return $coalition !== '' ? $coalition : null;
    }

    /**
     * Check if an event type is a combat event that should consider coalition.
     */
    private function isCombatEvent(string $typeKey): bool
    {
        return in_array($typeKey, [
            'hasfired',
            'hasbeenhitby',
            'hasbeendestroyed',
            'hasbeenshotdown',
            'hasbeenkilled',
            'hascrashed',
        ], true);
    }

    private function coalesceTerminalEvents(): void
    {
        if ($this->events === []) {
            return;
        }

        $buckets = [];
        foreach ($this->events as $event) {
            $typeKey = strtolower($event->getType());
            $category = $this->classifyObjectCategory($event->getPrimary());
            $isTerminal = isset(self::TERMINAL_EVENT_TYPES[$typeKey]);
            if (!$isTerminal && $category !== null) {
                $categoryActions = self::TERMINAL_CATEGORY_ACTIONS[$category] ?? [];
                if (in_array($typeKey, $categoryActions, true)) {
                    $isTerminal = true;
                }
            }

            if (!$isTerminal) {
                continue;
            }

            $primaryKey = $this->buildAugmentedObjectKey($event->getPrimary());
            if ($primaryKey === null) {
                continue;
            }

            $bucketKey = $typeKey . '|' . $primaryKey;
            $buckets[$bucketKey][] = $event;
        }

        if ($buckets === []) {
            return;
        }

        $remove = [];

        foreach ($buckets as $events) {
            if (count($events) <= 1) {
                continue;
            }

            usort($events, static fn (NormalizedEvent $left, NormalizedEvent $right): int => $left->getMissionTime() <=> $right->getMissionTime());

            $clusterAnchor = array_shift($events);
            if ($clusterAnchor === null) {
                continue;
            }

            foreach ($events as $candidate) {
                $delta = abs($candidate->getMissionTime() - $clusterAnchor->getMissionTime());
                if ($delta <= self::TERMINAL_EVENT_WINDOW) {
                    $clusterAnchor->mergeWith($candidate);
                    $remove[spl_object_id($candidate)] = true;
                    $this->metrics['duplicates_suppressed']++;
                } else {
                    $clusterAnchor = $candidate;
                }
            }
        }

        if ($remove === []) {
            return;
        }

        $filtered = [];
        foreach ($this->events as $event) {
            if (isset($remove[spl_object_id($event)])) {
                continue;
            }
            $filtered[] = $event;
        }

        $this->events = $filtered;
        $this->rebuildEventIndex();
    }

    private function buildObjectKey(?array $object, bool $relaxed = false): ?string
    {
        if ($object === null) {
            return null;
        }

        $identity = ObjectIdentity::forObject($object);
        if ($identity !== null) {
            if ($relaxed && $identity->getTier() === ObjectIdentity::TIER_ID) {
                $fallback = ObjectIdentity::buildFallbackSignature($object);
                if ($fallback !== null) {
                    return $fallback;
                }
            }

            return $identity->getKey();
        }

        return ObjectIdentity::buildFallbackSignature($object);
    }

    private function buildAugmentedObjectKey(?array $object): ?string
    {
        $key = $this->buildObjectKey($object);
        if ($key !== null) {
            return $key;
        }

        return $this->buildLooseObjectKey($object);
    }

    private function buildLooseObjectKey(?array $object): ?string
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
                return 'id:' . $id;
            }
        }

        return null;
    }

    private function classifyObjectCategory(?array $object): ?string
    {
        if ($object === null) {
            return null;
        }

        $segments = [];
        foreach (['Type', 'Name', 'Group', 'Pilot'] as $field) {
            if (!isset($object[$field])) {
                continue;
            }

            $value = strtolower(trim((string)$object[$field]));
            if ($value !== '') {
                $segments[] = $value;
            }
        }

        if ($segments === []) {
            return null;
        }

        $haystack = trim(implode(' ', $segments));
        if ($haystack === '') {
            return null;
        }

        foreach (['parachutist', 'bomb', 'missile', 'ground'] as $category) {
            $keywords = self::OBJECT_CATEGORY_KEYWORDS[$category] ?? null;
            if ($keywords === null) {
                continue;
            }

            if ($this->stringContainsAny($haystack, $keywords)) {
                return $category;
            }
        }

        return null;
    }

    /**
     * @param list<string> $needles
     */
    private function stringContainsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle === '') {
                continue;
            }

            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function addOrMerge(NormalizedEvent $candidate): void
    {
        $this->enrichEventIdentity($candidate);

        if ($this->mergeByCompositeSignature($candidate)) {
            return;
        }

        if ($this->shouldQuarantineEvent($candidate)) {
            $this->metrics['ambiguous_events_quarantined']++;
            return;
        }

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
        if ($this->isSameWeaponInstance($left, $right)) {
            return true;
        }

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

    private function isSameWeaponInstance(NormalizedEvent $left, NormalizedEvent $right): bool
    {
        $type = strtolower($left->getType());
        if (!in_array($type, self::WEAPON_INSTANCE_TYPES, true)) {
            return false;
        }

        $leftKey = $this->weaponInstanceKey($left->getSecondary());
        if ($leftKey === null) {
            return false;
        }

        $rightKey = $this->weaponInstanceKey($right->getSecondary());
        if ($rightKey === null || $leftKey !== $rightKey) {
            return false;
        }

        if (!$this->objectsComparable($left->getParent(), $right->getParent())) {
            return false;
        }

        $delta = abs($left->getMissionTime() - $right->getMissionTime());
        $window = max(6.0, min(180.0, $this->resolveWeaponWindow($left->getSecondary())));

        return $delta <= $window;
    }

    private function objectsComparable(?array $a, ?array $b): bool
    {
        if ($a === null && $b === null) {
            return true;
        }

        if ($a === null || $b === null) {
            return false;
        }

        $identityA = ObjectIdentity::forObject($a);
        $identityB = ObjectIdentity::forObject($b);

        if ($identityA === null || $identityB === null) {
            return false;
        }

        return $identityA->getKey() === $identityB->getKey();
    }

    private function enrichEventIdentity(NormalizedEvent $event): void
    {
        $primary = $event->getPrimary();
        if ($primary === null) {
            return;
        }

        $identity = ObjectIdentity::forObject($primary);
        if ($identity !== null && $identity->isHardSignal()) {
            $this->rememberIdentityHint($event, $identity);
            return;
        }

        if ($this->applyIdentityHint($event)) {
            $updatedIdentity = ObjectIdentity::forObject($event->getPrimary());
            if ($updatedIdentity !== null && $updatedIdentity->isHardSignal()) {
                $this->rememberIdentityHint($event, $updatedIdentity);
            }
        }
    }

    private function rememberIdentityHint(NormalizedEvent $event, ObjectIdentity $identity): void
    {
        if (!$identity->isHardSignal()) {
            return;
        }

        $signature = ObjectIdentity::buildFallbackSignature($event->getPrimary());
        if ($signature === null) {
            return;
        }

        $this->identityHints[$signature][] = [
            'identity' => $identity,
            'primary' => $event->getPrimary(),
            'position' => $event->getPosition(),
            'time' => $event->getMissionTime(),
        ];

        if (count($this->identityHints[$signature]) > 12) {
            array_shift($this->identityHints[$signature]);
        }
    }

    private function applyIdentityHint(NormalizedEvent $event): bool
    {
        $match = $this->matchIdentityHint($event);
        if ($match !== null) {
            $event->overwritePrimary($match['primary'], $match['reason']);
            $this->metrics['identity_salvaged']++;
            return true;
        }

        $existing = $this->matchExistingEventsForIdentity($event);
        if ($existing !== null) {
            $event->overwritePrimary($existing, 'identity-salvaged-existing');
            $this->metrics['identity_salvaged']++;
            return true;
        }

        return false;
    }

    private function matchIdentityHint(NormalizedEvent $event): ?array
    {
        $signature = ObjectIdentity::buildFallbackSignature($event->getPrimary());
        if ($signature === null || !isset($this->identityHints[$signature])) {
            return null;
        }

        $time = $event->getMissionTime();
        $position = $event->getPosition();

        for ($i = count($this->identityHints[$signature]) - 1; $i >= 0; $i--) {
            $hint = $this->identityHints[$signature][$i];
            if (!$this->withinIdentityContext($time, $position, $hint['time'], $hint['position'])) {
                continue;
            }

            if (!is_array($hint['primary'])) {
                continue;
            }

            return [
                'primary' => $hint['primary'],
                'reason' => 'identity-hint-context',
            ];
        }

        return null;
    }

    private function matchExistingEventsForIdentity(NormalizedEvent $event): ?array
    {
        $signature = ObjectIdentity::buildFallbackSignature($event->getPrimary());
        if ($signature === null) {
            return null;
        }

        $time = $event->getMissionTime();
        $position = $event->getPosition();

        for ($i = count($this->events) - 1; $i >= 0; $i--) {
            $existing = $this->events[$i];
            $existingPrimary = $existing->getPrimary();
            if ($existingPrimary === null) {
                continue;
            }

            $identity = ObjectIdentity::forObject($existingPrimary);
            if ($identity === null || !$identity->isHardSignal()) {
                continue;
            }

            $existingSignature = ObjectIdentity::buildFallbackSignature($existingPrimary);
            if ($existingSignature !== $signature) {
                continue;
            }

            if (!$this->withinIdentityContext($time, $position, $existing->getMissionTime(), $existing->getPosition())) {
                continue;
            }

            return $existingPrimary;
        }

        return null;
    }

    private function withinIdentityContext(float $time, ?array $position, float $referenceTime, ?array $referencePosition): bool
    {
        if (abs($time - $referenceTime) > self::IDENTITY_HINT_TIME_WINDOW) {
            return false;
        }

        if ($position === null || $referencePosition === null) {
            return true;
        }

        $distance = $this->calculateDistanceMeters($position, $referencePosition);
        if ($distance === null) {
            return true;
        }

        return $distance <= self::IDENTITY_HINT_DISTANCE_METERS;
    }

    private function calculateDistanceMeters(?array $a, ?array $b): ?float
    {
        if ($a === null || $b === null) {
            return null;
        }

        if (!isset($a['latitude'], $a['longitude'], $b['latitude'], $b['longitude'])) {
            return null;
        }

        $lat1 = deg2rad((float)$a['latitude']);
        $lat2 = deg2rad((float)$b['latitude']);
        $deltaLat = $lat2 - $lat1;
        $deltaLon = deg2rad((float)$b['longitude'] - (float)$a['longitude']);

        $sinLat = sin($deltaLat / 2);
        $sinLon = sin($deltaLon / 2);

        $aTerm = $sinLat * $sinLat + cos($lat1) * cos($lat2) * $sinLon * $sinLon;
        if ($aTerm > 1.0) {
            $aTerm = 1.0;
        } elseif ($aTerm < 0.0) {
            $aTerm = 0.0;
        }

        $c = 2 * atan2(sqrt($aTerm), sqrt(1 - $aTerm));

        $distance = 6371000.0 * $c;

        return $distance;
    }

    private function shouldQuarantineEvent(NormalizedEvent $event): bool
    {
        $primary = $event->getPrimary();
        if ($primary === null) {
            return false;
        }

        $primaryIdentity = ObjectIdentity::forObject($primary);
        if ($primaryIdentity !== null && $primaryIdentity->isHardSignal()) {
            return false;
        }

        $parentIdentity = ObjectIdentity::forObject($event->getParent());
        if ($parentIdentity !== null && $parentIdentity->isHardSignal()) {
            return false;
        }

        $weaponKey = $this->weaponInstanceKey($event->getSecondary());
        if ($weaponKey !== null) {
            return false;
        }

        return true;
    }

    private function secondaryComparable(NormalizedEvent $left, NormalizedEvent $right): bool
    {
        $secondaryLeft = $left->getSecondary();
        $secondaryRight = $right->getSecondary();

        if ($this->objectsComparable($secondaryLeft, $secondaryRight)) {
            return true;
        }

        if ($this->objectsSoftComparable($secondaryLeft, $secondaryRight)) {
            return true;
        }

        if (!$this->allowsMissingSecondary($left->getType())) {
            return false;
        }

        $leftMissing = $this->isObjectEffectivelyMissing($secondaryLeft);
        $rightMissing = $this->isObjectEffectivelyMissing($secondaryRight);

        if ($leftMissing && $rightMissing) {
            return true;
        }

        if ($leftMissing && !$rightMissing) {
            return true;
        }

        if ($rightMissing && !$leftMissing) {
            return true;
        }

        return false;
    }

    private function objectsSoftComparable(?array $a, ?array $b): bool
    {
        if ($a === null || $b === null) {
            return false;
        }

        $typeA = $a['Type'] ?? null;
        $typeB = $b['Type'] ?? null;

        if ($typeA === null || $typeB === null) {
            return false;
        }

        if ($typeA !== $typeB) {
            return false;
        }

        $nameA = $a['Name'] ?? null;
        $nameB = $b['Name'] ?? null;

        return $nameA === $nameB;
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
        $primaryIdentity = ObjectIdentity::forObject($event->getPrimary());
        if ($primaryIdentity === null) {
            return [];
        }

        $keys = [];
        $secondaryIdentity = ObjectIdentity::forObject($event->getSecondary());
        if ($secondaryIdentity !== null) {
            $keys[] = $event->getType() . '|' . $primaryIdentity->getKey() . '|' . $secondaryIdentity->getKey();
        }

        $keys[] = $event->getType() . '|' . $primaryIdentity->getKey();

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

        // STRONGLY prefer primary (small) offsets over fallback (large) offsets
        // Large offsets with many matches are likely false positives from repeated actions
        if ($bestPrimaryMedian !== null && $bestFallbackMedian !== null) {
            // If primary has enough matches, prefer it UNLESS fallback is overwhelming
            if ($bestPrimaryCount >= $this->anchorMinimumMatches) {
                // If fallback has significantly more matches (e.g. > 2x), it's likely the true offset
                // and the primary matches are just coincidental
                if ($bestFallbackCount > $bestPrimaryCount * 2) {
                    return ['offset' => $bestFallbackMedian, 'matches' => $bestFallbackCount];
                }
                return ['offset' => $bestPrimaryMedian, 'matches' => $bestPrimaryCount];
            }
            
            // Only use fallback if primary doesn't meet minimum matches
            // and fallback has significantly more matches (2x)
            if ($bestFallbackCount >= $bestPrimaryCount * 2) {
                return ['offset' => $bestFallbackMedian, 'matches' => $bestFallbackCount];
            }

            // Default to primary (smaller offset) when in doubt
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
                return [
                    'offset' => $fallback,
                    'strategy' => 'fallback-applied',
                    'matches' => 0,
                    'confidence' => 0.3,
                    'coalitionAligned' => null,
                ];
            }

            return [
                'offset' => 0.0,
                'strategy' => 'fallback-skipped',
                'matches' => 0,
                'confidence' => 0.1,
                'coalitionAligned' => null,
            ];
        }

        $baselineIndex = $this->buildAnchorIndex($baselineEvents);
        $anchorOffset = $this->findAnchorOffset($baselineIndex, $targetEvents);
        if ($anchorOffset !== null) {
            // NOTE: We intentionally do NOT add startTimeDiff here.
            // The MissionTime header in Tacview XML is notoriously unreliable -
            // different DCS clients record different start times for the same mission
            // (can vary by 2-3 hours!). The anchor offset from event matching is
            // the only reliable alignment method.

            // Calculate confidence based on number of matches and offset spread
            $matchCount = $anchorOffset['matches'];
            $matchConfidence = min(1.0, $matchCount / 10.0); // More matches = higher confidence
            $offsetMagnitude = abs($anchorOffset['offset']);
            $offsetConfidence = max(0.2, 1.0 - ($offsetMagnitude / $this->maxAnchorOffset));
            $confidence = (0.7 * $matchConfidence) + (0.3 * $offsetConfidence);

            // Check coalition alignment between recordings
            $coalitionAligned = $this->checkCoalitionAlignment($baselineId, $targetId);
            if ($coalitionAligned === true) {
                $this->metrics['coalition_alignment_matches']++;
            } elseif ($coalitionAligned === false) {
                $this->metrics['coalition_alignment_mismatches']++;
                $confidence *= 0.85; // Reduce confidence for misaligned coalitions
            }

            $this->metrics['anchor_match_total'] += $matchCount;

            return [
                'offset' => $anchorOffset['offset'],  // Pure anchor offset - no unreliable header time
                'strategy' => 'anchor',
                'matches' => $matchCount,
                'confidence' => round($confidence, 3),
                'coalitionAligned' => $coalitionAligned,
            ];
        }

        $fallback = $this->fallbackOffset($baselineId, $targetId);
        if ($fallback !== null) {
            $coalitionAligned = $this->checkCoalitionAlignment($baselineId, $targetId);
            return [
                'offset' => $fallback,
                'strategy' => 'fallback-applied',
                'matches' => 0,
                'confidence' => 0.4,
                'coalitionAligned' => $coalitionAligned,
            ];
        }

        return [
            'offset' => 0.0,
            'strategy' => 'fallback-skipped',
            'matches' => 0,
            'confidence' => 0.1,
            'coalitionAligned' => null,
        ];
    }

    /**
     * Check if two recordings have aligned (matching) dominant coalitions.
     * Returns true if same coalition, false if different, null if one or both unknown.
     */
    private function checkCoalitionAlignment(string $recordingIdA, string $recordingIdB): ?bool
    {
        $coalitionA = $this->recordingCoalitions[$recordingIdA] ?? null;
        $coalitionB = $this->recordingCoalitions[$recordingIdB] ?? null;

        if ($coalitionA === null || $coalitionB === null) {
            return null;
        }

        return strtolower($coalitionA) === strtolower($coalitionB);
    }

    private function fallbackOffset(string $baselineId, string $targetId): ?float
    {
        // DISABLED: The MissionTime header in Tacview XML files is unreliable.
        // Different DCS clients record different start times for the same mission,
        // sometimes varying by 2-3 hours. Using header-based fallback creates
        // incorrect offsets that cause duplicate events.
        // 
        // Without reliable anchor matches, we use 0 offset and let duplicate
        // detection handle any timing discrepancies.
        return null;

        // Original code kept for reference:
        // $baseline = $this->findRecording($baselineId);
        // $target = $this->findRecording($targetId);
        // if ($baseline !== null && $target !== null && $baseline->startTime !== null && $target->startTime !== null) {
        //     $difference = $target->startTime - $baseline->startTime;
        //     if (abs($difference) <= $this->maxFallbackOffset) {
        //         return $difference;
        //     }
        // }
        // return null;
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

        $id = strtolower(trim((string)($weapon['ID'] ?? '')));
        if ($id !== '') {
            return 'id:' . $id;
        }

        $parent = strtolower(trim((string)($weapon['Parent'] ?? '')));
        if ($parent !== '') {
            return 'parent:' . $parent;
        }

        $serial = strtolower(trim((string)($weapon['Serial'] ?? $weapon['SerialNumber'] ?? '')));
        $name = strtolower(trim((string)($weapon['Name'] ?? '')));
        if ($name !== '') {
            if ($serial !== '') {
                return $name . '#' . $serial;
            }
            return 'name:' . $name;
        }

        $type = strtolower(trim((string)($weapon['Type'] ?? '')));
        if ($type !== '') {
            return 'type:' . $type;
        }

        return null;
    }

    private function weaponInstanceKey(?array $weapon): ?string
    {
        if ($weapon === null) {
            return null;
        }

        $id = strtolower(trim((string)($weapon['ID'] ?? '')));
        if ($id !== '') {
            return $id;
        }

        $parent = strtolower(trim((string)($weapon['Parent'] ?? '')));
        if ($parent !== '') {
            return 'parent:' . $parent;
        }

        return null;
    }

    private function getHasFiredTimeBucket(float $missionTime): int
    {
        if (self::HAS_FIRED_BUCKET_SIZE <= 0.0) {
            return (int)round($missionTime);
        }

        return (int)floor($missionTime / self::HAS_FIRED_BUCKET_SIZE);
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

    private function reconcileDestructionEvents(): void
    {
        if ($this->events === []) {
            return;
        }

        $window = self::POST_RECONCILIATION_WINDOW;
        if ($window <= 0.0) {
            return;
        }

        $remove = [];
        /** @var array<string, list<int>> $recentByTarget */
        $recentByTarget = [];

        foreach ($this->events as $index => $event) {
            if ($event->getType() !== 'HasBeenDestroyed') {
                continue;
            }

            $targetKey = $this->canonicalObjectKey($event->getPrimary());
            if ($targetKey === null) {
                continue;
            }

            $recent = $recentByTarget[$targetKey] ?? [];
            $updatedRecent = [];
            $merged = false;

            foreach ($recent as $recentIndex) {
                $existing = $this->events[$recentIndex] ?? null;
                if ($existing === null) {
                    continue;
                }

                $delta = abs($event->getMissionTime() - $existing->getMissionTime());
                if ($delta <= $window) {
                    $existing->mergeWith($event);
                    $this->metrics['post_inference_merges']++;
                    $remove[$index] = true;
                    $merged = true;
                    break;
                }

                if (($event->getMissionTime() - $existing->getMissionTime()) <= $window) {
                    $updatedRecent[] = $recentIndex;
                }
            }

            if ($merged) {
                $recentByTarget[$targetKey] = $updatedRecent;
                continue;
            }

            $updatedRecent[] = $index;
            $recentByTarget[$targetKey] = $updatedRecent;
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

    private function collapseTargetSignatureDuplicates(): void
    {
        if ($this->events === []) {
            return;
        }

        $groups = [];
        foreach ($this->events as $index => $event) {
            if ($event->getType() !== 'HasBeenDestroyed') {
                continue;
            }

            $signature = $this->buildTargetSignature($event);
            if ($signature === null) {
                continue;
            }

            $groups[$signature][] = $index;
        }

        if ($groups === []) {
            return;
        }

        $remove = [];
        $mergeCount = 0;

        foreach ($groups as $indices) {
            if (count($indices) < 2) {
                continue;
            }

            $preferredIndex = $this->selectPreferredDestructionEvent($indices);
            $preferred = $this->events[$preferredIndex];
            $preservedTime = $preferred->getMissionTime();

            foreach ($indices as $candidateIndex) {
                if ($candidateIndex === $preferredIndex) {
                    continue;
                }

                $preferred->mergeWith($this->events[$candidateIndex]);
                $remove[$candidateIndex] = true;
                $mergeCount++;
            }

            $preferred->shiftMissionTime($preservedTime - $preferred->getMissionTime());
        }

        if ($mergeCount === 0) {
            return;
        }

        $this->metrics['target_signature_merges'] += $mergeCount;

        $filtered = [];
        foreach ($this->events as $index => $event) {
            if (isset($remove[$index])) {
                continue;
            }
            $filtered[] = $event;
        }

        $this->events = $filtered;
        $this->rebuildEventIndex();
    }

    /**
     * Collapse duplicate AI target destructions.
     * 
     * AI units (like "Olympus-20-2") can only die once. When we see multiple
     * destruction events for the same AI target with different attackers, this
     * is a data quality issue where different Tacview recordings have conflicting
     * information about who killed the target.
     * 
     * Resolution strategy:
     * 1. Group destructions by target only (ignoring attacker)
     * 2. Identify AI targets (non-human pilot names)
     * 3. When duplicates exist, prefer the event with most evidence
     * 4. If evidence is equal, prefer the one with a human attacker
     */
    private function collapseAITargetDuplicates(): void
    {
        if ($this->events === []) {
            return;
        }

        // Group destruction events by target only
        /** @var array<string, list<int>> $groups */
        $groups = [];
        foreach ($this->events as $index => $event) {
            if ($event->getType() !== 'HasBeenDestroyed') {
                continue;
            }

            $primary = $event->getPrimary();
            if ($primary === null) {
                continue;
            }

            $pilot = $primary['Pilot'] ?? '';
            // Only process AI targets (non-human pilots)
            if ($this->looksLikeHumanPilot($pilot, $primary['Type'] ?? '')) {
                continue;
            }

            $targetKey = $this->buildHumanTargetGroupKey($primary);
            if ($targetKey === null) {
                continue;
            }

            $groups[$targetKey][] = $index;
        }

        $remove = [];
        $mergeCount = 0;

        foreach ($groups as $targetKey => $indices) {
            if (count($indices) < 2) {
                continue;
            }

            // Find the preferred event (highest evidence, human attacker preference)
            $preferredIndex = $this->selectPreferredAITargetKill($indices);
            $preferred = $this->events[$preferredIndex];

            // Remove all other events for this target
            foreach ($indices as $candidateIndex) {
                if ($candidateIndex === $preferredIndex) {
                    continue;
                }

                // Don't merge - just remove the lower-evidence duplicate
                // This prevents wrong attacker info from polluting the correct event
                $remove[$candidateIndex] = true;
                $mergeCount++;
            }
        }

        if ($mergeCount === 0) {
            return;
        }

        $this->metrics['ai_target_duplicate_removals'] = ($this->metrics['ai_target_duplicate_removals'] ?? 0) + $mergeCount;

        $filtered = [];
        foreach ($this->events as $index => $event) {
            if (isset($remove[$index])) {
                continue;
            }
            $filtered[] = $event;
        }

        $this->events = $filtered;
        $this->rebuildEventIndex();
    }

    private function collapseHumanTargetDuplicates(): void
    {
        if ($this->events === []) {
            return;
        }

        /** @var array<string, list<int>> $groups */
        $groups = [];
        foreach ($this->events as $index => $event) {
            if ($event->getType() !== 'HasBeenDestroyed') {
                continue;
            }

            $primary = $event->getPrimary();
            if ($primary === null) {
                continue;
            }

            $pilot = $primary['Pilot'] ?? '';
            if (!$this->looksLikeHumanPilot($pilot, $primary['Type'] ?? '')) {
                continue;
            }

            $targetKey = $this->canonicalObjectKey($primary);
            if ($targetKey === null) {
                continue;
            }

            $groups[$targetKey][] = $index;
        }

        $remove = [];
        $mergeCount = 0;

        foreach ($groups as $indices) {
            if (count($indices) < 2) {
                continue;
            }

            $preferredIndex = $this->selectPreferredDestructionEvent($indices);
            $preferred = $this->events[$preferredIndex];

            foreach ($indices as $candidateIndex) {
                if ($candidateIndex === $preferredIndex) {
                    continue;
                }

                $candidate = $this->events[$candidateIndex];
                if (!$this->shouldMergeHumanDuplicate($preferred, $candidate)) {
                    continue;
                }

                $preferred->mergeWith($candidate);
                $remove[$candidateIndex] = true;
                $mergeCount++;
            }
        }

        if ($mergeCount === 0) {
            return;
        }

        $this->metrics['human_target_duplicate_merges'] = ($this->metrics['human_target_duplicate_merges'] ?? 0) + $mergeCount;

        $filtered = [];
        foreach ($this->events as $index => $event) {
            if (isset($remove[$index])) {
                continue;
            }
            $filtered[] = $event;
        }

        $this->events = $filtered;
        $this->rebuildEventIndex();
    }

    private function shouldMergeHumanDuplicate(NormalizedEvent $preferred, NormalizedEvent $candidate): bool
    {
        $preferredPrimary = $preferred->getPrimary();
        $candidatePrimary = $candidate->getPrimary();

        $preferredEvidence = count($preferred->getEvidence());
        $candidateEvidence = count($candidate->getEvidence());

        $sharedObjectId = false;
        if (($preferredPrimary['ID'] ?? null) !== null && ($candidatePrimary['ID'] ?? null) !== null) {
            $sharedObjectId = ((string)$preferredPrimary['ID']) === ((string)$candidatePrimary['ID']);
        }

        if ($sharedObjectId) {
            return true;
        }

        // If both events have the same attacker (Parent), merge them regardless of evidence count
        // This handles self-kills or duplicate kills by the same pilot that failed to merge earlier
        $preferredParent = $preferred->getParent();
        $candidateParent = $candidate->getParent();
        if ($this->objectsComparable($preferredParent, $candidateParent)) {
            return true;
        }

        if ($candidateEvidence <= 2) {
            return true;
        }

        if ($candidate->getParent() === null && $candidate->getSecondary() === null) {
            return true;
        }

        // Only merge when preferred has significantly stronger evidence
        return ($preferredEvidence - $candidateEvidence) >= 3;
    }

    private function buildHumanTargetGroupKey(array $primary): ?string
    {
        $identity = ObjectIdentity::forObject($primary);
        if ($identity === null) {
            return null;
        }

        $key = $identity->getKey();
        foreach (explode('|', $key) as $segment) {
            if (str_starts_with($segment, 'pilot:')) {
                return $segment;
            }
        }

        return $key;
    }

    private function enrichHumanDestructionsFromHits(): void
    {
        if ($this->events === []) {
            return;
        }

        /** @var array<string, list<array{attacker: array, evidence: int, time: float}>> $hitsByTarget */
        $hitsByTarget = [];

        foreach ($this->events as $event) {
            if ($event->getType() !== 'HasBeenHitBy') {
                continue;
            }

            $primary = $event->getPrimary();
            if ($primary === null) {
                continue;
            }

            $attacker = $this->selectHumanAttacker($event->getParent(), $event->getSecondary());
            if ($attacker === null) {
                continue;
            }

            $targetKey = $this->canonicalObjectKey($primary);
            if ($targetKey === null) {
                continue;
            }

            $hitsByTarget[$targetKey][] = [
                'attacker' => $attacker,
                'evidence' => count($event->getEvidence()),
                'time' => $event->getMissionTime(),
            ];
        }

        if ($hitsByTarget === []) {
            return;
        }

        $attachments = 0;
        foreach ($this->events as $event) {
            if ($event->getType() !== 'HasBeenDestroyed') {
                continue;
            }

            if ($event->getParent() !== null || $event->getSecondary() !== null) {
                continue;
            }

            $primary = $event->getPrimary();
            if ($primary === null) {
                continue;
            }

            $targetKey = $this->canonicalObjectKey($primary);
            if ($targetKey === null || !isset($hitsByTarget[$targetKey])) {
                continue;
            }

            $best = $this->selectBestHitAttacker($hitsByTarget[$targetKey]);
            if ($best === null) {
                continue;
            }

            $event->setSecondary($best['attacker'], 'inferredFromHit');
            $attachments++;
        }

        if ($attachments > 0) {
            $this->metrics['human_hit_inferences'] = ($this->metrics['human_hit_inferences'] ?? 0) + $attachments;
        }
    }

    /**
     * @param list<array{attacker: array, evidence: int, time: float}> $hits
     * @return array{attacker: array, evidence: int, time: float}|null
     */
    private function selectBestHitAttacker(array $hits): ?array
    {
        if ($hits === []) {
            return null;
        }

        $best = $hits[0];
        foreach ($hits as $hit) {
            if ($hit['evidence'] > $best['evidence']) {
                $best = $hit;
                continue;
            }

            if ($hit['evidence'] === $best['evidence'] && $hit['time'] < $best['time']) {
                $best = $hit;
            }
        }

        return $best;
    }

    /**
     * Select the preferred destruction event for an AI target.
     * Prefers: most evidence > human attacker > earlier time
     * 
     * @param list<int> $indices
     */
    private function selectPreferredAITargetKill(array $indices): int
    {
        $preferred = $indices[0];
        $preferredEvent = $this->events[$preferred];
        $preferredEvidence = count($preferredEvent->getEvidence());
        $preferredHasHuman = $this->hasHumanAttacker($preferredEvent);

        foreach ($indices as $index) {
            if ($index === $preferred) {
                continue;
            }

            $event = $this->events[$index];
            $evidence = count($event->getEvidence());
            $hasHuman = $this->hasHumanAttacker($event);

            // More evidence always wins
            if ($evidence > $preferredEvidence) {
                $preferred = $index;
                $preferredEvent = $event;
                $preferredEvidence = $evidence;
                $preferredHasHuman = $hasHuman;
                continue;
            }

            // Equal evidence - prefer human attacker
            if ($evidence === $preferredEvidence && $hasHuman && !$preferredHasHuman) {
                $preferred = $index;
                $preferredEvent = $event;
                $preferredEvidence = $evidence;
                $preferredHasHuman = $hasHuman;
            }
        }

        return $preferred;
    }

    /**
     * Check if an event has a human attacker (parent or secondary).
     */
    private function hasHumanAttacker(NormalizedEvent $event): bool
    {
        $parent = $event->getParent();
        $secondary = $event->getSecondary();

        if ($parent !== null) {
            $pilot = $parent['Pilot'] ?? '';
            $type = $parent['Type'] ?? '';
            if ($this->looksLikeHumanPilot($pilot, $type)) {
                return true;
            }
        }

        if ($secondary !== null) {
            $pilot = $secondary['Pilot'] ?? '';
            $type = $secondary['Type'] ?? '';
            if ($this->looksLikeHumanPilot($pilot, $type)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<int> $indices
     */
    private function selectPreferredDestructionEvent(array $indices): int
    {
        $preferred = $indices[0];
        foreach ($indices as $index) {
            $preferred = $this->preferDestructionEvent($preferred, $index);
        }

        return $preferred;
    }

    private function preferDestructionEvent(int $currentIndex, int $candidateIndex): int
    {
        if ($currentIndex === $candidateIndex) {
            return $currentIndex;
        }

        $current = $this->events[$currentIndex];
        $candidate = $this->events[$candidateIndex];

        $currentEvidence = count($current->getEvidence());
        $candidateEvidence = count($candidate->getEvidence());
        if ($candidateEvidence > $currentEvidence) {
            return $candidateIndex;
        }
        if ($candidateEvidence < $currentEvidence) {
            return $currentIndex;
        }

        $currentSignals = ($current->getParent() !== null ? 1 : 0) + ($current->getSecondary() !== null ? 1 : 0);
        $candidateSignals = ($candidate->getParent() !== null ? 1 : 0) + ($candidate->getSecondary() !== null ? 1 : 0);
        if ($candidateSignals > $currentSignals) {
            return $candidateIndex;
        }
        if ($candidateSignals < $currentSignals) {
            return $currentIndex;
        }

        return ($candidate->getMissionTime() < $current->getMissionTime()) ? $candidateIndex : $currentIndex;
    }

    private function buildTargetSignature(NormalizedEvent $event): ?string
    {
        $targetKey = $this->canonicalObjectKey($event->getPrimary());
        if ($targetKey === null) {
            return null;
        }

        // Prefer human pilots over AI units for attacker identification
        $attacker = $this->selectHumanAttacker($event->getParent(), $event->getSecondary());

        $attackerKey = $this->canonicalObjectKey($attacker) ?? 'unknown';

        return $targetKey . '|' . $attackerKey;
    }

    /**
     * Select the human pilot attacker, preferring secondary if parent is AI.
     */
    private function selectHumanAttacker(?array $parent, ?array $secondary): ?array
    {
        if ($parent === null && $secondary === null) {
            return null;
        }

        if ($parent === null) {
            return $secondary;
        }

        if ($secondary === null) {
            return $parent;
        }

        // If parent looks like an AI unit (SAM, AAA, ground unit without human pilot format)
        // prefer secondary which is more likely to be a human pilot
        $parentPilot = $parent['Pilot'] ?? '';
        $secondaryPilot = $secondary['Pilot'] ?? '';

        $parentLooksHuman = $this->looksLikeHumanPilot($parentPilot, $parent['Type'] ?? '');
        $secondaryLooksHuman = $this->looksLikeHumanPilot($secondaryPilot, $secondary['Type'] ?? '');

        if (!$parentLooksHuman && $secondaryLooksHuman) {
            return $secondary;
        }

        return $parent;
    }

    private function looksLikeHumanPilot(string $pilot, string $type): bool
    {
        if ($pilot === '') {
            return false;
        }

        // AI units typically have structured names like "RSAM SA-8 11GTD/HQ/SAM_PLT-2 Unit #1"
        // or are SAM/AAA/Ground types
        $typeLower = strtolower($type);
        if (str_contains($typeLower, 'sam') || str_contains($typeLower, 'aaa') || str_contains($typeLower, 'ground')) {
            return false;
        }

        // Human pilots usually have pipe separator (Callsign | Name) or brackets [Name]
        if (str_contains($pilot, '|') || str_contains($pilot, '[')) {
            return true;
        }

        // AI units often have "Unit #" or systematic naming
        if (preg_match('/Unit\s*#\d+/i', $pilot) || preg_match('/PLT-\d+/i', $pilot)) {
            return false;
        }

        // AI flight group naming patterns like "Olympus-20-2", "GroupName-NN-N", "Name-N-N"
        // These are typically AI-controlled aircraft spawned by the mission
        if (preg_match('/^[A-Za-z]+-\d+-\d+$/', $pilot)) {
            return false;
        }

        // Aircraft types are more likely to be human-piloted
        if (str_contains($typeLower, 'aircraft') || str_contains($typeLower, 'helicopter')) {
            return true;
        }

        return false;
    }

    private function mergeByCompositeSignature(NormalizedEvent $candidate): bool
    {
        $type = strtolower($candidate->getType());
        if (!in_array($type, self::COMPOSITE_SIGNATURE_TYPES, true)) {
            return false;
        }

        $signature = $this->buildCompositeSignature($candidate);
        if ($signature === null) {
            return false;
        }

        $this->metrics['composite_signatures_emitted']++;

        if (isset($this->compositeSignatureIndex[$signature])) {
            $this->compositeSignatureIndex[$signature]->mergeWith($candidate);
            $this->metrics['composite_signature_merges']++;
            return true;
        }

        $this->compositeSignatureIndex[$signature] = $candidate;
        return false;
    }

    private function buildCompositeSignature(NormalizedEvent $event): ?string
    {
        $targetKey = $this->canonicalObjectKey($event->getPrimary());
        if ($targetKey === null) {
            $this->metrics['composite_signature_missing_target']++;
            return null;
        }

        $weaponKey = $this->weaponInstanceKey($event->getSecondary());
        if ($weaponKey === null) {
            $parentKey = $this->canonicalObjectKey($event->getParent());
            if ($parentKey !== null) {
                $weaponKey = 'parent:' . $parentKey;
            }
        }

        if ($weaponKey === null) {
            $this->metrics['composite_signature_missing_weapon']++;
            $weaponKey = 'unknown';
        }

        $bucket = $this->getCompositeTimeBucket($event->getMissionTime());

        return strtolower($event->getType()) . '|' . $targetKey . '|' . $weaponKey . '|tb:' . $bucket;
    }

    private function canonicalObjectKey(?array $object): ?string
    {
        if ($object === null) {
            return null;
        }

        $identity = ObjectIdentity::forObject($object);
        return $identity?->getKey();
    }

    private function getCompositeTimeBucket(float $missionTime): int
    {
        if (self::COMPOSITE_SIGNATURE_BUCKET_SIZE <= 0.0) {
            return (int)round($missionTime);
        }

        return (int)floor($missionTime / self::COMPOSITE_SIGNATURE_BUCKET_SIZE);
    }

    private function findRecentHit(NormalizedEvent $event): ?NormalizedEvent
    {
        $primary = $event->getPrimary();
        if ($primary === null) {
            return null;
        }

        $targetIdentity = ObjectIdentity::forObject($primary);
        if ($targetIdentity === null) {
            return null;
        }

        $best = null;
        $bestDelta = null;
        foreach ($this->eventIndex['HasBeenHitBy'] ?? [] as $candidate) {
            if ($candidate->getParent() === null) {
                continue;
            }

            $candidateIdentity = ObjectIdentity::forObject($candidate->getPrimary());
            if ($candidateIdentity === null || $candidateIdentity->getKey() !== $targetIdentity->getKey()) {
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

        $confidenceSum = 0.0;
        $confidenceCount = 0;

        foreach ($this->recordings as $recording) {
            $eventScore = $recording->rawEventCount / $maxEvents;
            $durationScore = $recording->duration !== null ? min(1.0, $recording->duration / $maxDuration) : 0.6;
            $coverageScore = $this->computeCoverageScore($recording);

            // Include alignment confidence in reliability calculation
            $alignmentScore = $this->alignmentConfidence[$recording->id] ?? 0.5;
            $confidenceSum += $alignmentScore;
            $confidenceCount++;

            // Weighted reliability: events 40%, duration 25%, coverage 15%, alignment 20%
            $weight = (0.4 * $eventScore) + (0.25 * $durationScore) + (0.15 * $coverageScore) + (0.2 * $alignmentScore);
            if ($recording->id === $this->baselineRecordingId) {
                $weight = max($weight, 0.9);
            }

            $this->recordingReliability[$recording->id] = max(0.35, min(1.0, round($weight, 3)));
        }

        // Update aggregate confidence metric
        if ($confidenceCount > 0) {
            $this->metrics['alignment_confidence_avg'] = round($confidenceSum / $confidenceCount, 3);
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

        // Some Tacview exports have misaligned MissionTime headers (e.g., negative or far in past).
        // Detect and correct by shifting all events forward to ensure a non-negative timeline.
        // This commonly occurs when combining recordings with different start time conventions.
        $minimumEventTime = $this->getMinimumEventMissionTime();
        if ($minimumEventTime < 0.0) {
            $this->shiftAllEvents(-$minimumEventTime);
        }
    }

    private function pruneIncongruentSources(): void
    {
        if ($this->missionTimeCongruenceTolerance <= 0.0 || $this->startTime === null) {
            return;
        }

        $trustedSources = [];
        $candidates = [];

        foreach ($this->recordings as $recording) {
            $recordingId = $recording->id;
            $startTime = $recording->startTime;

            if ($recordingId === $this->baselineRecordingId || $startTime === null) {
                $trustedSources[$recordingId] = true;
                continue;
            }

            $strategy = $this->offsetStrategies[$recordingId] ?? '';
            if ($this->hasReliableAlignmentStrategy($strategy)) {
                $trustedSources[$recordingId] = true;
                continue;
            }

            $offset = $this->recordingOffsets[$recordingId] ?? 0.0;
            $alignedStart = $startTime - $offset;
            $delta = abs($alignedStart - $this->startTime);

            if ($delta <= $this->missionTimeCongruenceTolerance) {
                $trustedSources[$recordingId] = true;
                continue;
            }

            $candidates[$recordingId] = [
                'delta' => $delta,
                'strategy' => $strategy,
                'alignedStart' => $alignedStart,
            ];
        }

        if ($candidates === [] || $trustedSources === []) {
            return;
        }

        $this->metrics['incongruent_prune_candidates'] = count($candidates);

        $overlapMap = $this->buildCandidateOverlapMap($trustedSources);
        foreach (array_keys($candidates) as $candidateId) {
            if (isset($overlapMap[$candidateId])) {
                $trustedSources[$candidateId] = true;
                unset($candidates[$candidateId]);
                $this->metrics['incongruent_rescued']++;
            }
        }

        if ($candidates === []) {
            return;
        }

        $this->metrics['incongruent_recordings'] = count($candidates);

        $filtered = [];
        foreach ($this->events as $event) {
            $evidence = $event->getEvidence();
            $keep = false;

            foreach ($evidence as $entry) {
                if (isset($trustedSources[$entry->sourceId])) {
                    $keep = true;
                    break;
                }
            }

            if ($keep) {
                $filtered[] = $event;
                continue;
            }

            foreach ($evidence as $entry) {
                if (isset($candidates[$entry->sourceId])) {
                    $this->metrics['incongruent_events_pruned']++;
                    break;
                }
            }
        }

        if ($this->metrics['incongruent_events_pruned'] > 0) {
            $this->events = $filtered;
            $this->rebuildEventIndex();
        }

        foreach ($candidates as $recordingId => $info) {
            $this->excludedRecordings[$recordingId] = true;
            $label = 'mission-time-out-of-band (Δ=' . round($info['delta'], 1) . 's)';
            if (isset($this->offsetStrategies[$recordingId]) && $this->offsetStrategies[$recordingId] !== '') {
                $label .= ' | prior=' . $this->offsetStrategies[$recordingId];
            } else {
                $label .= ' | alignment=unresolved';
            }
            $this->offsetStrategies[$recordingId] = $label;
        }
    }

    /**
     * @param array<string, bool> $trustedSources
     * @return array<string, bool>
     */
    private function buildCandidateOverlapMap(array $trustedSources): array
    {
        if ($trustedSources === [] || $this->events === []) {
            return [];
        }

        $overlap = [];

        foreach ($this->events as $event) {
            $evidence = $event->getEvidence();
            if ($evidence === []) {
                continue;
            }

            $sourceIds = [];
            $hasTrusted = false;

            foreach ($evidence as $entry) {
                $sourceIds[$entry->sourceId] = true;
                if (!$hasTrusted && isset($trustedSources[$entry->sourceId])) {
                    $hasTrusted = true;
                }
            }

            if (!$hasTrusted) {
                continue;
            }

            foreach ($sourceIds as $sourceId => $_) {
                if (!isset($trustedSources[$sourceId])) {
                    $overlap[$sourceId] = true;
                }
            }
        }

        return $overlap;
    }

    private function hasReliableAlignmentStrategy(?string $strategy): bool
    {
        if ($strategy === null || $strategy === '') {
            return false;
        }

        $normalized = strtolower($strategy);

        return str_starts_with($normalized, 'anchor')
            || str_starts_with($normalized, 'fallback-applied')
            || str_starts_with($normalized, 'baseline');
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
        // Guard against empty event array; this shouldn't occur in practice after filtering,
        // but explicit check prevents silent logic errors.
        if ($this->events === []) {
            return 0.0;
        }

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

    /**
     * @return array<string, array{start: float, end: float}>
     */
    private function buildRecordingCoverageWindows(): array
    {
        if ($this->events === []) {
            return [];
        }

        $windows = [];

        foreach ($this->events as $event) {
            $evidenceSamples = $event->getEvidence();
            if ($evidenceSamples === []) {
                continue;
            }

            foreach ($evidenceSamples as $evidence) {
                $sourceId = $evidence->sourceId;
                $time = $evidence->missionTime;

                if (!isset($windows[$sourceId])) {
                    $windows[$sourceId] = [
                        'start' => $time,
                        'end' => $time,
                    ];
                    continue;
                }

                if ($time < $windows[$sourceId]['start']) {
                    $windows[$sourceId]['start'] = $time;
                }
                if ($time > $windows[$sourceId]['end']) {
                    $windows[$sourceId]['end'] = $time;
                }
            }
        }

        return $windows;
    }

    /**
     * Compute coverage statistics for a set of aligned windows.
     * Returns metrics including gap percentage, overlap percentage, and per-source coverage.
     *
     * @param array<string, array{start: ?float, end: ?float, duration: ?float}> $alignedWindows
     * @return array{
     *     missionStart: float,
     *     missionEnd: float,
     *     missionDuration: float,
     *     totalCoverage: float,
     *     gapPercentage: float,
     *     overlapPercentage: float,
     *     sourceCount: int,
     *     perSource: array<string, array{start: ?float, end: ?float, duration: ?float, coveragePercent: float}>
     * }
     */
    private function computeCoverageStatistics(array $alignedWindows): array
    {
        $missionStart = $this->startTime ?? 0.0;
        $missionEnd = $this->endTime ?? 0.0;
        $missionDuration = max(1.0, $missionEnd - $missionStart);

        $stats = [
            'missionStart' => $missionStart,
            'missionEnd' => $missionEnd,
            'missionDuration' => $missionDuration,
            'totalCoverage' => 0.0,
            'gapPercentage' => 100.0,
            'overlapPercentage' => 0.0,
            'sourceCount' => count($alignedWindows),
            'perSource' => [],
        ];

        if ($alignedWindows === []) {
            return $stats;
        }

        // Build interval list for coverage calculation
        $intervals = [];
        foreach ($alignedWindows as $sourceId => $window) {
            $start = $window['start'] ?? null;
            $end = $window['end'] ?? null;
            $duration = $window['duration'] ?? null;

            // Calculate coverage percent for this source
            $coveragePercent = 0.0;
            if ($start !== null && $end !== null && $missionDuration > 0) {
                $sourceDuration = max(0.0, $end - $start);
                $coveragePercent = min(100.0, ($sourceDuration / $missionDuration) * 100);
            }

            $stats['perSource'][$sourceId] = [
                'start' => $start,
                'end' => $end,
                'duration' => $duration,
                'coveragePercent' => round($coveragePercent, 2),
            ];

            if ($start !== null && $end !== null && $start < $end) {
                $intervals[] = [$start, $end];
            }
        }

        if ($intervals === []) {
            return $stats;
        }

        // Merge overlapping intervals to compute total coverage
        usort($intervals, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $merged = [];
        $current = $intervals[0];

        for ($i = 1, $count = count($intervals); $i < $count; $i++) {
            if ($intervals[$i][0] <= $current[1]) {
                // Overlapping - extend current interval
                $current[1] = max($current[1], $intervals[$i][1]);
            } else {
                // Non-overlapping - save current and start new
                $merged[] = $current;
                $current = $intervals[$i];
            }
        }
        $merged[] = $current;

        // Calculate total covered time
        $totalCovered = 0.0;
        foreach ($merged as $interval) {
            $totalCovered += ($interval[1] - $interval[0]);
        }

        // Calculate overlap: total of all individual durations minus merged duration
        $totalIndividual = 0.0;
        foreach ($intervals as $interval) {
            $totalIndividual += ($interval[1] - $interval[0]);
        }
        $overlapTime = max(0.0, $totalIndividual - $totalCovered);

        // Calculate gaps: mission duration minus total covered
        $gapTime = max(0.0, $missionDuration - $totalCovered);

        $stats['totalCoverage'] = round($totalCovered, 2);
        $stats['gapPercentage'] = round(($gapTime / $missionDuration) * 100, 2);
        $stats['overlapPercentage'] = round(($overlapTime / $missionDuration) * 100, 2);

        // Update metrics
        $this->metrics['coverage_gap_percent'] = $stats['gapPercentage'];
        $this->metrics['coverage_overlap_percent'] = $stats['overlapPercentage'];

        return $stats;
    }

    private function expandMissionBoundsWithAlignedWindows(array $alignedWindows): void
    {
        $minStart = $this->startTime;
        $maxEnd = $this->endTime;

        foreach ($alignedWindows as $window) {
            if (isset($window['start'])) {
                $minStart = $minStart === null ? $window['start'] : min($minStart, $window['start']);
            }
            if (isset($window['end'])) {
                $maxEnd = $maxEnd === null ? $window['end'] : max($maxEnd, $window['end']);
            }
        }

        $this->startTime = $minStart;
        $this->endTime = $maxEnd;
    }

    /**
     * @return array{start: ?float, end: ?float, duration: ?float}
     */
    private function computeAlignedWindow(SourceRecording $recording, array $coverageWindows): array
    {
        $rawStart = $recording->startTime;
        $rawEnd = $recording->endTime;
        $rawDuration = $recording->duration;
        $offset = $this->recordingOffsets[$recording->id] ?? 0.0;

        $alignedStart = $rawStart !== null ? $rawStart - $offset : null;
        $alignedEnd = $rawEnd !== null ? $rawEnd - $offset : null;

        $coverage = $coverageWindows[$recording->id] ?? null;
        if ($alignedStart === null && isset($coverage['start'])) {
            $alignedStart = $coverage['start'];
        }
        if ($alignedEnd === null && isset($coverage['end'])) {
            $alignedEnd = $coverage['end'];
        }

        $alignedDuration = null;
        if ($alignedStart !== null && $alignedEnd !== null) {
            $alignedDuration = max(0.0, $alignedEnd - $alignedStart);
        } elseif ($rawDuration !== null) {
            $alignedDuration = max(0.0, $rawDuration);
        } elseif (isset($coverage['start'], $coverage['end'])) {
            $alignedDuration = max(0.0, $coverage['end'] - $coverage['start']);
        }

        if ($alignedStart !== null && $alignedDuration !== null && $alignedEnd === null) {
            $alignedEnd = $alignedStart + $alignedDuration;
        }

        return [
            'start' => $alignedStart,
            'end' => $alignedEnd,
            'duration' => $alignedDuration,
        ];
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

    private function isWeaponObject(?array $object): bool
    {
        $category = $this->classifyObjectCategory($object);
        return in_array($category, ['missile', 'bomb', 'rocket', 'torpedo', 'projectile'], true);
    }
}
