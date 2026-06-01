<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Llm\LlmClientInterface;
use App\Llm\OllamaClient;
use App\MessageHandler\AnalyzeStageWithLlmHandler;
use App\MessageHandler\AnalyzeTripOverviewWithLlmHandler;
use PHPUnit\Framework\Attributes\Test;

/**
 * Guards the DI override that wires the analysis-scoped Ollama client into the
 * async analysis handlers (#564). Without this, renaming the `$llmClient`
 * constructor arg would silently fall back to the default chat-aliased client
 * and analysis would hit the chat endpoint once OLLAMA_ANALYSIS_URL diverges —
 * no compile error, no other test failure.
 */
final class LlmClientWiringTest extends ApiTestCase
{
    #[Test]
    public function analyzeHandlersReceiveTheAnalysisScopedClient(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $analysisClient = $container->get('app.llm.ollama_client.analysis');
        $chatClient = $container->get(LlmClientInterface::class);

        self::assertInstanceOf(OllamaClient::class, $analysisClient);
        self::assertNotSame($chatClient, $analysisClient, 'Analysis and chat clients must be distinct instances.');

        foreach ([AnalyzeStageWithLlmHandler::class, AnalyzeTripOverviewWithLlmHandler::class] as $handlerId) {
            $handler = $container->get($handlerId);
            $injected = new \ReflectionProperty($handler, 'llmClient')->getValue($handler);
            self::assertSame($analysisClient, $injected, \sprintf('%s must receive the analysis-scoped client.', $handlerId));
        }
    }
}
