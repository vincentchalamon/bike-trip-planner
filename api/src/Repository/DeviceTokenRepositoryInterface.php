<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DeviceToken;
use App\Entity\User;

interface DeviceTokenRepositoryInterface
{
    public function findOneByToken(string $token): ?DeviceToken;

    /**
     * The given token, but only if it belongs to the given user. Scopes the lookup
     * to the caller's own tokens so the delete path never reasons about foreign
     * tokens (no object-level authorization to mask — see DeviceTokenDeleteProcessor).
     */
    public function findOneOwnedByUser(string $token, User $user): ?DeviceToken;

    /**
     * @return list<DeviceToken>
     */
    public function findByUserId(string $userId): array;

    /**
     * @param list<string> $tokens
     */
    public function deleteByTokens(array $tokens): void;
}
