<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Llm\TripLlmResolver;
use App\Llm\TripLlmResolverInterface;
use App\MessageHandler\AllEnrichmentsCompletedHandler;
use App\MessageHandler\AnalyzeStageWithLlmHandler;
use App\MessageHandler\AnalyzeTripOverviewWithLlmHandler;
use PHPUnit\Framework\Attributes\Test;

/**
 * Guards the per-user analysis wiring (ADR-042): the async handlers resolve the
 * LLM client from the trip owner via {@see TripLlmResolverInterface}, not from a
 * global LLM client. A silent fallback to a different collaborator would skip
 * AI analysis with no compile error and no other test failure.
 */
final class LlmClientWiringTest extends ApiTestCase
{
    #[Test]
    public function analysisHandlersResolveTheClientPerTripOwner(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        self::assertInstanceOf(TripLlmResolver::class, $container->get(TripLlmResolverInterface::class));

        $handlerIds = [
            AnalyzeStageWithLlmHandler::class,
            AnalyzeTripOverviewWithLlmHandler::class,
            AllEnrichmentsCompletedHandler::class,
        ];

        foreach ($handlerIds as $handlerId) {
            $handler = $container->get($handlerId);
            $injected = new \ReflectionProperty($handler, 'llmResolver')->getValue($handler);
            self::assertInstanceOf(TripLlmResolverInterface::class, $injected, \sprintf('%s must receive the per-trip LLM resolver.', $handlerId));
        }
    }
}
