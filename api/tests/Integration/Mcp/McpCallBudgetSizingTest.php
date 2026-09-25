<?php

declare(strict_types=1);

namespace App\Tests\Integration\Mcp;

use App\Security\OAuth\McpCallBudget;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * The configured budgets, against the loop the server itself prescribes.
 *
 * `create_trip` returns at once and tells the agent to call `get_trip` until the days appear —
 * 10 to 45 seconds — then to drill into the days that matter (ADR-080). The limiters are read
 * from the container, so a later change to `rate_limiter.php` that would refuse that ordinary
 * task fails here rather than in an agent's hands.
 */
final class McpCallBudgetSizingTest extends KernelTestCase
{
    /** One write, fifteen polls, a drill-down into each of ten days, and room to spare. */
    #[Test]
    public function oneOrdinaryTaskFitsInTheCallBudget(): void
    {
        $calls = $this->limiter('limiter.mcp_tool_call');
        $key = McpCallBudget::key('sizing-ordinary@example.com', 'https://agent.example.com/client.json');

        foreach (range(1, 1 + 15 + 10) as $call) {
            self::assertTrue($calls->create($key)->consume()->isAccepted(), \sprintf('Call %d of an ordinary task was refused.', $call));
        }
    }

    /** And it is still a ceiling: a loop that never stops polling is refused within the minute. */
    #[Test]
    public function aRunawayLoopIsStoppedWithinTheMinute(): void
    {
        $calls = $this->limiter('limiter.mcp_tool_call');
        $key = McpCallBudget::key('sizing-runaway@example.com', 'https://agent.example.com/client.json');

        $accepted = 0;
        foreach (range(1, 200) as $ignored) {
            if ($calls->create($key)->consume()->isAccepted()) {
                ++$accepted;
            }
        }

        self::assertLessThanOrEqual(60, $accepted);
    }

    /**
     * The mutation budget must never undercut the older limiter behind `create_trip`: if it did,
     * `limiter.trip_create` could not be reached any more and its behaviour could not be tested.
     */
    #[Test]
    public function theMutationBudgetLeavesTripCreateReachable(): void
    {
        self::assertGreaterThan(
            $this->limitOf('limiter.trip_create', 'sizing-create@example.com'),
            $this->limitOf('limiter.mcp_mutation', 'sizing-mutation@example.com'),
        );
    }

    /** Nor the one behind `search_places`, for the same reason. */
    #[Test]
    public function theCallBudgetLeavesGeocodeReachable(): void
    {
        self::assertGreaterThan(
            $this->limitOf('limiter.geocode', 'sizing-geocode@example.com'),
            $this->limitOf('limiter.mcp_tool_call', 'sizing-call@example.com'),
        );
    }

    private function limiter(string $id): RateLimiterFactory
    {
        $limiter = self::getContainer()->get($id);
        self::assertInstanceOf(RateLimiterFactory::class, $limiter);

        return $limiter;
    }

    private function limitOf(string $id, string $key): int
    {
        return $this->limiter($id)->create($key)->consume(0)->getLimit();
    }
}
