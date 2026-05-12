<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use App\ApiResource\Model\TripChatContext;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input DTO for the trip chat endpoint.
 *
 * Carries the user's natural-language message along with a small context object
 * so the LLaMA 3B dialogue assistant can interpret stage-relative references.
 */
final class TripChatRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 2000)]
        #[ApiProperty(description: 'Natural language instruction or question from the rider.')]
        public string $message = '',
        #[Assert\Valid]
        #[ApiProperty(description: 'Conversational context (e.g. the stage currently consulted in the UI).')]
        public ?TripChatContext $context = null,
    ) {
    }
}
