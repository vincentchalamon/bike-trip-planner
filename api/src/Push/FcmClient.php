<?php

declare(strict_types=1);

namespace App\Push;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * FCM HTTP v1 sender (epic #1051).
 *
 * Auth is the OAuth2 JWT-bearer service-account flow: a short-lived RS256 JWT is
 * signed with the service-account private key and exchanged at Google's token
 * endpoint for an access token, which is then used as a bearer against
 * `POST /v1/projects/{projectId}/messages:send`. Both outbound hosts are reached
 * through host-locked scoped clients (SSRF control, ADR-011). HTTP v1 sends one
 * message per request, so tokens are delivered in a loop and any UNREGISTERED /
 * 404 token is collected for pruning by the caller.
 */
final class FcmClient implements PushSenderInterface
{
    private const string OAUTH_SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private const string OAUTH_AUDIENCE = 'https://oauth2.googleapis.com/token';

    private const int TOKEN_TTL = 3600;

    private ?string $accessToken = null;

    private int $accessTokenExpiresAt = 0;

    public function __construct(
        #[Autowire(service: 'google_oauth.client')]
        private readonly HttpClientInterface $googleOauthClient,
        #[Autowire(service: 'fcm.client')]
        private readonly HttpClientInterface $fcmClient,
        private readonly FcmCredentials $credentials,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function send(array $tokens, string $title, string $body, array $data = []): array
    {
        if ([] === $tokens) {
            return [];
        }

        $accessToken = $this->accessToken();
        $endpoint = \sprintf('/v1/projects/%s/messages:send', $this->credentials->projectId());
        $invalid = [];

        foreach ($tokens as $token) {
            try {
                $response = $this->fcmClient->request('POST', $endpoint, [
                    'auth_bearer' => $accessToken,
                    'json' => [
                        'message' => [
                            'token' => $token,
                            'notification' => ['title' => $title, 'body' => $body],
                            'data' => $data,
                        ],
                    ],
                ]);

                $status = $response->getStatusCode();
                if (200 === $status) {
                    continue;
                }

                if ($this->isUnregistered($status, $response->getContent(false))) {
                    $invalid[] = $token;
                    continue;
                }

                $this->logger->warning('FCM push failed.', ['status' => $status, 'body' => $response->getContent(false)]);
            } catch (\Throwable $e) {
                $this->logger->warning('FCM push transport error.', ['error' => $e->getMessage()]);
            }
        }

        return $invalid;
    }

    /**
     * A stale token is the only 404 we may prune. FCM v1 returns the same generic
     * `{"error":{"code":404,"status":"NOT_FOUND"}}` wrapper for unrelated 404s
     * (wrong project_id, disabled FCM API, revoked service account), so keying on
     * the status or an empty body would wipe every user's tokens on a config error.
     * The only reliable dead-token signal is the nested `errorCode: UNREGISTERED`.
     */
    private function isUnregistered(int $status, string $body): bool
    {
        return 404 === $status && str_contains($body, 'UNREGISTERED');
    }

    private function accessToken(): string
    {
        if (null !== $this->accessToken && time() < $this->accessTokenExpiresAt) {
            return $this->accessToken;
        }

        $assertion = $this->buildAssertion();
        $response = $this->googleOauthClient->request('POST', '/token', [
            'body' => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ],
        ]);

        /** @var array{access_token?: string, expires_in?: int} $payload */
        $payload = $response->toArray();
        if (!isset($payload['access_token'])) {
            throw new \RuntimeException('FCM token exchange returned no access_token.');
        }

        $this->accessToken = $payload['access_token'];
        $this->accessTokenExpiresAt = time() + (($payload['expires_in'] ?? self::TOKEN_TTL) - 60);

        return $this->accessToken;
    }

    private function buildAssertion(): string
    {
        $now = time();
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $claims = [
            'iss' => $this->credentials->clientEmail(),
            'scope' => self::OAUTH_SCOPE,
            'aud' => self::OAUTH_AUDIENCE,
            'iat' => $now,
            'exp' => $now + self::TOKEN_TTL,
        ];

        $signingInput = $this->base64UrlEncode($this->jsonEncode($header))
            .'.'.$this->base64UrlEncode($this->jsonEncode($claims));

        if (false === openssl_sign($signingInput, $signature, $this->credentials->privateKey(), \OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Failed to sign the FCM JWT assertion.');
        }

        return $signingInput.'.'.$this->base64UrlEncode($signature);
    }

    /**
     * @param array<string, mixed> $value
     */
    private function jsonEncode(array $value): string
    {
        return json_encode($value, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
