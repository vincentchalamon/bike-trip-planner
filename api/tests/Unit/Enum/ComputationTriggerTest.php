<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enum;

use App\Enum\ComputationName;
use App\Enum\ComputationTrigger;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The table every re-dispatch decision now reads (ADR-070).
 *
 * It replaced four hand-written lists that had drifted apart, so what matters here is not
 * that a given computation appears, but that the table stays exhaustive: a new enrichment
 * added without a trigger is a computation nothing will ever re-run.
 */
final class ComputationTriggerTest extends TestCase
{
    /**
     * The guard against the next gap. Every enrichment has to declare at least one trigger;
     * the four exemptions are named, so adding an eighteenth case forces a decision.
     */
    #[Test]
    public function everyEnrichmentDeclaresATrigger(): void
    {
        $exempt = [
            // Cascaded by FetchWeatherHandler once the forecast lands.
            ComputationName::WIND,
            ComputationName::FORDS,
            // Root computations and on-demand work, not enrichments.
            ComputationName::ROUTE,
            ComputationName::STAGES,
            ComputationName::ROUTE_SEGMENT,
        ];

        foreach (ComputationName::cases() as $computation) {
            if (\in_array($computation, $exempt, true)) {
                self::assertSame([], $computation->triggers(), \sprintf('%s is exempt and must declare no trigger.', $computation->value));

                continue;
            }

            self::assertNotSame([], $computation->triggers(), \sprintf(
                '%s would never be re-dispatched: declare what invalidates it, or add it to the exemptions with a reason.',
                $computation->value,
            ));
        }
    }

    /**
     * The seven a stage merge used to leave holding alerts drawn from the pre-merge line.
     */
    #[Test]
    public function theGroupsAMergeUsedToForgetDependOnGeometry(): void
    {
        $geometry = ComputationName::dependingOn(ComputationTrigger::GEOMETRY);

        foreach ([
            ComputationName::WATER_POINTS,
            ComputationName::HEALTH_SERVICES,
            ComputationName::RAILWAY_STATIONS,
            ComputationName::CULTURAL_POIS,
            ComputationName::BORDER_CROSSING,
            ComputationName::FERRIES,
        ] as $missedBefore) {
            self::assertContains($missedBefore, $geometry);
        }
    }

    /**
     * The three a change of start date used to leave on the old date: the resupply verdict
     * keeps a weekday, the seasonal one a month, the sunset alert a date.
     */
    #[Test]
    public function theGroupsADateChangeUsedToForgetDependOnDates(): void
    {
        $dates = ComputationName::dependingOn(ComputationTrigger::DATES);

        self::assertContains(ComputationName::POIS, $dates);
        self::assertContains(ComputationName::ACCOMMODATIONS, $dates);
        self::assertContains(ComputationName::TERRAIN, $dates);
    }

    /**
     * Corridor-only work must not ride along on a date change: a rest day shifts every later
     * date without moving a single metre of line.
     */
    #[Test]
    public function corridorOnlyWorkIsNotDateDependent(): void
    {
        $dates = ComputationName::dependingOn(ComputationTrigger::DATES);

        self::assertNotContains(ComputationName::WATER_POINTS, $dates);
        self::assertNotContains(ComputationName::BIKE_SHOPS, $dates);
        self::assertNotContains(ComputationName::FERRIES, $dates);
        self::assertNotContains(ComputationName::CULTURAL_POIS, $dates);
    }

    #[Test]
    public function aCalendarCheckReadsADateAndNoGeometry(): void
    {
        self::assertContains(ComputationName::CALENDAR, ComputationName::dependingOn(ComputationTrigger::DATES));
        self::assertNotContains(ComputationName::CALENDAR, ComputationName::dependingOn(ComputationTrigger::GEOMETRY));
    }

    /**
     * Asking for both is the full pipeline, which is what `TripAnalysisDispatcher::dispatch()`
     * relies on to stay equivalent to the list it replaced.
     */
    #[Test]
    public function bothTriggersTogetherCoverEveryEnrichment(): void
    {
        $both = ComputationName::dependingOn(ComputationTrigger::GEOMETRY, ComputationTrigger::DATES);

        self::assertCount(13, $both);
    }
}
