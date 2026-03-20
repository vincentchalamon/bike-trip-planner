<?php

declare(strict_types=1);

namespace App\Tests\Unit\State;

use ApiPlatform\Metadata\Post;
use App\ApiResource\AccommodationScanRequest;
use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Enum\ComputationName;
use App\Message\ScanAccommodations;
use App\Repository\TripRequestRepositoryInterface;
use App\Scanner\QueryBuilderInterface;
use App\State\AccommodationScanProcessor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class AccommodationScanProcessorTest extends TestCase
{
    #[Test]
    public function throwsNotFoundWhenTripDoesNotExist(): void
    {
        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getRequest')->willReturn(null);

        $messageBus = $this->createStub(MessageBusInterface::class);
        $computationTracker = $this->createStub(ComputationTrackerInterface::class);

        $generationTracker = $this->createStub(TripGenerationTrackerInterface::class);
        $generationTracker->method('current')->willReturn(1);
        $processor = new AccommodationScanProcessor($messageBus, $tripStateManager, $computationTracker, $generationTracker);

        $this->expectException(NotFoundHttpException::class);

        $processor->process(
            new AccommodationScanRequest(),
            new Post(),
            ['tripId' => 'unknown-trip'],
        );
    }

    #[Test]
    public function dispatchesScanAccommodationsWithCorrectRadiusAndReturnsTrip(): void
    {
        $tripId = 'trip-abc';
        $radiusKm = 7;
        $expectedRadiusMeters = $radiusKm * 1000;

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getRequest')->willReturn(new TripRequest());

        $computationTracker = $this->createMock(ComputationTrackerInterface::class);
        $computationTracker->expects($this->once())
            ->method('resetComputation')
            ->with($tripId, ComputationName::ACCOMMODATIONS);
        $computationTracker->method('getStatuses')->willReturn(['accommodations' => 'pending']);

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(static fn (ScanAccommodations $message): bool => $message->tripId === $tripId && $message->radiusMeters === $expectedRadiusMeters))
            ->willReturn(new Envelope(new ScanAccommodations($tripId, $expectedRadiusMeters)));

        $generationTracker = $this->createStub(TripGenerationTrackerInterface::class);
        $generationTracker->method('current')->willReturn(1);
        $processor = new AccommodationScanProcessor($messageBus, $tripStateManager, $computationTracker, $generationTracker);

        $data = new AccommodationScanRequest();
        $data->radiusKm = $radiusKm;

        $trip = $processor->process($data, new Post(), ['tripId' => $tripId]);

        $this->assertSame($tripId, $trip->id);
        $this->assertSame(['accommodations' => 'pending'], $trip->computationStatus);
    }

    #[Test]
    public function dispatchesDefaultRadiusMetersWhenNoRadiusProvided(): void
    {
        $tripId = 'trip-default';

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getRequest')->willReturn(new TripRequest());

        $computationTracker = $this->createStub(ComputationTrackerInterface::class);
        $computationTracker->method('getStatuses')->willReturn([]);

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(static fn (ScanAccommodations $message): bool => QueryBuilderInterface::DEFAULT_ACCOMMODATION_RADIUS_METERS === $message->radiusMeters))
            ->willReturn(new Envelope(new ScanAccommodations($tripId)));

        $generationTracker = $this->createStub(TripGenerationTrackerInterface::class);
        $generationTracker->method('current')->willReturn(1);
        $processor = new AccommodationScanProcessor($messageBus, $tripStateManager, $computationTracker, $generationTracker);

        $processor->process(new AccommodationScanRequest(), new Post(), ['tripId' => $tripId]);
    }

    #[Test]
    public function propagatesEnabledAccommodationTypesFromStoredRequest(): void
    {
        $tripId = 'trip-types';
        $enabledTypes = ['camp_site', 'hostel'];

        $tripRequest = new TripRequest();
        $tripRequest->enabledAccommodationTypes = $enabledTypes;

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getRequest')->willReturn($tripRequest);

        $computationTracker = $this->createStub(ComputationTrackerInterface::class);
        $computationTracker->method('getStatuses')->willReturn([]);

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(static fn (ScanAccommodations $message): bool => $message->enabledAccommodationTypes === $enabledTypes))
            ->willReturn(new Envelope(new ScanAccommodations($tripId)));

        $generationTracker = $this->createStub(TripGenerationTrackerInterface::class);
        $generationTracker->method('current')->willReturn(1);
        $processor = new AccommodationScanProcessor($messageBus, $tripStateManager, $computationTracker, $generationTracker);

        $processor->process(new AccommodationScanRequest(), new Post(), ['tripId' => $tripId]);
    }
}
