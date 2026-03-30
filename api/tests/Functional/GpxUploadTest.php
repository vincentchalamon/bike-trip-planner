<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Enum\ComputationName;
use App\Message\GenerateStages;
use App\Message\ScanAllOsmData;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\File\UploadedFile;
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

        // Other computations should be pending
        foreach (ComputationName::pipeline() as $computation) {
            if (ComputationName::ROUTE === $computation) {
                continue;
            }

            $this->assertArrayHasKey($computation->value, $data['computationStatus']);
            $this->assertSame('pending', $data['computationStatus'][$computation->value]);
        }
    }

    #[Test]
    public function uploadDispatchesDownstreamMessages(): void
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
        $sentMessages = $transport->getSent();

        $this->assertCount(2, $sentMessages);
        $this->assertInstanceOf(GenerateStages::class, $sentMessages[0]->getMessage());
        $this->assertInstanceOf(ScanAllOsmData::class, $sentMessages[1]->getMessage());
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
}
