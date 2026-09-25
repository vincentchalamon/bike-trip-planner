<?php

declare(strict_types=1);

namespace App\State\Mcp;

/**
 * The arguments of a `tools/call`, told apart from one another.
 *
 * `ApiPlatform\Mcp\Server\Handler` puts the JSON-RPC `arguments` into `$context['mcp_data']`
 * and stops there: they never become a query string, a request body, or a filter. Everything
 * an operation normally reads off the HTTP request therefore has to be read from here
 * instead, and this is the one place that knows where "here" is.
 *
 * Three kinds of argument share that one bag, and conflating them is how a tool ends up
 * writing a field it was only meant to check:
 *
 *  - **control** arguments steer the call — the version it is conditional on, the key that
 *    makes a retry safe, the token that confirms a destructive intent. They are named by
 *    {@see self::CONTROL} and are never part of the record being written;
 *  - **addressing** arguments are the operation's URI variables. The Handler copies those out
 *    by name before we get here, so they appear in both places and that is fine;
 *  - everything else is the **body**.
 */
final readonly class McpArguments
{
    /**
     * Arguments that steer the call rather than describe the record.
     *
     * They are declared on the tool's input DTO so that they appear in the published
     * `inputSchema` — a model cannot send what it has not been told about — but they must
     * never reach a denormalizer. `version` in particular is
     * `#[ApiProperty(writable: false)]` on {@see \App\ApiResource\TripRequest}, so letting it
     * through would only be harmless for as long as that attribute stays put.
     */
    public const array CONTROL = ['version', 'idempotencyKey', 'confirmationToken'];

    /** @param array<string, mixed> $arguments */
    private function __construct(public array $arguments)
    {
    }

    /**
     * Whether this context describes a `tools/call` rather than an HTTP request.
     *
     * `ApiPlatform\Mcp\Server\Handler` sets `mcp_data` for a tool call and only for a tool
     * call — to the arguments, even when there are none — so the key's presence tells the
     * transports apart where their contents cannot: a tool called with no arguments and an
     * HTTP request look alike once `from()` has normalised both to an empty set.
     *
     * The distinction matters wherever the two transports carry the same information by
     * different means: `$context['request']` is populated on MCP too (it is the `POST /mcp`
     * that carried the envelope), so a guard reading a header would read the envelope's,
     * which describes the batch rather than any one call in it.
     *
     * @param array<string, mixed> $context
     */
    public static function isToolCall(array $context): bool
    {
        return \array_key_exists('mcp_data', $context);
    }

    /**
     * Reads the arguments out of an operation context, whatever transport produced it.
     *
     * An HTTP call has no `mcp_data` and yields an empty set, which is what every caller
     * below wants: on that transport the request itself carries the same information.
     *
     * @param array<string, mixed> $context
     */
    public static function from(array $context): self
    {
        $data = $context['mcp_data'] ?? [];

        return new self(\is_array($data) ? $data : []);
    }

    /**
     * The arguments that describe the record, control arguments removed.
     *
     * @return array<string, mixed>
     */
    public function body(): array
    {
        return array_diff_key($this->arguments, array_flip(self::CONTROL));
    }

    public function control(string $name): mixed
    {
        \assert(\in_array($name, self::CONTROL, true), \sprintf('"%s" is not a control argument.', $name));

        return $this->arguments[$name] ?? null;
    }

    /**
     * The filters an operation should read, merging MCP arguments over the HTTP ones.
     *
     * `Pagination` reads `page` and `itemsPerPage` from `$context['filters']` exactly as the
     * collection providers read `title` or `startDate` from it
     * ({@see \ApiPlatform\State\Pagination\Pagination::getParameterFromContext()}). One bridge
     * therefore serves both, and a provider that already honours query filters honours tool
     * arguments by reading this instead of `$context['filters']` — no second code path, and
     * no pagination limits re-implemented where they could drift from the ones
     * `api_platform.php` publishes.
     *
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public static function filters(array $context): array
    {
        $http = $context['filters'] ?? [];

        // The two are never both populated — one transport produces each — but the order
        // states the intent rather than relying on that: on an MCP call the arguments are
        // the only thing the caller said.
        return self::from($context)->body() + (\is_array($http) ? $http : []);
    }

    /**
     * The body in an order that does not depend on how it was serialised.
     *
     * Both the idempotency digest and the confirmation token bind to a hash of what the call
     * asks for, and nothing obliges an agent to re-emit its JSON keys in the order it used the
     * first time. Without this, a perfectly legitimate retry hashes differently: the creation
     * answers 409 "already used for a different request body", and a valid confirmation token
     * is refused. Recursive, because a nested object has the same problem.
     *
     * The body, not every argument: a confirmation token binds to the arguments and is itself
     * one, so hashing the lot would guarantee a mismatch — the call that mints the token has
     * none, the call that spends it does. The same goes for an explicit idempotency key, which
     * would otherwise take part in the digest meant to tell whether it was reused honestly.
     *
     * @return array<string, mixed>
     */
    public function canonical(): array
    {
        return self::sortRecursively($this->body());
    }

    /**
     * @param array<array-key, mixed> $value
     *
     * @return array<array-key, mixed>
     */
    private static function sortRecursively(array $value): array
    {
        // A list is ordered by intent — the stages of an edit, say — so its order is data and
        // must survive. Only the keys of a map are arbitrary.
        if (!array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            if (\is_array($item)) {
                $value[$key] = self::sortRecursively($item);
            }
        }

        return $value;
    }
}
