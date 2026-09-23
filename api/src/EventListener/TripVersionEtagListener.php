<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Concurrency\TripVersionEtag;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Advertises the trip version stamped by the provider or processor as the response `ETag`.
 *
 * Only a response whose producer stamped one gets a tag: no stamp, no header, so a resource
 * that has nothing to do with a trip is never given a token clients could send back.
 *
 * Two stamps, two caching answers, and the difference is the whole point (ADR-078). The same
 * number is a precondition token on a body the version does not fully describe, and a real
 * representation validator on one it does.
 */
#[AsEventListener(event: KernelEvents::RESPONSE)]
final readonly class TripVersionEtagListener
{
    public function __invoke(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $response = $event->getResponse();

        $version = $request->attributes->get(TripVersionEtag::ATTRIBUTE);
        if (\is_int($version)) {
            $response->headers->set('ETag', TripVersionEtag::value($version));
            // The version tracks the structure, not the bytes: weather landing rewrites the
            // body without moving it. Barring caches keeps that from ever being observed —
            // see {@see TripVersionEtag::value()} for why the tag is strong regardless.
            $response->headers->set('Cache-Control', 'no-store');

            return;
        }

        $validator = $request->attributes->get(TripVersionEtag::VALIDATOR_ATTRIBUTE);
        if (!\is_int($validator)) {
            return;
        }

        $response->headers->set('ETag', TripVersionEtag::value($validator));
        // `no-cache`, not `no-store`: the client keeps the copy and revalidates it on every
        // use. `no-store` would forbid keeping it at all, so the conditional request that
        // makes the tag worth anything would never be sent. `private` because the body is one
        // user's trip even when a share link makes it publicly reachable.
        $response->headers->set('Cache-Control', 'private, no-cache');
    }
}
