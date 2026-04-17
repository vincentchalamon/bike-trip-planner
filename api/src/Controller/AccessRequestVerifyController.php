<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use App\Entity\AccessRequest;
use App\Repository\AccessRequestRepository;
use App\Repository\UserRepository;
use App\Service\AccessRequestHmacService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Handles HMAC-signed email verification for access requests.
 *
 * On valid signature: creates or updates AccessRequest to verified status, then redirects.
 * On invalid/expired/already-verified: silently redirects with a generic confirmation.
 */
final readonly class AccessRequestVerifyController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AccessRequestRepository $accessRequestRepository,
        private UserRepository $userRepository,
        private AccessRequestHmacService $hmacService,
        private LoggerInterface $logger,
        #[Autowire(env: 'FRONTEND_URL')]
        private string $frontendUrl = 'https://localhost',
    ) {
    }

    #[Route('/access-requests/verify', methods: ['GET'])]
    public function __invoke(Request $request): RedirectResponse
    {
        $landingUrl = rtrim($this->frontendUrl, '/');

        /** @var array{email?: mixed, expires?: mixed, signature?: mixed} $params */
        $params = $request->query->all();

        if (!$this->hmacService->verify($params)) {
            $this->logger->debug('Access request verify: invalid or expired HMAC', ['params' => array_keys($params)]);

            return new RedirectResponse($landingUrl.'?access=confirmed');
        }

        $email = $params['email'] ?? '';
        \assert(\is_string($email) && '' !== $email);

        // Silently ignore if user already exists
        $existingUser = $this->userRepository->findOneBy(['email' => $email]);
        if (null !== $existingUser) {
            $this->logger->debug('Access request verify: user already exists — silently ignored', ['email' => $email]);

            return new RedirectResponse($landingUrl.'?access=confirmed');
        }

        $accessRequest = $this->accessRequestRepository->findByEmail($email);

        // Silently ignore if already verified
        if ($accessRequest instanceof AccessRequest && $accessRequest->isVerified()) {
            $this->logger->debug('Access request verify: already verified — silently ignored', ['email' => $email]);

            return new RedirectResponse($landingUrl.'?access=confirmed');
        }

        if (!$accessRequest instanceof AccessRequest) {
            // Edge case: link was sent but the request record was not created yet
            // (e.g., email was sent but persist failed). Create a verified record directly.
            $clientIp = $request->getClientIp() ?? 'unknown';
            $accessRequest = new AccessRequest($email, $clientIp);
            $this->entityManager->persist($accessRequest);
        }

        $accessRequest->verify();
        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            $this->logger->debug('Access request verify: race condition — silently ignored', ['email' => $email]);

            return new RedirectResponse($landingUrl.'?access=confirmed');
        }

        $this->logger->debug('Access request verified', ['email' => $email]);

        return new RedirectResponse($landingUrl.'?access=confirmed');
    }
}
