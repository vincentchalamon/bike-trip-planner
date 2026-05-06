<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\ApiResource\Stage;
use App\ApiResource\TripRequest;
use App\Llm\Dto\TripAiOverview;
use App\Llm\Exception\OllamaUnavailableException;
use App\Llm\LlmClientInterface;
use App\Llm\SystemPromptLoader;
use App\Message\AnalyzeTripOverviewWithLlmMessage;
use App\Repository\TripRequestRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * LLaMA 8B pass-2: trip-level overview synthesis (issue #302).
 *
 * Consumes the per-stage briefings produced by {@see AnalyzeStageWithLlmHandler}
 * and asks the LLM to synthesise a global narrative covering cumulative fatigue,
 * cross-stage patterns and trip-level recommendations.
 *
 * Behaviour:
 * - When Ollama is disabled (feature flag off): the handler returns silently.
 * - When the LLM is unreachable: the {@see OllamaUnavailableException} is logged
 *   and swallowed. AI overview is best-effort enrichment, never blocking.
 * - When the response cannot be parsed: the handler logs and skips persistence.
 * - When NO stage has a pass-1 analysis yet (e.g. every per-stage handler failed
 *   or LLM was disabled): the handler skips, since there is nothing to summarise.
 * - Stages WITHOUT a pass-1 analysis are simply omitted from the input array;
 *   the LLM works with whatever subset is available.
 *
 * The orchestration of this handler (gate detection post-pass-1, automatic
 * dispatch, TRIP_READY emission) is the responsibility of issue #303 — this
 * class merely produces and persists the analysis.
 */
#[AsMessageHandler]
final readonly class AnalyzeTripOverviewWithLlmHandler
{
    public const string PROMPT_NAME = 'trip-overview';

    public const int PROMPT_VERSION = 1;

    public const int MAX_RESPONSE_TOKENS = 800;

    public const string DEFAULT_MODEL = 'llama3.1:8b';

    /**
     * Pass 2 takes ~2000-3000 tokens of input plus ~800 tokens of output, so a
     * 8K window leaves comfortable headroom for the system prompt.
     */
    public const int OVERVIEW_NUM_CTX = 8192;

    private string $model;

    public function __construct(
        private TripRequestRepositoryInterface $tripStateManager,
        private LlmClientInterface $llmClient,
        private SystemPromptLoader $promptLoader,
        private LoggerInterface $logger,
        #[Autowire(env: 'default::OLLAMA_OVERVIEW_MODEL')]
        ?string $model = null,
    ) {
        $this->model = (null === $model || '' === $model) ? self::DEFAULT_MODEL : $model;
    }

    public function __invoke(AnalyzeTripOverviewWithLlmMessage $message): void
    {
        if (!$this->llmClient->isEnabled()) {
            $this->logger->debug('LLM disabled — skipping trip overview synthesis.', [
                'tripId' => $message->tripId,
            ]);

            return;
        }

        $stages = $this->tripStateManager->getStages($message->tripId);
        if (null === $stages || [] === $stages) {
            $this->logger->info('No stages found for trip — skipping LLM trip overview.', [
                'tripId' => $message->tripId,
            ]);

            return;
        }

        $stageInputs = $this->buildStageInputs($stages);
        if ([] === $stageInputs) {
            $this->logger->info('No pass-1 stage analyses available — skipping LLM trip overview.', [
                'tripId' => $message->tripId,
            ]);

            return;
        }

        $request = $this->tripStateManager->getRequest($message->tripId);
        $variables = $this->buildPromptVariables($request);
        $systemPrompt = $this->promptLoader->load(self::PROMPT_NAME, $variables);

        $payload = [
            'rider_profile' => $this->buildRiderProfile($request),
            'stages' => $stageInputs,
        ];

        try {
            $userPrompt = json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
        } catch (\JsonException $exception) {
            $this->logger->warning('Failed to encode trip overview payload for LLM prompt.', [
                'tripId' => $message->tripId,
                'error' => $exception->getMessage(),
            ]);

            return;
        }

        try {
            $response = $this->llmClient->generate(
                model: $this->model,
                prompt: $userPrompt,
                systemPrompt: $systemPrompt,
                options: [
                    // The system prompt asks for a Markdown briefing — disable Ollama's JSON mode.
                    'format' => '',
                    'num_ctx' => self::OVERVIEW_NUM_CTX,
                    'num_predict' => self::MAX_RESPONSE_TOKENS,
                ],
            );
        } catch (OllamaUnavailableException $exception) {
            $this->logger->warning('Ollama unreachable — skipping trip overview synthesis.', [
                'tripId' => $message->tripId,
                'error' => $exception->getMessage(),
            ]);

            return;
        }

        if (null === $response) {
            return;
        }

        $rawText = $this->extractText($response);
        if (null === $rawText || '' === trim($rawText)) {
            $this->logger->warning('LLM returned an empty response for trip overview.', [
                'tripId' => $message->tripId,
            ]);

            return;
        }

        $overview = $this->parseMarkdownResponse($rawText);

        $this->tripStateManager->updateTripAiOverview(
            $message->tripId,
            $overview->toArray(),
        );

        $this->logger->info('LLM trip overview persisted for trip {tripId}.', [
            'tripId' => $message->tripId,
            'patterns' => \count($overview->patterns),
            'recommendations' => \count($overview->recommendations),
            'crossStageAlerts' => \count($overview->crossStageAlerts),
        ]);
    }

    /**
     * Builds the compact summary fed to the LLM: one entry per stage with the
     * key metrics + the pass-1 narrative (truncated). Rest days and stages
     * without a pass-1 analysis are skipped — pass 2 should not invent data.
     *
     * @param list<Stage> $stages
     *
     * @return list<array{stage_number: int, distance_km: float, elevation_gain_m: int, summary: string}>
     */
    private function buildStageInputs(array $stages): array
    {
        $inputs = [];

        foreach ($stages as $stage) {
            if ($stage->isRestDay) {
                continue;
            }

            $analysis = $stage->aiAnalysis;
            if (null === $analysis) {
                continue;
            }

            $summary = $this->composeStageSummary($analysis);
            if ('' === $summary) {
                continue;
            }

            $inputs[] = [
                'stage_number' => $stage->dayNumber,
                'distance_km' => round($stage->distance, 1),
                'elevation_gain_m' => (int) round($stage->elevation),
                'summary' => $summary,
            ];
        }

        return $inputs;
    }

    /**
     * Concatenates the pass-1 narrative + the most relevant insights/suggestions
     * into a single synthetic sentence-level summary. Mirrors the few-shot
     * example shape from the trip-overview system prompt.
     *
     * @param array{narrative?: string, insights?: list<string>, suggestions?: list<string>, model?: string, promptVersion?: int, generatedAt?: string} $analysis
     */
    private function composeStageSummary(array $analysis): string
    {
        $parts = [];

        $narrative = trim($analysis['narrative'] ?? '');
        if ('' !== $narrative) {
            $parts[] = $narrative;
        }

        $insights = $analysis['insights'] ?? [];
        if ([] !== $insights) {
            $parts[] = 'Insights: '.implode(' | ', $insights);
        }

        $suggestions = $analysis['suggestions'] ?? [];
        if ([] !== $suggestions) {
            $parts[] = 'Recommandations: '.implode(' | ', $suggestions);
        }

        return trim(implode(' ', $parts));
    }

    /**
     * @return array{type: string, fitness: string, ebike: bool, locale: string}
     */
    private function buildRiderProfile(?TripRequest $request): array
    {
        $ebike = (bool) $request?->ebikeMode;

        return [
            'type' => $ebike ? 'e-bike' : 'gravel',
            'fitness' => 'intermediate',
            'ebike' => $ebike,
            'locale' => $request?->locale ?? 'fr',
        ];
    }

    /**
     * Builds the placeholders for {@see SystemPromptLoader::load()}.
     *
     * @return array<string, scalar>
     */
    private function buildPromptVariables(?TripRequest $request): array
    {
        $language = $request?->locale ?? 'fr';
        $ebike = (bool) $request?->ebikeMode;
        $riderProfile = $ebike ? 'e-bike/VAE' : 'randonneur/bikepacker';

        $startDate = $request?->startDate;
        $date = $startDate instanceof \DateTimeImmutable ? $startDate->format('Y-m-d') : '';

        return [
            'region' => 'Europe',
            'rider_profile' => $riderProfile,
            'language' => $language,
            'date' => $date,
        ];
    }

    /**
     * @param array<string, mixed> $response Ollama API response (either /api/generate or /api/chat)
     */
    private function extractText(array $response): ?string
    {
        // /api/generate returns {"response": "...", "done": true, ...}
        if (isset($response['response']) && \is_string($response['response'])) {
            return $response['response'];
        }

        // /api/chat returns {"message": {"role": "assistant", "content": "..."}, ...}
        if (isset($response['message']) && \is_array($response['message'])) {
            $content = $response['message']['content'] ?? null;
            if (\is_string($content)) {
                return $content;
            }
        }

        return null;
    }

    /**
     * Parses a Markdown briefing with the four expected sections.
     *
     * Tolerant: when a section is missing or malformed, falls back to empty
     * arrays so the persisted DTO stays well-typed. Bullets ("- ...", "* ...")
     * are normalised into a list.
     *
     * Cross-stage alerts are derived from the patterns section: any bullet
     * containing keywords ("attention", "warning", "danger", "risque") is
     * additionally surfaced under crossStageAlerts.
     */
    private function parseMarkdownResponse(string $markdown): TripAiOverview
    {
        $sections = $this->splitMarkdownSections($markdown);

        $overview = trim($sections['vue densemble'] ?? $sections['vue d ensemble'] ?? $sections['vue ensemble'] ?? '');
        $fatigue = trim($sections['charge et fatigue cumulative'] ?? $sections['charge et fatigue'] ?? '');
        $patterns = $this->extractBullets($sections['patterns transversaux'] ?? '');
        $recommendations = $this->extractBullets($sections['recommandations globales'] ?? $sections['recommandations'] ?? '');
        $crossStageAlerts = $this->extractCrossStageAlerts($patterns);

        $narrativeParts = array_values(array_filter([$overview, $fatigue], static fn (string $part): bool => '' !== $part));
        $narrative = implode("\n\n", $narrativeParts);

        // Fallback: when both overview and fatigue sections are empty, use whatever
        // text the model emitted so the rider still gets something.
        if ('' === $narrative && [] === $patterns && [] === $recommendations) {
            $narrative = trim($markdown);
        }

        return new TripAiOverview(
            narrative: $narrative,
            patterns: $patterns,
            recommendations: $recommendations,
            crossStageAlerts: $crossStageAlerts,
            model: $this->model,
            promptVersion: self::PROMPT_VERSION,
            generatedAt: (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DATE_ATOM),
        );
    }

    /**
     * @param list<string> $patterns
     *
     * @return list<string>
     */
    private function extractCrossStageAlerts(array $patterns): array
    {
        $alerts = [];
        foreach ($patterns as $pattern) {
            $lower = mb_strtolower($pattern);
            if (
                str_contains($lower, 'attention')
                || str_contains($lower, 'warning')
                || str_contains($lower, 'danger')
                || str_contains($lower, 'risque')
                || str_contains($lower, 'alerte')
            ) {
                $alerts[] = $pattern;
            }
        }

        return array_values($alerts);
    }

    /**
     * Splits a Markdown response into normalised section keys. Any heading
     * level is accepted (#, ##, ###).
     *
     * @return array<string, string>
     */
    private function splitMarkdownSections(string $markdown): array
    {
        $sections = [];
        $currentKey = null;
        $buffer = [];

        foreach (preg_split('/\R/', $markdown) ?: [] as $line) {
            if (preg_match('/^\s*#+\s*(.+?)\s*$/u', $line, $matches)) {
                if (null !== $currentKey) {
                    $sections[$currentKey] = implode("\n", $buffer);
                }
                $currentKey = $this->normaliseHeading($matches[1]);
                $buffer = [];

                continue;
            }

            if (null !== $currentKey) {
                $buffer[] = $line;
            }
        }

        if (null !== $currentKey) {
            $sections[$currentKey] = implode("\n", $buffer);
        }

        return $sections;
    }

    private function normaliseHeading(string $heading): string
    {
        $lower = mb_strtolower(trim($heading));
        // Drop common French diacritics and apostrophes so "Vue d'ensemble" → "vue densemble"
        // and "Recommandations globales" stays "recommandations globales".
        $stripped = strtr($lower, [
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n', 'ÿ' => 'y',
        ]);

        return str_replace(["'", '’'], '', $stripped);
    }

    /**
     * @return list<string>
     */
    private function extractBullets(string $section): array
    {
        $bullets = [];
        $current = null;

        foreach (preg_split('/\R/', $section) ?: [] as $line) {
            if (preg_match('/^\s*[-*•]\s+(.*)$/u', $line, $matches)) {
                if (null !== $current) {
                    $bullets[] = trim($current);
                }
                $current = $matches[1];

                continue;
            }

            if (null === $current) {
                continue;
            }

            $trimmed = trim($line);
            if ('' === $trimmed) {
                $bullets[] = trim($current);
                $current = null;

                continue;
            }

            // Continuation line of the previous bullet.
            $current .= ' '.$trimmed;
        }

        if (null !== $current) {
            $bullets[] = trim($current);
        }

        return array_values(array_filter($bullets, static fn (string $b): bool => '' !== $b));
    }
}
