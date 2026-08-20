<?php

declare(strict_types=1);

namespace App\State\Account;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Account\AccountMe;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Returns the authenticated user's own profile for `GET /users/me`.
 *
 * The user is resolved from the security token (JWT Bearer), never from a URL
 * identifier, so there is no IDOR surface.
 *
 * @implements ProviderInterface<AccountMe>
 */
final readonly class AccountMeProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): AccountMe
    {
        $user = $this->security->getUser();

        \assert($user instanceof User);

        return new AccountMe(
            userId: $user->getId()->toRfc4122(),
            email: $user->getEmail(),
            locale: $user->getLocale(),
        );
    }
}
