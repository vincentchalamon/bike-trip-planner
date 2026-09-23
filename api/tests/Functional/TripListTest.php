<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\ApiTestCase;
use ApiPlatform\Test\Client;
use Symfony\Component\Uid\Uuid;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\ApiResource\TripRequest;
use App\Entity\User;
use App\Repository\DoctrineTripRequestRepository;
use PHPUnit\Framework\Attributes\Test;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class TripListTest extends ApiTestCase
{
    use JwtAuthTestTrait;

    private Client $client;

    private User $testUser;

    private string $jwtToken;

    private const string TRIP_ID_1 = '01936f6e-0000-7000-8000-000000000101';

    private const string TRIP_ID_2 = '01936f6e-0000-7000-8000-000000000102';

    #[\Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
        ['user' => $this->testUser, 'token' => $this->jwtToken] = $this->createTestUserWithJwt('list-user@test.com');
    }

    private function seedTrip(
        string $tripId,
        ?string $title = null,
        ?string $sourceUrl = 'https://www.komoot.com/tour/123456789',
        ?\DateTimeImmutable $startDate = null,
        ?\DateTimeImmutable $endDate = null,
    ): void {
        $request = new TripRequest(Uuid::fromString($tripId));
        $request->sourceUrl = $sourceUrl;
        $request->startDate = $startDate;
        $request->endDate = $endDate;

        $container = self::getContainer();

        /** @var DoctrineTripRequestRepository $repo */
        $repo = $container->get(DoctrineTripRequestRepository::class);
        $repo->initializeTrip($tripId, $request);

        if (null !== $title) {
            $repo->storeTitle($tripId, $title);
        }

        $this->associateTripWithUser($tripId, $this->testUser);
    }

    #[Test]
    public function listTripsReturnsHydraCollection(): void
    {
        $this->seedTrip(self::TRIP_ID_1, title: 'Tour des Alpes');
        $this->seedTrip(self::TRIP_ID_2, title: 'Bretagne coastal');

        $response = $this->client->request('GET', '/trips', [
            'headers' => array_merge(['Accept' => 'application/ld+json'], $this->authHeader($this->jwtToken)),
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/ld+json');

        $data = $response->toArray(false);
        $this->assertArrayHasKey('member', $data);
        $this->assertArrayHasKey('totalItems', $data);
        $this->assertGreaterThanOrEqual(2, $data['totalItems']);
    }

    #[Test]
    public function listTripsFilterByTitle(): void
    {
        $this->seedTrip(self::TRIP_ID_1, title: 'Tour des Alpes');
        $this->seedTrip(self::TRIP_ID_2, title: 'Bretagne coastal');

        $response = $this->client->request('GET', '/trips?title=Alpes', [
            'headers' => array_merge(['Accept' => 'application/ld+json'], $this->authHeader($this->jwtToken)),
        ]);

        $this->assertResponseIsSuccessful();

        $data = $response->toArray(false);
        $this->assertArrayHasKey('member', $data);
        $this->assertSame(1, $data['totalItems']);
        $this->assertSame('Tour des Alpes', $data['member'][0]['title']);
    }

    #[Test]
    public function listTripSerializesAccentedTitleAsByteCorrectUtf8(): void
    {
        // Device recette (#trips-list-title-encoding): "Entre Sensée et Escaut" showed
        // up mojibaked ("Entre SensÃ©e et Escaut") on the mobile list. The corruption is
        // introduced at the transport edge (Caddy zstd/br compression the RN HTTP stack
        // cannot decode), not here — this locks the invariant that the collection
        // provider + serializer emit the title as byte-for-byte UTF-8, so a future
        // mb_convert/htmlentities slip in the serialization path is caught in CI.
        $title = 'Entre Sensée et Escaut';
        $this->seedTrip(self::TRIP_ID_1, title: $title);

        $response = $this->client->request('GET', '/trips', [
            'headers' => array_merge(['Accept' => 'application/ld+json'], $this->authHeader($this->jwtToken)),
        ]);

        $this->assertResponseIsSuccessful();

        $raw = $response->getContent();
        // Byte-for-byte: the accented run "Sensée" must carry the raw UTF-8 bytes for é
        // (0xC3 0xA9), never the double-encoded "Ã©" (0xC3 0x83 0xC2 0xA9).
        $this->assertStringContainsString($title, $raw);
        $this->assertStringContainsString(bin2hex('Sensée'), bin2hex($raw));
        $this->assertStringNotContainsString('SensÃ©e', $raw);

        $member = $response->toArray(false)['member'][0];
        $this->assertSame($title, $member['title']);
    }

    #[Test]
    public function listTripItemContainsExpectedFields(): void
    {
        $this->seedTrip(
            self::TRIP_ID_1,
            title: 'Tour des Alpes',
            startDate: new \DateTimeImmutable('2025-07-01'),
            endDate: new \DateTimeImmutable('2025-07-15'),
        );

        $response = $this->client->request('GET', '/trips', [
            'headers' => array_merge(['Accept' => 'application/ld+json'], $this->authHeader($this->jwtToken)),
        ]);

        $this->assertResponseIsSuccessful();

        $data = $response->toArray(false);
        $members = array_values(array_filter($data['member'], fn (array $m): bool => self::TRIP_ID_1 === $m['id']));
        $this->assertNotEmpty($members, 'Seeded trip not found in response');
        $member = $members[0];

        $this->assertArrayHasKey('id', $member);
        $this->assertArrayHasKey('title', $member);
        $this->assertArrayHasKey('totalDistance', $member);
        $this->assertArrayHasKey('stageCount', $member);
        $this->assertArrayHasKey('createdAt', $member);
        $this->assertArrayHasKey('updatedAt', $member);
    }

    #[Test]
    public function listTripsFilterByStartDate(): void
    {
        $this->seedTrip(self::TRIP_ID_1, title: 'Early trip', startDate: new \DateTimeImmutable('2025-06-01'));
        $this->seedTrip(self::TRIP_ID_2, title: 'Late trip', startDate: new \DateTimeImmutable('2025-09-01'));

        $response = $this->client->request('GET', '/trips?startDate=2025-08-01', [
            'headers' => array_merge(['Accept' => 'application/ld+json'], $this->authHeader($this->jwtToken)),
        ]);

        $this->assertResponseIsSuccessful();

        $data = $response->toArray(false);
        $ids = array_column($data['member'], 'id');
        $this->assertContains(self::TRIP_ID_2, $ids);
        $this->assertNotContains(self::TRIP_ID_1, $ids);
    }

    /**
     * The page size a client asks for is capped at the number the contract already published.
     *
     * `paginationClientItemsPerPage` lets the caller size the page, and nothing used to bound
     * it: the runtime Pagination service is built from an options array that carried no
     * maximum, so `Pagination::getLimit()` skipped its clamp entirely while the exported
     * OpenAPI advertised `maximum: 30`. The total is deliberately *not* clamped — a client
     * still learns how many trips it has.
     */
    #[Test]
    public function aPageSizeBeyondTheMaximumIsClamped(): void
    {
        for ($i = 1; $i <= 31; ++$i) {
            $this->seedTrip(\sprintf('01936f6e-0000-7000-8000-0000000002%02d', $i));
        }

        $response = $this->client->request('GET', '/trips?itemsPerPage=100000', [
            'headers' => array_merge(['Accept' => 'application/ld+json'], $this->authHeader($this->jwtToken)),
        ]);

        $this->assertResponseIsSuccessful();

        $data = $response->toArray(false);
        $this->assertCount(30, $data['member']);
        $this->assertSame(31, $data['totalItems']);
    }

    /**
     * Paging over trips that share a createdAt must still show each of them exactly once.
     *
     * `trip.created_at` is `timestamp(0)`, so any two trips created in the same second tie,
     * and a tie straddling a page boundary is served twice or not at all unless the order is
     * total. The identifier is a UUID v7, which breaks the tie in the same direction time runs.
     *
     * Honest about what this proves: on three rows Postgres happens to sort deterministically,
     * so this does not go red against the untied query. It is a regression guard — it fails if
     * the second sort key is dropped and the list starts paging on a partial order again.
     */
    #[Test]
    public function pagingOverTripsCreatedInTheSameSecondShowsEachOnce(): void
    {
        $ids = [];
        for ($i = 1; $i <= 3; ++$i) {
            $ids[] = $id = \sprintf('01936f6e-0000-7000-8000-0000000003%02d', $i);
            $this->seedTrip($id);
        }

        $seen = [];
        foreach ([1, 2] as $page) {
            $response = $this->client->request('GET', \sprintf('/trips?itemsPerPage=2&page=%d', $page), [
                'headers' => array_merge(['Accept' => 'application/ld+json'], $this->authHeader($this->jwtToken)),
            ]);
            $this->assertResponseIsSuccessful();
            $seen = array_merge($seen, array_column($response->toArray(false)['member'], 'id'));
        }

        sort($ids);
        $unique = array_unique($seen);
        sort($unique);

        $this->assertSame($ids, array_values($unique));
        $this->assertCount(3, $seen, 'A trip was served on both pages.');
    }

    /**
     * The totals are read as an aggregate now, so assert the numbers, not the keys.
     *
     * `listTripItemContainsExpectedFields` only checks that `totalDistance` and `stageCount`
     * are present, which the rewrite would have satisfied while answering anything. Two cases
     * matter beyond the happy path: a rest day contributes neither distance nor count, and a
     * trip with no stage at all must stay in the list with zeroes rather than drop out of a
     * join.
     */
    #[Test]
    public function totalsExcludeRestDaysAndAStagelessTripStaysInTheList(): void
    {
        $this->seedTripWithStages(self::TRIP_ID_1);
        $this->seedTrip(self::TRIP_ID_2);

        $response = $this->client->request('GET', '/trips', [
            'headers' => array_merge(['Accept' => 'application/ld+json'], $this->authHeader($this->jwtToken)),
        ]);

        $this->assertResponseIsSuccessful();
        $members = array_column($response->toArray(false)['member'], null, 'id');

        // A whole float comes back from JSON as an int, so compare as floats.
        $this->assertArrayHasKey(self::TRIP_ID_1, $members);
        $this->assertSame(2, $members[self::TRIP_ID_1]['stageCount'], 'The rest day was counted.');
        $this->assertSame(120.0, (float) $members[self::TRIP_ID_1]['totalDistance'], 'The rest day was ridden.');

        $this->assertArrayHasKey(self::TRIP_ID_2, $members, 'A trip with no stage fell out of the list.');
        $this->assertSame(0, $members[self::TRIP_ID_2]['stageCount']);
        $this->assertSame(0.0, (float) $members[self::TRIP_ID_2]['totalDistance']);
    }

    private function seedTripWithStages(string $tripId): void
    {
        $this->seedTrip($tripId);

        $container = self::getContainer();
        /** @var DoctrineTripRequestRepository $repo */
        $repo = $container->get(DoctrineTripRequestRepository::class);

        $stage = static fn (int $day, float $distance, bool $restDay): Stage => new Stage(
            tripId: $tripId,
            dayNumber: $day,
            distance: $distance,
            elevation: 100.0,
            startPoint: new Coordinate(45.0, 5.0),
            endPoint: new Coordinate(45.5, 5.5),
            isRestDay: $restDay,
        );

        $repo->storeStages($tripId, [
            $stage(1, 50.0, false),
            $stage(2, 999.0, true),
            $stage(3, 70.0, false),
        ]);
    }

    #[Test]
    public function listTripsFilterByEndDate(): void
    {
        $this->seedTrip(self::TRIP_ID_1, title: 'Short trip', endDate: new \DateTimeImmutable('2025-06-15'));
        $this->seedTrip(self::TRIP_ID_2, title: 'Long trip', endDate: new \DateTimeImmutable('2025-09-30'));

        $response = $this->client->request('GET', '/trips?endDate=2025-07-01', [
            'headers' => array_merge(['Accept' => 'application/ld+json'], $this->authHeader($this->jwtToken)),
        ]);

        $this->assertResponseIsSuccessful();

        $data = $response->toArray(false);
        $ids = array_column($data['member'], 'id');
        $this->assertContains(self::TRIP_ID_1, $ids);
        $this->assertNotContains(self::TRIP_ID_2, $ids);
    }
}
