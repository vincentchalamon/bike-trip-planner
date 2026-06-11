<?php

declare(strict_types=1);

namespace App\Tests\Unit\State;

use ApiPlatform\Metadata\Patch;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\ApiResource\StageRequest;
use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Mapper\StageResponseMapper;
use App\Repository\TripRequestRepositoryInterface;
use App\State\StageMoveProcessor;
use App\State\TripLocker;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Messenger\MessageBusInterface;

#[AllowMockObjectsWithoutExpectations]
final class StageMoveProcessorTest extends TestCase
{
    #[Test]
    public function lockedTripThrowsHttpException(): void
    {
        $coord = new Coordinate(lat: 48.0, lon: 2.0);
        $stage0 = new Stage(tripId: 'trip-1', dayNumber: 1, distance: 80.0, elevation: 500.0, startPoint: $coord, endPoint: $coord);
        $stage1 = new Stage(tripId: 'trip-1', dayNumber: 2, distance: 90.0, elevation: 600.0, startPoint: $coord, endPoint: $coord);

        $lockedRequest = new TripRequest();
        $lockedRequest->startDate = new \DateTimeImmutable('yesterday');

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getRequest')->willReturn($lockedRequest);
        $tripStateManager->method('getStages')->willReturn([$stage0, $stage1]);

        $stageResponseMapper = new StageResponseMapper($this->createStub(ComputationTrackerInterface::class));

        $generationTracker = $this->createStub(TripGenerationTrackerInterface::class);
        $generationTracker->method('increment')->willReturn(1);

        $processor = new StageMoveProcessor(
            $tripStateManager,
            $this->createStub(MessageBusInterface::class),
            $stageResponseMapper,
            $generationTracker,
            new TripLocker(),
        );

        $data = new StageRequest();
        $data->toIndex = 1;

        try {
            $processor->process($data, new Patch(), ['tripId' => 'trip-1', 'index' => 0]);
            self::fail('Expected HttpException to be thrown.');
        } catch (HttpException $httpException) {
            self::assertSame(423, $httpException->getStatusCode());
        }
    }
}
