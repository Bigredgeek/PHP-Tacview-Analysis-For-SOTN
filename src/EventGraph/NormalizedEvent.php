<?php

declare(strict_types=1);

namespace EventGraph;

use function array_filter;
use function array_key_exists;
use function array_keys;
use function count;
use function is_numeric;
use function max;
use function md5;
use function min;
use function preg_replace;
use function round;
use function sprintf;
use function substr;

final class NormalizedEvent
{
    private const BASE_CONFIDENCE = 0.55;

    private readonly string $id;
    private string $type;
    private float $missionTime;
    private ?array $primary;
    private ?array $secondary;
    private ?array $parent;
    private ?array $airport;
    /** @var array{latitude: float|null, longitude: float|null, altitude: float|null}|null */
    private ?array $position;
    private array $baseEvent;

    /** @var list<EventEvidence> */
    private array $evidence = [];
    private float $confidence;
    /** @var array{A:int,B:int,C:int} */
    private array $tierCounts = ['A' => 0, 'B' => 0, 'C' => 0];
    /** @var array<string, float> */
    private array $tierWeights = ['A' => 0.0, 'B' => 0.0, 'C' => 0.0];
    /** @var array<string, int> */
    private array $coalitionEvidence = [];
    /** @var array<string, mixed> */
    private array $confidenceBreakdown = [];
    private float $confidenceAdjustments = 0.0;

    /** @var array<string, mixed> */
    private array $graphLinks = [];

    private function __construct(
        string $id,

        string $type,
        float $missionTime,
        ?array $primary,
        ?array $secondary,
        ?array $parent,
        ?array $airport,
        ?array $position,
        array $baseEvent
    ) {
        $this->id = $id;
        $this->type = $type;
        $this->missionTime = $missionTime;
        $this->primary = $primary;
        $this->secondary = $secondary;
        $this->parent = $parent;
        $this->airport = $airport;
        $this->position = $position;
        $this->baseEvent = $baseEvent;
        $this->confidence = self::BASE_CONFIDENCE;
    }

    public static function fromRaw(string $sourceId, int $eventId, array $event): self
    {
        $missionTime = isset($event['Time']) ? (float)$event['Time'] : 0.0;
        $position = null;
        if (array_key_exists('Latitude', $event) || array_key_exists('Longitude', $event) || array_key_exists('Altitude', $event)) {
            $position = [
                'latitude' => isset($event['Latitude']) ? (float)$event['Latitude'] : null,
                'longitude' => isset($event['Longitude']) ? (float)$event['Longitude'] : null,
                'altitude' => isset($event['Altitude']) ? (float)$event['Altitude'] : null,
            ];
        }

        $instance = new self(
            id: self::generateEventId($sourceId, $eventId),
            type: $event['Action'] ?? 'Unknown',
            missionTime: $missionTime,
            primary: $event['PrimaryObject'] ?? null,
            secondary: $event['SecondaryObject'] ?? null,
            parent: $event['ParentObject'] ?? null,
            airport: $event['Airport'] ?? null,
            position: $position,
            baseEvent: $event
        );

        $instance->addEvidence(new EventEvidence(
            $sourceId,
            $eventId,
            $missionTime,
            $event,
            $instance->confidence,
            EventEvidence::classifyDetailTier($event)
        ));

        return $instance;
    }

