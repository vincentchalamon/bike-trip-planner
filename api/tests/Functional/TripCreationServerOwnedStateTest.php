<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\ApiResource\TripRequest;
use App\Repository\TripRequestRepositoryInterface;
use App\Tests\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * What a creation is allowed to decide, and what only the server decides.
 *
 * `POST /trips` deserializes its body into {@see TripRequest}, which is an input DTO and not an
 * `#[ApiResource]`. That distinction is the whole story: `#[ApiProperty(writable: false)]` is
 * only consulted for a resource class — {@see \ApiPlatform\Serializer\AbstractItemNormalizer::getAllowedAttributes()}
 * hands anything else to Symfony's filter, which sorts by serialization group and finds none
 * declared here. So the attribute describes the published schema and guards nothing, and the
 * seven server-owned properties were reachable from the body.
 *
 * They only ever reached the database through one door: `initializeTrip()` persisted the
 * deserialized object outright on a new trip, where `storeRequest()` has always gone through an
 * explicit list of modifiable fields. That door is now closed with the same list.
 */
final class TripCreationServerOwnedStateTest extends ApiTestCase
{
    use JwtAuthTestTrait;

    #[\Override]
    protected static ?bool $alwaysBootKernel = false;

    private const string FIXTURES_DIR = __DIR__.'/../fixtures';

    private string $jwtToken;

    #[\Override]
    protected function setUp(): void
    {
        ['token' => $this->jwtToken] = $this->createTestUserWithJwt(\sprintf('owned-%s@test.com', bin2hex(random_bytes(8))));
    }

    /**
     * Each field below buys something concrete, which is why this is not a matter of tidiness:
     *
     *  - `status: ready` makes a trip announce itself structurally computed before any pacing
     *    has run (ADR-043) — `/trips/{id}/detail` and the MCP digest both read it to decide
     *    whether the day list they are serving is complete;
     *  - `version` seeds the optimistic-concurrency counter, so a creation could choose where
     *    every later `If-Match` starts counting from;
     *  - `createdAt` decides where the trip sorts in its owner's list (`idx_trip_user_created_at`);
     *  - `outOfZone` flips the display-only flag that says the route left the provisioned area;
     *  - `sourceType` and `computationStatus` are written by the pipeline as it runs.
     */
    #[Test]
    public function aCreationCannotSeedTheStateTheServerOwns(): void
    {
        $response = self::createClient()->request('POST', '/trips', [
            'headers' => array_merge([
                'Content-Type' => 'application/ld+json',
                'Idempotency-Key' => 'creation-cannot-seed-server-state-01',
            ], $this->authHeader($this->jwtToken)),
            'json' => [
                'sourceUrl' => 'https://www.komoot.com/tour/123456789',
                'status' => 'ready',
                'version' => 4242,
                'createdAt' => '1999-01-01T00:00:00+00:00',
                'outOfZone' => true,
                'sourceType' => 'forged',
                'computationStatus' => ['route' => 'done', 'stages' => 'done'],
            ],
        ]);

        $this->assertResponseStatusCodeSame(202);

        $tripId = $response->toArray(false)['id'];
        $this->assertIsString($tripId);

        $trip = $this->storedTrip($tripId);

        $this->assertSame('draft', $trip->status, 'A creation announced its own trip structurally ready.');
        $this->assertSame(1, $trip->version, 'A creation chose where the concurrency counter starts.');
        $this->assertFalse($trip->outOfZone, 'A creation set the out-of-coverage flag.');
        $this->assertNull($trip->sourceType, 'A creation set the source type the fetcher owns.');
        $this->assertNotSame('done', $trip->computationStatus['route'] ?? null, 'A creation declared its own computations finished.');
        $this->assertGreaterThan(
            new \DateTimeImmutable('-1 hour'),
            $trip->createdAt,
            'A creation backdated itself, which decides where it sorts in the owner list.',
        );
    }

