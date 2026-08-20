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
final class AccountMeTest extends ApiTestCase
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
     */
    private function createUserJwt(string $email, string $locale): string
    {
        $em = $this->getEntityManager();

        $user = new User($email);
        $user->setLocale($locale);

        $em->persist($user);
        $em->flush();

        /** @var JWTTokenManagerInterface $jwtManager */
        $jwtManager = self::getContainer()->get('lexik_jwt_authentication.jwt_manager');

        return $jwtManager->create($user);
    }

    #[Test]
    public function meReturnsTheCurrentUserProfile(): void
    {
        $jwt = $this->createUserJwt('me@example.com', 'en');

        $response = self::createClient()->request('GET', '/users/me', [
            'headers' => ['Authorization' => 'Bearer '.$jwt],
        ]);

        $this->assertResponseIsSuccessful();

        $data = $response->toArray();

        $this->assertSame('me@example.com', $data['email']);
        $this->assertSame('en', $data['locale']);
        $this->assertArrayHasKey('userId', $data);
        $this->assertNotSame('', $data['userId']);
    }

    #[Test]
    public function meWithoutAuthenticationReturns401(): void
    {
        self::createClient()->request('GET', '/users/me');

        $this->assertResponseStatusCodeSame(401);
    }
}
