<?php

declare(strict_types=1);

namespace App\Tests\Integration\State;

use ApiPlatform\State\ProcessorInterface;
use App\State\Mcp\McpConfirmationProcessor;
use App\State\PreconditionProcessor;
use App\State\TripLockProcessor;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * That every write guard is in every write chain, in the same order.
 *
 * There are two chains, which is the whole reason this test exists: `api-platform/mcp` builds a
 * `WriteProcessor` of its own rather than reusing the HTTP one, so a decorator declared against
 * `api_platform.state_processor.write` guards HTTP and nothing else. That was not a theory — a
 * tool call revoked a share link on the request that was only supposed to describe what
 * revoking would do, because the confirmation short circuit was not in the chain it ran
 * through. The lock and the precondition were missing from it too, which means a started trip
 * and a stale version were being accepted on that transport.
 *
 * The order is asserted as well, and it is the counter-intuitive one. `DecoratorServicePass`
 * hands the decorated service's id to the decorator it processes LAST, so the lowest priority
 * ends up outermost and runs first: lock, then precondition, then confirmation. Reversed, a
 * confirmation token would be minted for a call a lock or a stale version was about to refuse.
 */
final class WriteChainGuardsTest extends KernelTestCase
{
    /** In the order they must run. */
    private const array GUARDS = [
        TripLockProcessor::class,
        PreconditionProcessor::class,
        McpConfirmationProcessor::class,
    ];

    #[Test]
    public function theHttpChainRunsEveryGuardInOrder(): void
    {
        self::assertSame(self::GUARDS, $this->guardsOf('api_platform.state_processor.write'));
    }

    #[Test]
    public function theMcpChainRunsTheSameGuardsInTheSameOrder(): void
    {
        self::assertSame(self::GUARDS, $this->guardsOf('api_platform.mcp.state_processor.write'));
    }

    /**
     * @return list<class-string>
     */
    private function guardsOf(string $serviceId): array
    {
        self::bootKernel();

        $processor = self::getContainer()->get($serviceId);
        self::assertInstanceOf(ProcessorInterface::class, $processor);

        $found = [];

        for ($current = $processor; $current instanceof ProcessorInterface; $current = $this->inner($current)) {
            if (\in_array($current::class, self::GUARDS, true)) {
                $found[] = $current::class;
            }
        }

        return $found;
    }

    /**
     * The decorated processor a decorator holds, whatever it called the property.
     *
     * @param ProcessorInterface<mixed, mixed> $processor
     *
     * @return ProcessorInterface<mixed, mixed>|null
     */
    private function inner(ProcessorInterface $processor): ?ProcessorInterface
    {
        foreach (new \ReflectionClass($processor)->getProperties() as $property) {
            $value = $property->getValue($processor);

            if ($value instanceof ProcessorInterface && $value !== $processor) {
                return $value;
            }
        }

        return null;
    }
}
