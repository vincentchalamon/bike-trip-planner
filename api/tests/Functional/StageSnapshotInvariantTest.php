<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\ApiResource\Model\Accommodation;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Model\PointOfInterest;
use App\ApiResource\Model\Resupply;
use App\ApiResource\Stage as StageDto;
use App\ApiResource\TripRequest;
use App\Entity\User;
use App\Repository\DoctrineTripRequestRepository;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Snapshot invariant of the PG split (ADR-060): a persisted trip renders in full
 * from the stage's own JSONB columns (pois/resupply, accommodations,
 * selectedAccommodation) WITHOUT touching the shared read-only PG-référence.
 *
 * Historical integrity is a snapshot property — the computed result is frozen onto
 * the Stage entity at store time (which legitimately queries the reference index),
 * orthogonal to whether the reference index still holds those rows afterwards, or is
 * even reachable. This test pins that: after the trip is stored, the entire reference
 * schema is dropped, and the trip-detail render must still succeed and return the
 * frozen data — proving the render path reads only PG-app.
 *
 * To see it fail (and prove it is not vacuous): make TripDetailProvider or
 * DoctrineTripRequestRepository::getStages read the reference index (osm/tourism)
 * while serializing a stage — the dropped schema then makes the request 500.
 */
#[ResetDatabase]
final class StageSnapshotInvariantTest extends ApiTestCase
{
    use Factories;
    use JwtAuthTestTrait;

    private const string TRIP_ID = '01936f6e-0000-7000-8000-0000000000aa';

    private Client $client;

    private User $testUser;

    private string $jwtToken;

    #[\Override]
    public static function setUpBeforeClass(): void
    {
        self::$alwaysBootKernel = false;
    }

    #[\Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
        ['user' => $this->testUser, 'token' => $this->jwtToken] = $this->createTestUserWithJwt('snapshot@example.com');
    }

    #[Test]
    public function persistedStageRendersFromItsJsonbSnapshotAfterTheReferenceIndexIsGone(): void
    {
        $request = new TripRequest();
        $request->sourceUrl = 'https://www.komoot.com/tour/123456789';

        /** @var DoctrineTripRequestRepository $repo */
        $repo = self::getContainer()->get(DoctrineTripRequestRepository::class);
        $repo->initializeTrip(self::TRIP_ID, $request);
        $repo->storeTitle(self::TRIP_ID, 'Snapshot invariant trip');
        $this->associateTripWithUser(self::TRIP_ID, $this->testUser);

        $accommodation = new Accommodation(
            name: 'Camping Les Pins',
            type: 'camp_site',
            lat: 45.48,
            lon: 5.48,
            estimatedPriceMin: 12.0,
            estimatedPriceMax: 18.0,
            isExactPrice: false,
        );

        $stage = new StageDto(
            tripId: self::TRIP_ID,
            dayNumber: 1,
            distance: 85.5,
            elevation: 1200.0,
            startPoint: new Coordinate(45.0, 6.0, 1000.0),
            endPoint: new Coordinate(45.48, 5.48, 800.0),
            geometry: [new Coordinate(45.0, 6.0, 1000.0)],
        );
        // The three reference-derived JSONB snapshots the invariant is about.
        $stage->resupply = new Resupply(
            foodAtArrival: [new PointOfInterest(name: 'Boulangerie du Col', category: 'bakery', lat: 45.47, lon: 5.47)],
        );
        $stage->addAccommodation($accommodation);
        $stage->selectedAccommodation = $accommodation;

        // Freezes the snapshot onto the Stage entity (this is the store-time step that
        // legitimately reads the reference index, e.g. the on-cycle-network fraction).
        $repo->storeStages(self::TRIP_ID, [$stage]);
        $repo->storeStatus(self::TRIP_ID, 'ready');

        // Now make the reference index vanish entirely: any render-time query against
        // osm/tourism would raise "schema does not exist".
        /** @var Connection $reference */
        $reference = self::getContainer()->get('doctrine.dbal.reference_connection');
        $reference->executeStatement('DROP SCHEMA IF EXISTS osm CASCADE');
        $reference->executeStatement('DROP SCHEMA IF EXISTS tourism CASCADE');

        $response = $this->client->request('GET', \sprintf('/trips/%s/detail', self::TRIP_ID), [
            'headers' => array_merge(['Accept' => 'application/ld+json'], $this->authHeader($this->jwtToken)),
        ]);

        $this->assertResponseIsSuccessful();

        $data = $response->toArray();
        $this->assertNotEmpty($data['stages']);
        $stagePayload = $data['stages'][0];

        // Accommodations snapshot rendered from JSONB.
        $this->assertCount(1, $stagePayload['accommodations']);
        $this->assertSame('Camping Les Pins', $stagePayload['accommodations'][0]['name']);

        // selectedAccommodation snapshot rendered from JSONB.
        $this->assertNotNull($stagePayload['selectedAccommodation']);
        $this->assertSame('Camping Les Pins', $stagePayload['selectedAccommodation']['name']);

        // pois/resupply snapshot rendered from JSONB.
        $this->assertSame('Boulangerie du Col', $stagePayload['resupply']['foodAtArrival'][0]['name']);
    }
}
