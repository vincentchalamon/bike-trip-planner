<?php

declare(strict_types=1);

namespace App\Tests\Unit\State;

use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\TripRequest;
use App\Entity\TripShare;
use App\State\TripShareCreateProcessor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

#[AllowMockObjectsWithoutExpectations]
final class TripShareCreateProcessorTest extends TestCase
{
    #[Test]
    public function itSetsTheTripFromStringUriVariable(): void
    {
        $tripId = Uuid::v7();
        $trip = new TripRequest();

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects($this->once())
            ->method('find')
            ->with(
                TripRequest::class,
                $this->callback(static fn (mixed $id): bool => $id instanceof Uuid && (string) $id === (string) $tripId),
            )
            ->willReturn($trip);

        $share = new TripShare();

        /** @var MockObject&ProcessorInterface<TripShare, TripShare> $persistProcessor */
        $persistProcessor = $this->createMock(ProcessorInterface::class);
        $persistProcessor
            ->expects($this->once())
            ->method('process')
            ->with($share)
            ->willReturn($share);

        $processor = new TripShareCreateProcessor($persistProcessor, $entityManager);
        $result = $processor->process($share, new Post(), ['tripId' => (string) $tripId]);

        self::assertSame($trip, $result->getTrip());
        self::assertNotEmpty($result->getToken());
    }

    #[Test]
    public function itSetsTheTripFromUuidUriVariable(): void
    {
        $tripId = Uuid::v7();
        $trip = new TripRequest();

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects($this->once())
            ->method('find')
            ->with(TripRequest::class, $tripId)
            ->willReturn($trip);

        $share = new TripShare();

        /** @var MockObject&ProcessorInterface<TripShare, TripShare> $persistProcessor */
        $persistProcessor = $this->createMock(ProcessorInterface::class);
        $persistProcessor
            ->expects($this->once())
            ->method('process')
            ->with($share)
            ->willReturn($share);

        $processor = new TripShareCreateProcessor($persistProcessor, $entityManager);
        $result = $processor->process($share, new Post(), ['tripId' => $tripId]);

        self::assertSame($trip, $result->getTrip());
        self::assertNotEmpty($result->getToken());
    }

    #[Test]
    public function itSetsTheTripFromTripRequestUriVariable(): void
    {
        $trip = new TripRequest();

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('find');

        $share = new TripShare();

        /** @var MockObject&ProcessorInterface<TripShare, TripShare> $persistProcessor */
        $persistProcessor = $this->createMock(ProcessorInterface::class);
        $persistProcessor
            ->expects($this->once())
            ->method('process')
            ->with($share)
            ->willReturn($share);

        $processor = new TripShareCreateProcessor($persistProcessor, $entityManager);
        $result = $processor->process($share, new Post(), ['tripId' => $trip]);

        self::assertSame($trip, $result->getTrip());
        self::assertNotEmpty($result->getToken());
    }

    #[Test]
    public function itSkipsTripInjectionWhenTripIdIsMissing(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('find');

        $share = new TripShare();

        /** @var MockObject&ProcessorInterface<TripShare, TripShare> $persistProcessor */
        $persistProcessor = $this->createMock(ProcessorInterface::class);
        $persistProcessor->method('process')->willReturn($share);

        $processor = new TripShareCreateProcessor($persistProcessor, $entityManager);
        $processor->process($share, new Post(), []);

        self::assertNull($share->getTrip());
    }

    #[Test]
    public function itThrowsNotFoundWhenTripDoesNotExist(): void
    {
        $tripId = Uuid::v7();

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects($this->once())
            ->method('find')
            ->willReturn(null);

        $share = new TripShare();

        /** @var MockObject&ProcessorInterface<TripShare, TripShare> $persistProcessor */
        $persistProcessor = $this->createMock(ProcessorInterface::class);
        $persistProcessor->expects($this->never())->method('process');

        $processor = new TripShareCreateProcessor($persistProcessor, $entityManager);

        $this->expectException(NotFoundHttpException::class);
        $processor->process($share, new Post(), ['tripId' => (string) $tripId]);
    }

    #[Test]
    public function itThrowsNotFoundWhenTripIdIsNotAValidUuid(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('find');

        $share = new TripShare();

        /** @var MockObject&ProcessorInterface<TripShare, TripShare> $persistProcessor */
        $persistProcessor = $this->createMock(ProcessorInterface::class);
        $persistProcessor->expects($this->never())->method('process');

        $processor = new TripShareCreateProcessor($persistProcessor, $entityManager);

        $this->expectException(NotFoundHttpException::class);
        $processor->process($share, new Post(), ['tripId' => 'not-a-uuid']);
    }
}
