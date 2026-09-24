<?php

declare(strict_types=1);

namespace App\Tests\Unit\Concurrency;

use App\Concurrency\IfMatch;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionRequiredHttpException;

/**
 * The precondition read from each of the two transports that can carry it.
 *
 * The HTTP side is covered end to end by {@see \App\Tests\Functional\TripPreconditionTest};
 * what needs pinning here is the MCP side, where the same guard is a tool argument, and above
 * all the boundary between the two — an MCP context holds an HTTP request, and letting the
 * envelope's header speak for the calls inside it would waive the precondition of a whole
 * batch at once.
 */
final class IfMatchTest extends TestCase
{
    #[Test]
    public function readsTheHeaderOnHttp(): void
    {
        self::assertSame(7, IfMatch::fromContext($this->httpContext('"7"'))->expectedVersion);
        self::assertNull(IfMatch::fromContext($this->httpContext('*'))->expectedVersion);
    }

    #[Test]
    public function readsTheArgumentOnMcp(): void
    {
        self::assertSame(7, IfMatch::fromContext($this->toolCall(['version' => 7]))->expectedVersion);
        self::assertSame(7, IfMatch::expectedVersion($this->toolCall(['version' => 7])));
    }

    /**
     * A JSON-RPC client is free to send `7` or `"7"` — both are the same number to an agent,
     * and refusing one would only teach it to retry the other.
     */
    #[Test]
    public function acceptsTheArgumentAsAStringOfDigits(): void
    {
        self::assertSame(7, IfMatch::fromContext($this->toolCall(['version' => '7']))->expectedVersion);
    }

    #[Test]
    public function requiresTheArgumentOnMcp(): void
    {
        $this->expectException(PreconditionRequiredHttpException::class);
        $this->expectExceptionMessageMatches('/"version" argument/');

        IfMatch::fromContext($this->toolCall([]));
    }

    /**
     * On HTTP `*` is a caller stating it means to overwrite whatever is current. Offered to a
     * model it is simply the cheap way out of reading first, so the guard would hold nothing.
     */
    #[Test]
    public function refusesAWildcardArgument(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessageMatches('/does not accept "\*"/');

        IfMatch::fromContext($this->toolCall(['version' => '*']));
    }

    #[TestWith([-1])]
    #[TestWith(['seven'])]
    #[TestWith([['7']])]
    #[TestWith([7.5])]
    public function testRefusesAnythingThatIsNotAVersion(mixed $version): void
    {
        $this->expectException(BadRequestHttpException::class);

        IfMatch::fromContext($this->toolCall(['version' => $version]));
    }

    /**
     * The guard the whole pair exists for.
     *
     * `$context['request']` is populated on MCP too — it is the `POST /mcp` that carried the
     * envelope — so a source picked by "whichever holds a value" would let one header cover
     * every call batched inside it. Here that header says `*`, the most permissive thing it
     * could say, and the call is still refused for want of its own argument.
     */
    #[Test]
    public function theEnvelopeHeaderDoesNotSpeakForTheCallsItCarries(): void
    {
        $context = $this->toolCall([]) + $this->httpContext('*');

        $this->expectException(PreconditionRequiredHttpException::class);

        IfMatch::fromContext($context);
    }

    /**
     * Absent means "no constraint" here, on both transports: whether it was *required* is
     * settled earlier, by the operation's flag.
     */
    #[Test]
    public function anAbsentPreconditionIsNoConstraint(): void
    {
        self::assertNull(IfMatch::expectedVersion($this->toolCall([])));
        self::assertNull(IfMatch::expectedVersion($this->httpContext(null)));
        self::assertNull(IfMatch::expectedVersion([]));
    }

    /** @return array{request: Request} */
    private function httpContext(?string $header): array
    {
        $request = new Request();

        if (null !== $header) {
            $request->headers->set(IfMatch::HEADER, $header);
        }

        return ['request' => $request];
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array{mcp_data: array<string, mixed>}
     */
    private function toolCall(array $arguments): array
    {
        return ['mcp_data' => $arguments];
    }
}
