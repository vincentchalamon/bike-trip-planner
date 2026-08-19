<?php

declare(strict_types=1);

namespace App\State\Account;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Account\NotificationPreference as NotificationPreferenceResource;
use App\Entity\User;
use App\Enum\NotificationCategory;
use App\Repository\NotificationPreferenceRepositoryInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Returns the current user's effective opt-in for every notification category (#1124):
 * the stored override when present, otherwise the category default.
 *
 * @implements ProviderInterface<NotificationPreferenceResource>
 */
final readonly class NotificationPreferenceProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private NotificationPreferenceRepositoryInterface $preferences,
    ) {
    }

    /**
     * @return list<NotificationPreferenceResource>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $user = $this->security->getUser();
        \assert($user instanceof User);
        $userId = $user->getId()->toRfc4122();

        return array_map(
            fn (NotificationCategory $category): NotificationPreferenceResource => new NotificationPreferenceResource(
                $category,
                $this->preferences->isEnabled($userId, $category),
            ),
            NotificationCategory::cases(),
        );
    }
}
