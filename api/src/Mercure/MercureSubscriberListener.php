<?php

declare(strict_types=1);

namespace App\Mercure;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Attaches a Mercure subscriber access token to a successful response that created or
 * accessed a trip, as the `__Secure-mercure_access_token` cookie.
 *
 * The trip is the one stamped by {@see TripSubscription}, from:
 * - POST /trips (trip creation, 202)
 * - POST /trips/{id}/duplicate (trip duplication, 201)
 * - PATCH /trips/{id} (trip update, 202)
 * - GET /trips/{id}/detail (trip detail hydration)
 * - POST /trips/gpx-upload (GPX file upload, 202)
 */
#[AsEventListener(event: KernelEvents::RESPONSE, priority: -16)]
final readonly class MercureSubscriberListener
{
    public function __construct(
        private MercureTokenIssuer $tokenIssuer,
    ) {
    }

    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $tripId = $event->getRequest()->attributes->get(TripSubscription::ATTRIBUTE);
        $response = $event->getResponse();

        if (!\is_string($tripId) || !$response->isSuccessful()) {
            return;
        }

        $token = $this->tokenIssuer->generateSubscriberToken($tripId);

        // Set the HttpOnly subscriber cookie (for browser SSE via EventSource)
        $response->headers->setCookie($this->tokenIssuer->createSubscriberCookie($token));
    }
}
