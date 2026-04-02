<?php

declare(strict_types=1);

namespace App\Tests\Unit\State;

use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\TripRequest;
use App\Entity\TripShare;
use App\Repository\TripShareRepositoryInterface;
use App\State\TripShareCreateProcessor;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

#[AllowMockObjectsWithoutExpectations]
final class TripShareCreateProcessorTest extends TestCase
{
    private MockObject&EntityManagerInterface $entityManager;

    private MockObject&TripShareRepositoryInterface $tripShareRepository;

    /** @var MockObject&ProcessorInterface<TripShare, TripShare> */
    private MockObject $persistProcessor;

    private TripShareCreateProcessor $processor;

    #[\Override]
    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->tripShareRepository = $this->createMock(TripShareRepositoryInterface::class);
        /** @var MockObject&ProcessorInterface<TripShare, TripShare> $persistProcessor */
        $persistProcessor = $this->createMock(ProcessorInterface::class);
        $this->persistProcessor = $persistProcessor;

        $this->processor = new TripShareCreateProcessor(
            $this->persistProcessor,
            $this->entityManager,
            $this->tripShareRepository,
        );
    }

    #[Test]
    public function itCreatesShareFromStringTripId(): void
    {
        $tripId = Uuid::v7();
        $trip = new TripRequest();

        $this->entityManager->expects($this->once())->method('find')
            ->with(TripRequest::class, $this->callback(
                static fn (mixed $id): bool => $id instanceof Uuid && (string) $id === (string) $tripId,
            ))
            ->willReturn($trip);

        $this->tripShareRepository->expects($this->once())->method('findActiveByTrip')->willReturn(null);

        $share = new TripShare();
        $this->persistProcessor->expects($this->once())->method('process')->with($share)->willReturn($share);

        $result = $this->processor->process($share, new Post(), ['tripId' => (string) $tripId]);

        self::assertSame($trip, $result->getTrip());
        self::assertNotEmpty($result->getToken());
    }

    #[Test]
    public function itCreatesShareFromUuidTripId(): void
    {
        $tripId = Uuid::v7();
        $trip = new TripRequest();

        $this->entityManager->expects($this->once())->method('find')
            ->with(TripRequest::class, $tripId)
            ->willReturn($trip);

        $this->tripShareRepository->expects($this->once())->method('findActiveByTrip')->willReturn(null);

        $share = new TripShare();
        $this->persistProcessor->expects($this->once())->method('process')->with($share)->willReturn($share);

        $result = $this->processor->process($share, new Post(), ['tripId' => $tripId]);

        self::assertSame($trip, $result->getTrip());
        self::assertNotEmpty($result->getToken());
    }

    #[Test]
    public function itCreatesShareFromTripRequestObject(): void
    {
        $trip = new TripRequest();

        $this->entityManager->expects($this->never())->method('find');
        $this->tripShareRepository->expects($this->once())->method('findActiveByTrip')->willReturn(null);

        $share = new TripShare();
        $this->persistProcessor->expects($this->once())->method('process')->with($share)->willReturn($share);

        $result = $this->processor->process($share, new Post(), ['tripId' => $trip]);

        self::assertSame($trip, $result->getTrip());
        self::assertNotEmpty($result->getToken());
    }

    #[Test]
    public function itThrows409WhenActiveShareExists(): void
    {
        $tripId = Uuid::v7();
        $trip = new TripRequest();

        $this->entityManager->expects($this->once())->method('find')->willReturn($trip);
        $this->tripShareRepository->expects($this->once())->method('findActiveByTrip')->willReturn(new TripShare());
        $this->persistProcessor->expects($this->never())->method('process');

        $this->expectException(ConflictHttpException::class);
        $this->processor->process(new TripShare(), new Post(), ['tripId' => (string) $tripId]);
    }

    #[Test]
    public function itThrowsNotFoundWhenTripDoesNotExist(): void
    {
        $tripId = Uuid::v7();

        $this->entityManager->expects($this->once())->method('find')->willReturn(null);
        $this->persistProcessor->expects($this->never())->method('process');

        $this->expectException(NotFoundHttpException::class);
        $this->processor->process(new TripShare(), new Post(), ['tripId' => (string) $tripId]);
    }

    #[Test]
    public function itThrowsNotFoundWhenTripIdIsNotAValidUuid(): void
    {
        $this->entityManager->expects($this->never())->method('find');
        $this->persistProcessor->expects($this->never())->method('process');

        $this->expectException(NotFoundHttpException::class);
        $this->processor->process(new TripShare(), new Post(), ['tripId' => 'not-a-uuid']);
    }

    #[Test]
    public function itThrows409OnConcurrentInsertRaceCondition(): void
    {
        $tripId = Uuid::v7();
        $trip = new TripRequest();

        $this->entityManager->expects($this->once())->method('find')->willReturn($trip);
        $this->tripShareRepository->expects($this->once())->method('findActiveByTrip')->willReturn(null);

        $uniqueException = $this->getMockBuilder(UniqueConstraintViolationException::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->persistProcessor->expects($this->once())->method('process')->willThrowException($uniqueException);

        $this->expectException(ConflictHttpException::class);
        $this->processor->process(new TripShare(), new Post(), ['tripId' => (string) $tripId]);
    }
}
