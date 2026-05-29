<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\ApiResource\TripRequest;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class AccountExportTest extends ApiTestCase
{
    use Factories;

    #[\Override]
    public static function setUpBeforeClass(): void
    {
        self::$alwaysBootKernel = false;
    }

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

    #[Test]
    public function exportWithoutAuthenticationReturns401(): void
    {
        self::createClient()->request('GET', '/users/me/export');

        $this->assertResponseStatusCodeSame(401);
    }
}
