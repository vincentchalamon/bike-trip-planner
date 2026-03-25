<?php

declare(strict_types=1);

namespace App\Tests\Unit\State;

use ApiPlatform\Metadata\Post;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\StageRequest;
use App\ApiResource\StageResponse;
use App\ApiResource\TripRequest;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Engine\DistanceCalculatorInterface;
use App\Repository\TripRequestRepositoryInterface;
use App\Security\TripOwnershipChecker;
use App\State\StageCreateProcessor;
use App\State\TripLocker;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;

#[AllowMockObjectsWithoutExpectations]
final class StageCreateProcessorTest extends TestCase
{
    #[Test]
    public function lockedTripThrowsHttpException(): void
    {
        $lockedRequest = new TripRequest();
        $lockedRequest->startDate = new \DateTimeImmutable('yesterday');

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getRequest')->willReturn($lockedRequest);
        $tripStateManager->method('getStages')->willReturn([]);

        $distanceCalculator = $this->createStub(DistanceCalculatorInterface::class);
        $distanceCalculator->method('distanceBetween')->willReturn(0.0);

        $generationTracker = $this->createStub(TripGenerationTrackerInterface::class);
        $generationTracker->method('increment')->willReturn(1);

        $objectMapper = $this->createStub(ObjectMapperInterface::class);
        $objectMapper->method('map')->willReturn(new StageResponse());

        /** @var \Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface&\PHPUnit\Framework\MockObject\Stub $authChecker */
        $authChecker = $this->createStub(\Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface::class);
        $authChecker->method('isGranted')->willReturn(true);
        $ownershipChecker = new TripOwnershipChecker($authChecker);

        $processor = new StageCreateProcessor(
            $tripStateManager,
            $this->createStub(MessageBusInterface::class),
            $distanceCalculator,
            $objectMapper,
            $generationTracker,
            new TripLocker(),
            $ownershipChecker,
        );

        $coord = new Coordinate(lat: 48.0, lon: 2.0);
        $data = new StageRequest();
        $data->startPoint = $coord;
        $data->endPoint = $coord;

        try {
            $processor->process($data, new Post(), ['tripId' => 'trip-1']);
            self::fail('Expected HttpException to be thrown.');
        } catch (HttpException $httpException) {
            self::assertSame(423, $httpException->getStatusCode());
        }
    }
}
