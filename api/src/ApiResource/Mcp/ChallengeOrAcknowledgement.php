<?php

declare(strict_types=1);

namespace App\ApiResource\Mcp;

use ApiPlatform\Metadata\ApiProperty;

/**
 * The published shape of a tool that asks before it acts: never instantiated, only described.
 *
 * `update_trip_settings`, `delete_trip` and `unshare_trip` answer twice, differently. The first
 * call returns a {@see ConfirmationChallenge}; the call carrying the token returns a
 * {@see WriteAcknowledgement}. One output class cannot be both, and the alternative — publishing
 * one of them — would tell a model half the protocol and lie about the other half.
 *
 * So the schema is the union, every field optional, each description saying which call carries
 * it. Both real answers conform to it, and a model reading it learns the two-call shape before
 * it makes the first one.
 */
final readonly class ChallengeOrAcknowledgement
{
    public function __construct(
        #[ApiProperty(description: 'First call only. Pass this back as the `confirmationToken` argument of the same tool, with the same arguments, to carry the action out. Single use, and valid for five minutes.')]
        public ?string $confirmationToken = null,
        #[ApiProperty(description: 'First call only. What the tool will do if called again with the token. Report it to the user before confirming.')]
        public ?string $action = null,
        #[ApiProperty(description: 'First call only. What the action would touch.')]
        public ?TripImpact $impact = null,
        #[ApiProperty(description: 'First call only. True: nothing has been changed yet.')]
        public ?bool $confirmationRequired = null,
        #[ApiProperty(description: 'Confirmed call only. What was done. Report it to the user.')]
        public ?string $result = null,
        #[ApiProperty(description: 'Confirmed call only. The trip version after this change, when the trip still exists. Pass it as `version` on the next edit of this trip.')]
        public ?int $version = null,
        #[ApiProperty(description: 'Confirmed call only. What to do next, when there is something to do.')]
        public ?string $nextAction = null,
    ) {
    }
}
