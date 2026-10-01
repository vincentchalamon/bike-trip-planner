<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Enum\ComputationName;
use App\Message\AnalyzeTerrain;
use App\Message\FetchAndParseRoute;
use App\Repository\TripRequestRepositoryInterface;
use App\Repository\TripStageStoreInterface;
use App\Tests\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The state a creation leaves behind, for both doors: a source URL (`POST /trips`, the route
 * fetched by a worker) and a GPX file (`POST /trips/gpx-upload`, paced in the request). Both
 * go through the same bootstrap, so they must agree on owner, locale and the tracked
 * computations; they differ only in how far the structural pipeline got before answering.
 */
final class TripCreationStateTest extends ApiTestCase
{
    use JwtAuthTestTrait;

    #[\Override]
    protected static ?bool $alwaysBootKernel = false;

    #[Test]
    public function aTripCreatedFromAUrlWaitsForItsRoute(): void
    {
        $client = self::createClient();
        ['token' => $token, 'ownerId' => $ownerId] = $this->frenchOwner();

        $response = $client->request('POST', '/trips', [
            'headers' => array_merge(['Content-Type' => 'application/ld+json', 'Idempotency-Key' => 'creation-state-url-001'], $this->authHeader($token)),
            'json' => ['sourceUrl' => 'https://www.komoot.com/tour/123456789'],
        ]);

        $this->assertResponseStatusCodeSame(202);
        $tripId = $response->toArray(false)['id'];
        self::assertIsString($tripId);

        $trips = $this->service(TripRequestRepositoryInterface::class);
        self::assertSame($ownerId, $trips->getOwnerId($tripId));
        self::assertSame('fr', $trips->getLocale($tripId));
        self::assertSame('draft', $trips->getRequest($tripId)?->status);
        self::assertNull($trips->getSourceType($tripId));
        self::assertNull($trips->getTitle($tripId));
        self::assertSame(1, $this->service(TripGenerationTrackerInterface::class)->current($tripId));
        self::assertSame([], $this->service(TripStageStoreInterface::class)->getStages($tripId) ?? []);
        self::assertSame(
            array_fill_keys(array_map(static fn (ComputationName $c): string => $c->value, ComputationName::pipeline()), 'pending'),
            $this->service(ComputationTrackerInterface::class)->getStatuses($tripId),
        );

        $sent = $this->sent();
        self::assertCount(1, $sent);
        self::assertInstanceOf(FetchAndParseRoute::class, $sent[0]);
        self::assertSame($tripId, $sent[0]->tripId);
        self::assertSame(1, $sent[0]->generation);
    }

    #[Test]
    public function aTripCreatedFromAGpxFileIsStructurallyReady(): void
    {
        $client = self::createClient();
        ['token' => $token, 'ownerId' => $ownerId] = $this->frenchOwner();

        $response = $client->request('POST', '/trips/gpx-upload', [
            'headers' => array_merge(['Content-Type' => 'multipart/form-data'], $this->authHeader($token)),
            'extra' => ['files' => ['gpxFile' => new UploadedFile(__DIR__.'/../fixtures/multi-stage-route.gpx', 'multi-stage-route.gpx', 'application/gpx+xml', null, true)]],
        ]);

        $this->assertResponseStatusCodeSame(202);
        $data = $response->toArray(false);
        $tripId = $data['id'];
        self::assertIsString($tripId);

        $trips = $this->service(TripRequestRepositoryInterface::class);
        self::assertSame($ownerId, $trips->getOwnerId($tripId));
        self::assertSame('fr', $trips->getLocale($tripId));
        self::assertSame('ready', $trips->getRequest($tripId)?->status);
        self::assertSame('gpx_upload', $trips->getSourceType($tripId));
        self::assertSame('Multi Stage Route', $trips->getTitle($tripId));
        // Created at version 1, then bumped once by the stages written in the request.
        self::assertSame(2, $this->service(TripGenerationTrackerInterface::class)->current($tripId));
        self::assertCount(\count($data['stages']), $this->service(TripStageStoreInterface::class)->getStages($tripId) ?? []);

        $expected = array_fill_keys(array_map(static fn (ComputationName $c): string => $c->value, ComputationName::pipeline()), 'pending');
        $expected['route'] = 'done';
        $expected['stages'] = 'done';
        self::assertSame($expected, $this->service(ComputationTrackerInterface::class)->getStatuses($tripId));

        $sent = $this->sent();
        self::assertNotContains(FetchAndParseRoute::class, array_map(static fn (object $m): string => $m::class, $sent));
        $terrain = array_values(array_filter($sent, static fn (object $m): bool => $m instanceof AnalyzeTerrain));
        self::assertCount(1, $terrain);
        // Stamped with the version the stage write left, read after it (ADR-073).
        self::assertSame(2, $terrain[0]->generation);
    }

    /**
     * @return array{token: string, ownerId: string}
     */
    private function frenchOwner(): array
    {
        ['user' => $user, 'jwt' => $token] = $this->createAuthenticatedUser(\sprintf('creation-state-%s@test.com', bin2hex(random_bytes(6))));
        $user->setLocale('fr');
        $this->service(EntityManagerInterface::class)->flush();

        return ['token' => $token, 'ownerId' => $user->getId()->toRfc4122()];
    }

    /**
     * @return list<object>
     */
    private function sent(): array
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return array_map(static fn (Envelope $envelope): object => $envelope->getMessage(), array_values([...$transport->getSent()]));
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    private function service(string $id): object
    {
        $service = self::getContainer()->get($id);
        self::assertInstanceOf($id, $service);

        return $service;
    }
}
