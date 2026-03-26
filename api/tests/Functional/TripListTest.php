<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use DateTimeImmutable;
use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\ApiResource\TripRequest;
use App\Repository\DoctrineTripRequestRepository;
use PHPUnit\Framework\Attributes\Test;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class TripListTest extends ApiTestCase
{
    use Factories;

    private const string TRIP_ID_1 = '01936f6e-0000-7000-8000-000000000101';

    private const string TRIP_ID_2 = '01936f6e-0000-7000-8000-000000000102';

    private function seedTrip(
        string $tripId,
        ?string $title = null,
        ?string $sourceUrl = 'https://www.komoot.com/tour/123456789',
        ?DateTimeImmutable $startDate = null,
        ?DateTimeImmutable $endDate = null,
    ): void {
        $request = new TripRequest();
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
    }

    #[Test]
    public function listTripsReturnsHydraCollection(): void
    {
        $this->seedTrip(self::TRIP_ID_1, title: 'Tour des Alpes');
        $this->seedTrip(self::TRIP_ID_2, title: 'Bretagne coastal');

        $response = self::createClient()->request('GET', '/trips', [
            'headers' => ['Accept' => 'application/ld+json'],
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/ld+json; charset=utf-8');

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

        $response = self::createClient()->request('GET', '/trips?title=Alpes', [
            'headers' => ['Accept' => 'application/ld+json'],
        ]);

        $this->assertResponseIsSuccessful();

        $data = $response->toArray(false);
        $this->assertArrayHasKey('member', $data);
        $this->assertSame(1, $data['totalItems']);
        $this->assertSame('Tour des Alpes', $data['member'][0]['title']);
    }

    #[Test]
    public function listTripItemContainsExpectedFields(): void
    {
        $this->seedTrip(
            self::TRIP_ID_1,
            title: 'Tour des Alpes',
            startDate: new DateTimeImmutable('2025-07-01'),
            endDate: new DateTimeImmutable('2025-07-15'),
        );

        $response = self::createClient()->request('GET', '/trips', [
            'headers' => ['Accept' => 'application/ld+json'],
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
        $this->seedTrip(self::TRIP_ID_1, title: 'Early trip', startDate: new DateTimeImmutable('2025-06-01'));
        $this->seedTrip(self::TRIP_ID_2, title: 'Late trip', startDate: new DateTimeImmutable('2025-09-01'));

        $response = self::createClient()->request('GET', '/trips?startDate=2025-08-01', [
            'headers' => ['Accept' => 'application/ld+json'],
        ]);

        $this->assertResponseIsSuccessful();

        $data = $response->toArray(false);
        $ids = array_column($data['member'], 'id');
        $this->assertContains(self::TRIP_ID_2, $ids);
        $this->assertNotContains(self::TRIP_ID_1, $ids);
    }

    #[Test]
    public function listTripsFilterByEndDate(): void
    {
        $this->seedTrip(self::TRIP_ID_1, title: 'Short trip', endDate: new DateTimeImmutable('2025-06-15'));
        $this->seedTrip(self::TRIP_ID_2, title: 'Long trip', endDate: new DateTimeImmutable('2025-09-30'));

        $response = self::createClient()->request('GET', '/trips?endDate=2025-07-01', [
            'headers' => ['Accept' => 'application/ld+json'],
        ]);

        $this->assertResponseIsSuccessful();

        $data = $response->toArray(false);
        $ids = array_column($data['member'], 'id');
        $this->assertContains(self::TRIP_ID_1, $ids);
        $this->assertNotContains(self::TRIP_ID_2, $ids);
    }
}
