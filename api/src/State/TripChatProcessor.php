<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\TripChatRequest;
use App\ApiResource\TripChatResponse;
use App\Entity\User;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Llm\ChatActionInterpreter;
use App\Llm\ChatHistoryStore;
use App\Llm\Dto\ChatAction;
use App\Llm\Exception\OllamaUnavailableException;
use App\Llm\LlmClientInterface;
use App\Llm\SystemPromptLoader;
use App\Message\RecalculateStages;
use App\Repository\TripRequestRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Handles `POST /trips/{id}/chat`: orchestrates the LLaMA 3B dialogue assistant.
 *
 * Pipeline:
 * 1. Enforce a per-user rate limit (20 req/min) to protect the local LLM.
 * 2. Verify the trip exists; load minimal context for the dialogue prompt.
 * 3. Build the chat history (last {@see ChatHistoryStore::MAX_MESSAGES} turns)
 *    plus the new user message, and call {@see LlmClientInterface::chat()}.
 * 4. Parse the JSON envelope into a {@see ChatAction} via {@see ChatActionInterpreter}.
 * 5. Flag the response as `dispatched` for actions that require recomputation
 *    (the actual Messenger wiring is delivered by a follow-up issue).
 *
 * When the LLM is disabled or unreachable, the endpoint returns 503 so the
 * frontend can show a clear error instead of a vague fallback message.
 *
 * @implements ProcessorInterface<TripChatRequest, TripChatResponse>
 */
