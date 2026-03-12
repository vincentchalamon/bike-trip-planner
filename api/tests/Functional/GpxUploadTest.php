<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Enum\ComputationName;
use App\Message\GenerateStages;
use App\Message\ScanAllOsmData;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class GpxUploadTest extends ApiTestCase
{
    private const string FIXTURES_DIR = __DIR__.'/../fixtures';

    #[\Override]
    public static function setUpBeforeClass(): void
    {
        self::$alwaysBootKernel = false;
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

        $client = self::createClient();
        $response = $client->request('POST', '/trips/gpx-upload', [
            'headers' => ['Content-Type' => 'multipart/form-data'],
            'extra' => [
                'files' => ['gpxFile' => $file],
            ],
        ]);

        $this->assertResponseStatusCodeSame(202);

        $data = $response->toArray(false);
        $this->assertNotEmpty($data['id']);
        $this->assertSame('Trip', $data['@type']);
        $this->assertSame('/contexts/Trip', $data['@context']);

        // Route computation should already be done (parsed synchronously)
        $this->assertSame('done', $data['computationStatus']['route']);

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

        $client = self::createClient();
        $client->request('POST', '/trips/gpx-upload', [
            'headers' => ['Content-Type' => 'multipart/form-data'],
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
        $client = self::createClient();
        $client->request('POST', '/trips/gpx-upload', [
            'headers' => ['Content-Type' => 'multipart/form-data'],
        ]);

        $this->assertResponseStatusCodeSame(400);
    }

    #[Test]
    public function rejectsNonGpxExtension(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'test');
        \assert(false !== $tempFile);
        file_put_contents($tempFile, '<xml>not a gpx</xml>');

        $file = new UploadedFile(
            $tempFile,
            'route.kml',
            'application/xml',
            null,
            true,
        );

        $client = self::createClient();
        $client->request('POST', '/trips/gpx-upload', [
            'headers' => ['Content-Type' => 'multipart/form-data'],
            'extra' => [
                'files' => ['gpxFile' => $file],
            ],
        ]);

        $this->assertResponseStatusCodeSame(400);
        unlink($tempFile);
    }

    #[Test]
    public function rejectsEmptyGpxFile(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'test');
        \assert(false !== $tempFile);
        file_put_contents($tempFile, '<?xml version="1.0"?><gpx version="1.1"><trk><trkseg></trkseg></trk></gpx>');

        $file = new UploadedFile(
            $tempFile,
            'empty.gpx',
            'application/gpx+xml',
            null,
            true,
        );

        $client = self::createClient();
        $client->request('POST', '/trips/gpx-upload', [
            'headers' => ['Content-Type' => 'multipart/form-data'],
            'extra' => [
                'files' => ['gpxFile' => $file],
            ],
        ]);

        $this->assertResponseStatusCodeSame(422);
        unlink($tempFile);
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

        $client = self::createClient();
        $response = $client->request('POST', '/trips/gpx-upload', [
            'headers' => ['Content-Type' => 'multipart/form-data'],
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
