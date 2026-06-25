<?php

declare(strict_types=1);

namespace App\ApiResource\Account;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\State\Account\RequestEmailChangeProcessor;
use App\State\Account\VerifyEmailChangeProcessor;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Email-change-by-magic-link flow for the authenticated user (#777).
 *
 * - POST /users/me/email-change         request a change to {newEmail}; a
 *   confirmation link is sent to the NEW address.
 * - POST /users/me/email-change/verify  consume the {token} from that link and
 *   commit the new email (single-use, atomic, email uniqueness enforced).
 *
 * The current user is always resolved from the security token, never from a URL
 * identifier (no IDOR surface). Distinct from the login magic link, which only
 * re-authenticates the SAME email.
 */
#[ApiResource(
    shortName: 'EmailChange',
    operations: [
        new Post(
            uriTemplate: '/users/me/email-change',
            status: 202,
            security: "is_granted('ROLE_USER')",
            validationContext: ['groups' => ['email-change:request']],
            output: false,
            read: false,
            processor: RequestEmailChangeProcessor::class,
        ),
        new Post(
            uriTemplate: '/users/me/email-change/verify',
            security: "is_granted('ROLE_USER')",
            validationContext: ['groups' => ['email-change:verify']],
            output: false,
            read: false,
            processor: VerifyEmailChangeProcessor::class,
        ),
    ],
)]
final class EmailChange
{
    public function __construct(
        #[Assert\NotBlank(groups: ['email-change:request'])]
        #[Assert\Email(groups: ['email-change:request'])]
        #[Assert\Length(max: 180, groups: ['email-change:request'])]
        public string $newEmail = '',
        #[Assert\NotBlank(groups: ['email-change:verify'])]
        public string $token = '',
    ) {
    }
}
