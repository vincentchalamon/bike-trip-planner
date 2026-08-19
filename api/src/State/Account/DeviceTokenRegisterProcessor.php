<?php

declare(strict_types=1);

namespace App\State\Account;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Account\DeviceToken as DeviceTokenResource;
use App\Entity\DeviceToken;
use App\Entity\User;
use App\Repository\DeviceTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idempotent upsert of an FCM device token for the current user (epic #1051).
 *
 * Registering a token the current user already holds updates its platform in
 * place (200, no duplicate). A token globally unknown is created (201). A token
 * currently bound to ANOTHER account is reassigned to the current user (200):
 * one physical device belongs to a single account at a time.
 *
 * @implements ProcessorInterface<DeviceTokenResource, JsonResponse>
 */
final readonly class DeviceTokenRegisterProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private EntityManagerInterface $entityManager,
        private DeviceTokenRepository $deviceTokenRepository,
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
        \assert(null !== $data->platform);

        $deviceToken = $this->deviceTokenRepository->findOneByToken($data->token);

        if ($deviceToken instanceof DeviceToken) {
            // Re-registration: refresh the platform and (re)bind to the current
            // user. Same user + same platform is a no-op that still returns 200.
            $deviceToken->setUser($user)->setPlatform($data->platform);
            $status = Response::HTTP_OK;
        } else {
            $deviceToken = new DeviceToken($user, $data->token, $data->platform);
            $this->entityManager->persist($deviceToken);
            $status = Response::HTTP_CREATED;
        }

        $this->entityManager->flush();

        $this->logger->info('Device token registered', ['user' => $user->getId()->toRfc4122(), 'platform' => $data->platform->value]);

        return new JsonResponse(
            [
                'token' => $deviceToken->getToken(),
                'platform' => $deviceToken->getPlatform()->value,
                'createdAt' => $deviceToken->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ],
            $status,
        );
    }
}
