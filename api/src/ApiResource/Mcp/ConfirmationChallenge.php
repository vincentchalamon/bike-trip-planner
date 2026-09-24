<?php

declare(strict_types=1);

namespace App\ApiResource\Mcp;

use ApiPlatform\Metadata\ApiProperty;

/**
 * The answer a destructive tool gives the first time it is called: what it would do, and the
 * token that lets it be asked again in earnest.
 *
 * Nothing was written when this is returned. The short circuit happens inside the lock and the
 * precondition, so a token is only ever minted for a call that would actually have gone
 * through — an agent is never sent round the loop to confirm something a stale version or a
 * started trip was going to refuse anyway.
 */
final readonly class ConfirmationChallenge
{
    public function __construct(
        #[ApiProperty(description: 'Pass this back as the `confirmationToken` argument of the same tool, with the same arguments, to carry the action out. Single use, and valid for five minutes.')]
        public string $confirmationToken,
        #[ApiProperty(description: 'What the tool will do if called again with the token. Report it to the user before confirming.')]
        public string $action,
        public TripImpact $impact,
        #[ApiProperty(description: 'True: nothing has been changed yet.')]
        public bool $confirmationRequired = true,
    ) {
    }
}
