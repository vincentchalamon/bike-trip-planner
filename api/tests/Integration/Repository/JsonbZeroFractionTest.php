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
use App\Entity\Stage as StageEntity;
use App\Mapper\StageArrayMapper;
use App\Osm\CoverageRepositoryInterface;
use App\Osm\CycleRouteRepositoryInterface;
use App\Repository\DoctrineTripRequestRepository;
use App\Repository\DoctrineTripStageStore;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * Every jsonb column hands back the floats it was given: `2.0` reads back as the float `2.0`,
 * not the int `2` the library's encoder used to write.
 */
#[ResetDatabase]
final class JsonbZeroFractionTest extends KernelTestCase
{
    #[Test]
    public function integralFloatsReadBackAsFloats(): void
    {
        [$store, $tripId, $entityManager] = $this->storeWithTrip();

        $stage = $this->integralStage($tripId);
        $store->storeStages($tripId, [$stage]);
        $entityManager->clear();

        $entity = $entityManager->find(StageEntity::class, Uuid::fromString($stage->id));
        self::assertInstanceOf(StageEntity::class, $entity);

        self::assertSame(2.0, $entity->getGeometry()[0]['lon']);
        self::assertSame(0.0, $entity->getGeometry()[0]['ele']);
        self::assertSame(2.0, $entity->getWeather()['tempMin'] ?? null);
        $water = $entity->getPois()['waterMorning'] ?? null;
        self::assertIsArray($water);
        self::assertSame(2.0, $water['lon']);
        self::assertSame(2.0, $entity->getAccommodations()[0]['estimatedPriceMin'] ?? null);
        self::assertSame(2.0, $entity->getSelectedAccommodation()['lon'] ?? null);
    }

    /**
     * Rows written before the flag hold `2`: they still decode, as an int, and the readers
     * widen it back to a float.
     */
    #[Test]
    public function aRowWrittenWithoutZeroFractionsStillReads(): void
    {
        [$store, $tripId, $entityManager] = $this->storeWithTrip();

        $stage = $this->integralStage($tripId);
        $store->storeStages($tripId, [$stage]);
        $entityManager->getConnection()->executeStatement(
            <<<'SQL'
                UPDATE stage SET
                    geometry = '[{"lat": 48, "lon": 2, "ele": 0}]',
                    weather = jsonb_set(weather, '{tempMin}', '2')
                WHERE id = :id
                SQL,
            ['id' => $stage->id],
        );
        $entityManager->clear();

        $stages = $store->getStages($tripId);
        self::assertNotNull($stages);
        self::assertSame(2.0, $stages[0]->geometry[0]->lon);
        self::assertSame(2.0, $stages[0]->weather?->tempMin);
    }

    /**
     * @return array{DoctrineTripStageStore, string, EntityManagerInterface}
     */
    private function storeWithTrip(): array
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
        $cycleRoute->method('onNetworkFractions')->willReturn([0.0]);
        $coverage = $this->createStub(CoverageRepositoryInterface::class);
        $coverage->method('isRouteOutOfZone')->willReturn(false);

        $tripId = Uuid::v7()->toRfc4122();
        $trips->initializeTrip($tripId, new TripRequest(Uuid::fromString($tripId)));

        return [new DoctrineTripStageStore($entityManager, $cycleRoute, $coverage, $mapper), $tripId, $entityManager];
    }

    private function integralStage(string $tripId): StageDto
    {
        $stage = new StageDto(
            tripId: $tripId,
            dayNumber: 1,
            distance: 55.0,
            elevation: 100.0,
            startPoint: new Coordinate(48.0, 2.0, 0.0),
            endPoint: new Coordinate(49.0, 2.0, 0.0),
            geometry: [new Coordinate(48.0, 2.0, 0.0), new Coordinate(49.0, 2.0, 0.0)],
        );
        $stage->weather = new WeatherForecast('01d', 'Clear', 2.0, 20.0, 10.0, 'N', 0, 50, 80, 'headwind');
        $stage->resupply = new Resupply(waterMorning: new PointOfInterest('Fontaine', 'drinking_water', 48.0, 2.0, 10.0));

        $accommodation = new Accommodation('Camping', 'camp_site', 49.0, 2.0, 2.0, 15.0, false);
        $stage->addAccommodation($accommodation);
        $stage->selectedAccommodation = $accommodation;

        return $stage;
    }
}
