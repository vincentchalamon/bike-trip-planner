<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Llm\ResolvedLlmClient;
use App\ApiResource\Stage;
use App\ApiResource\TripRequest;
use App\Enum\ComputationName;
use App\Llm\Dto\StageAiAnalysis;
use App\Llm\Exception\AiUnavailableException;
use App\Llm\LlmAnalysisTrackerInterface;
use App\Llm\StageAnalysisSummaryBuilder;
use App\Llm\SystemPromptLoader;
use App\Llm\TripLlmResolverInterface;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\AnalyzeStageWithLlmMessage;
use App\Message\AnalyzeTripOverviewWithLlmMessage;
use App\Repository\TripRequestRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * LLaMA 8B pass-1: per-stage analysis (issue #301).
 *
 * Each {@see AnalyzeStageWithLlmMessage} produces a narrative briefing for a single
 * stage. Messages are dispatched in parallel by
 * {@see AllEnrichmentsCompletedHandler} so the 5-worker pool can pipeline them.
 *
 * Behaviour:
 * - When the trip owner has not configured an AI provider: the handler returns
 *   silently.
 * - When the LLM is unreachable: the {@see AiUnavailableException} is logged and
 *   swallowed. AI analysis is best-effort enrichment, never blocking.
 * - When the response cannot be parsed: the handler logs and skips persistence.
 */
#[AsMessageHandler]
final readonly class AnalyzeStageWithLlmHandler
{
    public const string PROMPT_NAME = 'stage-analysis';

    public const int PROMPT_VERSION = 1;

    public function __construct(
        private TripRequestRepositoryInterface $tripStateManager,
        private TripLlmResolverInterface $llmResolver,
        private SystemPromptLoader $promptLoader,
        private StageAnalysisSummaryBuilder $summaryBuilder,
        private LoggerInterface $logger,
        private LlmAnalysisTrackerInterface $llmTracker,
        private MessageBusInterface $messageBus,
        private TripUpdatePublisherInterface $publisher,
    ) {
    }

    public function __invoke(AnalyzeStageWithLlmMessage $message): void
    {
        $resolved = $this->llmResolver->resolveForTrip($message->tripId);
        if (!$resolved instanceof ResolvedLlmClient) {
            $this->logger->debug('AI not configured for trip owner — skipping stage analysis.', [
                'tripId' => $message->tripId,
                'dayNumber' => $message->dayNumber,
            ]);

            return;
        }

        $stages = $this->tripStateManager->getStages($message->tripId);
        if (null === $stages) {
            $this->logger->info('Stages not found for trip — skipping LLM stage analysis.', [
                'tripId' => $message->tripId,
                'dayNumber' => $message->dayNumber,
            ]);
            $this->settle($message->tripId, success: false);

            return;
        }

        $stage = $this->findStage($stages, $message->dayNumber);
        if (!$stage instanceof Stage) {
            $this->logger->info('Stage {dayNumber} not found for trip — skipping LLM stage analysis.', [
                'tripId' => $message->tripId,
                'dayNumber' => $message->dayNumber,
            ]);
            $this->settle($message->tripId, success: false);

            return;
        }

        if ($stage->isRestDay) {
            $this->logger->debug('Skipping LLM analysis for rest day.', [
                'tripId' => $message->tripId,
                'dayNumber' => $message->dayNumber,
            ]);

            // Rest days are not counted in the tracker total — do not settle here,
            // the dispatcher already excluded them.
            return;
        }

        $request = $this->tripStateManager->getRequest($message->tripId);
        $variables = $this->buildPromptVariables($request, $stage);
        $systemPrompt = $this->promptLoader->load(self::PROMPT_NAME, $variables);

        $summary = $this->summaryBuilder->build($stage);

        try {
            $userPrompt = json_encode($summary, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
        } catch (\JsonException $jsonException) {
            $this->logger->warning('Failed to encode stage summary for LLM prompt.', [
                'tripId' => $message->tripId,
                'dayNumber' => $message->dayNumber,
                'error' => $jsonException->getMessage(),
            ]);
            $this->settle($message->tripId, success: false);

            return;
        }

        $model = $resolved->provider->analysisModel();

        try {
            // Options are intentionally left to provider defaults: provider-specific knobs
            // (format, num_predict) are not portable across cloud providers.
            $response = $resolved->client->generate(
                model: $model,
                prompt: $userPrompt,
                systemPrompt: $systemPrompt,
            );
        } catch (AiUnavailableException $aiUnavailableException) {
            $this->logger->critical('AI provider unreachable — skipping stage analysis.', [
                'tripId' => $message->tripId,
                'dayNumber' => $message->dayNumber,
                'reason' => $aiUnavailableException->getReason()->value,
                'error' => $aiUnavailableException->getMessage(),
            ]);
            $this->settle($message->tripId, success: false);

            return;
        }

        if (null === $response) {
            $this->settle($message->tripId, success: false);

            return;
        }

        $rawText = $this->extractText($response);
        if (null === $rawText || '' === trim($rawText)) {
            $this->logger->warning('LLM returned an empty response for stage analysis.', [
                'tripId' => $message->tripId,
                'dayNumber' => $message->dayNumber,
            ]);
            $this->settle($message->tripId, success: false);

            return;
        }

        $analysis = $this->parseMarkdownResponse($rawText, $model);

        $this->tripStateManager->updateStageAiAnalysis(
            $message->tripId,
            $stage->dayNumber,
            $analysis->toArray(),
        );

        $this->logger->info('LLM stage analysis persisted for trip {tripId} stage {dayNumber}.', [
            'tripId' => $message->tripId,
            'dayNumber' => $stage->dayNumber,
            'insights' => \count($analysis->insights),
            'suggestions' => \count($analysis->suggestions),
        ]);

        $this->settle($message->tripId, success: true);
    }

    /**
     * Settles one pass-1 slot on the LLM tracker, emits the AI progress event,
     * and — when every pass-1 has settled — claims the pass-2 dispatch slot
     * and queues the trip-overview message.
     *
     * Settling is intentionally idempotent at the dispatch level: if two
     * concurrent workers race the final increment, only one will win the
     * NX `claimOverviewDispatch()` call.
     */
    private function settle(string $tripId, bool $success): void
    {
        $progress = $this->llmTracker->markStageAnalysisSettled($tripId, $success);

        // Total = pass-1 stages + 1 pass-2 overview. Progress published as steps
        // so the AI category mirrors the regular pipeline progress bar.
        $totalSteps = $progress['total'] + 1;
        $this->publisher->publishComputationStepCompleted(
            $tripId,
            ComputationName::STAGE_AI_ANALYSIS,
            completed: $progress['completed'],
            total: $totalSteps,
            failed: $progress['failed'],
        );

        $allSettled = $progress['total'] > 0
            && $progress['completed'] + $progress['failed'] === $progress['total'];

        if (!$allSettled) {
            return;
        }

        if (!$this->llmTracker->claimOverviewDispatch($tripId)) {
            $this->logger->debug('Trip overview already dispatched for trip {tripId} — skipping duplicate.', [
                'tripId' => $tripId,
            ]);

            return;
        }

        $this->logger->info('All pass-1 LLM analyses settled for trip {tripId} — dispatching pass-2.', [
            'tripId' => $tripId,
            'completed' => $progress['completed'],
            'failed' => $progress['failed'],
            'total' => $progress['total'],
        ]);

        $this->messageBus->dispatch(new AnalyzeTripOverviewWithLlmMessage($tripId));
    }

    /**
     * @param list<Stage> $stages
     */
    private function findStage(array $stages, int $dayNumber): ?Stage
    {
        foreach ($stages as $stage) {
            if ($stage->dayNumber === $dayNumber) {
                return $stage;
            }
        }

        return null;
    }

    /**
     * Builds the placeholders for {@see SystemPromptLoader::load()}.
     *
     * @return array<string, scalar>
     */
    private function buildPromptVariables(?TripRequest $request, Stage $stage): array
    {
        if (!$request instanceof TripRequest) {
            return [
                'region' => 'Europe',
                'rider_profile' => 'randonneur/bikepacker',
                'language' => 'fr',
                'date' => '',
            ];
        }

        $riderProfile = $request->ebikeMode ? 'e-bike/VAE' : 'randonneur/bikepacker';

        $date = '';
        if ($request->startDate instanceof \DateTimeImmutable) {
            $stageDate = $request->startDate->modify(\sprintf('+%d days', max(0, $stage->dayNumber - 1)));
            $date = $stageDate->format('Y-m-d');
        }

        return [
            'region' => 'Europe',
            'rider_profile' => $riderProfile,
            'language' => $request->locale,
            'date' => $date,
        ];
    }

    /**
     * @param array<string, mixed> $response provider response envelope (generate- or chat-shaped)
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
     * Parses a Markdown briefing with the three expected sections.
     *
     * Tolerant: when a section is missing or malformed, falls back to empty arrays
     * so the persisted DTO stays well-typed. Bullets ("- ...", "* ...") are normalised
     * into a list; the narrative section is stripped of leading/trailing whitespace.
     */
    private function parseMarkdownResponse(string $markdown, string $model): StageAiAnalysis
    {
        $sections = $this->splitMarkdownSections($markdown);

        $narrative = trim($sections['synthese'] ?? '');
        $insights = $this->extractBullets($sections['insights'] ?? '');
        $suggestions = $this->extractBullets($sections['recommandations'] ?? '');

        // When the model ignores section headings entirely, fall back to using the whole text
        // as the narrative so the rider still sees something.
        if ('' === $narrative && [] === $insights && [] === $suggestions) {
            $narrative = trim($markdown);
        }

        return new StageAiAnalysis(
            narrative: $narrative,
            insights: $insights,
            suggestions: $suggestions,
            model: $model,
            promptVersion: self::PROMPT_VERSION,
            generatedAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC'))->format(\DATE_ATOM),
        );
    }

    /**
     * Splits a Markdown response into normalised section keys ("synthese", "insights",
     * "recommandations"). Any heading level is accepted (#, ##, ###).
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

        // Drop common French diacritics so "Synthèse" → "synthese" and "Recommandations" → "recommandations".
        return strtr($lower, [
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n', 'ÿ' => 'y',
        ]);
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
