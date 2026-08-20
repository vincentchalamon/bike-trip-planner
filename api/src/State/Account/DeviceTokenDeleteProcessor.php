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
 * The token is resolved from the URL, never from a body. A token that does not
 * exist OR belongs to another account is masked as 404 (ADR-038): object-level
 * authorization failures are indistinguishable from a missing resource, so a
 * caller cannot probe which tokens exist for other users.
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
        $deviceToken = $this->deviceTokenRepository->findOneByToken($token);

        // Unknown token or one owned by another account: both masked as 404 so a
        // non-owner cannot distinguish the two (ADR-038).
        if (!$deviceToken instanceof DeviceToken || !$deviceToken->getUser()->getId()->equals($user->getId())) {
            throw new NotFoundHttpException();
        }

        $this->entityManager->remove($deviceToken);
        $this->entityManager->flush();

        $this->logger->info('Device token unregistered', ['user' => $user->getId()->toRfc4122()]);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
