<?php

declare(strict_types=1);

use App\Llm\AiTokenEncryptor;
use App\Llm\LlmClientFactory;
use App\Llm\LlmClientInterface;
use App\Llm\OllamaClient;
use App\Mercure\NullTripUpdatePublisher;
use App\Mercure\TripUpdatePublisher;
use App\Mercure\TripUpdatePublisherInterface;
use App\MessageHandler\AnalyzeStageWithLlmHandler;
use App\MessageHandler\AnalyzeTripOverviewWithLlmHandler;
use App\Repository\RedisTripRequestRepository;
use App\Repository\TripRequestRepositoryInterface;
use Symfony\AI\Platform\Bridge\Ollama\Factory as OllamaPlatformFactory;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $containerConfigurator): void {
    $containerConfigurator->parameters()
        ->set('app.commit_sha', '%env(default:default_commit_sha:APP_COMMIT)%')
        ->set('default_commit_sha', 'unknown')
        // AI token encryption key (ADR-042). PRODUCTION MUST set AI_TOKEN_ENC_KEY;
        // the dev/CI default below only keeps the container bootable (throwaway dev
        // tokens, never used to protect real credentials).
        ->set('app.ai_token_enc_key', '%env(default:default_ai_token_enc_key:AI_TOKEN_ENC_KEY)%')
        ->set('default_ai_token_enc_key', 'dev-only-ai-token-encryption-key-change-in-prod');

    $services = $containerConfigurator->services();

    $services->defaults()
        ->autowire()
        ->autoconfigure();

    $services->load('App\\', __DIR__.'/../src/');

    // Split Ollama analysis vs chat at the client level (issue #564). The chat
    // OllamaClient reuses the bundle-built `ai.platform.ollama` platform (bound to
    // `ollama_chat.client` in ai_ollama_platform.php); the analysis OllamaClient gets
    // its own Platform built on the `ollama_analysis.client` scoped HTTP client. Both
    // clients default to the same Ollama instance in beta, so behaviour is unchanged.
    $services->set('app.llm.ollama_platform.analysis', PlatformInterface::class)
        ->factory([OllamaPlatformFactory::class, 'createPlatform'])
        ->lazy()
        ->args([
            null,
            null,
            service('ollama_analysis.client'),
            service('ai.platform.contract.ollama'),
            service('event_dispatcher'),
        ]);

    // Chat client: default LlmClientInterface (in-ride assistant, POI intent, chat).
    $services->set(OllamaClient::class)
        ->args([
            service(PlatformInterface::class),
            service('logger'),
            '%env(bool:default::OLLAMA_ENABLED)%',
        ]);

    $services->set('app.llm.ollama_client.analysis', OllamaClient::class)
        ->args([
            service('app.llm.ollama_platform.analysis'),
            service('logger'),
            '%env(bool:default::OLLAMA_ENABLED)%',
        ]);

    $services->alias(LlmClientInterface::class, OllamaClient::class);

    // Per-user multi-provider client factory (ADR-042): the 3 HttpClientInterface
    // args are ambiguous for autowiring, so bind each provider's scoped client.
    $services->set(LlmClientFactory::class)
        ->args([
            service('anthropic.client'),
            service('openai.client'),
            service('gemini.client'),
            service('logger'),
            service(AiTokenEncryptor::class),
        ]);

    // Async analysis handlers (pass-1 per stage, pass-2 overview) use the analysis client.
    $services->get(AnalyzeStageWithLlmHandler::class)
        ->arg('$llmClient', service('app.llm.ollama_client.analysis'));
    $services->get(AnalyzeTripOverviewWithLlmHandler::class)
        ->arg('$llmClient', service('app.llm.ollama_client.analysis'));

    if ('test' === $containerConfigurator->env()) {
        $services->alias(TripUpdatePublisherInterface::class, NullTripUpdatePublisher::class);
        // Use Redis-backed repository in tests (no database available in PHPUnit).
        // TODO: add Foundry-based KernelTestCase integration tests with a real test database
        // to cover JSONB round-trips, UUID handling, and migration correctness (#56).
        $services->alias(TripRequestRepositoryInterface::class, RedisTripRequestRepository::class);
    } else {
        $services->alias(TripUpdatePublisherInterface::class, TripUpdatePublisher::class);
    }
};
