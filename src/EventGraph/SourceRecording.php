<?php

declare(strict_types=1);

namespace EventGraph;

use tacview;

use function arsort;
use function array_key_first;
use function array_values;
use function basename;
use function count;
use function is_array;
use function is_numeric;
use function pathinfo;
use function trim;

final class SourceRecording
{
    public readonly string $id;
    public readonly string $filename;
    public readonly string $missionName;
    public readonly ?float $startTime;
    public readonly ?float $duration;
    public readonly ?float $endTime;
    /** @var list<NormalizedEvent> */
    public readonly array $events;
    public readonly int $rawEventCount;
    public readonly ?string $dominantCoalition;

    /** @param array<string, mixed> $options */
    public function __construct(
        public readonly string $path,
        string $language,
        array $options = []
    ) {
        $this->filename = basename($path);
        $this->id = pathinfo($this->filename, PATHINFO_FILENAME) ?: $this->filename;

        $parser = new tacview($language);
        $parser->htmlOutput = '';
        $parser->objects = [];
        $parser->events = [];
        $parser->stats = [];
        $parser->weaponOwners = [];
        $parser->missionName = '';
        $parser->startTime = null;
        $parser->duration = null;

        $parser->parseXML($path);

        $missionName = trim((string)($parser->missionName ?? ''));
        $this->missionName = $missionName !== '' ? $missionName : $this->id;
        $this->startTime = is_numeric($parser->startTime) ? (float)$parser->startTime : null;
        $this->duration = is_numeric($parser->duration) ? (float)$parser->duration : null;
        $this->endTime = ($this->startTime !== null && $this->duration !== null)
            ? $this->startTime + $this->duration
            : null;

        $events = [];
        $coalitionHistogram = [];
        $rawEvents = array_values($parser->events);
        $this->rawEventCount = count($rawEvents);

        foreach ($rawEvents as $index => $event) {
            if (!is_array($event)) {
                continue;
            }
            if (!isset($event['PrimaryObject']) || !is_array($event['PrimaryObject'])) {
                continue;
            }

            $coalition = self::extractCoalition($event['PrimaryObject']);
            if ($coalition !== null) {
                $coalitionHistogram[$coalition] = ($coalitionHistogram[$coalition] ?? 0) + 1;
            }

            $events[] = NormalizedEvent::fromRaw($this->id, $index + 1, $event);
        }

        $this->events = $events;
        $this->dominantCoalition = self::resolveDominantCoalition($coalitionHistogram);
    }

    private static function normalizeCoalitionString(string $coalition): ?string
    {
        $value = trim($coalition);
        if ($value === '') {
            return null;
        }

        $value = strtolower($value);

        return match ($value) {
            'blue', 'blu', 'ally', 'allies', 'friendly', 'friendlies' => 'allies',
            'red', 'enemy', 'enemies', 'hostile', 'hostiles' => 'enemies',
            'neutral', 'neutrals' => 'neutral',
            default => $value,
        };
    }

    private static function extractCoalition(array $object): ?string
    {
        if (!isset($object['Coalition'])) {
            return null;
        }

        $coalition = self::normalizeCoalitionString((string)$object['Coalition']);
        if ($coalition !== null) {
            return $coalition;
        }

        return null;
    }

    /**
     * @param array<string, int> $histogram
     */
    private static function resolveDominantCoalition(array $histogram): ?string
    {
        if ($histogram === []) {
            return null;
        }

        arsort($histogram);
        $dominant = array_key_first($histogram);

        return $dominant !== null ? (string)$dominant : null;
    }
}
