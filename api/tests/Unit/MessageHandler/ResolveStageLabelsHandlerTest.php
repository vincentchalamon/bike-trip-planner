<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Message\ResolveStageLabels;
use App\MessageHandler\ResolveStageLabelsHandler;
use App\Osm\AdminBoundaryRepositoryInterface;
use App\Repository\TripRequestRepositoryInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ResolveStageLabelsHandlerTest extends TestCase
{
    private const string TRIP_ID = '01936f6e-0000-7000-8000-000000000099';

    #[Test]
    public function resolvesAndPersistsLabelsForEachStage(): void
    {
        $stage = new Stage(
            tripId: self::TRIP_ID,
            dayNumber: 1,
            distance: 50.0,
            elevation: 0.0,
            startPoint: new Coordinate(45.76, 4.84),
            endPoint: new Coordinate(45.90, 4.90),
        );

        $repo = $this->createMock(TripRequestRepositoryInterface::class);
        $repo->method('getStages')->willReturn([$stage]);
        $repo->method('getLocale')->willReturn('fr');
        $repo->expects(self::once())
            ->method('updateStageLabels')
            ->with(self::TRIP_ID, 1, 'Lyon', 'Villefranche-sur-Saône');

        $boundaries = $this->createMock(AdminBoundaryRepositoryInterface::class);
        // The trip locale is passed through to the index lookup.
        $boundaries->expects(self::exactly(2))
            ->method('findLocalityAt')
            ->willReturnMap([
                [45.76, 4.84, 'fr', 'Lyon'],
                [45.90, 4.90, 'fr', 'Villefranche-sur-Saône'],
            ]);

        $tracker = $this->createStub(TripGenerationTrackerInterface::class);
        $tracker->method('current')->willReturn(null);

        $handler = new ResolveStageLabelsHandler($repo, $boundaries, $tracker);
        $handler(new ResolveStageLabels(self::TRIP_ID, generation: 1));
    }

    #[Test]
    public function persistsNullWhenThePointLiesOutsideTheProvisionedZone(): void
    {
        $stage = new Stage(
            tripId: self::TRIP_ID,
            dayNumber: 1,
            distance: 50.0,
            elevation: 0.0,
            startPoint: new Coordinate(45.76, 4.84),
            endPoint: new Coordinate(0.0, 0.0),
        );

        $repo = $this->createMock(TripRequestRepositoryInterface::class);
        $repo->method('getStages')->willReturn([$stage]);
        $repo->method('getLocale')->willReturn(null);
        $repo->expects(self::once())
            ->method('updateStageLabels')
            ->with(self::TRIP_ID, 1, 'Lyon', null);

        $boundaries = $this->createStub(AdminBoundaryRepositoryInterface::class);
        // No trip locale: the lookup falls back to en.
        $boundaries->method('findLocalityAt')->willReturnMap([
            [45.76, 4.84, 'en', 'Lyon'],
            [0.0, 0.0, 'en', null],
        ]);

        $tracker = $this->createStub(TripGenerationTrackerInterface::class);
        $tracker->method('current')->willReturn(null);

        $handler = new ResolveStageLabelsHandler($repo, $boundaries, $tracker);
        $handler(new ResolveStageLabels(self::TRIP_ID, generation: 1));
    }

    #[Test]
    public function skipsASupersededGeneration(): void
    {
        $repo = $this->createMock(TripRequestRepositoryInterface::class);
        $repo->expects(self::never())->method('getStages');
        $repo->expects(self::never())->method('updateStageLabels');

        $tracker = $this->createStub(TripGenerationTrackerInterface::class);
        $tracker->method('current')->willReturn(5); // newer than the message's generation 2

        $boundaries = $this->createMock(AdminBoundaryRepositoryInterface::class);
        $boundaries->expects(self::never())->method('findLocalityAt');

        $handler = new ResolveStageLabelsHandler($repo, $boundaries, $tracker);
        $handler(new ResolveStageLabels(self::TRIP_ID, generation: 2));
    }

    #[Test]
    public function resolvesARestDayWithASingleLookup(): void
    {
        $restDay = new Stage(
            tripId: self::TRIP_ID,
            dayNumber: 2,
            distance: 0.0,
            elevation: 0.0,
            startPoint: new Coordinate(45.76, 4.84),
            endPoint: new Coordinate(45.76, 4.84),
            isRestDay: true,
        );

        $repo = $this->createMock(TripRequestRepositoryInterface::class);
        $repo->method('getStages')->willReturn([$restDay]);
        $repo->method('getLocale')->willReturn('fr');
        $repo->expects(self::once())
            ->method('updateStageLabels')
            ->with(self::TRIP_ID, 2, 'Lyon', 'Lyon');

        $tracker = $this->createStub(TripGenerationTrackerInterface::class);
        $tracker->method('current')->willReturn(null);

        // A rest day shares its endpoint with the previous arrival: exactly one
        // index lookup must serve both labels.
        $boundaries = $this->createMock(AdminBoundaryRepositoryInterface::class);
        $boundaries->expects(self::once())->method('findLocalityAt')->willReturn('Lyon');

        $handler = new ResolveStageLabelsHandler($repo, $boundaries, $tracker);
        $handler(new ResolveStageLabels(self::TRIP_ID, generation: 1));
    }

    #[Test]
    public function doesNothingWhenNoStagesAreFound(): void
    {
        $repo = $this->createMock(TripRequestRepositoryInterface::class);
        $repo->method('getStages')->willReturn(null);
        $repo->expects(self::never())->method('updateStageLabels');

        $tracker = $this->createStub(TripGenerationTrackerInterface::class);
        $tracker->method('current')->willReturn(null);

        $boundaries = $this->createMock(AdminBoundaryRepositoryInterface::class);
        $boundaries->expects(self::never())->method('findLocalityAt');

        $handler = new ResolveStageLabelsHandler($repo, $boundaries, $tracker);
        $handler(new ResolveStageLabels(self::TRIP_ID));
    }
}
