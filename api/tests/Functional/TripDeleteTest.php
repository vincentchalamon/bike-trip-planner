<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\ApiResource\TripRequest;
use App\Repository\DoctrineTripRequestRepository;
use PHPUnit\Framework\Attributes\Test;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class TripDeleteTest extends ApiTestCase
{
    use Factories;

    private const string TRIP_ID = '01936f6e-0000-7000-8000-000000000201';

    private function seedTrip(string $tripId): void
    {
        $request = new TripRequest();
        $request->sourceUrl = 'https://www.komoot.com/tour/123456789';

        $container = self::getContainer();

        /** @var DoctrineTripRequestRepository $repo */
        $repo = $container->get(DoctrineTripRequestRepository::class);
        $repo->initializeTrip($tripId, $request);
    }

    #[Test]
    public function deleteTripReturnsNoContent(): void
    {
        $this->seedTrip(self::TRIP_ID);

        self::createClient()->request('DELETE', \sprintf('/trips/%s', self::TRIP_ID));

        $this->assertResponseStatusCodeSame(204);
    }

    #[Test]
    public function deleteTripRemovesItFromList(): void
    {
        $this->seedTrip(self::TRIP_ID);

        $client = self::createClient();

        $client->request('DELETE', \sprintf('/trips/%s', self::TRIP_ID));
        $this->assertResponseStatusCodeSame(204);

        $response = $client->request('GET', '/trips', [
            'headers' => ['Accept' => 'application/ld+json'],
        ]);
        $this->assertResponseIsSuccessful();

        $data = $response->toArray(false);
        $ids = array_column($data['member'], 'id');
        $this->assertNotContains(self::TRIP_ID, $ids);
    }

    #[Test]
    public function deleteNonExistentTripReturns404(): void
    {
        self::createClient()->request('DELETE', '/trips/00000000-0000-0000-0000-000000000000');

        $this->assertResponseStatusCodeSame(404);
    }
}
