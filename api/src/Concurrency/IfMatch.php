<?php

declare(strict_types=1);

namespace App\Concurrency;

use App\State\Mcp\McpArguments;
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
 *
 * The precondition travels differently on each transport and the difference is not cosmetic.
 * HTTP has a header per request; MCP batches its calls into one `POST /mcp` and has none, so
 * there the version is a tool argument — the guard stated in the domain's vocabulary rather
 * than the transport's, which is what ADR-063 concluded for authorization and what a header
 * could never survive. Which source is read is decided by the transport
 * ({@see McpArguments::isToolCall()}), never by which one happens to hold a value.
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
     * @throws PreconditionRequiredHttpException when the precondition is absent
     * @throws BadRequestHttpException           when it is present but unparseable
     */
    public static function fromContext(array $context): self
    {
        if (McpArguments::isToolCall($context)) {
            return self::fromArgument(McpArguments::from($context)->control('version'));
        }

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
        if (McpArguments::isToolCall($context)) {
            $version = McpArguments::from($context)->control('version');

            return null === $version ? null : self::fromArgument($version)->expectedVersion;
        }

        $header = self::header($context);

        return null === $header ? null : self::fromHeader($header)->expectedVersion;
    }

    /**
     * The same precondition, carried as a tool argument because MCP has no per-call headers.
     *
     * A `tools/call` travels inside one `POST /mcp`, so there is no header to attach to an
     * individual call — the guard has to become part of the domain vocabulary, which is
     * ADR-063's move applied to concurrency instead of authorization. The messages are the
     * argument's own rather than the header's: telling an agent to send an `If-Match` header
     * would name something it has no way to produce, and a guard the caller cannot satisfy is
     * one that gets sent random values until it passes, or removed.
     *
     * `*` is refused here although the header accepts it. On HTTP it is a caller stating that
     * it means to write over whatever is current; asked of a model it would just be the easy
     * way out of reading first, and the precondition would protect nothing.
     *
     * @throws PreconditionRequiredHttpException when the argument is absent
     * @throws BadRequestHttpException           when it is present but unusable
     */
    public static function fromArgument(mixed $version): self
    {
        if (null === $version || '' === $version) {
            throw new PreconditionRequiredHttpException('This tool requires a "version" argument: the trip version you are editing, as returned by "get_trip". It is what makes someone else\'s concurrent edit visible instead of silently overwritten.');
        }

        if ('*' === $version) {
            throw new BadRequestHttpException('The "version" argument does not accept "*": writing unconditionally would defeat the check entirely. Read the trip with "get_trip" and pass the version it returned.');
        }

        if (\is_int($version) && $version >= 0) {
            return new self($version);
        }

        if (\is_string($version) && 1 === preg_match('/^\d+$/', trim($version))) {
            return new self((int) trim($version));
        }

        throw new BadRequestHttpException('Malformed "version" argument: expected the whole number returned by "get_trip", such as 7.');
    }

    /**
     * Read on the HTTP transport only.
     *
     * An MCP context carries a request too, so this would happily return the header of the
     * `POST /mcp` that shipped the envelope — one header waiving, or misdirecting, the
     * precondition of every call in the batch. {@see McpArguments::isToolCall()} settles the
     * source before anything is read, rather than taking whichever answers first.
     *
     * @param array<string, mixed> $context
     */
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
