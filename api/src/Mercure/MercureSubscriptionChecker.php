<?php

declare(strict_types=1);

namespace App\Mercure;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Reads the Mercure hub's subscription API to tell whether a trip's SSE stream
 * currently has a live subscriber (#1124).
 *
 * Used to suppress the `analysisDone` push when the rider is already watching the
 * trip in real time. The hub exposes `GET /.well-known/mercure/subscriptions/{topic}`
 * (enabled by the `subscriptions` directive in the Caddy Mercure config); the
 * request is authorised with a short-lived server-side JWT.
 *
 * The hub is reached through the host-locked `mercure.health.client` (ADR-011);
 * the requested URL is built from the trusted MERCURE_URL and a validated trip id,
 * never from user input. On any hub error the checker **fails open** (reports no
 * subscriber) so a transient hub hiccup never silently swallows the notification.
 */
final readonly class MercureSubscriptionChecker implements MercureSubscriptionCheckerInterface
{
    public function __construct(
        #[Autowire(service: 'mercure.health.client')]
        private HttpClientInterface $mercureClient,
        private MercureTokenIssuer $tokenIssuer,
        #[Autowire(env: 'MERCURE_URL')]
        private string $mercureUrl,
        private LoggerInterface $logger,
    ) {
    }

    public function hasActiveSubscriber(string $tripId): bool
    {
        $topic = \sprintf('/trips/%s', $tripId);
        $url = rtrim($this->mercureUrl, '/').'/subscriptions/'.rawurlencode($topic);

        try {
            $response = $this->mercureClient->request('GET', $url, [
                'auth_bearer' => $this->tokenIssuer->generateSubscriptionsToken($tripId),
            ]);

            if (200 !== $response->getStatusCode()) {
                $this->logger->warning('Mercure subscription API returned {status}, assuming no subscriber.', [
                    'status' => $response->getStatusCode(),
                    'tripId' => $tripId,
                ]);

                return false;
            }

            /** @var array{subscriptions?: list<array{active?: bool}>} $payload */
            $payload = $response->toArray();
        } catch (\Throwable $throwable) {
            $this->logger->warning('Mercure subscription check failed, assuming no subscriber: {error}', [
                'error' => $throwable->getMessage(),
                'tripId' => $tripId,
            ]);

            return false;
        }

        foreach ($payload['subscriptions'] ?? [] as $subscription) {
            if ($subscription['active'] ?? false) {
                return true;
            }
        }

        return false;
    }
}
