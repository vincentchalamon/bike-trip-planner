<?php

declare(strict_types=1);

namespace App\Alert;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * The language to render an alert in, for whoever is reading it.
 *
 * The account's preference, never `Accept-Language` (ADR-063): a stored choice beats a
 * browser header the server may never see, and an API client sends no header at all.
 *
 * The fallback carries the anonymous case. Nobody is signed in on `/s/{shortCode}`, so the
 * share page renders in the language of the trip's owner — the one person who chose
 * anything about it.
 */
final readonly class ReaderLocale
{
    public function __construct(private Security $security)
    {
    }

    public function or(string $fallback): string
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user->getLocale() : $fallback;
    }
}
