<?php

declare(strict_types=1);

namespace App\State\Account;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\OAuthGrant;
use App\Entity\User;
use App\Repository\OAuthGrantRepository;
use App\Security\OAuth\GrantRevoker;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Takes an application's access back.
 *
 * The lookup is scoped to the caller's own grants, so someone else's identifier and one that
 * never existed produce the same answer without this class ever comparing owners — the shape
 * {@see DeviceTokenDeleteProcessor} argues for, and the reason ADR-038's masking does not have
 * to be restated in a processor. A second revocation of the same grant lands there too: the row
 * is no longer live, so it answers 404 rather than pretending to work twice.
 *
 * What it does NOT do is call the bundle's `revokeCredentialsForClient()`, which the issue that
 * asked for this screen suggested: that method filters on the client alone and would cut the
 * application off for every user of it. {@see GrantRevoker} carries the intersection.
 *
 * @implements ProcessorInterface<mixed, JsonResponse>
 */
final readonly class AuthorizedApplicationRevokeProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private OAuthGrantRepository $grants,
        private GrantRevoker $revoker,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $user = $this->security->getUser();
        \assert($user instanceof User);

        $id = $uriVariables['id'] ?? null;

        if (!\is_string($id) || !Uuid::isValid($id)) {
            throw new NotFoundHttpException();
        }

        $grant = $this->grants->findLiveOwnedBy($user, Uuid::fromString($id));

        if (!$grant instanceof OAuthGrant) {
            throw new NotFoundHttpException();
        }

        $this->revoker->revoke($grant);

        // The client identifier, not its name: the name is text the application chose, and a
        // log line is read by someone deciding whether something went wrong.
        $this->logger->info('OAuth grant revoked by its owner', [
            'user' => $user->getId()->toRfc4122(),
            'client' => $grant->getClient()->getIdentifier(),
        ]);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
