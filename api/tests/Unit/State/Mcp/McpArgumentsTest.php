<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Mcp;

use App\State\Mcp\McpArguments;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class McpArgumentsTest extends TestCase
{
    #[Test]
    public function anHttpContextYieldsNoArguments(): void
    {
        self::assertSame([], McpArguments::from(['filters' => ['title' => 'x']])->body());
    }

    #[Test]
    public function controlArgumentsAreKeptOutOfTheBody(): void
    {
        $arguments = McpArguments::from(['mcp_data' => [
            'id' => 'a-trip',
            'title' => 'Vercors',
            'version' => 7,
            'idempotencyKey' => 'k',
            'confirmationToken' => 't',
        ]]);

        self::assertSame(['id' => 'a-trip', 'title' => 'Vercors'], $arguments->body());
        self::assertSame(7, $arguments->control('version'));
        self::assertSame('k', $arguments->control('idempotencyKey'));
        self::assertSame('t', $arguments->control('confirmationToken'));
    }

    #[Test]
    public function anAbsentControlArgumentIsNull(): void
    {
        self::assertNull(McpArguments::from(['mcp_data' => []])->control('version'));
    }

    /**
     * The bridge that makes a collection operation work on both transports: `Pagination` and
     * the collection providers both read `$context['filters']`, so filling it from the tool's
     * arguments is the whole of what a tool call needs to paginate and filter.
     */
    #[Test]
    public function toolArgumentsBecomeFilters(): void
    {
        self::assertSame(
            ['page' => 2, 'itemsPerPage' => 5, 'title' => 'Vercors'],
            McpArguments::filters(['mcp_data' => ['page' => 2, 'itemsPerPage' => 5, 'title' => 'Vercors']]),
        );
    }

    #[Test]
    public function httpFiltersAreLeftAloneWhenThereAreNoArguments(): void
    {
        self::assertSame(['title' => 'Vercors'], McpArguments::filters(['filters' => ['title' => 'Vercors']]));
    }

    /**
     * A control argument is not a filter either: `version` must not reach a query builder any
     * more than it reaches a denormalizer.
     */
    #[Test]
    public function controlArgumentsAreNotFilters(): void
    {
        self::assertSame(['page' => 2], McpArguments::filters(['mcp_data' => ['page' => 2, 'version' => 7]]));
    }

    /**
     * Nothing obliges an agent to re-emit its JSON keys in the order it used the first time.
     * Without canonicalisation a legitimate retry hashes differently, so the creation answers
     * 409 "already used for a different request body" and a valid confirmation token is
     * refused.
     */
    #[Test]
    public function keyOrderDoesNotChangeTheCanonicalForm(): void
    {
        $one = McpArguments::from(['mcp_data' => ['b' => 1, 'a' => ['d' => 4, 'c' => 3]]])->canonical();
        $other = McpArguments::from(['mcp_data' => ['a' => ['c' => 3, 'd' => 4], 'b' => 1]])->canonical();

        self::assertSame($one, $other);
        self::assertSame(json_encode($one), json_encode($other));
    }

    /**
     * But a list is ordered by intent — the stages of an edit, the points of a route — so its
     * order is data. Sorting it would make two different requests hash alike.
     */
    #[Test]
    public function listOrderIsData(): void
    {
        $one = McpArguments::from(['mcp_data' => ['stages' => ['b', 'a']]])->canonical();
        $other = McpArguments::from(['mcp_data' => ['stages' => ['a', 'b']]])->canonical();

        self::assertNotSame(json_encode($one), json_encode($other));
    }
}