    /** The settings that *are* the caller's still land. Without this the fix reads as green while writing nothing. */
    #[Test]
    public function aCreationStillDecidesItsOwnSettings(): void
    {
        $response = self::createClient()->request('POST', '/trips', [
            'headers' => array_merge([
                'Content-Type' => 'application/ld+json',
                'Idempotency-Key' => 'creation-still-decides-settings-01',
            ], $this->authHeader($this->jwtToken)),
            'json' => [
                'sourceUrl' => 'https://www.komoot.com/tour/123456789',
                'title' => 'Traversée du Vercors',
                // Full RFC 3339: symfony/serializer 8.1 deprecates the loose parser, and a
                // date-only string goes through it.
                'startDate' => '2026-07-01T00:00:00+00:00',
                'fatigueFactor' => 0.85,
                'elevationPenalty' => 40.0,
                'ebikeMode' => true,
                'departureHour' => 6,
                'maxDistancePerDay' => 95.0,
                'averageSpeed' => 18.0,
                'enabledAccommodationTypes' => ['hotel', 'camp_site'],
            ],
        ]);

        $this->assertResponseStatusCodeSame(202);

        $tripId = $response->toArray(false)['id'];
        $this->assertIsString($tripId);

        $trip = $this->storedTrip($tripId);

        $this->assertSame('https://www.komoot.com/tour/123456789', $trip->sourceUrl);
        $this->assertSame('Traversée du Vercors', $trip->title);
        $this->assertSame('2026-07-01', $trip->startDate?->format('Y-m-d'));
        $this->assertSame(0.85, $trip->fatigueFactor);
        $this->assertSame(40.0, $trip->elevationPenalty);
        $this->assertTrue($trip->ebikeMode);
        $this->assertSame(6, $trip->departureHour);
        $this->assertSame(95.0, $trip->maxDistancePerDay);
        $this->assertSame(18.0, $trip->averageSpeed);
        $this->assertSame(['hotel', 'camp_site'], $trip->enabledAccommodationTypes);
        $this->assertNotNull($trip->user, 'The owner is the one thing besides the settings that a creation carries.');
    }

    /**
     * The other caller of the same method.
     *
     * {@see \App\Controller\GpxUploadController} never had the hole — it builds its own
     * `TripRequest` and copies a bounded list of form fields into it, so nothing from the body
     * ever reached a deserializer. It goes through `initializeTrip()` all the same, and the list
     * it relies on is the one that now decides what gets persisted.
     */
    #[Test]
    public function aGpxUploadStillPersistsTheSettingsItWasGiven(): void
    {
        $file = new UploadedFile(
            self::FIXTURES_DIR.'/valid-route.gpx',
            'valid-route.gpx',
            'application/gpx+xml',
            null,
            true,
        );

        $response = self::createClient()->request('POST', '/trips/gpx-upload', [
            'headers' => array_merge(['Content-Type' => 'multipart/form-data'], $this->authHeader($this->jwtToken)),
            'extra' => [
                'files' => ['gpxFile' => $file],
                'parameters' => [
                    'startDate' => '2026-07-01',
                    'fatigueFactor' => '0.85',
                    'elevationPenalty' => '40',
                    'ebikeMode' => 'true',
                ],
            ],
        ]);

        $this->assertResponseStatusCodeSame(202);

        $tripId = $response->toArray(false)['id'];
        $this->assertIsString($tripId);

        $trip = $this->storedTrip($tripId);

        $this->assertSame('2026-07-01', $trip->startDate?->format('Y-m-d'));
        $this->assertSame(0.85, $trip->fatigueFactor);
        $this->assertSame(40.0, $trip->elevationPenalty);
        $this->assertTrue($trip->ebikeMode);
        $this->assertNotNull($trip->user, 'The GPX upload assigns the uploader as owner (recette #649).');
    }

    /**
     * Read the row, not the identity map: the object the request persisted is still managed, so
     * asserting against it would pass whatever was written to the database.
     */
    private function storedTrip(string $tripId): TripRequest
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        $em->clear();

        /** @var TripRequestRepositoryInterface $repo */
        $repo = self::getContainer()->get(TripRequestRepositoryInterface::class);

        $trip = $repo->getRequest($tripId);
        $this->assertInstanceOf(TripRequest::class, $trip);

        return $trip;
    }
}
