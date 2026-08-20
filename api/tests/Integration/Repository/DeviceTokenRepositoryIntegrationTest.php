<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\DeviceToken;
use App\Entity\User;
use App\Enum\DevicePlatform;
use App\Repository\DeviceTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Integration coverage for the notify-by-userId lookup (#1123): findByUserId binds
 * the id against the `uuid` FK column, so it must round-trip through real Doctrine /
 * Postgres — the unit test stubs the QueryBuilder and never validates the parameter
 * type. A raw-string bind would silently match nothing (ADR-058: no silent no-op).
 */
final class DeviceTokenRepositoryIntegrationTest extends KernelTestCase
{
    use ResetDatabase;

    private EntityManagerInterface $em;

    private DeviceTokenRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->repository = self::getContainer()->get(DeviceTokenRepository::class);
    }

    #[Test]
    public function findsOnlyTheGivenUsersTokens(): void
    {
        $owner = $this->persistUser('owner@example.com');
        $other = $this->persistUser('other@example.com');
        $this->persistToken($owner, 'tok-a', DevicePlatform::ANDROID);
        $this->persistToken($owner, 'tok-b', DevicePlatform::IOS);
        $this->persistToken($other, 'tok-c', DevicePlatform::ANDROID);

        $tokens = array_map(
            static fn (DeviceToken $t): string => $t->getToken(),
            $this->repository->findByUserId($owner->getId()->toRfc4122()),
        );
        sort($tokens);

        self::assertSame(['tok-a', 'tok-b'], $tokens);
    }

    #[Test]
    public function returnsAnEmptyListForAUserWithNoTokens(): void
    {
        $user = $this->persistUser('empty@example.com');

        self::assertSame([], $this->repository->findByUserId($user->getId()->toRfc4122()));
    }

    /**
     * @param non-empty-string $email
     */
    private function persistUser(string $email): User
    {
        $user = new User($email);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function persistToken(User $user, string $token, DevicePlatform $platform): void
    {
        $this->em->persist(new DeviceToken($user, $token, $platform));
        $this->em->flush();
    }
}
