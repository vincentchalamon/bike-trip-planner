<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\NotificationPreference;
use App\Entity\User;
use App\Enum\NotificationCategory;
use App\Repository\NotificationPreferenceRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Integration coverage for the per-category opt-in store (#1124): the default
 * fallback when no row exists, the stored override, and the opted-in user lookup.
 */
final class NotificationPreferenceRepositoryTest extends KernelTestCase
{
    use ResetDatabase;

    private EntityManagerInterface $em;

    private NotificationPreferenceRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->repository = self::getContainer()->get(NotificationPreferenceRepository::class);
    }

    #[Test]
    public function fallsBackToTheCategoryDefaultWhenNoRowExists(): void
    {
        $user = $this->persistUser('default@example.com');
        $userId = $user->getId()->toRfc4122();

        self::assertTrue($this->repository->isEnabled($userId, NotificationCategory::WEATHER_SAFETY));
        self::assertTrue($this->repository->isEnabled($userId, NotificationCategory::ANALYSIS_DONE));
        self::assertFalse($this->repository->isEnabled($userId, NotificationCategory::ZONE_OPENING));
    }

    #[Test]
    public function aStoredOverrideWins(): void
    {
        $user = $this->persistUser('override@example.com');
        $userId = $user->getId()->toRfc4122();

        $this->repository->save(new NotificationPreference($user, NotificationCategory::WEATHER_SAFETY, false));
        $this->repository->save(new NotificationPreference($user, NotificationCategory::ZONE_OPENING, true));

        self::assertFalse($this->repository->isEnabled($userId, NotificationCategory::WEATHER_SAFETY));
        self::assertTrue($this->repository->isEnabled($userId, NotificationCategory::ZONE_OPENING));
    }

    #[Test]
    public function findsOnlyUsersWhoExplicitlyOptedIntoACategoryWithTheirLocale(): void
    {
        $optedIn = $this->persistUser('in@example.com', 'en');
        $optedOut = $this->persistUser('out@example.com', 'fr');
        $this->persistUser('untouched@example.com', 'fr');

        $this->repository->save(new NotificationPreference($optedIn, NotificationCategory::ZONE_OPENING, true));
        $this->repository->save(new NotificationPreference($optedOut, NotificationCategory::ZONE_OPENING, false));

        $users = $this->repository->findEnabledUsers(NotificationCategory::ZONE_OPENING);

        self::assertSame([['id' => $optedIn->getId()->toRfc4122(), 'locale' => 'en']], $users);
    }

    /**
     * @param non-empty-string $email
     */
    private function persistUser(string $email, string $locale = 'fr'): User
    {
        $user = new User($email);
        $user->setLocale($locale);

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
