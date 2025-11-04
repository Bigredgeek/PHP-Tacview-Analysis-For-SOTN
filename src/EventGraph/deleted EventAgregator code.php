];

    /**
     * Certain mission events (e.g., takeoff/landing) can differ by several seconds between recordings
     * even after offset alignment, so allow a wider merge window to suppress duplicate timeline rows.
     */
    private const EVENT_TIME_OVERRIDES = [
        'HasTakenOff' => 30.0,
        'HasLanded' => 45.0,
        'HasEnteredTheArea' => 45.0,
        'HasLeftTheArea' => 45.0,
    ];

    private readonly string $language;
    private readonly float $timeTolerance;
    private readonly float $hitBacktrackWindow;
    private readonly float $anchorTolerance;
    private readonly int $anchorMinimumMatches;
    private readonly float $maxFallbackOffset;
    private readonly float $maxAnchorOffset;

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
        $anchorMatches = isset($options['anchor_min_matches']) ? (int)$options['anchor_min_matches'] : 3;
        $this->anchorMinimumMatches = $anchorMatches > 0 ? $anchorMatches : 1;
        $this->maxFallbackOffset = isset($options['max_fallback_offset']) ? (float)$options['max_fallback_offset'] : 600.0;
        $defaultAnchorOffset = max($this->maxFallbackOffset, 7200.0);
        $this->maxAnchorOffset = isset($options['max_anchor_offset']) ? (float)$options['max_anchor_offset'] : $defaultAnchorOffset;
    }

    public function ingestFile(string $path): void
    {
        if (!is_file($path)) {
            throw new RuntimeException("Tacview file not found: {$path}");
        }

        $recording = new SourceRecording($path, $this->language);
        $this->recordings[] = $recording;
        $this->metrics['raw_event_count'] += $recording->rawEventCount;

        $this->missionName = $this->resolveMissionName($this->missionName, $recording->missionName);
        $this->startTime = $this->resolveStartTime($recording);
        $this->endTime = $this->resolveEndTime($recording);

        $this->pendingEvents[$recording->id] = $recording->events;
    }

    public function build(): void
    {
        $this->prepareEvents();

        if ($this->events === []) {
            return;
        }

        $this->computeRecordingMetadata();

        usort($this->events, static function (NormalizedEvent $left, NormalizedEvent $right): int {
            return $left->getMissionTime() <=> $right->getMissionTime();
        });

        $this->runInference();
        $this->applyPostMergeFilters();
        $this->pruneDisconnectDestructions();
        $this->annotateEvents();
        $this->metrics['merged_events'] = count($this->events);
    }

    public function toAggregatedMission(): AggregatedMission
    {
        $this->build();

        $mission = new AggregatedMission(
            $this->missionName ?? 'Tacview Combined Debrief',
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
    } */
    public function getRecordingOffsets(): array
    {
        return $this->recordingOffsets;
    }

    public function getBaselineRecordingId(): ?string
    {
        return $this->baselineRecordingId;
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

        if (!$this->objectsComparable($left->getSecondary(), $right->getSecondary())) {
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

        $id = isset($object['ID']) ? strtolower(trim((string)$object['ID'])) : '';

        return $id !== '' ? $id : null;
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

            
                    private function classifyDestructionDisconnect(NormalizedEvent $event): ?string
                    {
                        $primary = $event->getPrimary();
                        if ($primary === null) {
                            return null;
                        }

                        $destroyTime = $event->getMissionTime();

                        $recentHitWindowStart = $destroyTime - 180.0;
                        if ($this->hasCombatAgainstTarget($primary, $recentHitWindowStart, $destroyTime + 5.0)) {
                            return null;
                        }

                        $departure = $this->findNearestFutureEventForPrimary('HasLeftTheArea', $primary, $destroyTime, 45.0);

                        $landing = $this->findMostRecentEventForPrimary('HasLanded', $primary, $destroyTime);
                        if ($landing !== null) {
                            $landTime = $landing->getMissionTime();
                            if (($destroyTime - $landTime) <= 1500.0 && !$this->hasCombatAgainstTarget($primary, $landTime, $destroyTime + 5.0)) {
                                if ($departure !== null) {
                                    return 'landed_disconnect';
                                }
                                if (count($event->getEvidence()) === 1) {
                                    return 'landed_disconnect';
                                }
                            }
                        }

                        if ($departure !== null) {
                            return 'midair_disconnect';
                        }

                        return null;
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
                        ]);
            
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
            $this->recordingCoalitions = [];
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
 private function selectBaselineRecordingId(): ?string
    {
        if ($this->recordings === []) {
            return null;
        }

            private function classifyDestructionDisconnect(NormalizedEvent $event): ?string
            {
                $primary = $event->getPrimary();
                if ($primary === null) {
                    return null;
                }

                $destroyTime = $event->getMissionTime();

                $recentHitWindowStart = $destroyTime - 180.0;
                if ($this->hasCombatAgainstTarget($primary, $recentHitWindowStart, $destroyTime + 5.0)) {
                    return null;
                }

                $departure = $this->findNearestFutureEventForPrimary('HasLeftTheArea', $primary, $destroyTime, 45.0);

                $landing = $this->findMostRecentEventForPrimary('HasLanded', $primary, $destroyTime);
                if ($landing !== null) {
                    $landTime = $landing->getMissionTime();
                    if (($destroyTime - $landTime) <= 1500.0 && !$this->hasCombatAgainstTarget($primary, $landTime, $destroyTime + 5.0)) {
                        if ($departure !== null) {
                            return 'landed_disconnect';
                        }

                        if (count($event->getEvidence()) === 1) {
                            return 'landed_disconnect';
                        }
                    }
                }

                if ($departure !== null) {
                    return 'midair_disconnect';
                }

                return null;
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
                ]);
            }    