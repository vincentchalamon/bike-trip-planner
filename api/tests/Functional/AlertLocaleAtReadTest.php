<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage as StageDto;
use App\ApiResource\TripRequest;
use App\Entity\User;
use App\Enum\AlertGroup;
use App\Repository\DoctrineTripRequestRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The reason lot B PR 2 exists: a stored alert has no language of its own.
 *
 * Before ADR-069 the producer translated and then persisted, so the row carried whatever
 * language the trip was computed in. Switching an account to English left its alerts in
 * French until something recomputed them — and nothing had to.
 *
 * This walks the real HTTP path twice, against one unchanged database row.
 */
#[ResetDatabase]
final class AlertLocaleAtReadTest extends ApiTestCase
{
    use Factories;
    use JwtAuthTestTrait;

    private const string TRIP_ID = '01936f6e-0000-7000-8000-000000000903';

    private Client $client;

    private User $owner;

    private string $jwtToken;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
        ['user' => $this->owner, 'token' => $this->jwtToken] = $this->createTestUserWithJwt('locale-at-read@example.com');
    }

    #[Test]
    public function theOwnersLanguageDecidesAndSwitchingItNeedsNoRecomputation(): void
    {
        $repository = $this->doctrineRepository();
        $this->seedTripWithAlert($repository);

        $this->setOwnerLocale('fr');
        self::assertSame(
            'Important dénivelé positif : 1500m D+ sur cette étape.',
            $this->readFirstAlertMessage(),
        );

        // Only the account preference changes. No producer runs, no row is rewritten.
        $this->setOwnerLocale('en');
        self::assertSame(
            'Significant elevation: 1500m D+ on this stage.',
            $this->readFirstAlertMessage(),
        );
    }

    /**
     * Nobody is signed in on the public share page, so there is no reader preference to
     * honour — the trip owner's language stands in rather than a hardcoded default.
     */
    #[Test]
    public function theAnonymousSharePageFallsBackToTheTripsOwnLanguage(): void
    {
        $repository = $this->doctrineRepository();
        $this->seedTripWithAlert($repository);
        $this->setOwnerLocale('fr');

        $response = $this->client->request('POST', \sprintf('/trips/%s/share', self::TRIP_ID), [
            'headers' => array_merge(['Content-Type' => 'application/ld+json'], $this->authHeader($this->jwtToken)),
            'json' => [],
        ]);
        $this->assertResponseStatusCodeSame(201);
        $shortCode = (string) $response->toArray(false)['shortCode'];

        // No Authorization header, and deliberately an English Accept-Language: the account
        // preference is the source of truth, not the header (ADR-063).
        $shared = $this->client->request('GET', \sprintf('/s/%s', $shortCode), [
            'headers' => ['Accept' => 'application/ld+json', 'Accept-Language' => 'en-GB,en;q=0.9'],
        ]);

        $this->assertResponseIsSuccessful();
        $alerts = $shared->toArray(false)['stages'][0]['alerts'];

        self::assertSame('Important dénivelé positif : 1500m D+ sur cette étape.', $alerts[0]['message']);
    }

    /**
     * The raw arguments stay on the wire next to the sentence, so a client that would rather
     * phrase it itself — an agent, in a language the server was never told about — can.
     */
    #[Test]
    public function theKeyAndItsRawArgumentsAreServedAlongsideTheSentence(): void
    {
        $repository = $this->doctrineRepository();
        $this->seedTripWithAlert($repository);

        $alerts = $this->readAlerts();

        self::assertSame('alert.elevation.warning', $alerts[0]['messageKey']);

        $parameters = $alerts[0]['parameters'];
        \assert(\is_array($parameters));
        self::assertSame(1500, $parameters['%elevation%']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readAlerts(): array
    {
        $response = $this->client->request('GET', \sprintf('/trips/%s/detail', self::TRIP_ID), [
            'headers' => array_merge(['Accept' => 'application/ld+json'], $this->authHeader($this->jwtToken)),
        ]);
        $this->assertResponseIsSuccessful();

        /** @var list<array<string, mixed>> $alerts */
        $alerts = $response->toArray(false)['stages'][0]['alerts'];
        \assert(\is_array($alerts));

        return $alerts;
    }

    private function readFirstAlertMessage(): string
    {
        $alerts = $this->readAlerts();
        $message = $alerts[0]['message'];
        \assert(\is_string($message));

        return $message;
    }

    private function setOwnerLocale(string $locale): void
    {
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        \assert($em instanceof EntityManagerInterface);

        $owner = $em->find(User::class, $this->owner->getId());
        \assert($owner instanceof User);
        $owner->setLocale($locale);
        $em->flush();
    }

    private function doctrineRepository(): DoctrineTripRequestRepository
    {
        $repository = self::getContainer()->get(DoctrineTripRequestRepository::class);
        \assert($repository instanceof DoctrineTripRequestRepository);

        return $repository;
    }

    private function seedTripWithAlert(DoctrineTripRequestRepository $repository): void
    {
        $request = new TripRequest(Uuid::fromString(self::TRIP_ID));
        $request->sourceUrl = 'https://www.komoot.com/tour/123456789';

        $repository->initializeTrip(self::TRIP_ID, $request);
        $repository->storeTitle(self::TRIP_ID, 'Trip read in two languages');
        // What TripCreateProcessor writes from the owner's account (ADR-063), and what the
        // share page falls back to when nobody is signed in.
        $repository->storeLocale(self::TRIP_ID, 'fr');
        $repository->storeStatus(self::TRIP_ID, 'ready');
        $this->associateTripWithUser(self::TRIP_ID, $this->owner);

        $repository->storeStages(self::TRIP_ID, [new StageDto(
            tripId: self::TRIP_ID,
            dayNumber: 1,
            distance: 85.5,
            elevation: 1500.0,
            startPoint: new Coordinate(45.0, 6.0, 1000.0),
            endPoint: new Coordinate(45.5, 6.5, 800.0),
            geometry: [new Coordinate(45.0, 6.0, 1000.0), new Coordinate(45.5, 6.5, 800.0)],
        )]);

        $stageId = ($repository->getStages(self::TRIP_ID) ?? [])[0]->id;
        // Stored exactly as a producer stores it: a key and a number, no prose.
        $repository->updateStageAlertsForGroup(self::TRIP_ID, $stageId, AlertGroup::TERRAIN, [[
            'code' => 'elevation_gain',
            'type' => 'warning',
            'messageKey' => 'alert.elevation.warning',
            'parameters' => ['%elevation%' => 1500],
        ]]);
    }
}
