<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage as StageDto;
use App\ApiResource\TripRequest;
use App\Repository\DoctrineTripRequestRepository;
use PHPUnit\Framework\Attributes\Test;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class TripDetailTest extends ApiTestCase
{
    use Factories;

    private const string TRIP_ID = '01936f6e-0000-7000-8000-000000000301';

    private function seedTrip(string $tripId): DoctrineTripRequestRepository
    {
        $request = new TripRequest();
        $request->sourceUrl = 'https://www.komoot.com/tour/123456789';

        /** @var DoctrineTripRequestRepository $repo */
        $repo = self::getContainer()->get(DoctrineTripRequestRepository::class);
        $repo->initializeTrip($tripId, $request);
        $repo->storeTitle($tripId, 'Detail test trip');

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

        $response = self::createClient()->request('GET', \sprintf('/trips/%s/detail', self::TRIP_ID), [
            'headers' => ['Accept' => 'application/ld+json'],
        ]);

        $this->assertResponseIsSuccessful();

        $data = $response->toArray(false);
        $this->assertSame(self::TRIP_ID, $data['id']);
        $this->assertArrayHasKey('stages', $data);
        $this->assertArrayHasKey('fatigueFactor', $data);
        $this->assertArrayHasKey('enabledAccommodationTypes', $data);
        $this->assertNotEmpty($data['stages']);
        $stage = $data['stages'][0];
        $this->assertArrayHasKey('dayNumber', $stage);
        $this->assertArrayHasKey('distance', $stage);
        $this->assertArrayHasKey('geometry', $stage);
        $this->assertArrayHasKey('alerts', $stage);
        $this->assertArrayHasKey('accommodations', $stage);
    }

    #[Test]
    public function detailNonExistentTripReturns404(): void
    {
        self::createClient()->request('GET', '/trips/00000000-0000-0000-0000-000000000000/detail');

        $this->assertResponseStatusCodeSame(404);
    }
}
