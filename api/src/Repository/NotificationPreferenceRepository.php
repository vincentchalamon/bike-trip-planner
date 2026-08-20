<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\NotificationPreference;
use App\Entity\User;
use App\Enum\NotificationCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NotificationPreference>
 */
final class NotificationPreferenceRepository extends ServiceEntityRepository implements NotificationPreferenceRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NotificationPreference::class);
    }

    public function isEnabled(string $userId, NotificationCategory $category): bool
    {
        /** @var NotificationPreference|null $preference */
        $preference = $this->createQueryBuilder('np')
            ->andWhere('IDENTITY(np.user) = :userId')
            ->andWhere('np.category = :category')
            ->setParameter('userId', $userId)
            ->setParameter('category', $category)
            ->getQuery()
            ->getOneOrNullResult();

        return $preference?->isEnabled() ?? $category->defaultEnabled();
    }

    public function findEnabledUsers(NotificationCategory $category): array
    {
        /** @var list<array{id: string, locale: string}> $rows */
        $rows = $this->createQueryBuilder('np')
            ->select('IDENTITY(np.user) AS id', 'u.locale AS locale')
            ->join('np.user', 'u')
            ->andWhere('np.category = :category')
            ->andWhere('np.enabled = true')
            ->setParameter('category', $category)
            ->getQuery()
            ->getArrayResult();

        return array_map(
            static fn (array $row): array => ['id' => (string) $row['id'], 'locale' => (string) $row['locale']],
            $rows,
        );
    }

    public function findOne(User $user, NotificationCategory $category): ?NotificationPreference
    {
        return $this->findOneBy(['user' => $user, 'category' => $category]);
    }

    public function save(NotificationPreference $preference): void
    {
        $em = $this->getEntityManager();
        $em->persist($preference);
        $em->flush();
    }
}
