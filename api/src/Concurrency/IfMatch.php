<?php

declare(strict_types=1);

namespace App\Concurrency;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionRequiredHttpException;

/**
 * The `If-Match` precondition carried by a structural edit.
 *
 * The entity-tag is the trip's structural version ({@see \App\ApiResource\TripRequest::$version}),
 * emitted as a **strong** validator. A weak one would have made the header inert: RFC 9110
 * §8.8.3.2 mandates the strong comparison function for `If-Match`, under which no weak tag
 * ever matches. The trade-off is that the version is not a byte-exact representation
 * validator — an enrichment arriving changes the body without moving it — which is why the
 * responses carrying it are `Cache-Control: no-store`: the tag is a precondition token, never
 * a cache validator.
 */
final readonly class IfMatch
{
    public const string HEADER = 'If-Match';

    private function __construct(
        /** null when the caller sent `*`, i.e. "whatever the current state is". */
        public ?int $expectedVersion,
    ) {
    }

    /**
     * @param array<string, mixed> $context the API Platform operation context
     *
     * @throws PreconditionRequiredHttpException when the header is absent
     * @throws BadRequestHttpException           when it is present but unparseable
     */
    public static function fromContext(array $context): self
    {
        return self::fromHeader(self::header($context));
    }

    /**
     * The expected version alone, for a caller that only forwards it to the repository.
     *
     * `null` means "no constraint", covering three cases the repository treats alike: a `*`
     * tag, an operation that requires no precondition, and a call from outside HTTP (a
     * worker regenerating the pacing). Whether the header was *required* is settled earlier,
     * by {@see \App\State\PreconditionProcessor}; here its absence is simply no constraint.
     *
     * @param array<string, mixed> $context
     */
    public static function expectedVersion(array $context): ?int
    {
        $header = self::header($context);

        return null === $header ? null : self::fromHeader($header)->expectedVersion;
    }

    /** @param array<string, mixed> $context */
    private static function header(array $context): ?string
    {
        $request = $context['request'] ?? null;

        return $request instanceof Request ? $request->headers->get(self::HEADER) : null;
    }

    public static function fromHeader(?string $header): self
    {
        if (null === $header || '' === trim($header)) {
            throw new PreconditionRequiredHttpException(\sprintf('This operation requires an "%s" header carrying the trip version you edited, taken from the ETag of the response that served it.', self::HEADER));
        }

        $header = trim($header);

        if ('*' === $header) {
            return new self(null);
        }

        // A list of candidate tags matches if any one of them does; we only ever mint one
        // tag per trip, so anything beyond the first is noise we accept rather than reject.
        foreach (explode(',', $header) as $candidate) {
            if (1 === preg_match('/^"(\d+)"$/', trim($candidate), $matches)) {
                return new self((int) $matches[1]);
            }
        }

        throw new BadRequestHttpException(\sprintf('Malformed "%s" header: expected a quoted trip version such as "%s: \"7\"", or "*".', self::HEADER, self::HEADER));
    }
}
