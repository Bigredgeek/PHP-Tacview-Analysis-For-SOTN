<?php

declare(strict_types=1);

namespace Tests\EventGraph;

use EventGraph\EventGraphAggregator;
use PHPUnit\Framework\TestCase;
use Tests\EventGraph\Fixture\TacviewFixtureLoader;

final class EventGraphAggregatorTest extends TestCase
{
    public function testCompositeSignaturesAndReconciliationCollapseDuplicates(): void
    {
        $config = require PROJECT_ROOT . '/config.php';
        $fixtureFiles = TacviewFixtureLoader::build('menton_dupe');

        $aggregator = new EventGraphAggregator(
            $config['default_language'] ?? 'en',
            $config['aggregator'] ?? []
        );

        foreach ($fixtureFiles as $file) {
            $aggregator->ingestFile($file);
        }

        $mission = $aggregator->toAggregatedMission();
        $events = array_values($mission->getEvents());
        $destroyed = array_values(array_filter(
            $events,
            static fn (array $event): bool => ($event['Action'] ?? '') === 'HasBeenDestroyed'
        ));

        $this->assertCount(2, $destroyed, 'Duplicate destructions should collapse to two unique targets.');

        $primaryIds = array_map(
            static fn (array $event): ?string => $event['PrimaryObject']['ID'] ?? null,
            $destroyed
        );
        sort($primaryIds);
        $this->assertSame(['GROUND-OLY-ALPHA', 'GROUND-OLY-BRAVO'], $primaryIds);

        foreach ($destroyed as $event) {
            $this->assertSame(
                2,
                count($event['Evidence'] ?? []),
                'Merged destructions should retain evidence from both recordings.'
            );
        }

        $metrics = $aggregator->getMetrics();
        $this->assertSame(2, $metrics['merged_events']);
        $this->assertSame(4, $metrics['composite_signatures_emitted']);
        $this->assertGreaterThanOrEqual(1, $metrics['composite_signature_merges']);
    }

    public function testMovementEventsDeduplicateWithoutSecondaryObjects(): void
    {
        $config = require PROJECT_ROOT . '/config.php';
        $fixtureFiles = TacviewFixtureLoader::build('movement_dupes');

        $aggregator = new EventGraphAggregator(
            $config['default_language'] ?? 'en',
            $config['aggregator'] ?? []
        );

        foreach ($fixtureFiles as $file) {
            $aggregator->ingestFile($file);
        }

        $mission = $aggregator->toAggregatedMission();
        $events = array_values($mission->getEvents());

        $enteredArea = array_values(array_filter(
            $events,
            static fn (array $event): bool => ($event['Action'] ?? '') === 'HasEnteredTheArea'
        ));
        $takenOff = array_values(array_filter(
            $events,
            static fn (array $event): bool => ($event['Action'] ?? '') === 'HasTakenOff'
        ));
        $landed = array_values(array_filter(
            $events,
            static fn (array $event): bool => ($event['Action'] ?? '') === 'HasLanded'
        ));

        $this->assertCount(1, $enteredArea, 'Entered-the-area events should deduplicate across recordings.');
        $this->assertSame(2, count($enteredArea[0]['Evidence'] ?? []));

        $this->assertCount(1, $takenOff, 'Takeoff events should deduplicate across recordings.');
        $this->assertSame(2, count($takenOff[0]['Evidence'] ?? []));

        $this->assertCount(1, $landed, 'Landing events should deduplicate across recordings.');
        $this->assertSame(2, count($landed[0]['Evidence'] ?? []));
    }
}
