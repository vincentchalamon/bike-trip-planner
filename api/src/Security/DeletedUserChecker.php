<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Rejects authentication for soft-deleted (anonymised) accounts.
 *
 * GDPR erasure soft-deletes the account (stamps deletedAt, anonymises the email)
 * rather than hard-deleting the row, so the FK ON DELETE CASCADE never fires and
 * the user remains loadable by the JWT user provider. Without this guard a
 * lingering credential (an un-purged magic link, or a JWT minted before the
 * deletion) could still authenticate the deleted account. Wired as the firewall
 * user_checker so it runs on every authentication, including the per-request JWT
 * reload — a deleted account is rejected globally.
 */
final class DeletedUserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if ($user instanceof User && $user->isDeleted()) {
            // Neutral message: Lexik surfaces it verbatim in the 401 body, so it
            // must not reveal that the account ever existed (same rationale as the
            // neutral key in AuthVerifyProcessor).
            throw new CustomUserMessageAccountStatusException('Invalid credentials.');
        }
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
    }
}
