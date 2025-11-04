<?php

declare(strict_types=1);

namespace EventGraph;

use function array_key_exists;
use function is_numeric;
use function is_string;
use function trim;

final class EventEvidence
{
    public function __construct(
        public readonly string $sourceId,
        public readonly int $sourceEventId,
        public readonly float $missionTime,
        public readonly array $rawEvent,
        public readonly float $confidence,
        public readonly string $detailTier = 'C'
    ) {
    }

    public function withTimeShifted(float $delta): self
    {
        $rawEvent = $this->rawEvent;
        if (array_key_exists('Time', $rawEvent) && is_numeric($rawEvent['Time'])) {
            $rawEvent['Time'] = (float)$rawEvent['Time'] + $delta;
        }

        return new self(
            $this->sourceId,
            $this->sourceEventId,
            $this->missionTime + $delta,
            $rawEvent,
            $this->confidence,
            $this->detailTier
        );
    }

    public static function classifyDetailTier(array $event): string
    {
        $primary = $event['PrimaryObject'] ?? null;
        $secondary = $event['SecondaryObject'] ?? null;

        $primaryHasPilot = self::objectHasNonEmpty($primary, ['Pilot', 'Name', 'Group']);
        $primaryHasType = self::objectHasNonEmpty($primary, ['Type']);
        $secondaryHasPilot = self::objectHasNonEmpty($secondary, ['Pilot', 'Parent', 'Group']);
        $secondaryHasWeapon = self::objectHasNonEmpty($secondary, ['Name', 'Type']);

        if ($primaryHasPilot && $primaryHasType && $secondaryHasWeapon && ($secondaryHasPilot || self::objectHasNonEmpty($secondary, ['Coalition']))) {
            return 'A';
        }

        if ($primaryHasPilot && ($secondaryHasWeapon || $secondaryHasPilot)) {
            return 'B';
        }

        if ($primaryHasType || $secondaryHasWeapon) {
            return 'B';
        }

        return 'C';
    }

    private static function objectHasNonEmpty(?array $object, array $keys): bool
    {
        if ($object === null) {
            return false;
        }

        foreach ($keys as $key) {
            if (!array_key_exists($key, $object)) {
                continue;
            }

            $value = $object[$key];
            if ($value === null) {
                continue;
            }

            if (is_string($value) && trim($value) === '') {
                continue;
            }

            return true;
        }

        return false;
    }

    public function getDetailTier(): string
    {
        return $this->detailTier;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'sourceId' => $this->sourceId,
            'sourceEventId' => $this->sourceEventId,
            'missionTime' => $this->missionTime,
            'confidence' => $this->confidence,
            'detailTier' => $this->detailTier,
        ];
    }
}
