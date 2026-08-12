<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Response;
use App\State\MercureTokenProvider;

/**
 * Delivers the per-trip Mercure subscriber JWT in the response body.
 *
 * The browser receives this token as the HttpOnly `mercureAuthorization` cookie
 * ({@see \App\Mercure\MercureSubscriberListener}), which a non-browser client
 * (React Native) cannot read. This resource exposes the same token — signed with
 * the same HMAC secret and scoped to `/trips/{id}` for 1h — through a readable
 * body so a mobile client can send it as `Authorization: Bearer` to the hub.
 * The cookie is untouched; the web keeps its cookie posture (issue #1019).
 */
#[ApiResource(
    shortName: 'MercureToken',
    operations: [
        new Get(
            uriTemplate: '/trips/{id}/mercure-token',
            openapi: new Operation(
                responses: [
                    200 => new Response(description: 'Subscriber JWT scoped to the trip topic'),
                    404 => new Response(description: 'Trip not found'),
                ],
                summary: 'Issue a per-trip Mercure subscriber JWT for non-browser SSE clients (mobile).',
            ),
            // Same object-level authz as GET /trips/{id}/detail: a non-owner is
            // masked as 404, not 403 (ADR-038, HideForbiddenAsNotFoundListener).
            security: "is_granted('TRIP_VIEW', request.attributes.get('id'))",
            provider: MercureTokenProvider::class,
        ),
    ],
)]
final readonly class MercureToken
{
    public function __construct(
        public string $token,
    ) {
    }
}
