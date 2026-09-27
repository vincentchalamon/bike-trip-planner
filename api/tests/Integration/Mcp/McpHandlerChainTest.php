<?php

declare(strict_types=1);

namespace App\Tests\Integration\Mcp;

use ApiPlatform\Mcp\Server\Handler;
use App\Security\OAuth\McpCallBudget;
use App\Security\OAuth\McpGrantUsage;
use App\Security\OAuth\McpScopeGuard;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The order the SDK meets the three decorators in, read from the container that production builds.
 *
 * Scope, budget, usage, then the tools. Each position is a decision:
 *
 *  - **scope outermost**: the other way round, a spent budget would hide a missing scope — the
 *    agent would be told to wait for a permission that waiting cannot grant — and a call the
 *    token may not make at all would spend what it may;
 *  - **usage innermost**: it records that an application did something, so it must only see the
 *    calls that were allowed and paid for. Outside the budget, a refused call would show up on
 *    the account screen as use.
 *
 * The order is decided by decorator priorities in `services.php`, where the lowest is the
 * outermost: easy to invert without noticing, which is what this is for.
 */
final class McpHandlerChainTest extends KernelTestCase
{
    #[Test]
    public function theScopeIsJudgedBeforeTheBudgetIsSpent(): void
    {
        $outer = self::getContainer()->get('api_platform.mcp.handler');
        self::assertInstanceOf(McpScopeGuard::class, $outer, 'The scope guard must be what the SDK calls.');

        $middle = $this->inner($outer);
        self::assertInstanceOf(McpCallBudget::class, $middle, 'The budget must sit inside the scope guard.');

        $inner = $this->inner($middle);
        self::assertInstanceOf(McpGrantUsage::class, $inner, 'Usage is only recorded for a call the budget allowed.');

        self::assertInstanceOf(Handler::class, $this->inner($inner), 'Nothing else may sit between the decorators and the tools.');
    }

    private function inner(object $decorator): mixed
    {
        return new \ReflectionProperty($decorator, 'inner')->getValue($decorator);
    }
}
