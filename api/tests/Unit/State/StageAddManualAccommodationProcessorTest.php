<?php

declare(strict_types=1);

namespace App\Tests\Unit\State;

use ApiPlatform\Metadata\Post;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\ApiResource\StageManualAccommodationRequest;
use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Geo\GeocoderInterface;
use App\Mapper\StageResponseMapper;
use App\Message\RecalculateStages;
use App\Repository\TripRequestRepositoryInterface;
use App\State\StageAddManualAccommodationProcessor;
use App\State\TripLocker;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

#[AllowMockObjectsWithoutExpectations]
final class StageAddManualAccommodationProcessorTest extends TestCase
{
    /** @return list<Stage> */
    private function twoStages(): array
    {
        return [
            new Stage('trip-1', 0, 40.0, 200.0, new Coordinate(48.0, 2.0), new Coordinate(48.5, 2.5)),
            new Stage('trip-1', 1, 35.0, 150.0, new Coordinate(48.5, 2.5), new Coordinate(49.0, 3.0)),
        ];
    }

    private function request(?StageManualAccommodationRequest $data = null): StageManualAccommodationRequest
    {
        if ($data instanceof StageManualAccommodationRequest) {
            return $data;
        }

        $req = new StageManualAccommodationRequest();
        $req->name = 'Chez Test';
        $req->address = '10 rue de la Paix, Paris';

        return $req;
    }

    private function processor(
        TripRequestRepositoryInterface $repo,
        GeocoderInterface $geocoder,
        ?MessageBusInterface $bus = null,
    ): StageAddManualAccommodationProcessor {
        if (!$bus instanceof MessageBusInterface) {
            $stub = $this->createStub(MessageBusInterface::class);
            $stub->method('dispatch')->willReturnCallback(static fn (object $m): Envelope => new Envelope($m));
            $bus = $stub;
        }

        return new StageAddManualAccommodationProcessor(
            $repo,
            $bus,
            new StageResponseMapper($this->createStub(ComputationTrackerInterface::class)),
            $this->createStub(TripGenerationTrackerInterface::class),
            new TripLocker(),
            $geocoder,
        );
    }

    #[Test]
    public function geocodesAndSelectsManualAccommodation(): void
    {
        $stages = $this->twoStages();
        $stored = null;

        $repo = $this->createMock(TripRequestRepositoryInterface::class);
        $repo->method('getRequest')->willReturn(new TripRequest());
        $repo->method('getStages')->willReturn($stages);
        $repo->expects(self::once())->method('storeStages')
            ->willReturnCallback(function (string $tripId, array $s) use (&$stored): void {
                $stored = $s;
            });

        $geocoder = $this->createStub(GeocoderInterface::class);
        $geocoder->method('geocode')->willReturn(new Coordinate(48.8566, 2.3522));

        $req = $this->request();
        $req->priceTotal = 90.0;
        $req->url = 'https://booking.example/abc';

        $response = $this->processor($repo, $geocoder)->process($req, new Post(), ['tripId' => 'trip-1', 'index' => 0]);

        self::assertNotNull($stored);
        $stage = $stored[0];
        self::assertNotNull($stage->selectedAccommodation);
        $acc = $stage->selectedAccommodation;
        self::assertSame('manual', $acc->source);
        self::assertSame('other', $acc->type);
        self::assertSame('Chez Test', $acc->name);
        self::assertSame('10 rue de la Paix, Paris', $acc->address);
        self::assertSame(90.0, $acc->estimatedPriceMin);
        self::assertSame(90.0, $acc->estimatedPriceMax);
        self::assertTrue($acc->isExactPrice);
        self::assertSame('https://booking.example/abc', $acc->url);
        // Only the manual accommodation is kept, endPoint moved to its coords.
        self::assertCount(1, $stage->accommodations);
        self::assertSame(48.8566, $stage->endPoint->lat);
        self::assertSame(2.3522, $stage->endPoint->lon);
        // Next stage startPoint follows.
        self::assertSame(48.8566, $stored[1]->startPoint->lat);
        self::assertSame(2.3522, $stored[1]->startPoint->lon);
        self::assertSame(0, $response->dayNumber);
    }

    #[Test]
    public function omittedPriceProducesNoExactPrice(): void
    {
        $repo = $this->createMock(TripRequestRepositoryInterface::class);
        $repo->method('getRequest')->willReturn(new TripRequest());
        $repo->method('getStages')->willReturn($this->twoStages());
        $stored = null;
        $repo->method('storeStages')->willReturnCallback(function (string $t, array $s) use (&$stored): void {
            $stored = $s;
        });

        $geocoder = $this->createStub(GeocoderInterface::class);
        $geocoder->method('geocode')->willReturn(new Coordinate(48.0, 2.0));

        $this->processor($repo, $geocoder)->process($this->request(), new Post(), ['tripId' => 'trip-1', 'index' => 0]);

        self::assertNotNull($stored);
        $acc = $stored[0]->selectedAccommodation;
        self::assertNotNull($acc);
        self::assertSame(0.0, $acc->estimatedPriceMin);
        self::assertSame(0.0, $acc->estimatedPriceMax);
        self::assertFalse($acc->isExactPrice);
        self::assertNull($acc->url);
    }

    #[Test]
    public function dispatchesRecalculationForAffectedStages(): void
    {
        $repo = $this->createStub(TripRequestRepositoryInterface::class);
        $repo->method('getRequest')->willReturn(new TripRequest());
        $repo->method('getStages')->willReturn($this->twoStages());

        $geocoder = $this->createStub(GeocoderInterface::class);
        $geocoder->method('geocode')->willReturn(new Coordinate(48.0, 2.0));

        $recalc = null;
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $m) use (&$recalc): Envelope {
            if ($m instanceof RecalculateStages) {
                $recalc = $m;
            }

            return new Envelope($m);
        });

        $this->processor($repo, $geocoder, $bus)->process($this->request(), new Post(), ['tripId' => 'trip-1', 'index' => 0]);

        self::assertInstanceOf(RecalculateStages::class, $recalc);
        self::assertSame([0, 1], $recalc->affectedIndices);
        self::assertTrue($recalc->skipAccommodationScan);
    }

    #[Test]
    public function unresolvableAddressThrows422AndPersistsNothing(): void
    {
        $repo = $this->createMock(TripRequestRepositoryInterface::class);
        $repo->method('getRequest')->willReturn(new TripRequest());
        $repo->method('getStages')->willReturn($this->twoStages());
        $repo->expects(self::never())->method('storeStages');

        $geocoder = $this->createStub(GeocoderInterface::class);
        $geocoder->method('geocode')->willReturn(null);

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->processor($repo, $geocoder)->process($this->request(), new Post(), ['tripId' => 'trip-1', 'index' => 0]);
    }

    #[Test]
    public function lockedTripThrows423(): void
    {
        $locked = new TripRequest();
        $locked->startDate = new \DateTimeImmutable('yesterday');

        $repo = $this->createStub(TripRequestRepositoryInterface::class);
        $repo->method('getRequest')->willReturn($locked);
        $repo->method('getStages')->willReturn($this->twoStages());

        $geocoder = $this->createStub(GeocoderInterface::class);

        try {
            $this->processor($repo, $geocoder)->process($this->request(), new Post(), ['tripId' => 'trip-1', 'index' => 0]);
            self::fail('Expected HttpException.');
        } catch (HttpException $httpException) {
            self::assertSame(423, $httpException->getStatusCode());
        }
    }
}
