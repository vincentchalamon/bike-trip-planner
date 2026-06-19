<?php

declare(strict_types=1);

use App\Llm\AiTokenEncryptor;
use App\Llm\LlmClientFactory;
use App\Mercure\NullTripUpdatePublisher;
use App\Mercure\TripUpdatePublisher;
use App\Mercure\TripUpdatePublisherInterface;
use App\Repository\RedisTripRequestRepository;
use App\Repository\TripRequestRepositoryInterface;
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
        ->set('default_ai_token_enc_key', 'dev-only-ai-token-encryption-key-change-in-prod')
        // Instance-wide AI kill-switch (ADR-042): on by default; set AI_ENABLED=0 to
        // hide every AI feature on a deployment regardless of per-user configuration.
        ->set('app.ai_enabled', '%env(bool:default:default_ai_enabled:AI_ENABLED)%')
        ->set('default_ai_enabled', true);

    $services = $containerConfigurator->services();

    $services->defaults()
        ->autowire()
        ->autoconfigure();

    $services->load('App\\', __DIR__.'/../src/');

    // Per-user multi-provider client factory (ADR-042): the 3 HttpClientInterface
    // args are ambiguous for autowiring, so bind each provider's scoped client.
    // The AI_ENABLED kill-switch is autowired from the app.ai_enabled parameter.
    $services->set(LlmClientFactory::class)
        ->args([
            service('anthropic.client'),
            service('openai.client'),
            service('gemini.client'),
            service('logger'),
            service(AiTokenEncryptor::class),
        ]);

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
