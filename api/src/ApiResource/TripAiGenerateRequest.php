<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input DTO for `POST /trips/ai-generate` (B1, ADR-042).
 *
 * Carries the rider's free-form trip description. The brief is never the full
 * prompt: {@see \App\Generation\AiTripGenerationService} wraps it in the
 * versioned `itinerary-generation` system prompt before calling the user's
 * configured provider.
 */
final class TripAiGenerateRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(min: 10, max: 2000)]
        #[ApiProperty(description: 'Free-form description of the desired trip (e.g. "boucle au départ de Lille, 2 jours, 80 km/jour, en tente").')]
        public string $brief = '',
    ) {
    }
}
