<?php

declare(strict_types=1);

namespace Tests\EventGraph;

use EventGraph\EventGraphAggregator;
use PHPUnit\Framework\TestCase;
use Tests\EventGraph\Fixture\TacviewFixtureLoader;

final class EventGraphAggregatorTest extends TestCase
{
    public function testReconciliationCollapsesDuplicateDestructions(): void
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
        $this->assertSame(2, $metrics['merged_events'], 'Four raw destructions across two recordings should collapse to two merged events.');
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

    public function testDestructionsAreAttributedToFinalBlowShooterWithRespawnGuard(): void
    {
        $config = require PROJECT_ROOT . '/config.php';
        $fixtureFiles = TacviewFixtureLoader::build('kill_attribution');

        $aggregator = new EventGraphAggregator(
            $config['default_language'] ?? 'en',
            $config['aggregator'] ?? []
        );

        foreach ($fixtureFiles as $file) {
            $aggregator->ingestFile($file);
        }

        $mission = $aggregator->toAggregatedMission();
        $destroyed = [];
        foreach ($mission->getEvents() as $event) {
            if (($event['Action'] ?? '') === 'HasBeenDestroyed') {
                $pilot = $event['PrimaryObject']['Pilot'] ?? '';
                $destroyed[$pilot] = $event;
            }
        }

        // The AI SAM that scored the final blow 5s earlier is credited as the killer.
        $this->assertArrayHasKey('Sting 1-4 | Corsac', $destroyed);
        $this->assertSame(
            'SAM-1',
            $destroyed['Sting 1-4 | Corsac']['ParentObject']['ID'] ?? null,
            'A destruction should be attributed to the final-blow shooter, including AI SAMs.'
        );

        // The only hit on Ghost preceded a respawn (takeoff), so it must NOT be credited.
        $this->assertArrayHasKey('Ghost 1-1 | Maverick', $destroyed);
        $this->assertArrayNotHasKey(
            'ParentObject',
            $destroyed['Ghost 1-1 | Maverick'],
            'A hit from a previous life (before a respawn takeoff) must not be credited to a later death.'
        );

        $metrics = $aggregator->getMetrics();
        $this->assertGreaterThanOrEqual(1, $metrics['hit_attribution_inferences']);
    }

    public function testOnlyAirUnitsAreClassifiedAsMidairDisconnects(): void
    {
        $config = require PROJECT_ROOT . '/config.php';
        $fixtureFiles = TacviewFixtureLoader::build('disconnect_classification');

        $aggregator = new EventGraphAggregator(
            $config['default_language'] ?? 'en',
            $config['aggregator'] ?? []
        );

        foreach ($fixtureFiles as $file) {
            $aggregator->ingestFile($file);
        }

        $aggregator->toAggregatedMission();
        $metrics = $aggregator->getMetrics();

        // Three combat-less destructions (aircraft, missile, tank). Only the aircraft is a
        // plausible pilot disconnect; the weapon and ground unit must not be flagged.
        $this->assertSame(
            1,
            $metrics['disconnect_midair_flagged'],
            'Only air units should be classified as midair disconnects; weapons and ground units must be excluded.'
        );
    }
}
