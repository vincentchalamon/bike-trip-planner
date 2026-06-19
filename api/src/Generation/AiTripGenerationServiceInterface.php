<?php

declare(strict_types=1);

namespace App\Generation;

use App\Llm\Exception\AiUnavailableException;
use App\Llm\ResolvedLlmClient;

interface AiTripGenerationServiceInterface
{
    /**
     * @throws AiUnavailableException when the provider is unreachable
     */
    public function generate(string $brief, ResolvedLlmClient $resolved, string $locale = 'fr'): AiGeneratedRoute;
}
