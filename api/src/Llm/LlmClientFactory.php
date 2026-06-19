<?php

declare(strict_types=1);

namespace App\Llm;

use App\Entity\User;
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
 */
final readonly class LlmClientFactory implements UserLlmResolverInterface
{
    public function __construct(
        private HttpClientInterface $anthropicClient,
        private HttpClientInterface $openAiClient,
        private HttpClientInterface $geminiClient,
        private LoggerInterface $logger,
        private AiTokenEncryptor $tokenEncryptor,
    ) {
    }

    /**
     * Returns the user's client paired with its provider, or null when AI is not
     * configured (no provider/token), the provider is unknown, or the stored
     * token cannot be decrypted (e.g. key rotation) — so callers degrade cleanly.
     * Availability is decided here, per-user; there is no instance-wide env toggle.
     */
    public function forUser(User $user): ?ResolvedLlmClient
    {
        $provider = AiProvider::tryFrom((string) $user->getAiProvider());
        $encrypted = $user->getAiToken();
        if (null === $provider || null === $encrypted) {
            return null;
        }

        $token = $this->tokenEncryptor->decrypt($encrypted);
        if (null === $token || '' === $token) {
            return null;
        }

        return new ResolvedLlmClient($this->create($provider, $token), $provider);
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
