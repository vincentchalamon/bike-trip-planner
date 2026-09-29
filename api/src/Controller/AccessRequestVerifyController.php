<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AccessRequest;
use App\Repository\AccessRequestRepository;
use App\Repository\UserRepository;
use App\Service\AccessRequestHmacService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Handles HMAC-signed email verification for access requests.
 *
 * The emailed link is `/access-requests/verify#id=…&expires=…&signature=…`: the
 * fragment never reaches a server, so the page POSTs those three values here and
 * nothing of the link lands in an access log. The link names the access request
 * by its id, never by its address.
 *
 * Every outcome answers 204, so the response tells nothing about the request.
 */
final readonly class AccessRequestVerifyController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AccessRequestRepository $accessRequestRepository,
        private UserRepository $userRepository,
        private AccessRequestHmacService $hmacService,
        private LoggerInterface $logger,
    ) {
    }

    #[Route('/access-requests/verify', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        $done = new Response(null, Response::HTTP_NO_CONTENT);

        try {
            $params = $request->toArray();
        } catch (\Throwable) {
            return $done;
        }

        /** @var array{id?: mixed, expires?: mixed, signature?: mixed} $params */
        if (!$this->hmacService->verify($params)) {
            $this->logger->debug('Access request verify: invalid or expired HMAC', ['params' => array_keys($params)]);

            return $done;
        }

        $id = $params['id'] ?? '';
        $accessRequest = \is_string($id) && Uuid::isValid($id) ? $this->accessRequestRepository->find($id) : null;
        if (!$accessRequest instanceof AccessRequest) {
            // A signed id the table no longer holds: the record was removed after a
            // failed send, so there is nothing to verify.
            $this->logger->debug('Access request verify: unknown request — silently ignored');

            return $done;
        }

        $context = ['accessRequest' => $accessRequest->getId()->toRfc4122()];

        if (null !== $this->userRepository->findOneBy(['email' => $accessRequest->getEmail()])) {
            $this->logger->debug('Access request verify: user already exists — silently ignored', $context);

            return $done;
        }

        if ($accessRequest->isVerified()) {
            $this->logger->debug('Access request verify: already verified — silently ignored', $context);

            return $done;
        }

        $accessRequest->verify();
        $this->entityManager->flush();

        $this->logger->debug('Access request verified', $context);

        return $done;
    }
}
