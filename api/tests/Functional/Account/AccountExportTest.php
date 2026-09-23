<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account;

use App\Tests\ApiTestCase;
use App\ApiResource\TripRequest;
use App\Entity\Stage;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class AccountExportTest extends ApiTestCase
{
    #[\Override]
    protected static ?bool $alwaysBootKernel = false;

    private function getEntityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.entity_manager');
    }

    /**
     * @param non-empty-string $email
     *
     * @return array{user: User, jwt: string}
     */
    private function createUserWithTrip(string $email): array
    {
        $em = $this->getEntityManager();

        $user = new User($email);
        $user->setLocale('en');

        $em->persist($user);

        $trip = new TripRequest(Uuid::v7());
        $trip->user = $user;
        $trip->title = 'My Bikepacking Trip';
        $trip->sourceUrl = 'https://www.komoot.com/tour/123456789';
        $trip->fatigueFactor = 0.85;
        $trip->maxDistancePerDay = 70.0;

        $em->persist($trip);

        $stage = new Stage($trip);
        $stage->setPosition(0);
        $stage->setDayNumber(1);
        $stage->setLabel('Day 1');
        $stage->setDistance(65.0);
        $stage->setElevation(800.0);
        $stage->setStartLat(48.8566);
        $stage->setStartLon(2.3522);
        $stage->setEndLat(48.85);
        $stage->setEndLon(2.36);

        $trip->addStage($stage);

        $em->persist($stage);

        $em->flush();

        /** @var JWTTokenManagerInterface $jwtManager */
        $jwtManager = self::getContainer()->get('lexik_jwt_authentication.jwt_manager');
        $jwt = $jwtManager->create($user);

        return ['user' => $user, 'jwt' => $jwt];
    }

    #[Test]
    public function exportReturnsProfileTripsAndPreferences(): void
    {
        $fixtures = $this->createUserWithTrip('export@example.com');

        $response = self::createClient()->request('GET', '/users/me/export', [
            'headers' => ['Authorization' => 'Bearer '.$fixtures['jwt']],
        ]);

        $this->assertResponseIsSuccessful();

        $data = $response->toArray();

        $this->assertSame('export@example.com', $data['profile']['email']);
        $this->assertSame('en', $data['profile']['locale']);
        $this->assertArrayHasKey('createdAt', $data['profile']);

        $this->assertCount(1, $data['trips']);
        $trip = $data['trips'][0];
        $this->assertSame('My Bikepacking Trip', $trip['title']);
        $this->assertSame('https://www.komoot.com/tour/123456789', $trip['sourceUrl']);
        $this->assertSame(0.85, $trip['preferences']['fatigueFactor']);
        $this->assertEqualsWithDelta(70.0, $trip['preferences']['maxDistancePerDay'], 0.001);
        $this->assertArrayHasKey('enabledAccommodationTypes', $trip['preferences']);

        $this->assertCount(1, $trip['stages']);
        $stage = $trip['stages'][0];
        $this->assertSame(1, $stage['dayNumber']);
        $this->assertSame('Day 1', $stage['label']);
        $this->assertEqualsWithDelta(65.0, $stage['distance'], 0.001);
        $this->assertEqualsWithDelta(800.0, $stage['elevation'], 0.001);
    }

    #[Test]
    public function exportIsADownloadableAttachment(): void
    {
        $fixtures = $this->createUserWithTrip('download@example.com');

        $response = self::createClient()->request('GET', '/users/me/export', [
            'headers' => ['Authorization' => 'Bearer '.$fixtures['jwt']],
        ]);

        $this->assertResponseIsSuccessful();
        $disposition = $response->getHeaders()['content-disposition'][0] ?? '';
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringContainsString('.json', $disposition);
    }

    /**
     * Stages are exported in riding order, whichever order the rows come back in.
     *
     * The trips used to be fetch-joined with their stages, so the collection carried the
     * `#[ORM\OrderBy(['position' => 'ASC'])]` declared on the association. Reading the four
     * exported columns as a scalar query does not inherit it, and the export would quietly
     * follow whatever order the table happened to yield.
     *
     * Honest about what this proves: dropping the ORDER BY does not turn it red, because
     * `idx_stage_trip_position` leads with `(trip_id, position)` and the planner walks it, so
     * the rows arrive sorted by accident. That accident is the point — it holds only while
     * the planner picks that index, and nothing says it must. This is a regression guard.
     */
    #[Test]
    public function exportOrdersStagesByPosition(): void
    {
        $em = $this->getEntityManager();

        $user = new User('ordered-export@example.com');
        $em->persist($user);

        $trip = new TripRequest(Uuid::v7());
        $trip->user = $user;
        $em->persist($trip);

        // position runs opposite to insertion, so a query without an ORDER BY — which would
        // follow the physical row order — cannot accidentally produce the expected answer.
        foreach ([0 => 2, 1 => 1, 2 => 0] as $day => $position) {
            $stage = new Stage($trip);
            $stage->setPosition($position);
            $stage->setDayNumber($day + 1);
            $stage->setDistance(10.0);
            $stage->setElevation(0.0);
            $stage->setStartLat(48.0);
            $stage->setStartLon(2.0);
            $stage->setEndLat(48.1);
            $stage->setEndLon(2.1);
            $trip->addStage($stage);
            $em->persist($stage);
        }

        $em->flush();

        /** @var JWTTokenManagerInterface $jwtManager */
        $jwtManager = self::getContainer()->get('lexik_jwt_authentication.jwt_manager');

        $response = self::createClient()->request('GET', '/users/me/export', [
            'headers' => ['Authorization' => 'Bearer '.$jwtManager->create($user)],
        ]);

        $this->assertResponseIsSuccessful();

        $stages = $response->toArray()['trips'][0]['stages'];
        $this->assertSame([3, 2, 1], array_column($stages, 'dayNumber'));
    }

    #[Test]
    public function exportWithoutAuthenticationReturns401(): void
    {
        self::createClient()->request('GET', '/users/me/export');

        $this->assertResponseStatusCodeSame(401);
    }
}
