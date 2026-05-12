<?php

declare(strict_types=1);

namespace App\ApiResource\Model;

use ApiPlatform\Metadata\ApiProperty;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Conversational context propagated with every chat request.
 *
 * Allows the dialogue assistant to resolve referential phrases such as « cette
 * étape » against the stage the user is currently consulting in the UI.
 */
final class TripChatContext
{
    public function __construct(
        #[Assert\Range(min: 1)]
        #[ApiProperty(description: '1-indexed day number of the stage currently consulted, when applicable.')]
        public ?int $currentStage = null,
    ) {
    }
}
