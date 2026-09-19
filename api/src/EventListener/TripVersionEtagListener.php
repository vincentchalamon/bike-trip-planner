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
 */
#[AsEventListener(event: KernelEvents::RESPONSE)]
final readonly class TripVersionEtagListener
{
    public function __invoke(ResponseEvent $event): void
    {
        $version = $event->getRequest()->attributes->get(TripVersionEtag::ATTRIBUTE);
        if (!\is_int($version)) {
            return;
        }

        $response = $event->getResponse();
        $response->headers->set('ETag', TripVersionEtag::value($version));
        // The version tracks the structure, not the bytes: weather landing rewrites the body
        // without moving it. Barring caches keeps that from ever being observed — see
        // {@see TripVersionEtag::value()} for why the tag is strong regardless.
        $response->headers->set('Cache-Control', 'no-store');
    }
}
