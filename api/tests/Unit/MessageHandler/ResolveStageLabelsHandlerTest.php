<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Geo\ReverseGeocoder;
use App\Message\ResolveStageLabels;
use App\MessageHandler\ResolveStageLabelsHandler;
use App\Repository\TripRequestRepositoryInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

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
        $repo->expects(self::once())
            ->method('updateStageLabels')
            ->with(self::TRIP_ID, 1, 'Lyon', 'Lyon');

        $tracker = $this->createMock(TripGenerationTrackerInterface::class);
        $tracker->method('current')->willReturn(null);

        $handler = new ResolveStageLabelsHandler($repo, $this->geocoder('Lyon'), $tracker);
        $handler(new ResolveStageLabels(self::TRIP_ID, generation: 1));
    }

    #[Test]
    public function skipsASupersededGeneration(): void
    {
        $repo = $this->createMock(TripRequestRepositoryInterface::class);
        $repo->expects(self::never())->method('getStages');
        $repo->expects(self::never())->method('updateStageLabels');

        $tracker = $this->createMock(TripGenerationTrackerInterface::class);
        $tracker->method('current')->willReturn(5); // newer than the message's generation 2

        // No HTTP responses queued: a geocoding call would throw, proving none happens.
        $geocoder = new ReverseGeocoder(new MockHttpClient([]), new ArrayAdapter());

        $handler = new ResolveStageLabelsHandler($repo, $geocoder, $tracker);
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
        $repo->expects(self::once())
            ->method('updateStageLabels')
            ->with(self::TRIP_ID, 2, 'Lyon', 'Lyon');

        $tracker = $this->createMock(TripGenerationTrackerInterface::class);
        $tracker->method('current')->willReturn(null);

        // A single queued response: a second HTTP call would throw, so the rest day
        // must resolve both endpoints from one lookup.
        $geocoder = new ReverseGeocoder(
            new MockHttpClient(new MockResponse('{"address":{"city":"Lyon"}}')),
            new ArrayAdapter(),
        );

        $handler = new ResolveStageLabelsHandler($repo, $geocoder, $tracker);
        $handler(new ResolveStageLabels(self::TRIP_ID, generation: 1));
    }

    private function geocoder(string $city): ReverseGeocoder
    {
        return new ReverseGeocoder(
            new MockHttpClient(static fn (): MockResponse => new MockResponse(\sprintf('{"address":{"city":"%s"}}', $city))),
            new ArrayAdapter(),
        );
    }
}
