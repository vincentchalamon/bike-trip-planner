<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Controller\GpxUploadController;
use App\Enum\ComputationName;
use App\Message\AnalyzeTerrain;
use App\Message\FetchWeather;
use App\Message\GenerateStages;
use App\Message\ScanPois;
use PHPUnit\Framework\Attributes\Test;
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
    public function uploadedTripIsViewableByItsUploader(): void
    {
        // Regression (recette #649): a GPX upload used to create an *ownerless*
        // trip, so the uploader's own GET /detail was denied and hidden as a 404
        // ("Voyage introuvable" right after a successful upload). createTrip now
        // assigns the owner (TripRequest.user + Redis ownership key) like the URL
        // flow, so the uploader can load their trip immediately.
        $file = new UploadedFile(
            self::FIXTURES_DIR.'/multi-stage-route.gpx',
            'multi-stage-route.gpx',
            'application/gpx+xml',
            null,
            true,
        );

        $upload = $this->client->request('POST', '/trips/gpx-upload', [
            'headers' => array_merge(['Content-Type' => 'multipart/form-data'], $this->authHeader($this->jwtToken)),
            'extra' => [
                'files' => ['gpxFile' => $file],
            ],
        ]);
        $this->assertResponseStatusCodeSame(202);

        $tripId = $upload->toArray(false)['id'];
        self::assertIsString($tripId);
        self::assertNotEmpty($tripId);

        // Same kernel + same JWT: the owner must load their own trip (200, not 404).
        $this->client->request('GET', \sprintf('/trips/%s/detail', $tripId), [
            'headers' => array_merge(['Accept' => 'application/ld+json'], $this->authHeader($this->jwtToken)),
        ]);
        $this->assertResponseStatusCodeSame(200);
    }
}
