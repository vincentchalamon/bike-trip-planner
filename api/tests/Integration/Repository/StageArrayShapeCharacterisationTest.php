<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use ApiPlatform\Metadata\Get;
use App\ApiResource\Model\Accommodation;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Model\Event;
use App\ApiResource\Model\HourlyWeatherSlot;
use App\ApiResource\Model\PointOfInterest;
use App\ApiResource\Model\Resupply;
use App\ApiResource\Model\WeatherForecast;
use App\ApiResource\Stage as StageDto;
use App\ApiResource\TripDetail;
use App\ApiResource\TripRequest;
use App\Mercure\StagePayloadMapper;
use App\Repository\DoctrineTripRequestRepository;
use App\State\TripDetailProvider;
use App\Weather\WeatherForecastSerializer;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * Pins the three array shapes a stage's sub-objects take: the JSONB columns, the `/detail`
 * body and the Mercure payload.
 *
 * They are not the same shape, and some of the differences are deliberate: the stored POI
 * keeps `openingHours` and `website` the two published ones drop, the stored forecast keeps
 * the wind and the gusts unrounded where the published one rounds them, and `/detail` serves the raw stage figures where Mercure rounds them and adds the geometry.
 * Every key and value is asserted so that any drift between them, deliberate or not, has to
 * be written down here.
 */
#[ResetDatabase]
final class StageArrayShapeCharacterisationTest extends KernelTestCase
{
    private DoctrineTripRequestRepository $repository;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();

