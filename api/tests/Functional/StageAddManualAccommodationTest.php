<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationTrackerInterface;
use App\Entity\User;
use App\Enum\ComputationName;
use App\Enum\SourceType;
use App\Message\RecalculateStages;
use App\Repository\TripRequestRepositoryInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class StageAddManualAccommodationTest extends ApiTestCase
{
    use Factories;
    use JwtAuthTestTrait;

    private const string TRIP_ID = '01936f6e-0000-7000-8000-0000000000a1';

    private Client $client;

    private User $testUser;

    private string $jwtToken;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        ['user' => $this->testUser, 'token' => $this->jwtToken] = $this->createTestUserWithJwt('manual-acc@example.com');
    }

    /**
     * @param list<array{lat: string, lon: string}> $results
     */
    private function mockNominatimClient(array $results): void
    {
        $mockResponse = new MockResponse(
            json_encode($results, \JSON_THROW_ON_ERROR),
            ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']],
        );
        self::getContainer()->set('nominatim.client', new MockHttpClient($mockResponse));
    }

    private function seedTripWithStages(string $tripId): void
    {
        $container = self::getContainer();

        /** @var TripRequestRepositoryInterface $repo */
        $repo = $container->get(TripRequestRepositoryInterface::class);

        $request = new TripRequest(Uuid::fromString($tripId));
        $request->sourceUrl = 'https://www.komoot.com/tour/987654321';
        $request->startDate = new \DateTimeImmutable('today +1 year');

        $repo->initializeTrip($tripId, $request);
        $repo->storeSourceType($tripId, SourceType::KOMOOT_TOUR->value);

        $stage0 = new Stage(
            tripId: $tripId,
            dayNumber: 1,
            distance: 80.0,
            elevation: 500.0,
            startPoint: new Coordinate(45.0, 5.0),
            endPoint: new Coordinate(45.5, 5.5),
        );
        $stage1 = new Stage(
            tripId: $tripId,
            dayNumber: 2,
            distance: 70.0,
            elevation: 400.0,
            startPoint: new Coordinate(45.5, 5.5),
            endPoint: new Coordinate(46.0, 6.0),
        );

        $repo->storeStages($tripId, [$stage0, $stage1]);

        /** @var ComputationTrackerInterface $tracker */
        $tracker = $container->get(ComputationTrackerInterface::class);
        $tracker->initializeComputations($tripId, ComputationName::cases());

        $this->associateTripWithUser($tripId, $this->testUser);
    }

    #[Test]
    public function addsGeocodedManualAccommodationAndMovesStageBoundary(): void
    {
        $this->seedTripWithStages(self::TRIP_ID);
        $this->mockNominatimClient([['lat' => '45.4801', 'lon' => '5.4802']]);

        $response = $this->client->request('POST', '/trips/'.self::TRIP_ID.'/stages/0/accommodations/manual', [
            'headers' => ['Content-Type' => 'application/ld+json', ...$this->authHeader($this->jwtToken)],
            'json' => [
                'name' => 'HomeExchange Grenoble',
                'address' => '5 rue Test, Grenoble',
                'priceTotal' => 90,
                'url' => 'https://homeexchange.example/xyz',
            ],
        ]);

        $this->assertResponseStatusCodeSame(202);
        $data = $response->toArray(false);
        $this->assertSame('StageResponse', $data['@type']);

        /** @var TripRequestRepositoryInterface $repo */
        $repo = self::getContainer()->get(TripRequestRepositoryInterface::class);
        $stages = $repo->getStages(self::TRIP_ID);

        $this->assertNotNull($stages);
        $acc = $stages[0]->selectedAccommodation;
        $this->assertNotNull($acc);
        $this->assertSame('manual', $acc->source);
        $this->assertSame('other', $acc->type);
        $this->assertSame('HomeExchange Grenoble', $acc->name);
        $this->assertSame('5 rue Test, Grenoble', $acc->address);
        $this->assertSame('https://homeexchange.example/xyz', $acc->url);
        $this->assertSame(90.0, $acc->estimatedPriceMin);
        $this->assertSame(90.0, $acc->estimatedPriceMax);
        $this->assertTrue($acc->isExactPrice);

        $this->assertCount(1, $stages[0]->accommodations);
        $this->assertEqualsWithDelta(45.4801, $stages[0]->endPoint->lat, 0.0001);
        $this->assertEqualsWithDelta(5.4802, $stages[0]->endPoint->lon, 0.0001);
        $this->assertEqualsWithDelta(45.4801, $stages[1]->startPoint->lat, 0.0001);
        $this->assertEqualsWithDelta(5.4802, $stages[1]->startPoint->lon, 0.0001);

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        $messageClasses = array_map(
            static fn (Envelope $e): string => $e->getMessage()::class,
            $transport->getSent(),
        );
        $this->assertContains(RecalculateStages::class, $messageClasses);
    }

    #[Test]
    public function omittedPriceStoresNoExactPrice(): void
    {
        $this->seedTripWithStages(self::TRIP_ID);
        $this->mockNominatimClient([['lat' => '45.10', 'lon' => '5.10']]);

        $this->client->request('POST', '/trips/'.self::TRIP_ID.'/stages/0/accommodations/manual', [
            'headers' => ['Content-Type' => 'application/ld+json', ...$this->authHeader($this->jwtToken)],
            'json' => [
                'name' => 'Chez lhabitant',
                'address' => '1 place Test, Chambery',
            ],
        ]);

        $this->assertResponseStatusCodeSame(202);

        /** @var TripRequestRepositoryInterface $repo */
        $repo = self::getContainer()->get(TripRequestRepositoryInterface::class);
        $stages = $repo->getStages(self::TRIP_ID);
        $acc = $stages[0]->selectedAccommodation ?? null;
        $this->assertNotNull($acc);
        $this->assertSame(0.0, $acc->estimatedPriceMin);
        $this->assertSame(0.0, $acc->estimatedPriceMax);
        $this->assertFalse($acc->isExactPrice);
        $this->assertNull($acc->url);
    }

    #[Test]
    public function unresolvableAddressReturns422AndPersistsNothing(): void
    {
        $this->seedTripWithStages(self::TRIP_ID);
        // Nominatim returns no match → geocoding fails.
        $this->mockNominatimClient([]);

        $this->client->request('POST', '/trips/'.self::TRIP_ID.'/stages/0/accommodations/manual', [
            'headers' => ['Content-Type' => 'application/ld+json', ...$this->authHeader($this->jwtToken)],
            'json' => [
                'name' => 'Nowhere',
                'address' => 'zzzz unresolvable qqqq',
            ],
        ]);

        $this->assertResponseStatusCodeSame(422);

        /** @var TripRequestRepositoryInterface $repo */
        $repo = self::getContainer()->get(TripRequestRepositoryInterface::class);
        $stages = $repo->getStages(self::TRIP_ID);
        $this->assertNotNull($stages);
        // Nothing persisted: endPoint untouched, no accommodation added.
        $this->assertNull($stages[0]->selectedAccommodation);
        $this->assertCount(0, $stages[0]->accommodations);
        $this->assertEqualsWithDelta(45.5, $stages[0]->endPoint->lat, 0.0001);
    }

    #[Test]
    public function missingRequiredFieldsReturns422(): void
    {
        $this->seedTripWithStages(self::TRIP_ID);

        $this->client->request('POST', '/trips/'.self::TRIP_ID.'/stages/0/accommodations/manual', [
            'headers' => ['Content-Type' => 'application/ld+json', ...$this->authHeader($this->jwtToken)],
            'json' => ['name' => '', 'address' => ''],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }
}
