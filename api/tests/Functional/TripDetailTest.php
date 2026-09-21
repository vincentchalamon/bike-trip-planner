<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\ApiTestCase;
use ApiPlatform\Test\Client;
use App\ApiResource\Model\Accommodation;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage as StageDto;
use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationTrackerInterface;
use App\Entity\User;
use App\Enum\AlertCode;
use App\Enum\AlertGroup;
use App\Enum\ComputationName;
use App\Repository\DoctrineTripRequestRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Symfony\Component\Uid\Uuid;

#[ResetDatabase]
final class TripDetailTest extends ApiTestCase
{
    use AddressesStagesByIdTrait;
    use JwtAuthTestTrait;

    #[\Override]
    protected static ?bool $alwaysBootKernel = false;

    private const string TRIP_ID = '01936f6e-0000-7000-8000-000000000301';

    private Client $client;

    private User $testUser;

    private string $jwtToken;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
        ['user' => $this->testUser, 'token' => $this->jwtToken] = $this->createTestUserWithJwt('test@example.com');
    }

    private function seedTrip(string $tripId, ?\DateTimeImmutable $startDate = null): DoctrineTripRequestRepository
    {
        $request = new TripRequest();
        $request->sourceUrl = 'https://www.komoot.com/tour/123456789';
        $request->startDate = $startDate;

        /** @var DoctrineTripRequestRepository $repo */
        $repo = self::getContainer()->get(DoctrineTripRequestRepository::class);
        $repo->initializeTrip($tripId, $request);
        $repo->storeTitle($tripId, 'Detail test trip');
        $this->associateTripWithUser($tripId, $this->testUser);

        return $repo;
    }

    #[Test]
    public function detailReturnsExpectedFields(): void
    {
        $repo = $this->seedTrip(self::TRIP_ID);

        $stage = new StageDto(
            tripId: self::TRIP_ID,
            dayNumber: 1,
            distance: 85.5,
            elevation: 1200.0,
            startPoint: new Coordinate(45.0, 6.0, 1000.0),
            endPoint: new Coordinate(45.5, 6.5, 800.0),
            geometry: [new Coordinate(45.0, 6.0, 1000.0)],
        );
        $repo->storeStages(self::TRIP_ID, [$stage]);
        $repo->storeStatus(self::TRIP_ID, 'ready');

        $response = $this->client->request('GET', \sprintf('/trips/%s/detail', self::TRIP_ID), [
            'headers' => array_merge(['Accept' => 'application/ld+json'], $this->authHeader($this->jwtToken)),
        ]);

        $this->assertResponseIsSuccessful();

        $data = $response->toArray(false);
        $this->assertSame(self::TRIP_ID, $data['id']);
        $this->assertArrayHasKey('stages', $data);
        $this->assertArrayHasKey('status', $data);
        $this->assertSame('ready', $data['status']);
        $this->assertArrayHasKey('fatigueFactor', $data);
        $this->assertArrayHasKey('enabledAccommodationTypes', $data);
        // Coverage polygon is unprovisioned in the test DB, so a valid trip is in zone.
        $this->assertArrayHasKey('outOfZone', $data);
        $this->assertFalse($data['outOfZone']);
        $this->assertNotEmpty($data['stages']);
        $stage = $data['stages'][0];
        $this->assertArrayHasKey('dayNumber', $stage);
        $this->assertArrayHasKey('distance', $stage);
        // Geometry is split off to GET /trips/{id}/route (ADR-057); the summary omits it.
        $this->assertArrayNotHasKey('geometry', $stage);
        $this->assertArrayHasKey('alerts', $stage);
        $this->assertArrayHasKey('accommodations', $stage);
        // No cycle routes provisioned in the test DB → stage is not on a network.
        $this->assertArrayHasKey('onCycleNetwork', $stage);
        $this->assertEqualsWithDelta(0.0, $stage['onCycleNetwork'], 0.0001);
        // No computations tracked for this trip → block statuses are null. JSON-LD
        // strips null properties, so they are absent here (the front reads them as
        // undefined and falls back to the presence of the underlying data).
        $this->assertNull($data['weatherStatus'] ?? null);
    }

    /**
     * REST is the third serialization boundary that used to strip `action`
     * (issue #863). Only `navigate` and `dismiss` are deliverable; `auto_fix`
     * and `detour` would render a dead disabled button (issue #397), so they
     * must come back as null rather than leak to the frontend.
     */
    #[Test]
    public function detailExposesDeliverableAlertActionsAndFiltersTheOthers(): void
    {
        $repo = $this->seedTrip(self::TRIP_ID);

        $stage = new StageDto(
            tripId: self::TRIP_ID,
            dayNumber: 1,
            distance: 85.5,
            elevation: 1200.0,
            startPoint: new Coordinate(45.0, 6.0, 1000.0),
            endPoint: new Coordinate(45.5, 6.5, 800.0),
            geometry: [new Coordinate(45.0, 6.0, 1000.0)],
        );
        $repo->storeStages(self::TRIP_ID, [$stage]);
        // Written by the producer, not carried by storeStages(): enrichment columns belong to
        // the computations that fill them (ADR-068). Stored in the shape the producer
        // publishes, so /detail serves exactly what Mercure pushed.
        $repo->updateStageAlertsForGroup(self::TRIP_ID, $stage->id, AlertGroup::TERRAIN, [
            [
                'code' => AlertCode::CONTINUITY_GAP_CRITICAL->value,
                'type' => 'critical',
                'messageKey' => 'alert.continuity.critical',
                'parameters' => ['%distance%' => 600.0],
                'parameterFormats' => ['%distance%' => 'distance_km'],
                'lat' => 48.1,
                'lon' => 2.2,
                'action' => [
                    'kind' => 'navigate',
                    'labelKey' => 'alert.continuity.action',
                    'payload' => ['lat' => 48.1, 'lon' => 2.2],
                ],
            ],
            [
                'code' => AlertCode::ELEVATION_GAIN->value,
                'type' => 'warning',
                'messageKey' => 'alert.elevation.warning',
                'parameters' => ['%elevation%' => 1500],
            ],
            // Persisted before issue #876: no code at all, must still serialise.
            ['code' => null, 'type' => 'nudge', 'messageKey' => 'alert.lunch.nudge'],
        ]);
        $repo->storeStatus(self::TRIP_ID, 'ready');

        $response = $this->client->request('GET', \sprintf('/trips/%s/detail', self::TRIP_ID), [
            'headers' => array_merge(['Accept' => 'application/ld+json'], $this->authHeader($this->jwtToken)),
        ]);

        $this->assertResponseIsSuccessful();

        $alerts = $response->toArray(false)['stages'][0]['alerts'];
        $this->assertCount(3, $alerts);
        $this->assertSame(['terrain', 'terrain', 'terrain'], array_column($alerts, 'group'));

        // The label is rendered here, from the key the producer stored (ADR-069).
        $this->assertSame([
            'kind' => 'navigate',
            'payload' => ['lat' => 48.1, 'lon' => 2.2],
            'labelKey' => 'alert.continuity.action',
            'label' => 'Voir la discontinuité sur la carte',
        ], $alerts[0]['action']);
        $this->assertEqualsWithDelta(48.1, $alerts[0]['lat'], 0.0001);
        $this->assertEqualsWithDelta(2.2, $alerts[0]['lon'], 0.0001);

        // JSON-LD strips null properties, so a filtered or absent action is absent.
        $this->assertNull($alerts[1]['action'] ?? null);
        $this->assertNull($alerts[2]['action'] ?? null);
    }

    #[Test]
    public function detailExposesRunningBlockStatusWhileComputing(): void
    {
        $repo = $this->seedTrip(self::TRIP_ID);
        $repo->storeStatus(self::TRIP_ID, 'ready');

        $tracker = self::getContainer()->get(ComputationTrackerInterface::class);
        \assert($tracker instanceof ComputationTrackerInterface);
        $tracker->initializeComputations(self::TRIP_ID, [
            ComputationName::WEATHER,
            ComputationName::WIND,
        ]);
        // Weather: one done, one still running → running.
        $tracker->markDone(self::TRIP_ID, ComputationName::WEATHER);
        $tracker->markRunning(self::TRIP_ID, ComputationName::WIND);

        $data = $this->fetchDetail();
        $this->assertSame('running', $data['weatherStatus']);
    }

    #[Test]
    public function detailExposesDoneBlockStatusWhenAllComputationsSucceed(): void
    {
        $repo = $this->seedTrip(self::TRIP_ID);
        $repo->storeStatus(self::TRIP_ID, 'ready');

        $tracker = self::getContainer()->get(ComputationTrackerInterface::class);
        \assert($tracker instanceof ComputationTrackerInterface);
        $tracker->initializeComputations(self::TRIP_ID, [
            ComputationName::WEATHER,
            ComputationName::WIND,
        ]);
        $tracker->markDone(self::TRIP_ID, ComputationName::WEATHER);
        $tracker->markDone(self::TRIP_ID, ComputationName::WIND);

        $data = $this->fetchDetail();
        $this->assertSame('done', $data['weatherStatus']);
    }

    #[Test]
    public function detailExposesFailedBlockStatusWhenAllTerminalAndNoneSucceeded(): void
    {
        $repo = $this->seedTrip(self::TRIP_ID);
        $repo->storeStatus(self::TRIP_ID, 'ready');

        $tracker = self::getContainer()->get(ComputationTrackerInterface::class);
        \assert($tracker instanceof ComputationTrackerInterface);
        $tracker->initializeComputations(self::TRIP_ID, [
            ComputationName::WEATHER,
            ComputationName::WIND,
        ]);
        // All weather computations terminal with at least one failed and zero done → failed.
        $tracker->markFailed(self::TRIP_ID, ComputationName::WEATHER);
        $tracker->markFailed(self::TRIP_ID, ComputationName::WIND);

        $data = $this->fetchDetail();
        $this->assertSame('failed', $data['weatherStatus']);
    }

    #[Test]
    public function detailExposesDoneBlockStatusOnPartialSuccess(): void
    {
        $repo = $this->seedTrip(self::TRIP_ID);
        $repo->storeStatus(self::TRIP_ID, 'ready');

        $tracker = self::getContainer()->get(ComputationTrackerInterface::class);
        \assert($tracker instanceof ComputationTrackerInterface);
        $tracker->initializeComputations(self::TRIP_ID, [
            ComputationName::WEATHER,
            ComputationName::WIND,
        ]);
        // Mixed terminal state: one done, one failed → done (partial success).
        $tracker->markDone(self::TRIP_ID, ComputationName::WEATHER);
        $tracker->markFailed(self::TRIP_ID, ComputationName::WIND);

        $data = $this->fetchDetail();
        $this->assertSame('done', $data['weatherStatus']);
    }

    /**
     * The tracker held every category all along; only the weather was ever exposed, so a
     * client could not tell a terrain scan that had failed from one still running (ADR-072).
     */
    #[Test]
    public function detailReportsEveryCategoryNotJustTheWeather(): void
    {
        $repo = $this->seedTrip(self::TRIP_ID);
        $repo->storeStatus(self::TRIP_ID, 'ready');

        $tracker = self::getContainer()->get(ComputationTrackerInterface::class);
        \assert($tracker instanceof ComputationTrackerInterface);
        $tracker->initializeComputations(self::TRIP_ID, [
            ComputationName::ROUTE,
            ComputationName::POIS,
            ComputationName::ACCOMMODATIONS,
            ComputationName::TERRAIN,
            ComputationName::WEATHER,
            ComputationName::CALENDAR,
        ]);
        foreach ([ComputationName::ROUTE, ComputationName::POIS, ComputationName::ACCOMMODATIONS, ComputationName::WEATHER, ComputationName::CALENDAR] as $done) {
            $tracker->markDone(self::TRIP_ID, $done);
        }

        $tracker->markFailed(self::TRIP_ID, ComputationName::TERRAIN);

        $data = $this->fetchDetail();

        $this->assertSame([
            'route' => 'done',
            'points_of_interest' => 'done',
            'accommodations' => 'done',
            'terrain_security' => 'failed',
            'weather' => 'done',
            'context' => 'done',
        ], $data['categoryStatus']);
    }

    /**
     * Five causes used to collapse into one null forecast. "Too far ahead" is a statement
     * about today, so it is derived at read rather than stored (ADR-072).
     */
    #[Test]
    public function aStageBeyondTheForecastHorizonSaysWhyItHasNoWeather(): void
    {
        $repo = $this->seedTrip(self::TRIP_ID, new \DateTimeImmutable('today +20 days'));
        $repo->storeStages(self::TRIP_ID, [$this->stageDto()]);
        $repo->storeStatus(self::TRIP_ID, 'ready');

        $this->assertSame('beyond_horizon', $this->firstStage()['weatherAvailability']);
    }

    #[Test]
    public function aStageAlreadyBehindUsSaysSoRatherThanReadingAsMissing(): void
    {
        $repo = $this->seedTrip(self::TRIP_ID, new \DateTimeImmutable('today -10 days'));
        $repo->storeStages(self::TRIP_ID, [$this->stageDto()]);
        $repo->storeStatus(self::TRIP_ID, 'ready');

        $this->assertSame('past', $this->firstStage()['weatherAvailability']);
    }

    /**
     * `unavailable` means the fetch happened and came back with nothing, so it must not be the
     * answer while the fetch is still in flight. Stages exist from the ROUTE computation
     * onwards, long before WEATHER settles, so this is most of the analysis — and telling a
     * reader to retry work still running is exactly the wrong advice.
     */
    #[Test]
    public function aStageWithinTheHorizonWaitsForTheFetchBeforeCallingItUnavailable(): void
    {
        $repo = $this->seedTrip(self::TRIP_ID, new \DateTimeImmutable('today +2 days'));
        $repo->storeStages(self::TRIP_ID, [$this->stageDto()]);
        $repo->storeStatus(self::TRIP_ID, 'ready');

        $tracker = self::getContainer()->get(ComputationTrackerInterface::class);
        \assert($tracker instanceof ComputationTrackerInterface);
        $tracker->initializeComputations(self::TRIP_ID, [ComputationName::WEATHER, ComputationName::WIND]);

        $this->assertNull($this->firstStage()['weatherAvailability']);
        $this->assertSame('running', $this->fetchDetail()['weatherStatus']);

        $tracker->markDone(self::TRIP_ID, ComputationName::WEATHER);
        $tracker->markDone(self::TRIP_ID, ComputationName::WIND);

        // Settled with no forecast for a stage two days out: now it really is unavailable.
        $this->assertSame('unavailable', $this->firstStage()['weatherAvailability']);
    }

    /**
     * A trip with no dates gets no answer, on purpose. `past` and `beyond_horizon` are claims
     * about a calendar the user has not set, and falling back to today would invent one.
     * ADR-070's guard withholds WEATHER from dispatch for such a trip, so there is nothing to
     * explain: `weatherStatus` says the computation has not run.
     */
    #[Test]
    public function anUndatedTripIsNotGivenABorrowedCalendar(): void
    {
        $repo = $this->seedTrip(self::TRIP_ID);
        $repo->storeStages(self::TRIP_ID, [$this->stageDto()]);
        $repo->storeStatus(self::TRIP_ID, 'ready');

        $tracker = self::getContainer()->get(ComputationTrackerInterface::class);
        \assert($tracker instanceof ComputationTrackerInterface);
        $tracker->initializeComputations(self::TRIP_ID, [ComputationName::WEATHER]);

        // Stages are a plain array inside the resource, so their nulls survive serialization:
        // "no answer" reads as an explicit null rather than an absent key.
        $this->assertNull($this->firstStage()['weatherAvailability']);
        $this->assertSame('running', $this->fetchDetail()['weatherStatus']);
    }

    /**
     * The assertion the PR exists for: the tracker cache is left empty — which is what a
     * trip older than the 30-minute TTL looks like — and the answer still comes back, from
     * the mirrored column (ADR-072). Before it, a trip whose every computation had failed
     * reported success on both read paths.
     */
    #[Test]
    public function stateOutlivesTheCacheThatHeldIt(): void
    {
        $repo = $this->seedTrip(self::TRIP_ID);
        $repo->storeStages(self::TRIP_ID, [$this->stageDto()]);
        $repo->storeStatus(self::TRIP_ID, 'ready');

        // Written straight to the column, never through the tracker: the in-memory cache
        // the `test` environment uses has nothing, exactly as an expired Redis key would.
        $repo->replaceComputationStatus(self::TRIP_ID, [
            'route' => 'failed',
            'stages' => 'failed',
            'weather' => 'failed',
        ]);

        $detail = $this->fetchDetail();
        $this->assertSame('failed', $detail['weatherStatus']);
        $categories = $detail['categoryStatus'];
        $this->assertIsArray($categories);
        $this->assertSame('failed', $categories['route']);

        // The list reads many trips at once, so the fallback has its own batched query —
        // exercised here against real hydration, not only through the mocked store.
        $this->assertSame(
            [self::TRIP_ID => ['route' => 'failed', 'stages' => 'failed', 'weather' => 'failed']],
            $repo->getComputationStatusBatch([self::TRIP_ID]),
        );

        $list = $this->client->request('GET', '/trips', [
            'headers' => array_merge(['Accept' => 'application/ld+json'], $this->authHeader($this->jwtToken)),
        ])->toArray(false);

        $this->assertResponseIsSuccessful();
        $this->assertSame('failed', $list['member'][0]['status']);
    }

    /**
     * A settling computation writes its own entry and nothing else, so two workers landing at
     * once cannot erase each other (ADR-072). Initialization is the one wholesale write: the
     * column must not keep answering with the previous generation's verdicts.
     */
    #[Test]
    public function aSettlingComputationDoesNotOverwriteItsNeighbours(): void
    {
        $repo = $this->seedTrip(self::TRIP_ID);

        $tracker = self::getContainer()->get(ComputationTrackerInterface::class);
        \assert($tracker instanceof ComputationTrackerInterface);
        $tracker->initializeComputations(self::TRIP_ID, [
            ComputationName::ROUTE,
            ComputationName::TERRAIN,
            ComputationName::WEATHER,
        ]);

        $this->assertSame(
            ['route' => 'pending', 'terrain' => 'pending', 'weather' => 'pending'],
            $repo->getComputationStatus(self::TRIP_ID),
        );

        $tracker->markFailed(self::TRIP_ID, ComputationName::TERRAIN);

        $this->assertSame(
            ['route' => 'pending', 'terrain' => 'failed', 'weather' => 'pending'],
            $repo->getComputationStatus(self::TRIP_ID),
        );
    }

    /**
     * @return array<mixed>
     */
    private function firstStage(): array
    {
        $stages = $this->fetchDetail()['stages'];
        $this->assertIsArray($stages);
        $stage = $stages[0];
        $this->assertIsArray($stage);

        return $stage;
    }

    private function stageDto(): StageDto
    {
        return new StageDto(
            tripId: self::TRIP_ID,
            dayNumber: 1,
            distance: 85.5,
            elevation: 1200.0,
            startPoint: new Coordinate(45.0, 6.0, 1000.0),
            endPoint: new Coordinate(45.5, 6.5, 800.0),
            geometry: [new Coordinate(45.0, 6.0, 1000.0)],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchDetail(): array
    {
        $response = $this->client->request('GET', \sprintf('/trips/%s/detail', self::TRIP_ID), [
            'headers' => array_merge(['Accept' => 'application/ld+json'], $this->authHeader($this->jwtToken)),
        ]);

        $this->assertResponseIsSuccessful();

        return $response->toArray(false);
    }

    #[Test]
    public function detailExposesDraftStatusForTripWithoutStages(): void
    {
        // A freshly initialized trip with no stages keeps the default `draft` status.
        $this->seedTrip(self::TRIP_ID);

        $response = $this->client->request('GET', \sprintf('/trips/%s/detail', self::TRIP_ID), [
            'headers' => array_merge(['Accept' => 'application/ld+json'], $this->authHeader($this->jwtToken)),
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertSame('draft', $response->toArray(false)['status']);
    }

    #[Test]
    public function detailFallsBackToReadyForLegacyTripWithStagesAndEmptyStatus(): void
    {
        // Simulates a trip persisted before the status column existed: status is blank
        // in DB, so the provider infers readiness from the presence of stages.
        $repo = $this->seedTrip(self::TRIP_ID);

        $stage = new StageDto(
            tripId: self::TRIP_ID,
            dayNumber: 1,
            distance: 40.0,
            elevation: 300.0,
            startPoint: new Coordinate(48.5, 3.0, 0.0),
            endPoint: new Coordinate(48.6, 3.1, 0.0),
        );
        $repo->storeStages(self::TRIP_ID, [$stage]);

        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        \assert($connection instanceof Connection);
        $connection->executeStatement("UPDATE trip SET status = '' WHERE id = :id", ['id' => self::TRIP_ID]);

        // Drop the Doctrine identity map so the provider re-reads the blanked status
        // from the DB instead of returning the still-managed entity (status='draft').
        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);
        $em->clear();

        $response = $this->client->request('GET', \sprintf('/trips/%s/detail', self::TRIP_ID), [
            'headers' => array_merge(['Accept' => 'application/ld+json'], $this->authHeader($this->jwtToken)),
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertSame('ready', $response->toArray(false)['status']);
    }

    #[Test]
    public function detailFlagsOutOfZoneFromStageEndpointsWhenGeometryIsEmpty(): void
    {
        // Exercises the stageRoutePoints() fallback (no stage geometry → start/end
        // points) against the real ST_Covers query: endpoints sit at lon 10, outside
        // the seeded coverage polygon (2..4 lon, 48..50 lat), so the trip is out of
        // zone. The flag is now computed and persisted at storeStages() time (#775),
        // so the coverage polygon must exist before storing the stages.
        $repo = $this->seedTrip(self::TRIP_ID);

        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        \assert($connection instanceof Connection);
        $connection->executeStatement('TRUNCATE osm.coverage');
        $connection->executeStatement(<<<'SQL'
            INSERT INTO osm.coverage (geom) VALUES (
                ST_Multi(ST_SetSRID(ST_GeomFromText('POLYGON((2 48, 4 48, 4 50, 2 50, 2 48))'), 4326))
            )
            SQL);

        $stage = new StageDto(
            tripId: self::TRIP_ID,
            dayNumber: 1,
            distance: 40.0,
            elevation: 300.0,
            startPoint: new Coordinate(48.5, 10.0, 0.0),
            endPoint: new Coordinate(48.6, 10.1, 0.0),
        );
        $repo->storeStages(self::TRIP_ID, [$stage]);

        $response = $this->client->request('GET', \sprintf('/trips/%s/detail', self::TRIP_ID), [
            'headers' => array_merge(['Accept' => 'application/ld+json'], $this->authHeader($this->jwtToken)),
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertTrue($response->toArray(false)['outOfZone']);
    }

    #[Test]
    public function detailReadsPersistedOnCycleNetworkFraction(): void
    {
        // #775: onCycleNetwork is computed by the expensive PostGIS query once at
        // storeStages() time and persisted on the stage row, then read back O(1)
        // by the detail provider. Seed a cycle route, store a stage running along
        // it, and assert the persisted fraction surfaces in the detail payload.
        $repo = $this->seedTrip(self::TRIP_ID);

        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        \assert($connection instanceof Connection);
        $connection->executeStatement('TRUNCATE osm.cycle_routes');
        $connection->executeStatement(<<<'SQL'
            INSERT INTO osm.cycle_routes (osm_id, name, network, ref, tags, geom) VALUES
              (1, 'EuroVelo Test', 'icn', 'EV-T', '{}'::jsonb,
                  ST_Multi(ST_SetSRID(ST_GeomFromText('LINESTRING(2 48, 2 49)'), 4326)))
            SQL);

        $stage = new StageDto(
            tripId: self::TRIP_ID,
            dayNumber: 1,
            distance: 55.0,
            elevation: 100.0,
            startPoint: new Coordinate(48.1, 2.0, 0.0),
            endPoint: new Coordinate(48.9, 2.0, 0.0),
            geometry: [
                new Coordinate(48.1, 2.0, 0.0),
                new Coordinate(48.5, 2.0, 0.0),
                new Coordinate(48.9, 2.0, 0.0),
            ],
        );
        $repo->storeStages(self::TRIP_ID, [$stage]);

        // Verify the value is actually persisted on the row (not recomputed on read).
        $persisted = $connection->fetchOne('SELECT on_cycle_network FROM stage WHERE trip_id = :id', ['id' => self::TRIP_ID]);
        self::assertIsNumeric($persisted);
        self::assertGreaterThan(0.95, (float) $persisted);

        $response = $this->client->request('GET', \sprintf('/trips/%s/detail', self::TRIP_ID), [
            'headers' => array_merge(['Accept' => 'application/ld+json'], $this->authHeader($this->jwtToken)),
        ]);

        $this->assertResponseIsSuccessful();
        $data = $response->toArray(false);
        self::assertGreaterThan(0.95, $data['stages'][0]['onCycleNetwork']);
    }

    /**
     * #870: the enrichment paid for at provision time (Wikidata, ADR-041) used to be
     * dropped both on write and on read, so a reload — and the anonymous shared view,
     * which hydrates from the same payload — showed a bare OSM card.
     */
    #[Test]
    public function detailExposesPersistedAccommodationEnrichment(): void
    {
        $repo = $this->seedTrip(self::TRIP_ID);

        $accommodation = new Accommodation(
            name: 'Gîte du Morvan',
            type: 'guest_house',
            lat: 47.212,
            lon: 3.951,
            estimatedPriceMin: 55.0,
            estimatedPriceMax: 80.0,
            isExactPrice: true,
            url: 'https://example.com/gite',
            distanceToEndPoint: 1.4,
            source: 'datatourisme',
            description: 'Maison de maître du XIXe siècle.',
            imageUrl: 'https://commons.example.org/gite.jpg',
            wikipediaUrl: 'https://fr.wikipedia.org/wiki/Gite',
            openingHours: 'Mo-Su 08:00-20:00',
        );

        $stage = new StageDto(
            tripId: self::TRIP_ID,
            dayNumber: 1,
            distance: 60.0,
            elevation: 500.0,
            startPoint: new Coordinate(48.0, 3.0, 0.0),
            endPoint: new Coordinate(48.2, 3.1, 0.0),
        );
        $stage->addAccommodation($accommodation);
        $stage->selectedAccommodation = $accommodation;

        $repo->storeStages(self::TRIP_ID, [$stage]);
        $repo->storeStatus(self::TRIP_ID, 'ready');

        $stages = $this->fetchDetail()['stages'];
        $this->assertIsArray($stages);
        $this->assertIsArray($stages[0]);
        $accommodations = $stages[0]['accommodations'];
        $this->assertIsArray($accommodations);

        foreach ([$accommodations[0], $stages[0]['selectedAccommodation']] as $payload) {
            $this->assertIsArray($payload);
            $this->assertSame('datatourisme', $payload['source']);
            $this->assertSame('Maison de maître du XIXe siècle.', $payload['description']);
            $this->assertSame('https://commons.example.org/gite.jpg', $payload['imageUrl']);
            $this->assertSame('https://fr.wikipedia.org/wiki/Gite', $payload['wikipediaUrl']);
            $this->assertSame('Mo-Su 08:00-20:00', $payload['openingHours']);
        }
    }

    #[Test]
    public function detailNonExistentTripReturns404(): void
    {
        // Object-level authz denials are surfaced as 404 (ADR-038): an unknown trip
        // and a foreign trip are indistinguishable, so existence is not leaked.
        $this->client->request('GET', '/trips/00000000-0000-0000-0000-000000000000/detail', [
            'headers' => $this->authHeader($this->jwtToken),
        ]);

        $this->assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function detailOfAnotherUsersTripReturns404(): void
    {
        // IDOR-DETAIL regression: a trip owned by someone else must not be readable,
        // and is hidden as 404 (not 403) so its existence is not revealed (ADR-038).
        $repo = $this->seedTrip(self::TRIP_ID);
        $repo->storeStages(self::TRIP_ID, []);

        ['token' => $otherToken] = $this->createTestUserWithJwt('intruder@example.com');

        $this->client->request('GET', \sprintf('/trips/%s/detail', self::TRIP_ID), [
            'headers' => array_merge(['Accept' => 'application/ld+json'], $this->authHeader($otherToken)),
        ]);

        $this->assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function unauthenticatedRequestReturns401(): void
    {
        $this->client->request('GET', '/trips/01936f6e-0000-7000-8000-000000000001/detail');

        $this->assertResponseStatusCodeSame(401);
    }

    #[Test]
    public function routeReturnsPerStageGeometry(): void
    {
        $repo = $this->seedTrip(self::TRIP_ID);
        $repo->storeStages(self::TRIP_ID, [
            new StageDto(
                tripId: self::TRIP_ID,
                dayNumber: 1,
                distance: 40.0,
                elevation: 300.0,
                startPoint: new Coordinate(45.0, 6.0, 100.0),
                endPoint: new Coordinate(45.5, 6.5, 200.0),
                geometry: [new Coordinate(45.0, 6.0, 100.0), new Coordinate(45.5, 6.5, 200.0)],
            ),
        ]);

        $response = $this->client->request('GET', \sprintf('/trips/%s/route', self::TRIP_ID), [
            'headers' => array_merge(['Accept' => 'application/ld+json'], $this->authHeader($this->jwtToken)),
        ]);

        $this->assertResponseIsSuccessful();
        $data = $response->toArray(false);
        $this->assertSame(self::TRIP_ID, $data['id']);
        $this->assertCount(1, $data['stages']);
        $this->assertSame(1, $data['stages'][0]['dayNumber']);
        $this->assertCount(2, $data['stages'][0]['geometry']);
        $this->assertEqualsWithDelta(45.0, $data['stages'][0]['geometry'][0]['lat'], 0.0001);
    }

    #[Test]
    public function stageDetailReturnsTheFullStageIncludingGeometry(): void
    {
        $repo = $this->seedTrip(self::TRIP_ID);
        $repo->storeStages(self::TRIP_ID, [
            new StageDto(
                tripId: self::TRIP_ID,
                dayNumber: 1,
                distance: 40.0,
                elevation: 300.0,
                startPoint: new Coordinate(45.0, 6.0, 100.0),
                endPoint: new Coordinate(45.5, 6.5, 200.0),
                geometry: [new Coordinate(45.0, 6.0, 100.0), new Coordinate(45.5, 6.5, 200.0)],
            ),
        ]);

        $stageId = ($repo->getStages(self::TRIP_ID) ?? [])[0]->id;
        $response = $this->client->request('GET', \sprintf('/trips/%s/stages/%s/detail', self::TRIP_ID, $stageId), [
            'headers' => array_merge(['Accept' => 'application/ld+json'], $this->authHeader($this->jwtToken)),
        ]);

        $this->assertResponseIsSuccessful();
        $data = $response->toArray(false);
        $this->assertSame(1, $data['dayNumber']);
        $this->assertCount(2, $data['geometry']);
        $this->assertArrayHasKey('resupply', $data);
        $this->assertArrayHasKey('accommodations', $data);
    }

    #[Test]
    public function routeOfAnotherUsersTripReturns404(): void
    {
        // IDOR-DETAIL regression: TRIP_VIEW hides another user's trip as a 404.
        $repo = $this->seedTrip(self::TRIP_ID);
        $repo->storeStages(self::TRIP_ID, []);

        ['token' => $otherToken] = $this->createTestUserWithJwt('intruder@example.com');

        $this->client->request('GET', \sprintf('/trips/%s/route', self::TRIP_ID), [
            'headers' => array_merge(['Accept' => 'application/ld+json'], $this->authHeader($otherToken)),
        ]);

        $this->assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function stageDetailOfAnotherUsersTripReturns404(): void
    {
        $repo = $this->seedTrip(self::TRIP_ID);
        $repo->storeStages(self::TRIP_ID, [
            new StageDto(
                tripId: self::TRIP_ID,
                dayNumber: 1,
                distance: 40.0,
                elevation: 300.0,
                startPoint: new Coordinate(45.0, 6.0, 100.0),
                endPoint: new Coordinate(45.5, 6.5, 200.0),
            ),
        ]);

        ['token' => $otherToken] = $this->createTestUserWithJwt('intruder2@example.com');

        $stageId = ($repo->getStages(self::TRIP_ID) ?? [])[0]->id;
        $this->client->request('GET', \sprintf('/trips/%s/stages/%s/detail', self::TRIP_ID, $stageId), [
            'headers' => array_merge(['Accept' => 'application/ld+json'], $this->authHeader($otherToken)),
        ]);

        $this->assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function stageDetailOfAnUnknownStageReturns404(): void
    {
        $repo = $this->seedTrip(self::TRIP_ID);
        $repo->storeStages(self::TRIP_ID, []);

        $this->client->request('GET', \sprintf('/trips/%s/stages/%s/detail', self::TRIP_ID, Uuid::v7()->toRfc4122()), [
            'headers' => array_merge(['Accept' => 'application/ld+json'], $this->authHeader($this->jwtToken)),
        ]);

        $this->assertResponseStatusCodeSame(404);
    }
}
