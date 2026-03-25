<?php

declare(strict_types=1);

namespace App\Mercure;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Attaches a Mercure subscriber JWT cookie to responses that create or access a trip.
 *
 * Listens on kernel.response and injects the `mercureAuthorization` cookie
 * for endpoints matching `/trips/{uuid}` patterns. For Capacitor requests,
 * the JWT is also included in the JSON response body.
 *
 * Matched routes:
 * - POST /trips (trip creation — 202 response)
 * - POST /trips/{id}/duplicate (trip duplication — 201 response)
 * - PATCH /trips/{id} (trip update — 202 response)
 * - GET /trips/{id}/detail (trip detail hydration)
 * - POST /trips/gpx-upload (GPX file upload — 202 response)
 */
#[AsEventListener(event: KernelEvents::RESPONSE, priority: -16)]
final readonly class MercureSubscriberListener
{
    private const string UUID_PATTERN = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    public function __construct(
        private MercureTokenIssuer $tokenIssuer,
    ) {
    }

    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $response = $event->getResponse();
        $path = $request->getPathInfo();
        $method = $request->getMethod();

        $tripId = $this->extractTripId($path, $method, $response->getStatusCode());

        if (null === $tripId) {
            // For POST /trips and POST /trips/gpx-upload, the trip ID is in the response body
            $tripId = $this->extractTripIdFromResponseBody($path, $method, $response);
        }

        if (null === $tripId) {
            return;
        }

        // Set the subscriber cookie
        $cookie = $this->tokenIssuer->createSubscriberCookie($tripId);
        $response->headers->setCookie($cookie);

        // For Capacitor clients, include the JWT in the response body
        if ($this->isCapacitorRequest($request)) {
            $this->injectTokenInBody($response, $tripId);
        }
    }

    /**
     * Extracts the trip ID from the URL path for endpoints that contain it.
     */
    private function extractTripId(string $path, string $method, int $statusCode): ?string
    {
        // GET /trips/{id}/detail
        if ('GET' === $method && 1 === preg_match('#^/trips/('.self::UUID_PATTERN.')/detail$#', $path, $matches)) {
            return $matches[1];
        }

        // PATCH /trips/{id}
        if ('PATCH' === $method && 1 === preg_match('#^/trips/('.self::UUID_PATTERN.')(?:\.\w+)?$#', $path, $matches)) {
            return $matches[1];
        }

        // POST /trips/{id}/duplicate
        if ('POST' === $method && 1 === preg_match('#^/trips/('.self::UUID_PATTERN.')/duplicate(?:\.\w+)?$#', $path, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Extracts the trip ID from the JSON response body for creation endpoints.
     */
    private function extractTripIdFromResponseBody(string $path, string $method, mixed $response): ?string
    {
        if ('POST' !== $method) {
            return null;
        }

        // POST /trips or POST /trips/gpx-upload
        $isCreateEndpoint = 1 === preg_match('#^/trips(?:\.\w+)?$#', $path);
        $isGpxUpload = '/trips/gpx-upload' === $path;

        if (!$isCreateEndpoint && !$isGpxUpload) {
            return null;
        }

        if (!$response instanceof JsonResponse) {
            return null;
        }

        $content = $response->getContent();
        if (false === $content) {
            return null;
        }

        try {
            /** @var array{id?: string} $data */
            $data = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        $id = $data['id'] ?? null;

        if (null !== $id && 1 === preg_match('/^'.self::UUID_PATTERN.'$/', $id)) {
            return $id;
        }

        return null;
    }

    private function isCapacitorRequest(\Symfony\Component\HttpFoundation\Request $request): bool
    {
        $origin = $request->headers->get('Origin', '');

        return str_starts_with($origin, 'capacitor://');
    }

    private function injectTokenInBody(\Symfony\Component\HttpFoundation\Response $response, string $tripId): void
    {
        if (!$response instanceof JsonResponse) {
            return;
        }

        $content = $response->getContent();
        if (false === $content) {
            return;
        }

        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return;
        }

        $data['mercureToken'] = $this->tokenIssuer->generateSubscriberToken($tripId);
        $response->setData($data);
    }
}
