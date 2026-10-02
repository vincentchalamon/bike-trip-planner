<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\ApiResource\Model\Accommodation;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Model\PointOfInterest;
use App\ApiResource\Model\Resupply;
use App\ApiResource\Model\WeatherForecast;
use App\ApiResource\Stage as StageDto;
use App\ApiResource\TripRequest;
use App\Enum\AlertGroup;
use App\Mapper\StageArrayMapper;
use App\Osm\CoverageRepositoryInterface;
use App\Osm\CycleRouteRepositoryInterface;
use App\Repository\DoctrineTripRequestRepository;
use App\Repository\DoctrineTripStageStore;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * Writing back the stages the store just read changes nothing, so the unit of work must find
 * nothing to update. It compares each jsonb column strictly with what was loaded, and Postgres
 * hands objects back with their keys reordered (shortest first), so a freshly mapped array
 * looked different from an equal stored one and every jsonb column was written again.
 */
#[ResetDatabase]
final class DoctrineStageUnchangedRewriteTest extends KernelTestCase
{
    #[Test]
    public function rewritingAnUnchangedEnrichedStageIssuesNoStageUpdate(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get(EntityManagerInterface::class);
        /** @var StageArrayMapper $mapper */
        $mapper = $container->get(StageArrayMapper::class);
        /** @var DoctrineTripRequestRepository $trips */
        $trips = $container->get(DoctrineTripRequestRepository::class);

        $cycleRoute = $this->createStub(CycleRouteRepositoryInterface::class);
        $cycleRoute->method('onNetworkFractions')->willReturn([0.42]);
        $coverage = $this->createStub(CoverageRepositoryInterface::class);
        $coverage->method('isRouteOutOfZone')->willReturn(false);

        $tripId = Uuid::v7()->toRfc4122();
        $trips->initializeTrip($tripId, new TripRequest(Uuid::fromString($tripId)));
        $store = new DoctrineTripStageStore($entityManager, $cycleRoute, $coverage, $mapper);

        $stage = $this->enrichedStage($tripId);
        $store->storeStages($tripId, [$stage]);
        $store->updateStageAlertsForGroup($tripId, $stage->id, AlertGroup::WIND, [
            ['type' => 'warning', 'code' => 'wind_headwind', 'message' => 'Headwind', 'lat' => 48.5, 'lon' => 2.0],
        ]);
        $store->updateStageSupplyTimeline($tripId, $stage->id, [
            ['type' => 'water', 'name' => 'Fontaine', 'distanceFromStart' => 12.0, 'lat' => 48.1, 'lon' => 2.0],
        ]);
        $entityManager->clear();

        $stages = $store->getStages($tripId);
        self::assertNotNull($stages);

        $queries = $container->get('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $queries);
        $queries->reset();

        $store->storeStages($tripId, $stages);

        // The trip row is updated on purpose: every write of the collection bumps its version.
        $stageUpdates = [];
        foreach ($queries->getData()['default'] ?? [] as $query) {
            $sql = \is_string($query['sql'] ?? null) ? $query['sql'] : '';
            if (str_starts_with($sql, 'UPDATE stage')) {
                $stageUpdates[] = $sql;
            }
        }

        self::assertSame([], $stageUpdates);
    }

    private function enrichedStage(string $tripId): StageDto
    {
        $stage = new StageDto(
            tripId: $tripId,
            dayNumber: 1,
            distance: 55.0,
            elevation: 100.0,
            startPoint: new Coordinate(48.0, 2.0, 0.0),
            endPoint: new Coordinate(49.0, 2.0, 0.0),
            geometry: [
                new Coordinate(48.0, 2.0, 0.0),
                new Coordinate(48.5, 2.1, 35.5),
                new Coordinate(49.0, 2.0, 0.0),
            ],
        );
        $stage->weather = new WeatherForecast('01d', 'Clear', 2.0, 20.5, 10.0, 'N', 0, 50, 80, 'headwind');
        $stage->resupply = new Resupply(
            foodAtLunch: [new PointOfInterest('Boulangerie', 'bakery', 48.5, 2.1, 27.0, 'node', 123, 'Mo-Sa 07:00-19:00')],
            waterMorning: new PointOfInterest('Fontaine', 'drinking_water', 48.1, 2.0, 12.0),
        );
        $accommodation = new Accommodation('Camping', 'camp_site', 49.0, 2.0, 12.0, 15.5, false, distanceToEndPoint: 0.4);
        $stage->addAccommodation($accommodation);
        $stage->selectedAccommodation = $accommodation;

        return $stage;
    }
}
