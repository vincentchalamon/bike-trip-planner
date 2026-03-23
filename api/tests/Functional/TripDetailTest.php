<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\ApiResource\TripRequest;
use App\Repository\DoctrineTripRequestRepository;
use PHPUnit\Framework\Attributes\Test;

final class TripDetailTest extends ApiTestCase
{
    #[\Override]
    public static function setUpBeforeClass(): void
    {
        self::$alwaysBootKernel = false;
    }

    private const string TRIP_ID = '01936f6e-0000-7000-8000-000000000301';

    private function seedTrip(string $tripId): void
    {
        $request = new TripRequest();
        $request->sourceUrl = 'https://www.komoot.com/tour/123456789';

        /** @var DoctrineTripRequestRepository $repo */
        $repo = self::getContainer()->get(DoctrineTripRequestRepository::class);
        $repo->initializeTrip($tripId, $request);
        $repo->storeTitle($tripId, 'Detail test trip');
    }

    #[Test]
    public function detailReturnsExpectedFields(): void
    {
        $this->seedTrip(self::TRIP_ID);

        $response = self::createClient()->request('GET', \sprintf('/trips/%s/detail', self::TRIP_ID), [
            'headers' => ['Accept' => 'application/ld+json'],
        ]);

        $this->assertResponseIsSuccessful();

        $data = $response->toArray(false);
        $this->assertSame(self::TRIP_ID, $data['id']);
        $this->assertArrayHasKey('stages', $data);
        $this->assertArrayHasKey('fatigueFactor', $data);
        $this->assertArrayHasKey('enabledAccommodationTypes', $data);
    }

    #[Test]
    public function detailNonExistentTripReturns404(): void
    {
        self::createClient()->request('GET', '/trips/00000000-0000-0000-0000-000000000000/detail');

        $this->assertResponseStatusCodeSame(404);
    }
}
