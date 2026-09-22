<?php

declare(strict_types=1);

namespace App\Tests\Unit\State;

use ApiPlatform\Metadata\Get;
use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationTrackerInterface;
use App\Exception\TripNotFoundException;
use App\Repository\TripRequestRepositoryInterface;
use App\State\TripGpxProvider;
use App\State\TripLocker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * One operation serves three formats, and they do not want the same thing (ADR-074).
 */
#[CoversClass(TripGpxProvider::class)]
final class TripGpxProviderTest extends TestCase
{
    private const string TRIP_ID = 'trip-1';

    /**
     * The status map is a cache read that falls through to a query once the cache has let go
     * (ADR-072). The export normalizers build a file out of the stages and never look at it,
     * so a download must not pay for it.
     */
    #[Test]
    public function anExportDoesNotAskForTheComputationStatus(): void
    {
        $tracker = $this->createMock(ComputationTrackerInterface::class);
        $tracker->expects($this->never())->method('getStatuses');

        $trip = $this->provider($tracker)->provide(new Get(), ['id' => self::TRIP_ID], $this->contextFor('gpx'));

        self::assertSame(self::TRIP_ID, $trip->id);
        self::assertSame([], $trip->computationStatus);
    }

    #[Test]
    public function theCanonicalReadCarriesTheComputationStatus(): void
    {
        $tracker = $this->createStub(ComputationTrackerInterface::class);
        $tracker->method('getStatuses')->willReturn(['route' => 'done']);

        $trip = $this->provider($tracker)->provide(new Get(), ['id' => self::TRIP_ID], $this->contextFor('jsonld'));

        self::assertSame(['route' => 'done'], $trip->computationStatus);
    }

    /**
     * `isLocked` costs nothing — the request is already loaded — so it is answered on the
     * export too, rather than fabricating a `false` the next reader would have to distrust.
     */
    #[Test]
    public function theLockIsAnsweredTruthfullyOnEveryFormat(): void
    {
        $tracker = $this->createStub(ComputationTrackerInterface::class);

        foreach (['gpx', 'fit', 'jsonld'] as $format) {
            $trip = $this->provider($tracker, startDate: new \DateTimeImmutable('today -1 day'))
                ->provide(new Get(), ['id' => self::TRIP_ID], $this->contextFor($format));

            self::assertTrue($trip->isLocked, $format);
        }
    }

    /**
     * A trip whose stages have not been computed is an ordinary trip — `POST /trips` hands out
     * its `@id` before any stage exists — but there is no file to build from it.
     */
    #[Test]
    public function aTripWithoutStagesReadsButDoesNotExport(): void
    {
        $tracker = $this->createStub(ComputationTrackerInterface::class);
        $provider = $this->provider($tracker, withStages: false);

        $trip = $provider->provide(new Get(), ['id' => self::TRIP_ID], $this->contextFor('jsonld'));
        self::assertSame(self::TRIP_ID, $trip->id);

        $this->expectException(TripNotFoundException::class);
        $provider->provide(new Get(), ['id' => self::TRIP_ID], $this->contextFor('gpx'));
    }

    #[Test]
    public function anUnknownTripIsNotFoundInAnyFormat(): void
    {
        $repository = $this->createStub(TripRequestRepositoryInterface::class);
        $repository->method('getRequest')->willReturn(null);

        $provider = new TripGpxProvider(
            $repository,
            $this->createStub(ComputationTrackerInterface::class),
            new TripLocker(),
        );

        $this->expectException(TripNotFoundException::class);
        $provider->provide(new Get(), ['id' => 'unknown'], $this->contextFor('jsonld'));
    }

    private function provider(
        ComputationTrackerInterface $tracker,
        ?\DateTimeImmutable $startDate = null,
        bool $withStages = true,
    ): TripGpxProvider {
        $request = new TripRequest();
        $request->startDate = $startDate;

        $repository = $this->createStub(TripRequestRepositoryInterface::class);
        $repository->method('getRequest')->willReturn($request);
        $repository->method('getStages')->willReturn($withStages ? [] : null);

        return new TripGpxProvider($repository, $tracker, new TripLocker());
    }

    /** @return array<string, mixed> */
    private function contextFor(string $format): array
    {
        $request = new Request();
        $request->setRequestFormat($format);

        return ['request' => $request];
    }
}
