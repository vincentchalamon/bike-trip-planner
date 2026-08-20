<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DeviceToken;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DeviceToken>
 */
final class DeviceTokenRepository extends ServiceEntityRepository implements DeviceTokenRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DeviceToken::class);
    }

    public function findOneByToken(string $token): ?DeviceToken
    {
        return $this->findOneBy(['token' => $token]);
    }

    public function findOneOwnedByUser(string $token, User $user): ?DeviceToken
    {
        return $this->findOneBy(['token' => $token, 'user' => $user]);
    }
}
