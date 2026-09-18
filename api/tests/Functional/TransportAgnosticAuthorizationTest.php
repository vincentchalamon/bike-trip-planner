<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage as StageDto;
use App\ApiResource\TripRequest;
use App\Entity\User;
use App\Repository\TripRequestRepositoryInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Proves every object-level authorization expression still denies, after ADR-063
 * moved them from `request.attributes.get(...)` onto the URI variable itself.
 *
 * Reading the eighteen expressions is not enough, because the failure mode is
 * silent: an expression that resolves to something {@see \App\Security\Voter\TripVoter}
 * does not accept makes the voter **abstain**, which denies — and ADR-038 then
 * renders that as a 404 indistinguishable from a legitimate one. The migration
 * caught exactly that on the TripShare operations, whose `tripId` API Platform
 * converts to a `Uuid` (`UuidUriVariableTransformer`) because their Link points at
 * `TripRequest::$id`.
 *
 * So each operation is exercised three ways: the owner is let through, a different
 * authenticated user gets 404, and an anonymous caller gets 401. The first case is
 * the one that catches a rule which denies *everyone*.
 */
#[ResetDatabase]
final class TransportAgnosticAuthorizationTest extends ApiTestCase
{
    use Factories;
    use JwtAuthTestTrait;

    private const string TRIP_ID = '01936f6e-0000-7000-8000-0000000004aa';

    private Client $client;

    private User $owner;

    private string $ownerToken;

    #[\Override]
    public static function setUpBeforeClass(): void
    {
        self::$alwaysBootKernel = false;
    }

