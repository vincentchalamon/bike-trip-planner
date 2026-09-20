<?php

declare(strict_types=1);

namespace App\Tests\Unit\State;

use App\Tests\Unit\AlertMessageTestTrait;
use ApiPlatform\Metadata\Post;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\StageRequest;
use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationTrackerInterface;
use App\Engine\DistanceCalculatorInterface;
use App\Mapper\StageResponseMapper;
use App\Repository\TripRequestRepositoryInterface;
use App\State\StageCreateProcessor;
use App\State\TripLocker;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Messenger\MessageBusInterface;

#[AllowMockObjectsWithoutExpectations]
final class StageCreateProcessorTest extends TestCase
{
    use AlertMessageTestTrait;

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


        $stageResponseMapper = new StageResponseMapper(
            $this->createStub(ComputationTrackerInterface::class),
            $this->createStub(TripRequestRepositoryInterface::class),
            $this->createAlertRenderer(),
            $this->createReaderLocale(),
        );

        $processor = new StageCreateProcessor(
            $tripStateManager,
            $this->createStub(MessageBusInterface::class),
            $distanceCalculator,
            $stageResponseMapper,
            new TripLocker(),
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
