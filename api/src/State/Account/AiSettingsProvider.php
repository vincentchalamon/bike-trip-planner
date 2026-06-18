<?php

declare(strict_types=1);

namespace App\State\Account;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Account\AiSettings;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Exposes the current user's AI configuration without ever revealing the stored
 * token — only whether one is set.
 *
 * @implements ProviderInterface<AiSettings>
 */
final readonly class AiSettingsProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): AiSettings
    {
        $user = $this->security->getUser();

        \assert($user instanceof User);

        $settings = new AiSettings();
        $settings->provider = $user->getAiProvider();
        $settings->tokenConfigured = null !== $user->getAiToken() && '' !== $user->getAiToken();

        return $settings;
    }
}
