<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\ApiResource\TripRequest;
use App\Controller\GpxUploadController;
use App\Enum\ComputationName;
use App\Message\AnalyzeTerrain;
use App\Message\FetchWeather;
use App\Message\GenerateStages;
use App\Message\ScanPois;
use App\Service\GpxUploadService;
use PHPUnit\Framework\Attributes\Test;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class GpxUploadTest extends ApiTestCase
{
    use JwtAuthTestTrait;

    private const string FIXTURES_DIR = __DIR__.'/../fixtures';

    private Client $client;

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
        ['token' => $this->jwtToken] = $this->createTestUserWithJwt(\sprintf('gpx-%s@test.com', bin2hex(random_bytes(4))));
    }

    #[Test]
    public function uploadValidGpxReturns202(): void
    {
        $file = new UploadedFile(
            self::FIXTURES_DIR.'/valid-route.gpx',
            'valid-route.gpx',
            'application/gpx+xml',
            null,
            true,
        );

        $response = $this->client->request('POST', '/trips/gpx-upload', [
            'headers' => array_merge(['Content-Type' => 'multipart/form-data'], $this->authHeader($this->jwtToken)),
            'extra' => [
                'files' => ['gpxFile' => $file],
            ],
        ]);

        $this->assertResponseStatusCodeSame(202);

        $data = $response->toArray(false);
        $this->assertNotEmpty($data['id']);
        $this->assertSame('Trip', $data['@type']);
        $this->assertSame('/contexts/Trip', $data['@context']);

        // Title should be extracted from GPX metadata
        $this->assertSame('Test Route', $data['title']);

        // Route computation should already be done (parsed synchronously)
        $this->assertSame('done', $data['computationStatus']['route']);

        // Route metrics should be included in the response
        $this->assertArrayHasKey('totalDistance', $data);
        $this->assertIsFloat($data['totalDistance']);
        $this->assertGreaterThan(0, $data['totalDistance']);

        $this->assertArrayHasKey('totalElevation', $data);
        $this->assertIsInt($data['totalElevation']);

        $this->assertArrayHasKey('totalElevationLoss', $data);
        $this->assertIsInt($data['totalElevationLoss']);

        // ADR-043: stages are computed synchronously, so the structural pipeline
        // (route + stages) is already done and the response carries status + stages.
        $this->assertSame('done', $data['computationStatus']['stages']);
        $this->assertArrayHasKey('status', $data);
        $this->assertContains($data['status'], ['draft', 'ready']);
        $this->assertArrayHasKey('stages', $data);
        $this->assertIsArray($data['stages']);

        // Enrichment computations (everything but the structural pipeline) stay pending.
        $structural = ComputationName::structuralPipeline();
        foreach (ComputationName::pipeline() as $computation) {
            if (\in_array($computation, $structural, true)) {
                continue;
            }

            $this->assertArrayHasKey($computation->value, $data['computationStatus']);
            $this->assertSame('pending', $data['computationStatus'][$computation->value]);
        }
    }

    #[Test]
    public function uploadLongRouteIsReadyWithStages(): void
    {
        // A ~100km route splits into >= 2 stages (30km minimum per stage), so the
        // synchronously computed trip reaches the structural `ready` status.
        $file = new UploadedFile(
            self::FIXTURES_DIR.'/multi-stage-route.gpx',
            'multi-stage-route.gpx',
            'application/gpx+xml',
            null,
            true,
        );

        $response = $this->client->request('POST', '/trips/gpx-upload', [
            'headers' => array_merge(['Content-Type' => 'multipart/form-data'], $this->authHeader($this->jwtToken)),
            'extra' => [
                'files' => ['gpxFile' => $file],
            ],
        ]);

        $this->assertResponseStatusCodeSame(202);

        $data = $response->toArray(false);
        $this->assertSame('ready', $data['status']);
        $this->assertGreaterThanOrEqual(2, \count($data['stages']));
        $this->assertSame('done', $data['computationStatus']['stages']);

        $firstStage = $data['stages'][0];
        $this->assertArrayHasKey('dayNumber', $firstStage);
        $this->assertArrayHasKey('distance', $firstStage);
        $this->assertArrayHasKey('geometry', $firstStage);
    }

    #[Test]
    public function uploadDispatchesEnrichmentMessagesNotStageGeneration(): void
    {
        $file = new UploadedFile(
            self::FIXTURES_DIR.'/valid-route.gpx',
            'valid-route.gpx',
            'application/gpx+xml',
            null,
            true,
        );

        $this->client->request('POST', '/trips/gpx-upload', [
            'headers' => array_merge(['Content-Type' => 'multipart/form-data'], $this->authHeader($this->jwtToken)),
            'extra' => [
                'files' => ['gpxFile' => $file],
            ],
        ]);

        $this->assertResponseStatusCodeSame(202);

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        $dispatched = array_map(
            static fn (Envelope $envelope): string => $envelope->getMessage()::class,
            $transport->getSent(),
        );

        // ADR-043: stage generation now runs synchronously, so GenerateStages is no
        // longer dispatched; instead the async enrichment fan-out is dispatched directly.
        $this->assertNotContains(GenerateStages::class, $dispatched);
        $this->assertContains(ScanPois::class, $dispatched);
        $this->assertContains(FetchWeather::class, $dispatched);
        $this->assertContains(AnalyzeTerrain::class, $dispatched);
    }

    #[Test]
    public function rejectsMissingFile(): void
    {
        $this->client->request('POST', '/trips/gpx-upload', [
            'headers' => array_merge(['Content-Type' => 'multipart/form-data'], $this->authHeader($this->jwtToken)),
        ]);

        $this->assertResponseStatusCodeSame(400);
    }

    #[Test]
    public function rejectsNonGpxExtension(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'test');
        \assert(false !== $tempFile);
        file_put_contents($tempFile, '<xml>not a gpx</xml>');

        try {
            $file = new UploadedFile(
                $tempFile,
                'route.kml',
                'application/xml',
                null,
                true,
            );

            $this->client->request('POST', '/trips/gpx-upload', [
                'headers' => array_merge(['Content-Type' => 'multipart/form-data'], $this->authHeader($this->jwtToken)),
                'extra' => [
                    'files' => ['gpxFile' => $file],
                ],
            ]);

            $this->assertResponseStatusCodeSame(400);
        } finally {
            unlink($tempFile);
        }
    }

    #[Test]
    public function rejectsEmptyGpxFile(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'test');
        \assert(false !== $tempFile);
        file_put_contents($tempFile, '<?xml version="1.0"?><gpx version="1.1"><trk><trkseg></trkseg></trk></gpx>');

        try {
            $file = new UploadedFile(
                $tempFile,
                'empty.gpx',
                'application/gpx+xml',
                null,
                true,
            );

            $this->client->request('POST', '/trips/gpx-upload', [
                'headers' => array_merge(['Content-Type' => 'multipart/form-data'], $this->authHeader($this->jwtToken)),
                'extra' => [
                    'files' => ['gpxFile' => $file],
                ],
            ]);

            $this->assertResponseStatusCodeSame(422);
        } finally {
            unlink($tempFile);
        }
    }

    #[Test]
    public function rejectsDisallowedMimeType(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'test');
        \assert(false !== $tempFile);
        // Write JPEG magic bytes so finfo detects image/jpeg
        file_put_contents($tempFile, "\xFF\xD8\xFF".str_repeat('x', 100));

        try {
            $file = new UploadedFile(
                $tempFile,
                'malicious.gpx',
                'image/jpeg',
                null,
                true,
            );

            $this->client->request('POST', '/trips/gpx-upload', [
                'headers' => array_merge(['Content-Type' => 'multipart/form-data'], $this->authHeader($this->jwtToken)),
                'extra' => ['files' => ['gpxFile' => $file]],
            ]);

            $this->assertResponseStatusCodeSame(400);
        } finally {
            unlink($tempFile);
        }
    }

    /**
     * Tests the application-level MAX_FILE_SIZE guard by calling the controller
     * directly — bypasses BrowserKit, which reconstructs UploadedFile objects
     * and discards mock overrides.
     */
    #[Test]
    public function rejectsOversizedFile(): void
    {
        $file = $this->createStub(UploadedFile::class);
        $file->method('isValid')->willReturn(true);
        $file->method('getSize')->willReturn(31 * 1024 * 1024);

        $request = new Request([], [], [], [], ['gpxFile' => $file]);

        /** @var GpxUploadController $controller */
        $controller = self::getContainer()->get(GpxUploadController::class);
        $response = $controller($request);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(
            ['error' => 'File exceeds maximum size of 30 MB.'],
            json_decode((string) $response->getContent(), true),
        );
    }

    #[Test]
    public function applyOptionalParametersIgnoresInvalidValues(): void
    {
        $file = new UploadedFile(
            self::FIXTURES_DIR.'/valid-route.gpx',
            'valid-route.gpx',
            'application/gpx+xml',
            null,
            true,
        );

        $this->client->request('POST', '/trips/gpx-upload', [
            'headers' => array_merge(['Content-Type' => 'multipart/form-data'], $this->authHeader($this->jwtToken)),
            'extra' => [
                'files' => ['gpxFile' => $file],
                'parameters' => [
                    'fatigueFactor' => 'not-a-number',
                    'ebikeMode' => 'notaboolean',
                ],
            ],
        ]);

        // Invalid values must be silently ignored — endpoint must still return 202
        $this->assertResponseStatusCodeSame(202);
    }

    #[Test]
    public function uploadWithZeroElevationPenaltyReturns202NotServerError(): void
    {
        // BUG-002 regression: elevationPenalty=0 is numeric but out of range. It must
        // be clamped (ignored), never reach the pacing engine as a DivisionByZeroError
        // (which surfaced as an unhandled HTTP 500 before the fix).
        $file = new UploadedFile(
            self::FIXTURES_DIR.'/valid-route.gpx',
            'valid-route.gpx',
            'application/gpx+xml',
            null,
            true,
        );

        $this->client->request('POST', '/trips/gpx-upload', [
            'headers' => array_merge(['Content-Type' => 'multipart/form-data'], $this->authHeader($this->jwtToken)),
            'extra' => [
                'files' => ['gpxFile' => $file],
                'parameters' => ['elevationPenalty' => '0', 'fatigueFactor' => '0.3'],
            ],
        ]);

        $this->assertResponseStatusCodeSame(202);
    }

    // Note: a functional "11 uploads → 429" test is intentionally omitted. The
    // test cache pool is cache.adapter.array, which implements ResetInterface and
    // is cleared by Symfony's service resetter after every request (independently
    // of disableReboot()), so a real sliding-window limiter never accumulates
    // across HTTP requests here. This is why the existing 429 tests in the suite
    // mock a provider 429 rather than exhausting a real limiter. The limiter
    // wiring itself (limiter.gpx_upload consumed before createTrip) is covered by
    // the controller change.

    #[Test]
    public function uploadWithOptionalParameters(): void
    {
        $file = new UploadedFile(
            self::FIXTURES_DIR.'/valid-route.gpx',
            'valid-route.gpx',
            'application/gpx+xml',
            null,
            true,
        );

        $response = $this->client->request('POST', '/trips/gpx-upload', [
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

        $data = $response->toArray(false);
        $this->assertNotEmpty($data['id']);
        $this->assertSame('Trip', $data['@type']);
    }

    #[Test]
    public function unauthenticatedRequestReturns401(): void
    {
        $this->client->request('POST', '/trips/gpx-upload', [
            'headers' => ['Content-Type' => 'multipart/form-data'],
        ]);

        $this->assertResponseStatusCodeSame(401);
    }

    #[Test]
    public function uploadAssignsOwnershipToUploader(): void
    {
        // Regression (recette #649): a GPX upload used to create an *ownerless*
        // trip, so the uploader's GET /detail was denied by TripVoter and hidden
        // as a 404 ("Voyage introuvable" right after a successful upload). The
        // uploader is now assigned as owner like the URL flow: TripRequest.user is
        // set and the trip.{id}.user_id key is written (the Postgres column + the
        // Redis fallback the voter reads). Asserted at the service layer: right
        // after upload the trip lives in the Redis-backed state (the voter's Redis
        // fallback), not yet in Postgres.
        ['user' => $user] = $this->createTestUserWithJwt(\sprintf('gpx-owner-%s@test.com', bin2hex(random_bytes(4))));

        $service = self::getContainer()->get(GpxUploadService::class);
        self::assertInstanceOf(GpxUploadService::class, $service);

        $points = $service->parseGpx((string) file_get_contents(self::FIXTURES_DIR.'/multi-stage-route.gpx'));
        $tripRequest = new TripRequest();
        $result = $service->createTrip($points, 'Test Route', $tripRequest, 'en', $user);

        // Postgres ownership column.
        self::assertSame($user, $tripRequest->user);

        // Redis ownership key (the voter's fallback for not-yet-persisted trips).
        $pool = self::getContainer()->get('cache.trip_state');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
        $item = $pool->getItem(\sprintf('trip.%s.user_id', $result['tripId']));
        self::assertTrue($item->isHit());
        self::assertSame($user->getId()->toRfc4122(), $item->get());
    }
}
