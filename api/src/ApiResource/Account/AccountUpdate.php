<?php

declare(strict_types=1);

namespace App\ApiResource\Account;

use App\Entity\User;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input of `PATCH /users/me`: the account preferences the owner may change.
 *
 * Only `locale` for now. It is the language the server renders *for* the user —
 * emails, and the language passed to third parties when enriching a trip — so it
 * belongs to the account, not to the `Accept-Language` header of whichever client
 * happens to be calling (ADR-063).
 */
final class AccountUpdate
{
    public function __construct(
        // Required: a body omitting it is 422, not a silent no-op. Nullable so a
        // missing field denormalizes to null and trips NotNull, same reasoning as
        // NotificationPreference::$enabled.
        #[Assert\NotNull]
        #[Assert\Choice(choices: User::SUPPORTED_LOCALES)]
        public ?string $locale = null,
    ) {
    }
}
