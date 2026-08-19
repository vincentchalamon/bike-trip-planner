<?php

declare(strict_types=1);

namespace App\State\Account;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Account\NotificationPreference as NotificationPreferenceResource;
use App\Entity\NotificationPreference;
use App\Entity\User;
use App\Enum\NotificationCategory;
use App\Repository\NotificationPreferenceRepositoryInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Upserts the current user's opt-in for one notification category (#1124).
 *
 * The category comes from the URL, the boolean from the body. An unknown category
 * is 404 (there is no such preference resource). Same user + same value is an
 * idempotent no-op that still returns the resource.
 *
 * @implements ProcessorInterface<NotificationPreferenceResource, NotificationPreferenceResource>
 */
final readonly class NotificationPreferenceUpdateProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private NotificationPreferenceRepositoryInterface $preferences,
    ) {
    }

    /**
     * @param NotificationPreferenceResource $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): NotificationPreferenceResource
    {
        $user = $this->security->getUser();
        \assert($user instanceof User);

        $rawCategory = $uriVariables['category'] ?? null;
        $category = \is_string($rawCategory) ? NotificationCategory::tryFrom($rawCategory) : null;
        if (null === $category) {
            throw new NotFoundHttpException();
        }

        $preference = $this->preferences->findOne($user, $category);
        if ($preference instanceof NotificationPreference) {
            $preference->setEnabled($data->enabled);
        } else {
            $preference = new NotificationPreference($user, $category, $data->enabled);
        }

        $this->preferences->save($preference);

        return new NotificationPreferenceResource($category, $preference->isEnabled());
    }
}
