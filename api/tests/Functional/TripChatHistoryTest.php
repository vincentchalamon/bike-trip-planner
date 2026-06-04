<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\ApiResource\TripRequest;
use App\Entity\TripChatMessage;
use App\Entity\User;
use App\Repository\TripRequestRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class TripChatHistoryTest extends ApiTestCase
{
    use Factories;
    use JwtAuthTestTrait;

    private const string TRIP_ID = '01936f6e-0000-7000-8000-000000000301';

    private const string OTHER_TRIP_ID = '01936f6e-0000-7000-8000-000000000302';

    private Client $client;

    private User $testUser;

    private string $jwtToken;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
        ['user' => $this->testUser, 'token' => $this->jwtToken] = $this->createTestUserWithJwt('chat-history@example.com');
    }

    private function seedTrip(string $tripId, ?User $owner = null): void
    {
        /** @var TripRequestRepositoryInterface $repo */
        $repo = self::getContainer()->get(TripRequestRepositoryInterface::class);

        $request = new TripRequest(Uuid::fromString($tripId));
        $request->sourceUrl = 'https://www.komoot.com/tour/123456789';
        $request->startDate = new \DateTimeImmutable('2026-07-01');

        $repo->initializeTrip($tripId, $request);

        $this->associateTripWithUser($tripId, $owner ?? $this->testUser);
    }

    /**
     * @param list<array{role: non-empty-string, content: string, action?: ?string, createdAt: string}> $turns
     */
    private function seedMessages(string $tripId, User $user, array $turns): void
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        $trip = $em->getReference(TripRequest::class, Uuid::fromString($tripId));
        \assert($trip instanceof TripRequest);
        $managedUser = $em->find(User::class, $user->getId());
        \assert($managedUser instanceof User);

        foreach ($turns as $turn) {
            $message = new TripChatMessage(
                trip: $trip,
                user: $managedUser,
                role: $turn['role'],
                content: $turn['content'],
                action: $turn['action'] ?? null,
                createdAt: new \DateTimeImmutable($turn['createdAt']),
            );
            $em->persist($message);
        }

        $em->flush();
        $em->clear();
    }

    #[Test]
    public function chatHistoryReturnsMessagesMostRecentFirst(): void
    {
        $this->seedTrip(self::TRIP_ID);
        $this->seedMessages(self::TRIP_ID, $this->testUser, [
            ['role' => TripChatMessage::ROLE_USER, 'content' => 'Bonjour', 'createdAt' => '2026-05-01 10:00:00'],
            ['role' => TripChatMessage::ROLE_ASSISTANT, 'content' => '{"action":"info","params":{},"response":"Salut"}', 'action' => 'info', 'createdAt' => '2026-05-01 10:00:05'],
            ['role' => TripChatMessage::ROLE_USER, 'content' => "Coupe l'étape 3", 'createdAt' => '2026-05-01 10:01:00'],
        ]);

        $response = $this->client->request(
            'GET',
            \sprintf('/trips/%s/chat-history', self::TRIP_ID),
            ['headers' => ['Accept' => 'application/ld+json', ...$this->authHeader($this->jwtToken)]],
        );

        $this->assertResponseIsSuccessful();

        $data = $response->toArray(false);
        $this->assertArrayHasKey('member', $data);
        $this->assertCount(3, $data['member']);
        $this->assertSame("Coupe l'étape 3", $data['member'][0]['content']);
        $this->assertSame(TripChatMessage::ROLE_USER, $data['member'][0]['role']);
        $this->assertSame('Bonjour', $data['member'][2]['content']);
        $this->assertSame(self::TRIP_ID, $data['member'][0]['tripId']);
    }

    #[Test]
    public function chatHistoryHonoursLimitQueryParameter(): void
    {
        $this->seedTrip(self::TRIP_ID);
        $this->seedMessages(self::TRIP_ID, $this->testUser, [
            ['role' => TripChatMessage::ROLE_USER, 'content' => 'msg-1', 'createdAt' => '2026-05-01 09:00:00'],
            ['role' => TripChatMessage::ROLE_USER, 'content' => 'msg-2', 'createdAt' => '2026-05-01 09:01:00'],
            ['role' => TripChatMessage::ROLE_USER, 'content' => 'msg-3', 'createdAt' => '2026-05-01 09:02:00'],
        ]);

        $response = $this->client->request(
            'GET',
            \sprintf('/trips/%s/chat-history?limit=2', self::TRIP_ID),
            ['headers' => ['Accept' => 'application/ld+json', ...$this->authHeader($this->jwtToken)]],
        );

        $this->assertResponseIsSuccessful();
        $data = $response->toArray(false);
        $this->assertCount(2, $data['member']);
        $this->assertSame('msg-3', $data['member'][0]['content']);
        $this->assertSame('msg-2', $data['member'][1]['content']);
    }

    #[Test]
    public function chatHistoryRejectsLimitZero(): void
    {
        $this->seedTrip(self::TRIP_ID);

        $this->client->request(
            'GET',
            \sprintf('/trips/%s/chat-history?limit=0', self::TRIP_ID),
            ['headers' => ['Accept' => 'application/ld+json', ...$this->authHeader($this->jwtToken)]],
        );

        $this->assertResponseStatusCodeSame(400);
    }

    #[Test]
    public function chatHistoryNormalisesNonUtcBeforeCursorToUtc(): void
    {
        $this->seedTrip(self::TRIP_ID);
        $this->seedMessages(self::TRIP_ID, $this->testUser, [
            // 09:00 UTC. A +02:00 cursor of 11:30 must compare to 09:30 UTC, so this row stays.
            ['role' => TripChatMessage::ROLE_USER, 'content' => 'kept-utc-0900', 'createdAt' => '2026-05-01 09:00:00'],
            ['role' => TripChatMessage::ROLE_USER, 'content' => 'dropped-utc-1000', 'createdAt' => '2026-05-01 10:00:00'],
        ]);

        $response = $this->client->request(
            'GET',
            \sprintf('/trips/%s/chat-history?before=%s', self::TRIP_ID, urlencode('2026-05-01T11:30:00+02:00')),
            ['headers' => ['Accept' => 'application/ld+json', ...$this->authHeader($this->jwtToken)]],
        );

        $this->assertResponseIsSuccessful();
        $data = $response->toArray(false);
        $this->assertCount(1, $data['member']);
        $this->assertSame('kept-utc-0900', $data['member'][0]['content']);
    }

    #[Test]
    public function chatHistorySupportsBeforeCursorPagination(): void
    {
        $this->seedTrip(self::TRIP_ID);
        $this->seedMessages(self::TRIP_ID, $this->testUser, [
            ['role' => TripChatMessage::ROLE_USER, 'content' => 'oldest', 'createdAt' => '2026-05-01 08:00:00'],
            ['role' => TripChatMessage::ROLE_USER, 'content' => 'middle', 'createdAt' => '2026-05-01 09:00:00'],
            ['role' => TripChatMessage::ROLE_USER, 'content' => 'newest', 'createdAt' => '2026-05-01 10:00:00'],
        ]);

        $response = $this->client->request(
            'GET',
            \sprintf('/trips/%s/chat-history?before=%s', self::TRIP_ID, urlencode('2026-05-01T09:30:00+00:00')),
            ['headers' => ['Accept' => 'application/ld+json', ...$this->authHeader($this->jwtToken)]],
        );

        $this->assertResponseIsSuccessful();
        $data = $response->toArray(false);
        $this->assertCount(2, $data['member']);
        $this->assertSame('middle', $data['member'][0]['content']);
        $this->assertSame('oldest', $data['member'][1]['content']);
    }

    #[Test]
    public function chatHistoryReturns400OnInvalidBeforeCursor(): void
    {
        $this->seedTrip(self::TRIP_ID);

        $this->client->request(
            'GET',
            \sprintf('/trips/%s/chat-history?before=not-a-date', self::TRIP_ID),
            ['headers' => ['Accept' => 'application/ld+json', ...$this->authHeader($this->jwtToken)]],
        );

        $this->assertResponseStatusCodeSame(400);
    }

    #[Test]
    public function chatHistoryRejectsUnauthenticatedRequests(): void
    {
        $this->seedTrip(self::TRIP_ID);

        $this->client->request(
            'GET',
            \sprintf('/trips/%s/chat-history', self::TRIP_ID),
            ['headers' => ['Accept' => 'application/ld+json']],
        );

        $this->assertResponseStatusCodeSame(401);
    }

    #[Test]
    public function chatHistoryDeniesAccessToTripsOwnedByAnotherUser(): void
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        $other = new User('stranger@example.com');
        $em->persist($other);
        $em->flush();

        $this->seedTrip(self::OTHER_TRIP_ID, owner: $other);

        $this->client->request(
            'GET',
            \sprintf('/trips/%s/chat-history', self::OTHER_TRIP_ID),
            ['headers' => ['Accept' => 'application/ld+json', ...$this->authHeader($this->jwtToken)]],
        );

        // Object-level authz denials are hidden as 404 (ADR-038), not 403, so a
        // foreign trip is indistinguishable from a non-existent one.
        $this->assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function chatHistoryReturnsEmptyCollectionWhenNoMessagesPersisted(): void
    {
        $this->seedTrip(self::TRIP_ID);

        $response = $this->client->request(
            'GET',
            \sprintf('/trips/%s/chat-history', self::TRIP_ID),
            ['headers' => ['Accept' => 'application/ld+json', ...$this->authHeader($this->jwtToken)]],
        );

        $this->assertResponseIsSuccessful();
        $data = $response->toArray(false);
        $this->assertSame([], $data['member']);
    }
}
