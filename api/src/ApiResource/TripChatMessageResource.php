<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;
use ApiPlatform\OpenApi\Model\Response;
use App\State\TripChatHistoryProvider;

/**
 * Read-only DTO exposing a single persisted chat turn over the API.
 *
 * Backs the `GET /trips/{id}/chat-history` endpoint introduced in #459 so the
 * PWA can rehydrate the chat drawer after a refresh or a return visit. The
 * underlying Doctrine entity ({@see \App\Entity\TripChatMessage}) is kept
 * private to the backend; only the publicly safe fields are exposed here.
 *
 * Messages are returned ordered by `createdAt` DESC (most recent first); the
 * frontend reverses them for chronological rendering. Cursor pagination is
 * available via the `before` query parameter (RFC 3339 datetime).
 */
#[ApiResource(
    shortName: 'TripChatMessage',
    operations: [
        new GetCollection(
            uriTemplate: '/trips/{id}/chat-history{._format}',
            openapi: new Operation(
                responses: [
                    404 => new Response(description: 'Trip not found'),
                ],
                summary: 'List the persisted chat history for a trip (most-recent first, cursor pagination).',
                parameters: [
                    new Parameter(
                        name: 'limit',
                        in: 'query',
                        description: 'Maximum number of messages to return (default 50, max 200).',
                        required: false,
                        schema: ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50],
                    ),
                    new Parameter(
                        name: 'before',
                        in: 'query',
                        description: 'Only return messages strictly older than this RFC 3339 timestamp (used as a cursor for "load older").',
                        required: false,
                        schema: ['type' => 'string', 'format' => 'date-time'],
                    ),
                ],
            ),
            paginationEnabled: false,
            security: "is_granted('TRIP_VIEW', request.attributes.get('id'))",
            provider: TripChatHistoryProvider::class,
        ),
    ],
)]
final readonly class TripChatMessageResource
{
    /**
     * @param non-empty-string $role one of `user` or `assistant`
     */
    public function __construct(
        #[ApiProperty(description: 'Message identifier (UUID v7).', identifier: true)]
        public string $id,
        #[ApiProperty(description: 'Trip identifier (UUID v7) the message belongs to.')]
        public string $tripId,
        #[ApiProperty(description: 'Author role: `user` or `assistant`.')]
        public string $role,
        #[ApiProperty(description: 'Raw message content. For assistant turns this is the JSON envelope returned by the LLM.')]
        public string $content,
        #[ApiProperty(description: 'Structured action interpreted by the dialogue assistant (e.g. `split_stage`, `info`).')]
        public ?string $action,
        #[ApiProperty(description: 'Server-side creation timestamp (RFC 3339).')]
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
