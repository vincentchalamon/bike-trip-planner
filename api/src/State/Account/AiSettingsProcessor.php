<?php

declare(strict_types=1);

namespace App\State\Account;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Account\AiSettings;
use App\Entity\User;
use App\Llm\AiProvider;
use App\Llm\AiTokenEncryptor;
use App\Llm\LlmClientFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Stores the current user's AI provider + token (ADR-042). The token is
 * format-validated against the chosen provider's bridge (no network call — the
 * live provider ping lands in A3 with the error classifier), encrypted at rest,
 * and never logged or returned.
 *
 * @implements ProcessorInterface<AiSettings, AiSettings>
 */
final readonly class AiSettingsProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private EntityManagerInterface $entityManager,
        private AiTokenEncryptor $tokenEncryptor,
        private LlmClientFactory $clientFactory,
    ) {
    }

    /**
     * @param AiSettings $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): AiSettings
    {
        $user = $this->security->getUser();

        \assert($user instanceof User);

        $provider = AiProvider::tryFrom((string) $data->provider);
        if (null === $provider) {
            throw new UnprocessableEntityHttpException('Unknown AI provider.');
        }

        $token = (string) $data->token;
        if ('' === $token) {
            throw new UnprocessableEntityHttpException('An API token is required.');
        }

        // Format validation only (e.g. OpenAI rejects keys without the sk- prefix
        // at construction); a real provider ping is deferred to A3.
        try {
            $this->clientFactory->create($provider, $token);
        } catch (\InvalidArgumentException) {
            throw new UnprocessableEntityHttpException('The API token format is invalid for this provider.');
        }

        $user->setAiProvider($provider->value);
        $user->setAiToken($this->tokenEncryptor->encrypt($token));

        $this->entityManager->flush();

        $result = new AiSettings();
        $result->provider = $provider->value;
        $result->tokenConfigured = true;

        return $result;
    }
}
