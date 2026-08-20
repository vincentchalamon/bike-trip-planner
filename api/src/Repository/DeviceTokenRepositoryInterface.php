<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DeviceToken;

interface DeviceTokenRepositoryInterface
{
    public function findOneByToken(string $token): ?DeviceToken;
}