final readonly class TripChatProcessor implements ProcessorInterface
{
    public const string PROMPT_NAME = 'dialogue';

    public const string DEFAULT_MODEL = 'llama3.2:3b';

    public const int MAX_RESPONSE_TOKENS = 600;

    /**
     * Actions that mutate the trip and therefore require backend recomputation.
     *
     * Each non-info, non-change_route action is mapped to a {@see RecalculateStages}
     * dispatch (with `skipAiAnalysis: true`) by {@see self::dispatchRecomputation()}.
     *
     * @var list<string>
     */
    private const array RECOMPUTE_ACTIONS = [
        ChatAction::ACTION_SPLIT_STAGE,
        ChatAction::ACTION_MERGE_STAGES,
        ChatAction::ACTION_ADD_WAYPOINT,
        ChatAction::ACTION_CHANGE_ACCOMMODATION,
        ChatAction::ACTION_ADJUST_DISTANCE,
    ];

    private string $model;

    public function __construct(
        private TripRequestRepositoryInterface $tripStateManager,
        private LlmClientInterface $llmClient,
        private SystemPromptLoader $promptLoader,
        private ChatActionInterpreter $interpreter,
        private ChatHistoryStore $historyStore,
        private Security $security,
        private LoggerInterface $logger,
        private MessageBusInterface $messageBus,
        private TripGenerationTrackerInterface $generationTracker,
        #[Autowire(service: 'limiter.trip_chat')]
        private RateLimiterFactory $tripChatLimiter,
        #[Autowire(env: 'default::OLLAMA_DIALOGUE_MODEL')]
        ?string $model = null,
    ) {
        $this->model = (null === $model || '' === $model) ? self::DEFAULT_MODEL : $model;
    }

    /**
     * @param TripChatRequest    $data
     * @param Post               $operation
     * @param array{id?: string} $uriVariables
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): TripChatResponse
    {
        \assert($data instanceof TripChatRequest);

        $tripId = $uriVariables['id'] ?? '';
        if ('' === $tripId) {
            throw new NotFoundHttpException('Trip not found.');
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new HttpException(401, 'Authentication required.');
        }

        $userId = $user->getId()->toRfc4122();

        // Trip existence is guaranteed by TripRequestProvider — wired as the
        // operation's provider, it throws NotFoundHttpException before this
        // processor is entered. Don't re-read the cache here.

        // Feature-flag guard before consuming a rate-limit token, so a user
        // hitting a disabled endpoint doesn't burn their 20 req/min quota.
        if (!$this->llmClient->isEnabled()) {
            throw new ServiceUnavailableHttpException(retryAfter: null, message: 'AI assistant is currently disabled.');
        }

        $limiter = $this->tripChatLimiter->create($userId);
        $rateLimit = $limiter->consume();
        if (!$rateLimit->isAccepted()) {
            $retryAfter = $rateLimit->getRetryAfter();
            $secondsUntilRetry = max(0, $retryAfter->getTimestamp() - new \DateTimeImmutable()->getTimestamp());

            throw new TooManyRequestsHttpException(retryAfter: $secondsUntilRetry, message: 'Chat rate limit reached. Please wait a moment before retrying.');
        }

        $systemPrompt = $this->promptLoader->load(self::PROMPT_NAME);
        $userMessage = $this->buildUserMessage($data);
        $history = $this->historyStore->get($tripId, $userId);

        $messages = [...$history, ['role' => 'user', 'content' => $userMessage]];

        try {
            $response = $this->llmClient->chat(
                model: $this->model,
                messages: $messages,
                systemPrompt: $systemPrompt,
                options: [
                    'format' => 'json',
                    'num_predict' => self::MAX_RESPONSE_TOKENS,
                ],
            );
        } catch (OllamaUnavailableException $ollamaUnavailableException) {
            $this->logger->warning('Ollama unreachable — chat endpoint returning 503.', [
                'tripId' => $tripId,
                'error' => $ollamaUnavailableException->getMessage(),
            ]);

            throw new ServiceUnavailableHttpException(retryAfter: null, message: 'AI assistant is temporarily unavailable. Please retry shortly.', previous: $ollamaUnavailableException);
        }

        if (null === $response) {
            throw new ServiceUnavailableHttpException(retryAfter: null, message: 'AI assistant returned an empty response. Please retry.');
        }

        $rawContent = $this->extractText($response);
        if (null === $rawContent) {
            $this->logger->warning('Ollama chat response missing message content.', ['tripId' => $tripId]);

            throw new ServiceUnavailableHttpException(retryAfter: null, message: 'AI assistant returned an invalid response. Please retry.');
        }

        $action = $this->interpreter->interpret($rawContent);

        $this->historyStore->appendMany($tripId, $userId, [
            ['role' => 'user', 'content' => $userMessage],
            ['role' => 'assistant', 'content' => $rawContent],
        ]);

        $impactedStageNumbers = $this->resolveImpactedStageNumbers($tripId, $action);
        $requiresFullAnalysis = ChatAction::ACTION_CHANGE_ROUTE === $action->action;
        $dispatched = false;

        if ($requiresFullAnalysis) {
            $this->logger->info('Chat action requires full trip re-analysis (Acte 2).', [
                'tripId' => $tripId,
                'action' => $action->action,
            ]);
        } elseif ([] !== $impactedStageNumbers) {
            $dispatched = $this->dispatchRecomputation($tripId, $impactedStageNumbers);

            if ($dispatched) {
                $this->logger->info('Chat action triggered inline recomputation.', [
                    'tripId' => $tripId,
                    'action' => $action->action,
                    'impactedStageNumbers' => $impactedStageNumbers,
                ]);
            }
        }

        return new TripChatResponse(
            tripId: $tripId,
            action: $action->action,
            params: $action->params,
            response: $action->response,
            dispatched: $dispatched,
            impactedStageNumbers: $impactedStageNumbers,
            requiresFullAnalysis: $requiresFullAnalysis,
        );
    }

    /**
     * Maps a parsed action to the list of 1-indexed day numbers whose
     * recomputation must be triggered.
     *
     * @return list<int>
     */
    private function resolveImpactedStageNumbers(string $tripId, ChatAction $action): array
    {
        if (!\in_array($action->action, self::RECOMPUTE_ACTIONS, true)) {
            return [];
        }

        $stages = $this->tripStateManager->getStages($tripId) ?? [];
        $totalStages = \count($stages);
        if (0 === $totalStages) {
            return [];
        }

        $params = $action->params;

        switch ($action->action) {
            case ChatAction::ACTION_SPLIT_STAGE:
                $stage = $this->extractStageNumber($params['stage'] ?? null);
                if (null === $stage || $stage < 1 || $stage > $totalStages) {
                    return [];
                }

                // Split produces two halves: the original day and the new day immediately
                // after it. When the rider asks to split the last stage, the second half
                // becomes a brand-new day number.
                $second = min($stage + 1, $totalStages + 1);

                return [$stage, $second];

            case ChatAction::ACTION_MERGE_STAGES:
                $raw = $params['stages'] ?? null;
                if (!\is_array($raw)) {
                    return [];
                }

                $values = array_values($raw);
                $first = $this->extractStageNumber($values[0] ?? null);
                $second = $this->extractStageNumber($values[1] ?? null);
                if (null === $first || null === $second) {
                    return [];
                }

                if ($first < 1 || $first > $totalStages || $second < 1 || $second > $totalStages) {
                    return [];
                }

                // The surviving stage after merging two consecutive days is the
                // lower-numbered one. Normalise to min() so a reversed [3, 2]
                // pair from the LLM still shimmers the correct card.
                return [min($first, $second)];

            case ChatAction::ACTION_ADD_WAYPOINT:

            case ChatAction::ACTION_ADJUST_DISTANCE:
                $stage = $this->extractStageNumber($params['stage'] ?? null);
                if (null === $stage || $stage < 1 || $stage > $totalStages) {
                    return [];
                }

                return [$stage];
            case ChatAction::ACTION_CHANGE_ACCOMMODATION:
                $stage = $this->extractStageNumber($params['stage'] ?? null);
                if (null === $stage || $stage < 1 || $stage > $totalStages) {
                    return [];
                }

                // Changing the accommodation moves the arrival point — the next stage
                // departs from the new spot, so recompute it too when it exists.
                $next = $stage + 1;

                return $next <= $totalStages ? [$stage, $next] : [$stage];
            default:
                return [];
        }
    }

    private function extractStageNumber(mixed $value): ?int
    {
        if (\is_int($value) && $value > 0) {
            return $value;
        }

        if (\is_string($value) && ctype_digit($value)) {
            $int = (int) $value;

            return $int > 0 ? $int : null;
        }

        return null;
    }

    /**
     * Dispatches a single {@see RecalculateStages} message covering the affected
     * stage indices (0-indexed for the message contract). Returns `true` when a
     * dispatch happened — `false` when the indices map to nothing recomputable.
     *
     * @param list<int> $stageNumbers 1-indexed day numbers
     */
    private function dispatchRecomputation(string $tripId, array $stageNumbers): bool
    {
        $indices = array_values(array_unique(array_map(
            static fn (int $n): int => $n - 1,
            $stageNumbers,
        )));

        if ([] === $indices) {
            return false;
        }

        // Bump generation: chat-driven edits invalidate in-flight computations on
        // the same trip so a stale ScanPois cannot land after the new STAGE_UPDATED.
        $generation = $this->generationTracker->increment($tripId);

        $this->messageBus->dispatch(new RecalculateStages(
            tripId: $tripId,
            affectedIndices: $indices,
            generation: $generation,
            skipAiAnalysis: true,
        ));

        return true;
    }

    private function buildUserMessage(TripChatRequest $request): string
    {
        $currentStage = $request->context?->currentStage;
        $contextLine = \sprintf(
            "[contexte] currentStage=%s\n",
            null === $currentStage ? 'null' : (string) $currentStage,
        );

        return $contextLine.$request->message;
    }

    /**
     * @param array<string, mixed> $response
     */
    private function extractText(array $response): ?string
    {
        if (isset($response['message']) && \is_array($response['message'])) {
            $content = $response['message']['content'] ?? null;
            if (\is_string($content)) {
                return $content;
            }
        }

        if (isset($response['response']) && \is_string($response['response'])) {
            return $response['response'];
        }

        return null;
    }
}
