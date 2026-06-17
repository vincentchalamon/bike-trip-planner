<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\User;
use App\Llm\AiTokenEncryptor;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class AiSettingsTest extends ApiTestCase
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
    public function getReturnsUnconfiguredByDefault(): void
    {
        $fixtures = $this->createUser('ai-unconfigured@example.com');

        self::createClient()->request('GET', '/users/me/ai-settings', [
            'headers' => ['Authorization' => 'Bearer '.$fixtures['jwt']],
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertJsonContains(['provider' => null, 'tokenConfigured' => false]);
    }

    #[Test]
    public function putStoresProviderAndEncryptedToken(): void
    {
        $fixtures = $this->createUser('ai-store@example.com');
        $userId = $fixtures['user']->getId()->toRfc4122();

        $response = self::createClient()->request('PUT', '/users/me/ai-settings', [
            'headers' => ['Authorization' => 'Bearer '.$fixtures['jwt']],
            'json' => ['provider' => 'anthropic', 'token' => 'sk-ant-secret-token'],
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertJsonContains(['provider' => 'anthropic', 'tokenConfigured' => true]);
        // The token is write-only: it must never be echoed back.
        $this->assertArrayNotHasKey('token', $response->toArray());

        $em = $this->getEntityManager();
        $em->clear();

        $user = $em->find(User::class, Uuid::fromString($userId));
        \assert($user instanceof User);

        $stored = $user->getAiToken();
        $this->assertNotNull($stored);
        $this->assertNotSame('sk-ant-secret-token', $stored, 'the token must be encrypted at rest');

        /** @var AiTokenEncryptor $encryptor */
        $encryptor = self::getContainer()->get(AiTokenEncryptor::class);
        $this->assertSame('sk-ant-secret-token', $encryptor->decrypt($stored));
    }

    #[Test]
    public function putRejectsAnUnknownProvider(): void
    {
        $fixtures = $this->createUser('ai-bad-provider@example.com');

        self::createClient()->request('PUT', '/users/me/ai-settings', [
            'headers' => ['Authorization' => 'Bearer '.$fixtures['jwt']],
            'json' => ['provider' => 'ollama', 'token' => 'whatever'],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    #[Test]
    public function putRejectsATokenWithAnInvalidFormat(): void
    {
        // OpenAI's bridge rejects keys without the sk- prefix at construction.
        $fixtures = $this->createUser('ai-bad-token@example.com');

        self::createClient()->request('PUT', '/users/me/ai-settings', [
            'headers' => ['Authorization' => 'Bearer '.$fixtures['jwt']],
            'json' => ['provider' => 'openai', 'token' => 'not-an-openai-key'],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    #[Test]
    public function putRejectsAMissingToken(): void
    {
        $fixtures = $this->createUser('ai-no-token@example.com');

        self::createClient()->request('PUT', '/users/me/ai-settings', [
            'headers' => ['Authorization' => 'Bearer '.$fixtures['jwt']],
            'json' => ['provider' => 'anthropic'],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    #[Test]
    public function deleteClearsTheConfiguration(): void
    {
        $fixtures = $this->createUser('ai-clear@example.com');
        $userId = $fixtures['user']->getId()->toRfc4122();

        $client = self::createClient();
        $client->request('PUT', '/users/me/ai-settings', [
            'headers' => ['Authorization' => 'Bearer '.$fixtures['jwt']],
            'json' => ['provider' => 'anthropic', 'token' => 'sk-ant-secret-token'],
        ]);
        $this->assertResponseIsSuccessful();

        $client->request('DELETE', '/users/me/ai-settings', [
            'headers' => ['Authorization' => 'Bearer '.$fixtures['jwt']],
        ]);
        $this->assertResponseStatusCodeSame(204);

        $em = $this->getEntityManager();
        $em->clear();

        $user = $em->find(User::class, Uuid::fromString($userId));
        \assert($user instanceof User);

        $this->assertNull($user->getAiProvider());
        $this->assertNull($user->getAiToken());
    }

    #[Test]
    public function endpointsRequireAuthentication(): void
    {
        $client = self::createClient();

        $client->request('GET', '/users/me/ai-settings');
        $this->assertResponseStatusCodeSame(401);

        $client->request('PUT', '/users/me/ai-settings', ['json' => []]);
        $this->assertResponseStatusCodeSame(401);

        $client->request('DELETE', '/users/me/ai-settings');
        $this->assertResponseStatusCodeSame(401);
    }
}