        /** @var DoctrineTripRequestRepository $repository */
        $repository = self::getContainer()->get(DoctrineTripRequestRepository::class);
        $this->repository = $repository;
    }

    #[Test]
    public function theStoredColumnsKeepTheirShape(): void
    {
        [, $stageId] = $this->seed();

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        /** @var array<string, string|null> $row */
        $row = $connection->fetchAssociative(
            'SELECT weather, pois, accommodations, selected_accommodation, events, supply_timeline, geometry FROM stage WHERE id = :id',
            ['id' => $stageId],
        );

        self::assertSame($this->sorted($this->storedWeather()), $this->decode($row['weather']));
        self::assertSame($this->sorted($this->storedResupply()), $this->decode($row['pois']));
        self::assertSame($this->sorted([$this->accommodationArray()]), $this->decode($row['accommodations']));
        self::assertSame($this->sorted($this->accommodationArray()), $this->decode($row['selected_accommodation']));
        self::assertSame($this->sorted([$this->eventArray()]), $this->decode($row['events']));
        self::assertSame($this->sorted([['km' => 12.5, 'type' => 'water']]), $this->decode($row['supply_timeline']));
        self::assertSame(
            $this->sorted([['lat' => 45.01, 'lon' => 5.01, 'ele' => 200.5], ['lat' => 45.1, 'lon' => 5.1, 'ele' => 250.5]]),
            $this->decode($row['geometry']),
        );
    }

    #[Test]
    public function theStoredColumnsReadBackIntoTheSameObjects(): void
    {
        [$tripId] = $this->seed();

        $stage = ($this->repository->getStages($tripId) ?? [])[0];

        self::assertEquals($this->forecast(), $stage->weather);
        self::assertEquals($this->resupply(), $stage->resupply);
        self::assertEquals([$this->accommodation()], $stage->accommodations);
        self::assertEquals($this->accommodation(), $stage->selectedAccommodation);
        self::assertEquals([$this->event()], $stage->events);
        self::assertSame([['km' => 12.5, 'type' => 'water']], $stage->supplyTimeline);
        self::assertSame('Grenoble', $stage->startLabel);
        self::assertSame('Chambery', $stage->endLabel);
    }

    #[Test]
    public function theTripDetailServesTheStageShape(): void
    {
        [$tripId, $stageId] = $this->seed();

        /** @var TripDetailProvider $provider */
        $provider = self::getContainer()->get(TripDetailProvider::class);
        $detail = $provider->provide(new Get(), ['id' => $tripId]);
        self::assertInstanceOf(TripDetail::class, $detail);

        self::assertSame([
            'stageId' => $stageId,
            'dayNumber' => 1,
            'distance' => 81.234,
            'elevation' => 512.7,
            'elevationLoss' => 300.4,
            'startPoint' => ['lat' => 45.01, 'lon' => 5.01, 'ele' => 200.5],
            'endPoint' => ['lat' => 45.1, 'lon' => 5.1, 'ele' => 250.5],
            'label' => 'Day one',
            'startLabel' => 'Grenoble',
            'endLabel' => 'Chambery',
            'isRestDay' => false,
            'onCycleNetwork' => 0.0,
            'weather' => $this->publishedWeather(),
            'weatherAvailability' => null,
            'alerts' => [],
            'events' => [$this->eventArray()],
            'supplyTimeline' => [['km' => 12.5, 'type' => 'water']],
            'resupply' => $this->publishedResupply(),
            'accommodations' => [$this->accommodationArray()],
            'selectedAccommodation' => $this->accommodationArray(),
        ], $detail->stages[0]);
    }

    #[Test]
    public function theMercurePayloadKeepsItsShape(): void
    {
        $tripId = Uuid::v7()->toRfc4122();

        /** @var StagePayloadMapper $mapper */
        $mapper = self::getContainer()->get(StagePayloadMapper::class);
        $stage = $this->stage($tripId);

        self::assertSame([
            'stageId' => $stage->id,
            'dayNumber' => 1,
            'distance' => 81.2,
            'elevation' => 512,
            'elevationLoss' => 300,
            'startPoint' => ['lat' => 45.01, 'lon' => 5.01, 'ele' => 200.5],
            'endPoint' => ['lat' => 45.1, 'lon' => 5.1, 'ele' => 250.5],
            'label' => 'Day one',
            'isRestDay' => false,
            'geometry' => [['lat' => 45.01, 'lon' => 5.01, 'ele' => 200.5], ['lat' => 45.1, 'lon' => 5.1, 'ele' => 250.5]],
            'weather' => $this->publishedWeather(),
            'alerts' => [],
            'resupply' => $this->publishedResupply(),
            'accommodations' => [$this->accommodationArray()],
            'selectedAccommodation' => $this->accommodationArray(),
            'events' => [$this->eventArray()],
        ], $mapper->toPayload($stage, 'en'));
    }

    #[Test]
    public function aForecastWrittenByTheWeatherHandlerReloadsAsItWasPublished(): void
    {
        [$tripId, $stageId] = $this->seed();
        $this->repository->updateStageWeather($tripId, $stageId, $this->forecast());

        /** @var WeatherForecastSerializer $serializer */
        $serializer = self::getContainer()->get(WeatherForecastSerializer::class);
        /** @var TripDetailProvider $provider */
        $provider = self::getContainer()->get(TripDetailProvider::class);
        $detail = $provider->provide(new Get(), ['id' => $tripId]);
        self::assertInstanceOf(TripDetail::class, $detail);

        self::assertSame($serializer->toArray($this->forecast()), $detail->stages[0]['weather']);
    }

    #[Test]
    public function aForecastStoredWithTheTenDailyScalarsOnlyStillReadsBack(): void
    {
        [$tripId, $stageId] = $this->seed();

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement(
            'UPDATE stage SET weather = CAST(:weather AS jsonb) WHERE id = :id',
            ['weather' => json_encode($this->legacyStoredWeather(), \JSON_THROW_ON_ERROR), 'id' => $stageId],
        );

        $stage = ($this->repository->getStages($tripId) ?? [])[0];

        self::assertEquals(new WeatherForecast('sun', 'Sunny', 12.0, 24.0, 15.46, 'NW', 10, 55, 80, 'headwind'), $stage->weather);
    }

    /** @return array{string, string} */
    private function seed(): array
    {
        $tripId = Uuid::v7()->toRfc4122();
        $request = new TripRequest(Uuid::fromString($tripId));
        $request->startDate = new \DateTimeImmutable('2020-06-01', new \DateTimeZone('UTC'));

        $this->repository->initializeTrip($tripId, $request);

        $stage = $this->stage($tripId);
        $this->repository->storeStages($tripId, [$stage]);
        $this->repository->updateStageEvents($tripId, $stage->id, [$this->event()]);
        $this->repository->updateStageSupplyTimeline($tripId, $stage->id, [['km' => 12.5, 'type' => 'water']]);

        return [$tripId, $stage->id];
    }

    private function stage(string $tripId): StageDto
    {
        $stage = new StageDto(
            tripId: $tripId,
            dayNumber: 1,
            distance: 81.234,
            elevation: 512.7,
            startPoint: new Coordinate(45.01, 5.01, 200.5),
            endPoint: new Coordinate(45.1, 5.1, 250.5),
            geometry: [new Coordinate(45.01, 5.01, 200.5), new Coordinate(45.1, 5.1, 250.5)],
            label: 'Day one',
            elevationLoss: 300.4,
        );
        $stage->startLabel = 'Grenoble';
        $stage->endLabel = 'Chambery';
        $stage->weather = $this->forecast();
        $stage->resupply = $this->resupply();
        $stage->addAccommodation($this->accommodation());
        $stage->selectedAccommodation = $this->accommodation();
        $stage->addEvent($this->event());

        return $stage;
    }

    private function forecast(): WeatherForecast
    {
        return new WeatherForecast(
            'sun',
            'Sunny',
            12.0,
            24.0,
            15.46,
            'NW',
            10,
            55,
            80,
            'headwind',
            apparentTempMin: 11.0,
            apparentTempMax: 25.0,
            windGusts: 30.04,
            precipitationMm: 1.5,
            uvIndex: 6,
            hourly: [new HourlyWeatherSlot(9, 15.5, 14.0, 0.2, 5, 10.4, 20.0, 270, 'headwind', 1)],
        );
    }

    private function resupply(): Resupply
    {
        return new Resupply(
            foodAtLunch: [new PointOfInterest('Bakery', 'bakery', 45.05, 5.05, 30.5, 'node', 42, 'Mo-Sa 07:00-19:00', 'https://bakery.example')],
            waterMorning: new PointOfInterest('Fountain', 'drinking_water', 45.02, 5.02, 10.25),
            foodAtArrival: [],
        );
    }

    private function accommodation(): Accommodation
    {
        return new Accommodation(
            name: 'Camping du Lac',
            type: 'camp_site',
            lat: 45.1,
            lon: 5.1,
            estimatedPriceMin: 12.5,
            estimatedPriceMax: 20.5,
            isExactPrice: true,
            url: 'https://camping.example',
            possibleClosed: true,
            distanceToEndPoint: 0.4,
            source: 'datatourisme',
            description: 'By the lake',
            imageUrl: 'https://img.example/c.jpg',
            wikipediaUrl: 'https://fr.wikipedia.org/wiki/Lac',
            openingHours: 'Apr-Oct',
            phone: '+33 4 00 00 00 00',
            address: '1 route du Lac, Chambery',
            osmType: 'way',
            osmId: 7,
        );
    }

    private function event(): Event
    {
        return new Event(
            name: 'Fete du village',
            type: 'festival',
            lat: 45.1,
            lon: 5.1,
            startDate: new \DateTimeImmutable('2020-06-01T10:00:00+00:00'),
            endDate: new \DateTimeImmutable('2020-06-01T18:00:00+00:00'),
            url: 'https://fete.example',
            description: 'Music',
            priceMin: 5.5,
            distanceToEndPoint: 1.2,
            source: 'datatourisme',
            wikidataId: 'Q1',
            imageUrl: 'https://img.example/f.jpg',
            wikipediaUrl: 'https://fr.wikipedia.org/wiki/Fete',
            openingHours: '10:00-18:00',
        );
    }

    /** @return array<string, mixed> */
    private function storedWeather(): array
    {
        return [
            ...$this->legacyStoredWeather(),
            // A whole float loses its fraction in a column the entity writes, and reads back as an int.
            'apparentTempMin' => 11,
            'apparentTempMax' => 25,
            'windGusts' => 30.04,
            'precipitationMm' => 1.5,
            'uvIndex' => 6,
            'hourly' => [[
                'hour' => 9,
                'temp' => 15.5,
                'apparentTemp' => 14,
                'precipitationMm' => 0.2,
                'precipitationProbability' => 5,
                'windSpeed' => 10.4,
                'windGusts' => 20,
                'windDirectionDeg' => 270,
                'relativeWindDirection' => 'headwind',
                'weatherCode' => 1,
            ]],
        ];
    }

    /**
     * The shape the column held before it kept the whole forecast.
     *
     * @return array<string, mixed>
     */
    private function legacyStoredWeather(): array
    {
        return [
            'icon' => 'sun',
            'description' => 'Sunny',
            'tempMin' => 12,
            'tempMax' => 24,
            'windSpeed' => 15.46,
            'windDirection' => 'NW',
            'precipitationProbability' => 10,
            'humidity' => 55,
            'comfortIndex' => 80,
            'relativeWindDirection' => 'headwind',
        ];
    }

    /** @return array<string, mixed> */
    private function publishedWeather(): array
    {
        return [
            'icon' => 'sun',
            'description' => 'Sunny',
            'tempMin' => 12.0,
            'tempMax' => 24.0,
            'windSpeed' => 15.5,
            'windDirection' => 'NW',
            'precipitationProbability' => 10,
            'humidity' => 55,
            'comfortIndex' => 80,
            'relativeWindDirection' => 'headwind',
            'apparentTempMin' => 11.0,
            'apparentTempMax' => 25.0,
            'windGusts' => 30.0,
            'precipitationMm' => 1.5,
            'uvIndex' => 6,
            'hourly' => [[
                'hour' => 9,
                'temp' => 15.5,
                'apparentTemp' => 14.0,
                'precipitationMm' => 0.2,
                'precipitationProbability' => 5,
                'windSpeed' => 10.4,
                'windGusts' => 20.0,
                'windDirectionDeg' => 270,
                'relativeWindDirection' => 'headwind',
                'weatherCode' => 1,
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private function storedResupply(): array
    {
        return [
            'foodAtLunch' => [[
                'name' => 'Bakery',
                'category' => 'bakery',
                'lat' => 45.05,
                'lon' => 5.05,
                'distanceFromStart' => 30.5,
                'osmType' => 'node',
                'osmId' => 42,
                'openingHours' => 'Mo-Sa 07:00-19:00',
                'website' => 'https://bakery.example',
            ]],
            'waterMorning' => [
                'name' => 'Fountain',
                'category' => 'drinking_water',
                'lat' => 45.02,
                'lon' => 5.02,
                'distanceFromStart' => 10.25,
                'osmType' => null,
                'osmId' => null,
                'openingHours' => null,
                'website' => null,
            ],
            'waterAfternoon' => null,
            'foodAtArrival' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function publishedResupply(): array
    {
        return [
            'foodAtLunch' => [[
                'name' => 'Bakery',
                'category' => 'bakery',
                'lat' => 45.05,
                'lon' => 5.05,
                'distanceFromStart' => 30.5,
                'osmType' => 'node',
                'osmId' => 42,
            ]],
            'waterMorning' => [
                'name' => 'Fountain',
                'category' => 'drinking_water',
                'lat' => 45.02,
                'lon' => 5.02,
                'distanceFromStart' => 10.25,
                'osmType' => null,
                'osmId' => null,
            ],
            'waterAfternoon' => null,
            'foodAtArrival' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function accommodationArray(): array
    {
        return [
            'name' => 'Camping du Lac',
            'type' => 'camp_site',
            'lat' => 45.1,
            'lon' => 5.1,
            'estimatedPriceMin' => 12.5,
            'estimatedPriceMax' => 20.5,
            'isExactPrice' => true,
            'url' => 'https://camping.example',
            'possibleClosed' => true,
            'distanceToEndPoint' => 0.4,
            'source' => 'datatourisme',
            'description' => 'By the lake',
            'imageUrl' => 'https://img.example/c.jpg',
            'wikipediaUrl' => 'https://fr.wikipedia.org/wiki/Lac',
            'openingHours' => 'Apr-Oct',
            'phone' => '+33 4 00 00 00 00',
            'address' => '1 route du Lac, Chambery',
            'osmType' => 'way',
            'osmId' => 7,
        ];
    }

    /** @return array<string, mixed> */
    private function eventArray(): array
    {
        return [
            'name' => 'Fete du village',
            'type' => 'festival',
            'lat' => 45.1,
            'lon' => 5.1,
            'startDate' => '2020-06-01T10:00:00+00:00',
            'endDate' => '2020-06-01T18:00:00+00:00',
            'url' => 'https://fete.example',
            'description' => 'Music',
            'priceMin' => 5.5,
            'distanceToEndPoint' => 1.2,
            'source' => 'datatourisme',
            'wikidataId' => 'Q1',
            'imageUrl' => 'https://img.example/f.jpg',
            'wikipediaUrl' => 'https://fr.wikipedia.org/wiki/Fete',
            'openingHours' => '10:00-18:00',
        ];
    }

    /**
     * JSONB does not keep the key order it was given, so both sides are sorted before the
     * strict comparison: the values and their types are what is pinned here.
     */
    private function decode(?string $json): mixed
    {
        return null === $json ? null : $this->sorted(json_decode($json, true, flags: \JSON_THROW_ON_ERROR));
    }

    private function sorted(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }

        if (!array_is_list($value)) {
            ksort($value);
        }

        return array_map($this->sorted(...), $value);
    }
}