    private static function generateEventId(string $sourceId, int $eventId): string
    {
        return sprintf('evt-%s-%d-%s', preg_replace('/[^a-zA-Z0-9]+/', '-', $sourceId), $eventId, substr(md5($sourceId . $eventId), 0, 6));
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getMissionTime(): float
    {
        return $this->missionTime;
    }

    public function getPrimary(): ?array
    {
        return $this->primary;
    }

    public function getSecondary(): ?array
    {
        return $this->secondary;
    }

    public function getParent(): ?array
    {
        return $this->parent;
    }

    public function getAirport(): ?array
    {
        return $this->airport;
    }

    /**
     * @return array{latitude: float|null, longitude: float|null, altitude: float|null}|null
     */
    public function getPosition(): ?array
    {
        return $this->position;
    }

    /**
     * @return list<EventEvidence>
     */
    public function getEvidence(): array
    {
        return $this->evidence;
    }

    public function addEvidence(EventEvidence $evidence): void
    {
        $this->evidence[] = $evidence;
        $tier = $evidence->getDetailTier();
        if (!isset($this->tierCounts[$tier])) {
            $tier = 'C';
        }
        $this->tierCounts[$tier]++;
        $this->confidence = self::BASE_CONFIDENCE;
    }

    public function mergeWith(self $other): void
    {
        $totalEvidenceBefore = count($this->evidence);
        foreach ($other->evidence as $evidence) {
            $this->addEvidence($evidence);
        }

        // Weighted average mission time based on evidence counts
        $totalEvidenceAfter = count($this->evidence);
        if ($totalEvidenceAfter > 0) {
            $weightCurrent = $totalEvidenceBefore / max(1, $totalEvidenceAfter);
            $weightOther = (count($other->evidence)) / max(1, $totalEvidenceAfter);
            $this->missionTime = ($this->missionTime * $weightCurrent) + ($other->missionTime * $weightOther);
        }

        $this->primary = $this->mergeObject($this->primary, $other->primary);
        $this->secondary = $this->mergeObject($this->secondary, $other->secondary);
        $this->parent = $this->mergeObject($this->parent, $other->parent);
        $this->airport = $this->mergeObject($this->airport, $other->airport);
        $this->position = $this->mergePosition($this->position, $other->position);

        // Preserve richer action types when available
        if ($this->type === 'Unknown' && $other->type !== 'Unknown') {
            $this->type = $other->type;
        }
    }

    private function mergeObject(?array $current, ?array $candidate): ?array
    {
        if ($current === null) {
            return $candidate;
        }
        if ($candidate === null) {
            return $current;
        }

        foreach ($candidate as $key => $value) {
            if ((!array_key_exists($key, $current) || $current[$key] === '' || $current[$key] === null) && $value !== '') {
                $current[$key] = $value;
            }
        }

        return $current;
    }

    /**
     * @param array{latitude: float|null, longitude: float|null, altitude: float|null}|null $current
     * @param array{latitude: float|null, longitude: float|null, altitude: float|null}|null $candidate
     * @return array{latitude: float|null, longitude: float|null, altitude: float|null}|null
     */
    private function mergePosition(?array $current, ?array $candidate): ?array
    {
        if ($current === null) {
            return $candidate;
        }
        if ($candidate === null) {
            return $current;
        }

        return [
            'latitude' => $this->mergeCoordinate($current['latitude'], $candidate['latitude']),
            'longitude' => $this->mergeCoordinate($current['longitude'], $candidate['longitude']),
            'altitude' => $this->mergeCoordinate($current['altitude'], $candidate['altitude']),
        ];
    }

    private function mergeCoordinate(?float $current, ?float $candidate): ?float
    {
        if ($current === null) {
            return $candidate;
        }
        if ($candidate === null) {
            return $current;
        }

        return round(($current + $candidate) / 2, 4);
    }

    public function addGraphLink(string $key, mixed $value, float $confidenceBoost = 0.0): void
    {
        $this->graphLinks[$key] = $value;
        if ($confidenceBoost > 0.0) {
            $this->confidenceAdjustments += $confidenceBoost;
        }
    }

    public function setParent(array $parent, string $reason, float $confidenceBoost = 0.1): void
    {
        $this->parent = $parent;
        $this->addGraphLink('parentReason', $reason, $confidenceBoost);
    }

    public function shiftMissionTime(float $delta): void
    {
        if ($delta === 0.0) {
            return;
        }

        $this->missionTime += $delta;

        if (is_numeric($this->baseEvent['Time'] ?? null)) {
            $this->baseEvent['Time'] = (float)$this->baseEvent['Time'] + $delta;
        }

        if ($this->evidence !== []) {
            $shifted = [];
            foreach ($this->evidence as $evidence) {
                $shifted[] = $evidence->withTimeShifted($delta);
            }
            $this->evidence = $shifted;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $event = $this->baseEvent;
        $event['Action'] = $this->type;
        $event['Time'] = $this->missionTime;

        if ($this->primary !== null) {
            $event['PrimaryObject'] = $this->primary;
        }
        if ($this->secondary !== null) {
            $event['SecondaryObject'] = $this->secondary;
        }
        if ($this->parent !== null) {
            $event['ParentObject'] = $this->parent;
        }
        if ($this->airport !== null) {
            $event['Airport'] = $this->airport;
        }
        if ($this->position !== null) {
            // Reinstate precision for coordinates
            if ($this->position['latitude'] !== null) {
                $event['Latitude'] = $this->position['latitude'];
            }
            if ($this->position['longitude'] !== null) {
                $event['Longitude'] = $this->position['longitude'];
            }
            if ($this->position['altitude'] !== null) {
                $event['Altitude'] = $this->position['altitude'];
                $event['Location'] = $this->position['altitude'];
            }
        }

        $event['Confidence'] = round($this->confidence, 3);
        $event['Evidence'] = array_map(static fn (EventEvidence $evidence): array => $evidence->toArray(), $this->evidence);
        if ($this->graphLinks !== []) {
            $event['GraphLinks'] = $this->graphLinks;
        }
        if ($this->coalitionEvidence !== []) {
            $event['CoalitionEvidence'] = $this->coalitionEvidence;
        }
        if ($this->confidenceBreakdown !== []) {
            $event['ConfidenceBreakdown'] = $this->confidenceBreakdown;
        }

        return $event;
    }

    /**
     * @param array<string, float> $sourceWeights
     * @param array<string, string|null> $sourceCoalitions
     */
    public function recomputeConfidence(array $sourceWeights, array $sourceCoalitions): void
    {
        $tierWeights = ['A' => 0.0, 'B' => 0.0, 'C' => 0.0];
        $coalitionCounts = [];
        $totalWeight = 0.0;

        foreach ($this->evidence as $evidence) {
            $sourceId = $evidence->sourceId;
            $weight = $sourceWeights[$sourceId] ?? 1.0;
            $tier = $evidence->getDetailTier();
            if (!isset($tierWeights[$tier])) {
                $tier = 'C';
            }

            $tierWeights[$tier] += $weight;
            $totalWeight += $weight;

            $coalition = $sourceCoalitions[$sourceId] ?? 'unknown';
            if ($coalition === null || $coalition === '') {
                $coalition = 'unknown';
            }
            $coalitionCounts[$coalition] = ($coalitionCounts[$coalition] ?? 0) + 1;
        }

        $this->tierWeights = $tierWeights;
        $this->coalitionEvidence = $coalitionCounts;

        $aWeight = $tierWeights['A'];
        $bWeight = $tierWeights['B'];
        $cWeight = $tierWeights['C'];

    $twoSourceThreshold = 1.4;
    $singleSourceThreshold = 0.7;

        $confidence = self::BASE_CONFIDENCE;

        if ($aWeight >= $twoSourceThreshold) {
            $confidence = 1.0;
        } elseif ($aWeight >= $singleSourceThreshold && ($aWeight + $bWeight) >= $twoSourceThreshold) {
            $confidence = 0.88;
        } elseif ($aWeight >= $singleSourceThreshold) {
            $confidence = 0.75;
        } elseif ($bWeight >= $twoSourceThreshold) {
            $confidence = 0.70;
        } elseif ($bWeight >= $singleSourceThreshold) {
            $confidence = 0.62;
        } elseif ($cWeight > 0.0) {
            $confidence = 0.57;
        }

        $activeCoalitions = array_filter(
            array_keys($coalitionCounts),
            static fn (string $coalition): bool => $coalition !== 'unknown' && $coalition !== 'neutral'
        );

        if (count($activeCoalitions) > 1) {
            $confidence -= 0.05;
        }

        if ($totalWeight < 0.6) {
            $confidence -= 0.04;
        }

        if ($this->confidenceAdjustments > 0.0) {
            $confidence += min(0.1, $this->confidenceAdjustments);
        }

    $confidence = max(0.55, min(1.0, $confidence));
        $this->confidence = $confidence;

        $this->confidenceBreakdown = [
            'tierWeights' => $tierWeights,
            'tierCounts' => $this->tierCounts,
            'totalWeight' => $totalWeight,
            'coalitions' => $coalitionCounts,
            'graphAdjustment' => $this->confidenceAdjustments,
        ];
    }

    /**
     * @return array<string, int>
     */
    public function getCoalitionEvidence(): array
    {
        return $this->coalitionEvidence;
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfidenceBreakdown(): array
    {
        return $this->confidenceBreakdown;
    }
}
