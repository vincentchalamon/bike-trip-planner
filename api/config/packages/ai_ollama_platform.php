<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $containerConfigurator): void {
    // The AI bundle builds a single `ai.platform.ollama` from this config; we bind it
    // to the chat-scoped HTTP client. A second, analysis-scoped Platform + OllamaClient
    // is wired manually in services.php (issue #564). Both clients default to the same
    // Ollama instance in beta, so behaviour is unchanged.
    $containerConfigurator->extension('ai', [
        'platform' => [
            'ollama' => [
                'http_client' => 'ollama_chat.client',
            ],
        ],
    ]);
};