    #[\Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
        ['user' => $this->owner, 'token' => $this->ownerToken] = $this->createTestUserWithJwt('owner@example.com');
    }

    /**
     * Every operation whose `security:` expression was migrated by ADR-063.
     *
     * Keyed by the expression's source location so a failure names the line to fix.
     *
     * The third element is the `Accept` header: the two export operations declare
     * only gpx/fit, and content negotiation rejects anything else with a 406 *before*
     * the security stage, which would make the denial assertions vacuous.
     *
     * @return iterable<string, array{string, string, string}>
     */
    public static function migratedOperations(): iterable
    {
        $trip = self::TRIP_ID;
        $jsonLd = 'application/ld+json';
        $gpx = 'application/gpx+xml';

        // is_granted('TRIP_VIEW', id) — keyed on the trip's own identifier
        yield 'Trip.php:155 GET /trips/{id} (export)' => ['GET', '/trips/' . $trip, $gpx];
        yield 'TripRoute.php:24 GET /trips/{id}/route' => ['GET', sprintf('/trips/%s/route', $trip), $jsonLd];
        yield 'TripDetail.php:29 GET /trips/{id}/detail' => ['GET', sprintf('/trips/%s/detail', $trip), $jsonLd];
        yield 'MercureToken.php:37 GET /trips/{id}/mercure-token' => ['GET', sprintf('/trips/%s/mercure-token', $trip), $jsonLd];

        // is_granted('TRIP_VIEW', tripId) — keyed on the parent trip
        yield 'Stage.php:52 GET stage export' => ['GET', sprintf('/trips/%s/stages/1/export', $trip), $gpx];
        yield 'Stage.php:65 GET stage detail' => ['GET', sprintf('/trips/%s/stages/1/detail', $trip), $jsonLd];

        // is_granted('TRIP_EDIT', tripId)
        yield 'Stage.php:76 POST stages' => ['POST', sprintf('/trips/%s/stages', $trip), $jsonLd];
        yield 'Stage.php:89 PATCH stage' => ['PATCH', sprintf('/trips/%s/stages/1', $trip), $jsonLd];
        yield 'Stage.php:103 PATCH stage move' => ['PATCH', sprintf('/trips/%s/stages/1/move', $trip), $jsonLd];
        yield 'Stage.php:117 DELETE stage' => ['DELETE', sprintf('/trips/%s/stages/1', $trip), $jsonLd];
        yield 'Stage.php:129 POST rest-day' => ['POST', sprintf('/trips/%s/stages/1/rest-day', $trip), $jsonLd];
        yield 'Stage.php:142 PATCH accommodation' => ['PATCH', sprintf('/trips/%s/stages/1/accommodation', $trip), $jsonLd];
        yield 'Stage.php:163 POST manual accommodation' => ['POST', sprintf('/trips/%s/stages/1/accommodations/manual', $trip), $jsonLd];
        yield 'Stage.php:176 POST poi-waypoint' => ['POST', sprintf('/trips/%s/stages/1/poi-waypoint', $trip), $jsonLd];
        yield 'AccommodationScan.php:23 POST accommodations/scan' => ['POST', sprintf('/trips/%s/accommodations/scan', $trip), $jsonLd];
        yield 'TripShare.php:47 POST share' => ['POST', sprintf('/trips/%s/share', $trip), $jsonLd];
        yield 'TripShare.php:57 GET share' => ['GET', sprintf('/trips/%s/share', $trip), $jsonLd];
        yield 'TripShare.php:66 DELETE share' => ['DELETE', sprintf('/trips/%s/share', $trip), $jsonLd];
    }

    #[Test]
    #[DataProvider('migratedOperations')]
    public function anotherUserIsDenied(string $method, string $uri, string $accept): void
    {
        $this->seedTrip();
        ['token' => $intruderToken] = $this->createTestUserWithJwt('intruder@example.com');

        $this->client->request($method, $uri, $this->options($intruderToken, $method, $accept));

        // ADR-038 masks an object-level denial as 404, so a foreign trip is
        // indistinguishable from one that does not exist.
        $this->assertResponseStatusCodeSame(404);
    }

    #[Test]
    #[DataProvider('migratedOperations')]
    public function anonymousIsChallenged(string $method, string $uri, string $accept): void
    {
        $this->seedTrip();

        $this->client->request($method, $uri, $this->options(null, $method, $accept));

        // The masking listener only applies to fully authenticated users, so an
        // anonymous caller still gets the firewall's 401.
        $this->assertResponseStatusCodeSame(401);
    }

    #[Test]
    #[DataProvider('migratedOperations')]
    public function theOwnerIsNotDenied(string $method, string $uri, string $accept): void
    {
        $this->seedTrip();

        $response = $this->client->request($method, $uri, $this->options($this->ownerToken, $method, $accept));

        // This is the assertion that catches a rule denying *everyone*: whatever the
        // operation then does (202, 200, 422 on a body it did not get...), it must
        // not be an authorization refusal. A masked 404 on a trip the owner owns is
        // exactly the silent-abstention bug.
        $status = $response->getStatusCode();

        $this->assertNotSame(
            401,
            $status,
            'The owner was challenged for authentication on an operation they own.',
        );
        $this->assertNotSame(
            403,
            $status,
            'The owner was refused: the authorization expression does not resolve.',
        );
        $this->assertStringNotContainsString(
            'Trip not found or has expired.',
            $response->getContent(false),
            'The owner got the ADR-038 masked denial, i.e. the voter abstained instead of granting. '.
            'The URI variable most likely does not reach TripVoter in a shape it accepts.',
        );
    }

    /**
     * @return array{headers: array<string, string>, json?: array<string, mixed>}
     */
    private function options(?string $token, string $method, string $accept): array
    {
        $headers = ['Accept' => $accept];

        if (null !== $token) {
            $headers = array_merge($headers, $this->authHeader($token));
        }

        if ('PATCH' === $method) {
            $headers['Content-Type'] = 'application/merge-patch+json';
        }

        $options = ['headers' => $headers];

        // A body-less POST/PATCH would 400 on content negotiation before reaching the
        // security stage, which would make the denial assertions vacuous.
        if (\in_array($method, ['POST', 'PATCH'], true)) {
            $options['json'] = [];
        }

        return $options;
    }

    /**
     * A trip owned by {@see self::$owner}, with one stage.
     *
     * The stage matters: several providers 404 on a trip that has none, with the
     * *same* "Trip not found or has expired." body that the ADR-038 masking
     * produces. Without it, `theOwnerIsNotDenied` could not tell a legitimate
     * "nothing to export" from a silent authorization refusal.
     */
    private function seedTrip(): void
    {
        $request = new TripRequest();
        $request->sourceUrl = 'https://www.komoot.com/tour/123456789';

        // The interface, not the Doctrine class: that is the implementation the
        // providers read through, and TripGpxProvider's getStages() misses anything
        // written straight to Postgres.
        /** @var TripRequestRepositoryInterface $repo */
        $repo = self::getContainer()->get(TripRequestRepositoryInterface::class);
        $repo->initializeTrip(self::TRIP_ID, $request);
        $this->associateTripWithUser(self::TRIP_ID, $this->owner);

        $repo->storeStages(self::TRIP_ID, [new StageDto(
            tripId: self::TRIP_ID,
            dayNumber: 1,
            distance: 85.5,
            elevation: 1200.0,
            startPoint: new Coordinate(45.0, 6.0, 1000.0),
            endPoint: new Coordinate(45.5, 6.5, 800.0),
            geometry: [new Coordinate(45.0, 6.0, 1000.0), new Coordinate(45.5, 6.5, 800.0)],
        )]);
        $repo->storeStatus(self::TRIP_ID, 'ready');
    }
}
