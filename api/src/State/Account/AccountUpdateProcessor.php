<?php

declare(strict_types=1);

namespace App\State\Account;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Account\AccountMe;
use App\ApiResource\Account\AccountUpdate;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Updates the authenticated user's own preferences for `PATCH /users/me`.
 *
 * The user is resolved from the security token, never from a URL identifier, so
 * there is no IDOR surface — same posture as the rest of the Account resource.
 *
 * @implements ProcessorInterface<AccountUpdate, AccountMe>
 */
final readonly class AccountUpdateProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param AccountUpdate $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): AccountMe
    {
        $user = $this->security->getUser();
        \assert($user instanceof User);

        // Guaranteed non-null by #[Assert\NotNull] (validation runs before this
        // processor): a PATCH omitting `locale` 422s and never reaches here.
        $locale = $data->locale;
        \assert(null !== $locale);

        $user->setLocale($locale);
        $this->entityManager->flush();

        return new AccountMe(
            userId: $user->getId()->toRfc4122(),
            email: $user->getEmail(),
            locale: $user->getLocale(),
        );
    }
}
