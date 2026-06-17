<?php

declare(strict_types=1);

namespace App\Llm;

use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Bridge\Anthropic\Factory as AnthropicFactory;
use Symfony\AI\Platform\Bridge\Gemini\Factory as GeminiFactory;
use Symfony\AI\Platform\Bridge\OpenAi\Factory as OpenAiFactory;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Builds a provider-agnostic {@see PlatformLlmClient} at runtime from a user's
 * chosen provider + API token (ADR-042) — there is no single DI-wired platform,
 * since the token is per-user. Each provider's `symfony/ai-platform` bridge gets
 * the matching scoped HTTP client (timeout / User-Agent / SSRF scope).
 *
 * The user-aware `forUser(User)` entry point (decrypt token + map provider)
 * lands with the encrypted token storage; this class keeps the provider+token
 * construction it depends on.
 */
final readonly class LlmClientFactory
{
    public function __construct(
        private HttpClientInterface $anthropicClient,
        private HttpClientInterface $openAiClient,
        private HttpClientInterface $geminiClient,
        private LoggerInterface $logger,
    ) {
    }

    public function create(AiProvider $provider, #[\SensitiveParameter] string $token): LlmClientInterface
    {
        $platform = match ($provider) {
            AiProvider::ANTHROPIC => AnthropicFactory::createPlatform($token, $this->anthropicClient),
            AiProvider::OPENAI => OpenAiFactory::createPlatform($token, $this->openAiClient),
            AiProvider::GEMINI => GeminiFactory::createPlatform($token, $this->geminiClient),
        };

        return new PlatformLlmClient($platform, $this->logger);
    }
}
