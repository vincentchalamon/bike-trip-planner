<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Security\MagicLinkManager;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Twig\Environment;

/**
 * Handles passwordless authentication: magic link request, token verification,
 * JWT refresh, and logout.
 *
 * All endpoints return consistent timing/messages to prevent user enumeration.
 */
final readonly class AuthController
{
    private const string REFRESH_TOKEN_COOKIE = 'refresh_token';
    private const string NEUTRAL_MESSAGE = 'Si votre adresse est enregistrée, vous recevrez un lien de connexion.';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private MagicLinkManager $magicLinkManager,
        private JWTTokenManagerInterface $jwtManager,
        private MailerInterface $mailer,
        private Environment $twig,
        #[Autowire(service: 'limiter.magic_link_email')]
        private RateLimiterFactory $magicLinkEmailLimiter,
        #[Autowire(service: 'limiter.magic_link_ip')]
        private RateLimiterFactory $magicLinkIpLimiter,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    #[Route('/api/auth/request-link', name: 'auth_request_link', methods: ['POST'])]
    public function requestLink(Request $request): JsonResponse
    {
        $data = $request->toArray();
        $email = $data['email'] ?? '';

        if ('' === $email || !\is_string($email)) {
            return new JsonResponse(['message' => self::NEUTRAL_MESSAGE]);
        }

        // Apply rate limiters — silently deny if exceeded
        $ipLimiter = $this->magicLinkIpLimiter->create($request->getClientIp() ?? 'unknown');
        $emailLimiter = $this->magicLinkEmailLimiter->create($email);

        if (!$ipLimiter->consume()->isAccepted() || !$emailLimiter->consume()->isAccepted()) {
            return new JsonResponse(['message' => self::NEUTRAL_MESSAGE]);
        }

        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);

        if (null === $user) {
            return new JsonResponse(['message' => self::NEUTRAL_MESSAGE]);
        }

        $magicLink = $this->magicLinkManager->create($user);

        if (null !== $magicLink) {
            $verifyUrl = $this->urlGenerator->generate(
                'auth_verify',
                ['token' => $magicLink->getToken()],
                UrlGeneratorInterface::ABSOLUTE_URL,
            );

            $html = $this->twig->render('email/magic_link.html.twig', [
                'verifyUrl' => $verifyUrl,
                'expiresInMinutes' => 30,
            ]);

            $emailMessage = (new Email())
                ->to($user->getEmail())
                ->subject('Votre lien de connexion — Bike Trip Planner')
                ->html($html);

            $this->mailer->send($emailMessage);
        }

        return new JsonResponse(['message' => self::NEUTRAL_MESSAGE]);
    }

    #[Route('/auth/verify/{token}', name: 'auth_verify', methods: ['GET'])]
    public function verify(string $token, Request $request): Response
    {
        $user = $this->magicLinkManager->verify($token);

        if (null === $user) {
            return new JsonResponse(
                ['error' => 'Lien invalide ou expiré.'],
                Response::HTTP_UNAUTHORIZED,
            );
        }

        $jwt = $this->jwtManager->create($user);
        $refreshToken = $this->magicLinkManager->createRefreshToken($user);
        $isCapacitor = $this->isCapacitorRequest($request);

        if ($isCapacitor) {
            return new JsonResponse([
                'token' => $jwt,
                'refresh_token' => $refreshToken->getToken(),
            ]);
        }

        $response = new RedirectResponse('/');
        $response->headers->set('Authorization', 'Bearer '.$jwt);
        $this->setRefreshTokenCookie($response, $refreshToken->getToken(), $refreshToken->getExpiresAt());

        return $response;
    }

    #[Route('/api/auth/refresh', name: 'auth_refresh', methods: ['POST'])]
    public function refresh(Request $request): JsonResponse
    {
        $token = $request->cookies->get(self::REFRESH_TOKEN_COOKIE);
        $isCapacitor = $this->isCapacitorRequest($request);

        // Capacitor sends refresh token in body
        if (null === $token && $isCapacitor) {
            $data = $request->toArray();
            $token = $data['refresh_token'] ?? null;
        }

        if (null === $token || '' === $token) {
            return new JsonResponse(
                ['error' => 'Refresh token manquant.'],
                Response::HTTP_UNAUTHORIZED,
            );
        }

        $newRefreshToken = $this->magicLinkManager->rotateRefreshToken($token);

        if (null === $newRefreshToken) {
            $response = new JsonResponse(
                ['error' => 'Refresh token invalide ou expiré.'],
                Response::HTTP_UNAUTHORIZED,
            );
            // Clear the invalid cookie
            $response->headers->clearCookie(self::REFRESH_TOKEN_COOKIE, '/', null, true, true, 'strict');

            return $response;
        }

        $user = $newRefreshToken->getUser();
        $jwt = $this->jwtManager->create($user);

        $responseData = ['token' => $jwt];
        if ($isCapacitor) {
            $responseData['refresh_token'] = $newRefreshToken->getToken();
        }

        $response = new JsonResponse($responseData);
        $this->setRefreshTokenCookie($response, $newRefreshToken->getToken(), $newRefreshToken->getExpiresAt());

        return $response;
    }

    #[Route('/api/auth/logout', name: 'auth_logout', methods: ['POST'])]
    public function logout(#[CurrentUser] ?User $user): JsonResponse
    {
        if (null !== $user) {
            $this->magicLinkManager->revokeAllRefreshTokens($user);
        }

        $response = new JsonResponse(['message' => 'Déconnecté.']);
        $response->headers->clearCookie(self::REFRESH_TOKEN_COOKIE, '/', null, true, true, 'strict');

        return $response;
    }

    private function isCapacitorRequest(Request $request): bool
    {
        $origin = $request->headers->get('Origin', '');

        return str_starts_with($origin, 'capacitor://');
    }

    private function setRefreshTokenCookie(Response $response, string $token, \DateTimeImmutable $expiresAt): void
    {
        $cookie = Cookie::create(self::REFRESH_TOKEN_COOKIE)
            ->withValue($token)
            ->withExpires($expiresAt)
            ->withPath('/')
            ->withSecure(true)
            ->withHttpOnly(true)
            ->withSameSite('strict');

        $response->headers->setCookie($cookie);
    }
}
