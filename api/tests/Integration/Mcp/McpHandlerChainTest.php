<?php

declare(strict_types=1);

namespace App\Tests\Integration\Mcp;

use ApiPlatform\Mcp\Server\Handler;
use App\Security\OAuth\McpCallBudget;
use App\Security\OAuth\McpScopeGuard;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The order the SDK meets the two guards in, read from the container that production builds.
 *
 * Scope first, then budget, then the tools. The other way round, a spent budget would hide a
 * missing scope — the agent would be told to wait for a permission that waiting cannot grant —
 * and a call the token may not make at all would spend what it may. The order is decided by
 * decorator priorities in `services.php`, where the lowest is the outermost: easy to invert
 * without noticing, which is what this is for.
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

        self::assertInstanceOf(Handler::class, $this->inner($middle), 'Nothing else may sit between the budget and the tools.');
    }

    private function inner(object $decorator): mixed
    {
        return new \ReflectionProperty($decorator, 'inner')->getValue($decorator);
    }
}
