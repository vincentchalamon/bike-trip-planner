<?php

declare(strict_types=1);

namespace App\State\Account;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Account\DeviceToken as DeviceTokenResource;
use App\Entity\DeviceToken;
use App\Entity\User;
use App\Repository\DeviceTokenRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Unregisters an FCM device token owned by the current user (epic #1051).
 *
 * Like every other `/users/me` operation, the resource is scoped to the current
 * user resolved from the security token: the lookup only ever considers the
 * caller's own tokens (`findOneOwnedByUser`), so a token that is unknown OR owned
 * by someone else is simply "not among your tokens" -> 404. There is no
 * object-level authorization decision here to mask, so this does not scatter the
 * ADR-038 policy into a processor (which that ADR rejects); a non-owner still
 * cannot tell a foreign token from a missing one, and a foreign token is never
 * touched.
 *
 * The token rides in the URL path as the resource identifier. It is a
 * semi-sensitive per-device value, so it lands in access logs — an accepted
 * trade-off (there is no exposure without an attacker already holding the token);
 * the alternative, a body-carrying unregister action, is not worth the divergence
 * from REST identity here.
 *
 * @implements ProcessorInterface<DeviceTokenResource, JsonResponse>
 */
final readonly class DeviceTokenDeleteProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private EntityManagerInterface $entityManager,
        private DeviceTokenRepositoryInterface $deviceTokenRepository,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param DeviceTokenResource $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $user = $this->security->getUser();
        \assert($user instanceof User);

        $token = $uriVariables['token'] ?? '';
        \assert(\is_string($token));

        // Scoped to the caller's own tokens: unknown or foreign both resolve to null
        // -> 404, without the processor ever comparing owners.
        $deviceToken = $this->deviceTokenRepository->findOneOwnedByUser($token, $user);
        if (!$deviceToken instanceof DeviceToken) {
            throw new NotFoundHttpException();
        }

        $this->entityManager->remove($deviceToken);
        $this->entityManager->flush();

        $this->logger->info('Device token unregistered', ['user' => $user->getId()->toRfc4122()]);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
