<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use App\ApiResource\Model\GeoPosition;
use App\ApiResource\Model\TripChatContext;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input DTO for the trip chat endpoint.
 *
 * Carries the user's natural-language message along with a small context object
 * so the LLaMA 3B dialogue assistant can interpret stage-relative references.
 *
 * When {@see $position} is provided (i.e. the rider is in-ride), the processor
 * delegates to the {@see \App\InRide\InRideAssistant} and returns POI
 * suggestions instead of planning actions.
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
        #[Assert\Valid]
        #[ApiProperty(description: 'Optional rider GPS position. When provided, switches the assistant to in-ride POI search mode.')]
        public ?GeoPosition $position = null,
    ) {
    }
}
