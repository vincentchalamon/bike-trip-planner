<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\ApiResource\TripRequest;
use App\Concurrency\IfMatch;
use App\Entity\User;
use App\Enum\SourceType;
use App\Repository\TripRequestRepositoryInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The `If-Match` precondition on structural edits: what it refuses, what it lets through, and
 * what it must not reveal.
 *
 * Unlike the other editing suites, which send `*`, this one pins real versions — it is the
 * only place the version arithmetic itself is under test.
 */
#[ResetDatabase]
final class TripPreconditionTest extends ApiTestCase
{
    use AddressesStagesByIdTrait;
    use Factories;
    use JwtAuthTestTrait;

    private const string TRIP_ID = '01936f6e-0000-7000-8000-0000000000f1';

    private Client $client;

    private User $owner;

    private string $ownerToken;

    private string $intruderToken;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        ['user' => $this->owner, 'token' => $this->ownerToken] = $this->createTestUserWithJwt('owner@example.com');
        // Created here rather than mid-test: the seeded trip lives in the test repository's
        // in-memory store, which a later kernel boot would drop.
        ['token' => $this->intruderToken] = $this->createTestUserWithJwt('intruder@example.com');
    }

    #[Test]
    public function detailServesTheVersionAsAnEtagAndForbidsCaching(): void
    {
        $this->seedTrip();

        $response = $this->client->request('GET', \sprintf('/trips/%s/detail', self::TRIP_ID), $this->asOwner());

        self::assertResponseIsSuccessful();
        // A quoted integer, not `W/"…"`: If-Match mandates the strong comparison function,
        // under which a weak validator never matches. The exact value is not asserted here —
        // in the test environment /detail reads Postgres while the precondition compares the
        // in-memory repository, two stores that only coincide in production.
        self::assertMatchesRegularExpression('/^"\d+"$/', $response->getHeaders()['etag'][0] ?? '');
        // The version tracks the structure, not the bytes: an enrichment rewrites the body
        // without moving it, so nothing may treat this tag as a cache validator.
        self::assertStringContainsString('no-store', $response->getHeaders()['cache-control'][0] ?? '');
    }

    #[Test]
    public function aMissingPreconditionIsRefusedWith428(): void
    {
        $this->seedTrip();

        $this->client->request('DELETE', $this->stageUrl(1), $this->asOwner());

        self::assertResponseStatusCodeSame(428);
    }

    #[Test]
    public function aMalformedPreconditionIsRefusedWith400(): void
    {
        $this->seedTrip();

        $this->client->request('DELETE', $this->stageUrl(1), $this->asOwner(['If-Match' => 'seven']));

        self::assertResponseStatusCodeSame(400);
    }

    #[Test]
    public function aStalePreconditionIsRefusedWith412AndChangesNothing(): void
    {
        $this->seedTrip();
        $before = $this->currentVersion();

        $this->client->request('DELETE', $this->stageUrl(1), $this->asOwner(['If-Match' => \sprintf('"%d"', $before - 1)]));

        self::assertResponseStatusCodeSame(412);
        self::assertCount(4, $this->stages(), 'A refused edit must not have deleted anything.');
        self::assertSame($before, $this->currentVersion(), 'A refused edit must not have moved the version.');
    }

    #[Test]
    public function theCurrentVersionIsAcceptedAndTheResponseCarriesTheNextOne(): void
    {
        $this->seedTrip();
        $before = $this->currentVersion();

        $response = $this->client->request('DELETE', $this->stageUrl(1), $this->asOwner(['If-Match' => \sprintf('"%d"', $before)]));

        self::assertResponseStatusCodeSame(202);
        self::assertCount(3, $this->stages());
        // The tag comes back fresh, so a client chaining edits never has to re-read the trip
        // between them — which is what the batch queue does.
        self::assertSame(\sprintf('"%d"', $this->currentVersion()), $response->getHeaders(false)['etag'][0] ?? null);
        self::assertGreaterThan($before, $this->currentVersion());
    }

    #[Test]
    public function aVersionThatWasValidOnceIsRefusedAfterTheTripMovesOn(): void
    {
        $this->seedTrip();
        $stale = $this->currentVersion();
        $url = $this->stageUrl(1);

        // Someone else's write lands in between — a worker regenerating the pacing moves the
        // version with no HTTP response to tell this client about it. Driven through the
        // repository rather than a second request: the in-memory store the test environment
        // uses does not survive the kernel reboot between two client calls.
        $this->repository()->bumpVersion(self::TRIP_ID);

        $this->client->request('DELETE', $url, $this->asOwner(['If-Match' => \sprintf('"%d"', $stale)]));

        self::assertResponseStatusCodeSame(412);
        self::assertCount(4, $this->stages(), 'The refused edit must not have applied.');
    }

    /**
     * A refused settings edit must leave the settings alone.
     *
     * `PATCH /trips/{id}` had no precondition coverage at all, which is how it shipped
     * persisting the merged request *before* the authoritative comparison (#1292 review).
     *
     * What this pins is the contract, not the ordering: the version is already stale when the
     * request arrives, so the refusal comes from the fail-fast check and the processor body
     * never runs. The ordering itself is pinned where it can be — in
     * {@see \App\Tests\Unit\State\TripUpdateProcessorTest::resolvesTheChangeBeforeTheWriteAliasesTheOldRequest}.
     */
    #[Test]
    public function aStaleSettingsEditIsRefusedWithoutPersistingAnything(): void
    {
        $this->seedTrip();
        $stale = $this->currentVersion();
        $before = $this->repository()->getRequest(self::TRIP_ID)?->fatigueFactor;
        $this->repository()->bumpVersion(self::TRIP_ID);

        $this->client->request('PATCH', \sprintf('/trips/%s', self::TRIP_ID), $this->asOwner([
            'If-Match' => \sprintf('"%d"', $stale),
            'Content-Type' => 'application/merge-patch+json',
        ]) + ['json' => ['fatigueFactor' => 0.55]]);

        self::assertResponseStatusCodeSame(412);
        self::assertSame($before, $this->repository()->getRequest(self::TRIP_ID)?->fatigueFactor);
    }

    /**
     * `POST /trips/{id}/recompute` is the other operation that moves the version through
     * `increment()` rather than through a stage write, and it had no precondition coverage.
     *
     * Its ordering is already right — the bump precedes every dispatch — but nothing pinned
     * it: the processor's unit test stubs `increment()` to return a fixed value whatever its
     * arguments, so dropping the precondition would have failed nothing (#1292 review).
     */
    #[Test]
    public function aStaleBatchRecomputeIsRefusedWithoutDispatchingAnything(): void
    {
        $this->seedTrip();
        $stale = $this->currentVersion();
        $stageId = $this->stageIdAt(self::TRIP_ID, 0);
        $this->repository()->bumpVersion(self::TRIP_ID);

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        $transport->reset();

        $this->client->request('POST', \sprintf('/trips/%s/recompute', self::TRIP_ID), $this->asOwner([
            'If-Match' => \sprintf('"%d"', $stale),
            'Content-Type' => 'application/ld+json',
        ]) + ['json' => ['modifications' => [[
            'stageId' => $stageId,
            'type' => 'distance',
            'label' => 'Day 1 shortened',
        ]]]]);

        self::assertResponseStatusCodeSame(412);
        self::assertSame([], $transport->getSent(), 'A refused batch must not have re-dispatched anything.');
    }

    /**
     * The precondition runs past the provider chain, so it cannot answer before authorization
     * has. If it ran earlier — in a `kernel.request` listener, say — the 412/428 pair would
     * tell a stranger whether a given version of someone else's trip exists.
     */
    #[Test]
    public function anIntruderGetsTheSameAnswerWhateverVersionTheySend(): void
    {
        $this->seedTrip();
        $current = $this->currentVersion();

        // Resolved once: the in-memory store of the test environment does not survive the
        // kernel reboot between two client calls, and a stranger's answer must not depend on
        // the identifier being live anyway.
        $url = $this->stageUrl(1);

        foreach ([null, '*', \sprintf('"%d"', $current), \sprintf('"%d"', $current + 99)] as $header) {
            $options = ['headers' => ['Authorization' => \sprintf('Bearer %s', $this->intruderToken)]];
            if (null !== $header) {
                $options['headers'][IfMatch::HEADER] = $header;
            }

            $this->client->request('DELETE', $url, $options);

            // 404, per ADR-038: an object-level denial is masked as not-found. The point is
            // that every one of these is identical — a stranger learns nothing about the
            // trip's version, which a precondition checked before authorization would leak.
            self::assertResponseStatusCodeSame(404, \sprintf('If-Match: %s leaked a different answer.', $header ?? '(absent)'));
        }
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{headers: array<string, string>}
     */
    private function asOwner(array $headers = []): array
    {
        return ['headers' => ['Authorization' => \sprintf('Bearer %s', $this->ownerToken)] + $headers];
    }

    private function stageUrl(int $position): string
    {
        return \sprintf('/trips/%s/stages/%s', self::TRIP_ID, $this->stageIdAt(self::TRIP_ID, $position));
    }

    private function currentVersion(): int
    {
        return $this->repository()->getVersion(self::TRIP_ID) ?? 0;
    }

    private function repository(): TripRequestRepositoryInterface
    {
        /** @var TripRequestRepositoryInterface $repository */
        $repository = self::getContainer()->get(TripRequestRepositoryInterface::class);

        return $repository;
    }

    /**
     * @return list<Stage>
     */
    private function stages(): array
    {
        return $this->repository()->getStages(self::TRIP_ID) ?? [];
    }

    private function seedTrip(int $stageCount = 4): void
    {
        /** @var TripRequestRepositoryInterface $repository */
        $repository = self::getContainer()->get(TripRequestRepositoryInterface::class);

        $request = new TripRequest(Uuid::fromString(self::TRIP_ID));
        $request->sourceUrl = 'https://www.komoot.com/tour/123456789';
        $request->startDate = new \DateTimeImmutable('today +1 year');

        $repository->initializeTrip(self::TRIP_ID, $request);
        $repository->storeSourceType(self::TRIP_ID, SourceType::KOMOOT_TOUR->value);

        $stages = [];
        for ($i = 0; $i < $stageCount; ++$i) {
            $stages[] = new Stage(
                tripId: self::TRIP_ID,
                dayNumber: $i + 1,
                distance: 80.0,
                elevation: 500.0,
                startPoint: new Coordinate(45.0 + $i * 0.5, 5.0 + $i * 0.5),
                endPoint: new Coordinate(45.0 + ($i + 1) * 0.5, 5.0 + ($i + 1) * 0.5),
                geometry: [
                    new Coordinate(45.0 + $i * 0.5, 5.0 + $i * 0.5),
                    new Coordinate(45.0 + ($i + 1) * 0.5, 5.0 + ($i + 1) * 0.5),
                ],
                label: \sprintf('Stage %d', $i + 1),
            );
        }

        $repository->storeStages(self::TRIP_ID, $stages);
        $this->associateTripWithUser(self::TRIP_ID, $this->owner);
    }
}
