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
        $category = $data['category'] ?? null;
        $targetedTokens = \count($tokens);
        $invalid = [];
        $failures = 0;

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

                $responseBody = $response->getContent(false);

                // A dead token is expected churn, not an incident: prune it and
                // record it at info level so it stays distinct from a real failure.
                if ($this->isUnregistered($status, $responseBody)) {
                    $invalid[] = $token;
                    $this->logger->info('Pruning unregistered FCM token.', [
                        'tokenRef' => $this->tokenRef($token),
                        'category' => $category,
                    ]);
                    continue;
                }

                // Any other non-200 (bad request, quota, auth/config, 5xx) is a real
                // send failure the operator must see (ADR-058: never a silent no-op).
                // Logged at error, not warning: prod's monolog `main` handler is
                // fingers_crossed with action_level: error, so a warning never
                // crosses the threshold and would be dropped. Counted so the batch
                // is rethrown below and Messenger retries it.
                ++$failures;
                $error = $this->extractError($responseBody);
                $this->logger->error('FCM push send failed.', [
                    'httpStatus' => $status,
                    'fcmStatus' => $error['status'],
                    'fcmMessage' => $error['message'],
                    'tokenRef' => $this->tokenRef($token),
                    'category' => $category,
                    'targetedTokens' => $targetedTokens,
                ]);
            } catch (\Throwable $e) {
                // Transport-level failure (timeout, DNS, TLS reset): surfaced at
                // error (crosses prod fingers_crossed), counted so the batch is
                // rethrown below. Loop continues to log every failing token first.
                ++$failures;
                $this->logger->error('FCM push transport error.', [
                    'error' => $e->getMessage(),
                    'tokenRef' => $this->tokenRef($token),
                    'category' => $category,
                    'targetedTokens' => $targetedTokens,
                ]);
            }
        }

        // A real send/transport failure must reach Messenger so its retry_strategy
        // (3 retries, backoff) fires — losing a security alert silently is worse than
        // the trade-off risk of a duplicate push re-delivered to a token already
        // served in this batch on retry. UNREGISTERED pruning is normal churn, not a
        // failure, so it never triggers a rethrow. When both happen in one batch the
        // dead tokens still ride out on the exception so the caller prunes them once
        // instead of rediscovering them on every retry (ADR-058).
        if ($failures > 0) {
            throw new FcmSendException(\sprintf('FCM push failed for %d of %d token(s) (category: %s); rethrowing for Messenger retry.', $failures, $targetedTokens, $category ?? 'none'), $invalid);
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
        if (404 !== $status) {
            return false;
        }

        try {
            /** @var array{error?: array{details?: list<array{errorCode?: string}>}} $decoded */
            $decoded = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }

        // Parse the nested errorCode rather than substring-matching the whole body:
        // the literal could otherwise appear in a human-readable `message` on an
        // unrelated 404 and wrongly prune every token on a config error.
        foreach ($decoded['error']['details'] ?? [] as $detail) {
            if ('UNREGISTERED' === ($detail['errorCode'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A device token is semi-sensitive (it can push to a user's device), so logs
     * never carry it whole — only a short, stable hash prefix to correlate lines.
     */
    private function tokenRef(string $token): string
    {
        return substr(hash('sha256', $token), 0, 12);
    }

    /**
     * Pulls the FCM `error.status` / `error.message` out of the response body for
     * logging. Returns nulls when the body is empty or not the expected shape.
     *
     * @return array{status: string|null, message: string|null}
     */
    private function extractError(string $body): array
    {
        try {
            /** @var array{error?: array{status?: string, message?: string}} $decoded */
            $decoded = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['status' => null, 'message' => null];
        }

        return [
            'status' => $decoded['error']['status'] ?? null,
            'message' => $decoded['error']['message'] ?? null,
        ];
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
