<?php

declare(strict_types=1);

namespace App\ApiResource;

/**
 * Output DTO for a trip share link.
 */
final readonly class TripShareResponse
{
    public function __construct(
        public string $id,
        public string $shareUrl,
        public string $token,
        public ?\DateTimeImmutable $expiresAt,
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
