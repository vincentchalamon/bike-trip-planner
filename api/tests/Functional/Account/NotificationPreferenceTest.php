<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class NotificationPreferenceTest extends ApiTestCase
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
    private function createUser(string $email): array
    {
        $em = $this->getEntityManager();
        $user = new User($email);
        $em->persist($user);
        $em->flush();

        /** @var JWTTokenManagerInterface $jwtManager */
        $jwtManager = self::getContainer()->get('lexik_jwt_authentication.jwt_manager');

        return ['user' => $user, 'jwt' => $jwtManager->create($user)];
    }

    #[Test]
    public function listReturnsTheThreeCategoriesWithTheirDefaults(): void
    {
        $fixtures = $this->createUser('prefs-list@example.com');

        $response = self::createClient()->request('GET', '/users/me/notification-preferences', [
            'headers' => ['Authorization' => 'Bearer '.$fixtures['jwt']],
        ]);

        $this->assertResponseIsSuccessful();

        $members = $response->toArray(false)['hydra:member'] ?? $response->toArray(false)['member'] ?? [];
        $byCategory = [];
        foreach ($members as $member) {
            $byCategory[$member['category']] = $member['enabled'];
        }

        $this->assertSame(
            ['weatherSafety' => true, 'analysisDone' => true, 'zoneOpening' => false],
            $byCategory,
        );
    }

    #[Test]
    public function putTogglesACategoryAndTheChangePersists(): void
    {
        $fixtures = $this->createUser('prefs-put@example.com');
        $client = self::createClient();

        $client->request('PUT', '/users/me/notification-preferences/zoneOpening', [
            'headers' => ['Content-Type' => 'application/ld+json', 'Authorization' => 'Bearer '.$fixtures['jwt']],
            'json' => ['enabled' => true],
        ]);
        $this->assertResponseIsSuccessful();

        $response = $client->request('GET', '/users/me/notification-preferences', [
            'headers' => ['Authorization' => 'Bearer '.$fixtures['jwt']],
        ]);
        $members = $response->toArray(false)['hydra:member'] ?? $response->toArray(false)['member'] ?? [];
        $byCategory = [];
        foreach ($members as $member) {
            $byCategory[$member['category']] = $member['enabled'];
        }

        $this->assertTrue($byCategory['zoneOpening']);
    }

    #[Test]
    public function putOnAnUnknownCategoryIs404(): void
    {
        $fixtures = $this->createUser('prefs-unknown@example.com');

        self::createClient()->request('PUT', '/users/me/notification-preferences/bogus', [
            'headers' => ['Content-Type' => 'application/ld+json', 'Authorization' => 'Bearer '.$fixtures['jwt']],
            'json' => ['enabled' => true],
        ]);

        $this->assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function anonymousAccessIsRejected(): void
    {
        self::createClient()->request('GET', '/users/me/notification-preferences');

        $this->assertResponseStatusCodeSame(401);
    }

    #[Test]
    public function putWithoutAnEnabledFieldIsRejectedAndDoesNotSilentlyDisable(): void
    {
        // A body omitting `enabled` must 422 (Assert\NotNull), not default to false
        // and silently opt the user out of a default-ON category.
        $fixtures = $this->createUser('prefs-missing-enabled@example.com');

        self::createClient()->request('PUT', '/users/me/notification-preferences/weatherSafety', [
            'headers' => ['Content-Type' => 'application/ld+json', 'Authorization' => 'Bearer '.$fixtures['jwt']],
            'json' => [],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }
}
