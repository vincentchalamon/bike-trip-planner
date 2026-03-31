<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTCreatedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Adds the `sub` claim (user UUID) to JWT tokens.
 *
 * LexikJWTBundle uses `username` (from getUserIdentifier()) by default.
 * The frontend needs a stable, opaque `sub` identifier (UUID) in addition
 * to the human-readable `username` (email).
 */
#[AsEventListener(event: 'lexik_jwt_authentication.on_jwt_created')]
final class JwtCreatedListener
{
    public function __invoke(JWTCreatedEvent $event): void
    {
        $user = $event->getUser();

        if (!$user instanceof User) {
            return;
        }

        $payload = $event->getData();
        $payload['sub'] = $user->getId()->toRfc4122();
        $event->setData($payload);
    }
}
