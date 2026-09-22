<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Points a 201 or a 202 at the resource it created or accepted work for (ADR-074).
 *
 * The JSON-LD body already carries the same URI as `@id`, and every client this project has
 * today reads the body — so this header changes nothing for them. It is for a consumer that
 * does not parse JSON-LD: the agent this API is being restructured for, a `HEAD`, a proxy.
 * RFC 9110 §15.3.3 asks a 202 to point at something that describes the request's status, and
 * since ADR-074 that address answers.
 *
 * Read off `@id` rather than rebuilt from an IRI converter: `POST /trips/gpx-upload` composes
 * its JSON-LD body by hand outside API Platform's serializer, and one listener that reads the
 * body covers both paths without either of them having to remember to stamp anything.
 */
#[AsEventListener(event: KernelEvents::RESPONSE)]
final readonly class AcceptedLocationListener
{
    public function __invoke(ResponseEvent $event): void
    {
        $response = $event->getResponse();

        if (!\in_array($response->getStatusCode(), [Response::HTTP_CREATED, Response::HTTP_ACCEPTED], true)
            || $response->headers->has('Location')) {
            return;
        }

        $content = $response->getContent();
        if (!\is_string($content) || '' === $content) {
            return;
        }

        try {
            $body = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            // A 202 with a non-JSON body — nothing to point at, and not this listener's
            // business to complain about it.
            return;
        }

        $iri = \is_array($body) ? ($body['@id'] ?? null) : null;
        if (\is_string($iri) && '' !== $iri) {
            $response->headers->set('Location', $iri);
        }
    }
}
