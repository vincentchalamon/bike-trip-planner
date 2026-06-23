<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage as StageDto;
use App\ApiResource\TripRequest;
use App\Repository\TripRequestRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Integration coverage for the JSONB persistence of the AI-analysis columns
 * (issue #750, item A). {@see DoctrineTripRequestRepository::updateStageAiAnalysis}
 * and {@see DoctrineTripRequestRepository::updateTripAiOverview} bind a PHP array
 * to a `jsonb` column. Without the explicit `jsonb` parameter type, Doctrine
 * infers ArrayParameterType::STRING and expands the array into an IN list
 * (`SET ai_analysis = $1, $2, $3, ...`), which PostgreSQL rejects with
 * SQLSTATE[42601].
 *
 * These tests run the real DQL against the database — the mocked unit test and
 * the in-memory pipeline test cannot reproduce the failure.
 */
final class DoctrineTripRequestAiPersistenceTest extends KernelTestCase
{
    use ResetDatabase;

    private TripRequestRepositoryInterface $repository;

    private EntityManagerInterface $entityManager;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();

        /** @var TripRequestRepositoryInterface $repository */
        $repository = $container->get(TripRequestRepositoryInterface::class);
        $this->repository = $repository;

        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get(EntityManagerInterface::class);
        $this->entityManager = $entityManager;
    }

    #[Test]
    public function stageAiAnalysisIsStoredAndReadBackAsJsonb(): void
    {
        $tripId = Uuid::v7()->toRfc4122();
        $this->repository->initializeTrip($tripId, new TripRequest(Uuid::fromString($tripId)));
        $this->repository->storeStages($tripId, [$this->stage($tripId)]);

        $payload = [
            'narrative' => 'Étape exigeante.',
            'insights' => ["Long tronçon sans point d'eau"],
            'suggestions' => ['Faire le plein avant le départ'],
            'model' => 'gemini-2.5-flash',
            'promptVersion' => 1,
            'generatedAt' => '2026-06-23T10:00:00+00:00',
        ];

        // Before the fix this threw SQLSTATE[42601]: the array was expanded into an IN list.
        $this->repository->updateStageAiAnalysis($tripId, 1, $payload);

        // Detach so getStages reloads from the database, not the identity map
        // (the UPDATE is a direct DQL statement that bypasses the unit of work).
        $this->entityManager->clear();

        $stages = $this->repository->getStages($tripId);
        self::assertNotNull($stages);
        self::assertCount(1, $stages);

        $analysis = $stages[0]->aiAnalysis;
        self::assertNotNull($analysis);
        self::assertSame($payload, $analysis->toArray());
    }

    #[Test]
    public function tripAiOverviewIsStoredAndReadBackAsJsonb(): void
    {
        $tripId = Uuid::v7()->toRfc4122();
        $this->repository->initializeTrip($tripId, new TripRequest(Uuid::fromString($tripId)));

        $payload = [
            'narrative' => "Vue d'ensemble du trip.",
            'patterns' => ['Zones sans eau récurrentes'],
            'recommendations' => ['Prévoir une pause à mi-parcours'],
            'crossStageAlerts' => [],
            'model' => 'gemini-2.5-flash',
            'promptVersion' => 1,
            'generatedAt' => '2026-06-23T10:00:00+00:00',
        ];

        // Before the fix this threw SQLSTATE[42601]: the array was expanded into an IN list.
        $this->repository->updateTripAiOverview($tripId, $payload);

        $this->entityManager->clear();

        $trip = $this->repository->getRequest($tripId);
        self::assertNotNull($trip);
        // jsonb does not preserve key order, hence canonicalizing.
        self::assertEqualsCanonicalizing($payload, $trip->aiOverviewData);
    }

    #[Test]
    public function stageAiAnalysisCanBeClearedToNull(): void
    {
        $tripId = Uuid::v7()->toRfc4122();
        $this->repository->initializeTrip($tripId, new TripRequest(Uuid::fromString($tripId)));
        $this->repository->storeStages($tripId, [$this->stage($tripId)]);

        // null must pass through the jsonb type as SQL NULL without throwing.
        $this->repository->updateStageAiAnalysis($tripId, 1, null);

        $this->entityManager->clear();

        $stages = $this->repository->getStages($tripId);
        self::assertNotNull($stages);
        self::assertCount(1, $stages);
        self::assertNull($stages[0]->aiAnalysis);
    }

    #[Test]
    public function tripAiOverviewCanBeClearedToNull(): void
    {
        $tripId = Uuid::v7()->toRfc4122();
        $this->repository->initializeTrip($tripId, new TripRequest(Uuid::fromString($tripId)));

        // null must pass through the jsonb type as SQL NULL without throwing.
        $this->repository->updateTripAiOverview($tripId, null);

        $this->entityManager->clear();

        $trip = $this->repository->getRequest($tripId);
        self::assertNotNull($trip);
        self::assertNull($trip->aiOverviewData);
    }

    private function stage(string $tripId): StageDto
    {
        return new StageDto(
            tripId: $tripId,
            dayNumber: 1,
            distance: 80.0,
            elevation: 500.0,
            startPoint: new Coordinate(48.0, 2.0),
            endPoint: new Coordinate(48.5, 2.5),
        );
    }
}
