<?php

declare(strict_types=1);

namespace EventGraph;

final class AggregatedMission
{
    /** @var array<int, array<string, mixed>> */
    private array $events = [];

    /** @var list<array<string, mixed>> */
    private array $sources = [];

    /** @var array<string, mixed> */
    private array $metrics = [];

    public function __construct(
        public readonly string $missionName,
        public readonly float $startTime,
        public readonly float $duration
    ) {
    }

    public function getMissionName(): string
    {
        return $this->missionName;
    }

    public function getStartTime(): float
    {
        return $this->startTime;
    }

    public function getDuration(): float
    {
        return $this->duration;
    }

    /**
     * @param array<string, mixed> $event
     */
    public function addEvent(int $index, array $event): void
    {
        $this->events[$index] = $event;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getEvents(): array
    {
        return $this->events;
    }

    /**
     * @param array<string, mixed> $source
     */
    public function addSource(array $source): void
    {
        $this->sources[] = $source;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getSources(): array
    {
        return $this->sources;
    }

    /**
     * @param array<string, mixed> $metrics
     */
    public function setMetrics(array $metrics): void
    {
        $this->metrics = $metrics;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetrics(): array
    {
        return $this->metrics;
    }
}
