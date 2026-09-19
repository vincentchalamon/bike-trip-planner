<?php

declare(strict_types=1);

namespace App\Tests\Unit\State;

use ApiPlatform\Metadata\Patch;
use App\ApiResource\StageSelectAccommodationRequest;
use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationTrackerInterface;
use App\Mapper\StageResponseMapper;
use App\Repository\TripRequestRepositoryInterface;
use App\State\StageSelectAccommodationProcessor;
use App\State\StageLocator;
use App\State\TripLocker;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

#[AllowMockObjectsWithoutExpectations]
final class StageSelectAccommodationProcessorTest extends TestCase
{
    #[Test]
    public function lockedTripThrowsHttpException(): void
    {
        $lockedRequest = new TripRequest();
        $lockedRequest->startDate = new \DateTimeImmutable('yesterday');

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getRequest')->willReturn($lockedRequest);
        $tripStateManager->method('getStages')->willReturn([]);

        $processor = new StageSelectAccommodationProcessor(
            $tripStateManager,
            $this->createStub(MessageBusInterface::class),
            new StageResponseMapper($this->createStub(ComputationTrackerInterface::class)),
            new TripLocker(),
            new StageLocator(),
        );

        try {
            $processor->process(new StageSelectAccommodationRequest(), new Patch(), ['tripId' => 'trip-1', 'stageId' => Uuid::v7()->toRfc4122()]);
            self::fail('Expected HttpException to be thrown.');
        } catch (HttpException $httpException) {
            self::assertSame(423, $httpException->getStatusCode());
        }
    }
}
