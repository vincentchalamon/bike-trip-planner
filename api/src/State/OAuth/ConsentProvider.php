<?php

declare(strict_types=1);

namespace App\State\OAuth;

use Symfony\Component\Security\Core\User\UserInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\OAuth\Consent;
use App\Security\OAuth\ConsentRecord;
use App\Security\OAuth\ConsentStore;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Reads a pending consent back for the screen that will decide it.
 *
 * The handle travels in a URL, so it is treated as public: what gates this read is the
 * Bearer, and the record belonging to the user it names. A record held for someone else
 * answers exactly like a record that does not exist — the handle is derived from the
 * requesting user, so telling the two apart would leak that a given authorization was in
 * flight.
 *
 * @implements ProviderInterface<Consent>
 */
final readonly class ConsentProvider implements ProviderInterface
{
    public function __construct(
        private ConsentStore $consents,
        private Security $security,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Consent
    {
        $handle = \is_string($uriVariables['handle'] ?? null) ? $uriVariables['handle'] : '';

        $record = $this->consents->get($handle);
        $user = $this->security->getUser();

        if (!$record instanceof ConsentRecord || !$user instanceof UserInterface || $record->userId !== $user->getUserIdentifier()) {
            throw new NotFoundHttpException('No pending authorization.');
        }

        $host = null === $record->redirectUri ? null : parse_url($record->redirectUri, \PHP_URL_HOST);

        return new Consent(
            handle: $handle,
            clientName: $record->clientName,
            scopes: $record->scopes,
            redirectHost: \is_string($host) ? $host : null,
            redirectsToLoopback: \in_array($host, ['127.0.0.1', '[::1]', '::1'], true),
            continueUrl: $record->continueUrl,
        );
    }
}
