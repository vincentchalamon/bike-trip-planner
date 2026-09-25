<?php

declare(strict_types=1);

namespace App\ApiResource\Mcp;

use ApiPlatform\Metadata\ApiProperty;

/**
 * Where one family of enrichments stands.
 *
 * A pair rather than a map entry, and that is not a style choice. **An associative array does
 * not survive the MCP transport**: the JSON-LD normalizer turns every array property into a
 * Hydra `Collection`, and for a map it emits only the values —
 * `{"route": "done", "weather": "running"}` leaves as `{"member": ["done", "running"]}`. The
 * keys are gone, so the reader is told two statuses and cannot tell what either describes.
 *
 * Measured on this transport, not assumed. Nothing in a tool's answer may be keyed by data.
 */
final readonly class CategoryStatus
{
    public function __construct(
        #[ApiProperty(description: 'Enrichment family: route, points_of_interest, accommodations, terrain_security, weather or context.')]
        public string $category,
        #[ApiProperty(
            description: '`running`, `done`, `failed`, or `superseded` when the trip moved on before these computations settled — nothing failed and nothing is still running.',
            schema: ['type' => 'string', 'enum' => ['running', 'done', 'failed', 'superseded']],
        )]
        public string $status,
    ) {
    }
}
